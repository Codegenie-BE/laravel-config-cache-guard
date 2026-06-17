<?php

declare(strict_types=1);

(static function (): void {
    $enabled = getenv('CONFIG_CACHE_GUARD_ENABLED');

    if (is_string($enabled) && in_array(strtolower($enabled), ['0', 'false', 'off', 'no'], true)) {
        return;
    }

    $basePath = dirname(__DIR__, 4);

    $configDir = $basePath . '/config';
    $cacheDir = $basePath . '/bootstrap/cache';

    $cachedConfigPath = $cacheDir . '/config.php';
    $signaturePath = $cacheDir . '/config-source.signature';
    $lockPath = $cacheDir . '/config-cache-refresh.lock';
    $failedPath = $cacheDir . '/config-cache-refresh.failed';

    $failureCooldownSeconds = (int) (getenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN') ?: 60);

    if ($failureCooldownSeconds < 1) {
        $failureCooldownSeconds = 60;
    }

    if (! is_dir($configDir) || ! is_dir($cacheDir)) {
        return;
    }

    $buildSignature = static function () use ($basePath, $configDir): ?string {
        $files = [];
        $envPath = $basePath . '/.env';

        if (is_file($envPath)) {
            $files[] = $envPath;
        }

        $externalAppEnv = getenv('APP_ENV');

        if (is_string($externalAppEnv) && $externalAppEnv !== '') {
            $environmentEnvPath = $basePath . '/.env.' . $externalAppEnv;

            if (is_file($environmentEnvPath)) {
                $files[] = $environmentEnvPath;
            }
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (
                    $file instanceof SplFileInfo
                    && $file->isFile()
                    && strtolower($file->getExtension()) === 'php'
                ) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (Throwable) {
            return null;
        }

        if ($files === []) {
            return null;
        }

        sort($files, SORT_STRING);

        $parts = [];

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            $size = @filesize($file);

            if ($mtime === false || $size === false) {
                return null;
            }

            $parts[] = str_replace($basePath . '/', '', $file) . '|' . $mtime . '|' . $size;
        }

        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        return hash($algorithm, implode("\n", $parts));
    };

    $readSignature = static function (string $path): ?string {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return trim($contents);
    };

    $writeSignature = static function (string $path, string $signature): void {
        @file_put_contents($path, $signature, LOCK_EX);
    };

    $invalidateOpcache = static function (string $path): void {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    };

    $isRecentlyFailed = static function (string $path, int $cooldownSeconds): bool {
        if (! is_file($path)) {
            return false;
        }

        $mtime = @filemtime($path);

        return $mtime !== false && $mtime > (time() - $cooldownSeconds);
    };

    $canUseExec = static function (): bool {
        if (! function_exists('exec')) {
            return false;
        }

        $disabledFunctions = array_filter(array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions'))
        ));

        return ! in_array('exec', $disabledFunctions, true);
    };

    $resolvePhpBinary = static function (): ?string {
        $candidates = array_filter([
            getenv('CONFIG_CACHE_GUARD_PHP_BINARY') ?: null,
            getenv('PHP_CLI_BINARY') ?: null,
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/alt/php85/usr/bin/php',
            '/opt/alt/php84/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            PHP_BINARY,
        ]);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $basename = strtolower(basename($candidate));

            if (
                str_contains($basename, 'fpm')
                || str_contains($basename, 'cgi')
                || str_contains($basename, 'lsphp')
            ) {
                continue;
            }

            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    };

    $currentSignature = $buildSignature();

    if ($currentSignature === null) {
        return;
    }

    $storedSignature = $readSignature($signaturePath);

    if ($storedSignature === $currentSignature && is_file($cachedConfigPath)) {
        return;
    }

    if ($isRecentlyFailed($failedPath, $failureCooldownSeconds)) {
        return;
    }

    $lock = @fopen($lockPath, 'c');

    if ($lock === false) {
        return;
    }

    try {
        if (! flock($lock, LOCK_EX)) {
            return;
        }

        clearstatcache();

        $currentSignature = $buildSignature();

        if ($currentSignature === null) {
            return;
        }

        $storedSignature = $readSignature($signaturePath);

        if ($storedSignature === $currentSignature && is_file($cachedConfigPath)) {
            return;
        }

        if (! $canUseExec()) {
            @unlink($cachedConfigPath);
            $invalidateOpcache($cachedConfigPath);
            @touch($failedPath);

            return;
        }

        $phpBinary = $resolvePhpBinary();

        if ($phpBinary === null) {
            @unlink($cachedConfigPath);
            $invalidateOpcache($cachedConfigPath);
            @touch($failedPath);

            return;
        }

        $command = sprintf(
            'cd %s && %s artisan config:cache --no-interaction --no-ansi 2>&1',
            escapeshellarg($basePath),
            escapeshellarg($phpBinary)
        );

        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);

        clearstatcache(true, $cachedConfigPath);

        if ($exitCode === 0 && is_file($cachedConfigPath)) {
            $writeSignature($signaturePath, $currentSignature);
            @unlink($failedPath);
            $invalidateOpcache($cachedConfigPath);

            return;
        }

        @unlink($cachedConfigPath);
        $invalidateOpcache($cachedConfigPath);
        @touch($failedPath);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
})();
