<?php

declare(strict_types=1);

(static function (): void {
    $envString = static function (string $name): ?string {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    };

    $envFlagEnabled = static function (string $name, bool $default = true) use ($envString): bool {
        $value = $envString($name);

        if ($value === null || $value === '') {
            return $default;
        }

        return ! in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
    };

    if (! $envFlagEnabled('CONFIG_CACHE_GUARD_ENABLED')) {
        return;
    }

    $basePath = dirname(__DIR__, 4);
    $packagePath = dirname(__DIR__);
    $cacheDir = $basePath.'/bootstrap/cache';

    if (! is_dir($cacheDir)) {
        return;
    }

    $failureCooldownSeconds = (int) ($envString('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN') ?: 60);

    if ($failureCooldownSeconds < 1) {
        $failureCooldownSeconds = 60;
    }

    $failHard = $envFlagEnabled('CONFIG_CACHE_GUARD_FAIL_HARD', false);

    $collectPhpFiles = static function (string $directory): array {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
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
            return [];
        }

        return $files;
    };

    $envFiles = static function () use ($basePath, $envString): array {
        $files = [];
        $envPath = $basePath.'/.env';

        if (is_file($envPath)) {
            $files[] = $envPath;
        }

        $externalAppEnv = $envString('APP_ENV');

        if ($externalAppEnv !== null) {
            $environmentEnvPath = $basePath.'/.env.'.$externalAppEnv;

            if (is_file($environmentEnvPath)) {
                $files[] = $environmentEnvPath;
            }
        }

        return $files;
    };

    $buildSignature = static function (array $files, array $values = []) use ($basePath): ?string {
        $files = array_values(array_unique(array_filter(
            $files,
            static fn (mixed $file): bool => is_string($file) && is_file($file)
        )));

        if ($files === [] && $values === []) {
            return null;
        }

        sort($files, SORT_STRING);
        sort($values, SORT_STRING);

        $parts = [];

        foreach ($files as $file) {
            $stats = @stat($file);

            if (! is_array($stats)) {
                return null;
            }

            $parts[] = implode('|', [
                str_starts_with($file, $basePath.'/') ? str_replace($basePath.'/', '', $file) : $file,
                (string) ($stats['mtime'] ?? 0),
                (string) ($stats['ctime'] ?? 0),
                (string) ($stats['size'] ?? 0),
                (string) ($stats['ino'] ?? 0),
            ]);
        }

        foreach ($values as $value) {
            $parts[] = 'value|'.$value;
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

    $removeCachedFiles = static function (array $paths) use ($invalidateOpcache): void {
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            @unlink($path);
            clearstatcache(true, $path);
            $invalidateOpcache($path);
        }
    };

    $cacheExists = static function (array $paths): bool {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                return true;
            }
        }

        return false;
    };

    $writeFailureMarker = static function (string $path, string $target, string $reason, string $message, string $action): void {
        $contents = implode(PHP_EOL, [
            'Codegenie Laravel Config Cache Guard failure',
            'generated_at='.gmdate('c'),
            'target='.$target,
            'reason='.$reason,
            'message='.$message,
            'action='.$action,
            'repair_endpoint=/_config-cache-guard/repair',
            'note=No .env values, secrets, tokens or command output are stored in this file.',
            '',
        ]);

        @file_put_contents($path, $contents, LOCK_EX);
    };

    $showFailure = static function (string $target, string $reason, string $message, string $action): void {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Laravel deployment cache refresh failed</title><style>body{font-family:ui-sans-serif,system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px;line-height:1.6;color:#172033}code{background:#f3f4f6;padding:2px 5px;border-radius:4px}</style></head><body>';
        echo '<h1>Laravel deployment cache refresh failed</h1>';
        echo '<p><strong>Target:</strong> '.htmlspecialchars($target, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p><strong>Reason:</strong> '.htmlspecialchars($reason, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p><strong>Action:</strong> '.htmlspecialchars($action, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p>You can also enable the protected repair endpoint with <code>CONFIG_CACHE_GUARD_REPAIR_TOKEN</code> to rebuild through Laravel without <code>exec()</code>.</p>';
        echo '<p>No .env values, secrets, tokens or command output are shown.</p>';
        echo '</body></html>';

        exit;
    };

    $fail = static function (array $target, string $reason, string $message, string $action) use ($writeFailureMarker, $showFailure, $failHard): void {
        $writeFailureMarker($target['failed_path'], $target['name'], $reason, $message, $action);

        if ($failHard) {
            $showFailure($target['name'], $reason, $message, $action);
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

    $resolvePhpBinary = static function () use ($envString): ?string {
        $candidates = array_filter([
            $envString('CONFIG_CACHE_GUARD_PHP_BINARY'),
            $envString('PHP_CLI_BINARY'),
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

    $runArtisan = static function (string $command, string $phpBinary) use ($basePath): bool {
        $shellCommand = sprintf(
            'cd %s && %s artisan %s --no-interaction --no-ansi 2>&1',
            escapeshellarg($basePath),
            escapeshellarg($phpBinary),
            escapeshellarg($command)
        );

        $output = [];
        $exitCode = 1;

        exec($shellCommand, $output, $exitCode);

        return $exitCode === 0;
    };

    $refreshDeploymentCache = static function (array $target) use (
        $buildSignature,
        $readSignature,
        $writeSignature,
        $removeCachedFiles,
        $invalidateOpcache,
        $cacheExists,
        $isRecentlyFailed,
        $canUseExec,
        $resolvePhpBinary,
        $runArtisan,
        $failureCooldownSeconds,
        $fail
    ): void {
        $sourceFiles = is_callable($target['source_files']) ? $target['source_files']() : [];
        $sourceValues = is_callable($target['source_values'] ?? null) ? $target['source_values']() : [];
        $currentSignature = $buildSignature($sourceFiles, $sourceValues);

        if ($currentSignature === null) {
            return;
        }

        $cachedFiles = is_callable($target['cached_files']) ? $target['cached_files']() : [];
        $targetCacheExists = $cacheExists($cachedFiles);

        if (! $targetCacheExists && ! ($target['create_when_missing'] ?? false)) {
            return;
        }

        $storedSignature = $readSignature($target['signature_path']);

        if ($storedSignature === $currentSignature && $targetCacheExists) {
            return;
        }

        if ($isRecentlyFailed($target['failed_path'], $failureCooldownSeconds)) {
            $removeCachedFiles($cachedFiles);

            if ($target['fail_on_recent_failure'] ?? true) {
                $fail(
                    $target,
                    'recent_failure_cooldown',
                    'Automatic '.$target['name'].' cache refresh recently failed and is waiting before retrying.',
                    'Use the protected repair endpoint or fix the hosting environment before retrying.'
                );
            }

            return;
        }

        $lock = @fopen($target['lock_path'], 'c');

        if ($lock === false) {
            return;
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return;
            }

            clearstatcache();

            $sourceFiles = is_callable($target['source_files']) ? $target['source_files']() : [];
            $sourceValues = is_callable($target['source_values'] ?? null) ? $target['source_values']() : [];
            $currentSignature = $buildSignature($sourceFiles, $sourceValues);

            if ($currentSignature === null) {
                return;
            }

            $cachedFiles = is_callable($target['cached_files']) ? $target['cached_files']() : [];
            $targetCacheExists = $cacheExists($cachedFiles);

            if (! $targetCacheExists && ! ($target['create_when_missing'] ?? false)) {
                return;
            }

            $storedSignature = $readSignature($target['signature_path']);

            if ($storedSignature === $currentSignature && $targetCacheExists) {
                return;
            }

            if (! $canUseExec()) {
                $removeCachedFiles($cachedFiles);
                $fail(
                    $target,
                    'exec_disabled',
                    'Automatic '.$target['name'].' cache refresh cannot run because PHP exec() is unavailable or disabled on this hosting account.',
                    'Ask your hosting provider to enable exec(), or use the protected repair endpoint to rebuild through Laravel without exec().'
                );

                return;
            }

            $phpBinary = $resolvePhpBinary();

            if ($phpBinary === null) {
                $removeCachedFiles($cachedFiles);
                $fail(
                    $target,
                    'php_cli_not_found',
                    'Automatic '.$target['name'].' cache refresh cannot run because no PHP CLI binary was found.',
                    'Set CONFIG_CACHE_GUARD_PHP_BINARY to the full PHP CLI path, or use the protected repair endpoint.'
                );

                return;
            }

            $rebuilt = $runArtisan($target['artisan_command'], $phpBinary);
            $cachedFiles = is_callable($target['cached_files']) ? $target['cached_files']() : [];
            $targetCacheExists = $cacheExists($cachedFiles);

            if ($rebuilt && $targetCacheExists) {
                $writeSignature($target['signature_path'], $currentSignature);
                @unlink($target['failed_path']);

                foreach ($cachedFiles as $cachedFile) {
                    if (is_string($cachedFile)) {
                        clearstatcache(true, $cachedFile);
                        $invalidateOpcache($cachedFile);
                    }
                }

                return;
            }

            $removeCachedFiles($cachedFiles);
            $fail(
                $target,
                'artisan_command_failed',
                'The '.$target['artisan_command'].' command did not complete successfully.',
                'Run the command manually, check whether it works in this application, or use the protected repair endpoint.'
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    };

    if ($envFlagEnabled('CONFIG_CACHE_GUARD_CONFIG')) {
        $configDir = $basePath.'/config';

        if (is_dir($configDir)) {
            $refreshDeploymentCache([
                'artisan_command' => 'config:cache',
                'cached_files' => static fn (): array => [$cacheDir.'/config.php'],
                'create_when_missing' => true,
                'failed_path' => $cacheDir.'/config-cache-refresh.failed',
                'lock_path' => $cacheDir.'/config-cache-refresh.lock',
                'name' => 'config',
                'signature_path' => $cacheDir.'/config-source.signature',
                'source_files' => static function () use ($collectPhpFiles, $configDir, $envFiles): array {
                    return array_merge($collectPhpFiles($configDir), $envFiles());
                },
            ]);
        }
    }

    if ($envFlagEnabled('CONFIG_CACHE_GUARD_ROUTES')) {
        $routeCacheFiles = static fn (): array => glob($cacheDir.'/routes-*.php') ?: [];

        if ($routeCacheFiles() !== []) {
            $refreshDeploymentCache([
                'artisan_command' => 'route:cache',
                'cached_files' => $routeCacheFiles,
                'create_when_missing' => false,
                'failed_path' => $cacheDir.'/route-cache-refresh.failed',
                'lock_path' => $cacheDir.'/route-cache-refresh.lock',
                'name' => 'route',
                'signature_path' => $cacheDir.'/route-source.signature',
                'source_files' => static function () use ($basePath, $packagePath, $collectPhpFiles, $envFiles): array {
                    $files = array_merge($collectPhpFiles($basePath.'/routes'), $envFiles());

                    foreach ([
                        $basePath.'/bootstrap/app.php',
                        $basePath.'/app/Providers/RouteServiceProvider.php',
                        $packagePath.'/routes/repair.php',
                    ] as $routeSourceFile) {
                        if (is_file($routeSourceFile)) {
                            $files[] = $routeSourceFile;
                        }
                    }

                    return $files;
                },
                'source_values' => static function () use ($envFlagEnabled, $envString): array {
                    return [
                        'repair_enabled='.($envFlagEnabled('CONFIG_CACHE_GUARD_REPAIR_ENABLED', true) ? 'yes' : 'no'),
                        'repair_token_configured='.($envString('CONFIG_CACHE_GUARD_REPAIR_TOKEN') !== null ? 'yes' : 'no'),
                        'repair_allow_get='.($envFlagEnabled('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET', false) ? 'yes' : 'no'),
                    ];
                },
            ]);
        }
    }
})();
