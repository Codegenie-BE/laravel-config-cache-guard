<?php

declare(strict_types=1);

/**
 * Codegenie Laravel Config Cache Guard
 *
 * This file is loaded by Composer before Laravel bootstraps. It intentionally
 * avoids Laravel classes so it can safely remove stale deployment cache files
 * before Laravel has a chance to load them.
 */
$definedVariables = get_defined_vars();
$composerAutoloadPath = $definedVariables['_composer_autoload_path'] ?? null;

(static function (?string $composerAutoloadPath): void {

    /**
     * @return non-empty-string|null
     */
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

        if ($value === null) {
            return $default;
        }

        return ! in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
    };

    if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && ! $envFlagEnabled('CONFIG_CACHE_GUARD_ALLOW_CLI', false)) {
        return;
    }

    $loadedKey = realpath(__FILE__) ?: __FILE__;
    $loadedGuards = $GLOBALS['__codegenie_config_cache_guard_loaded'] ?? [];

    if (! is_array($loadedGuards)) {
        $loadedGuards = [];
    }

    if (($loadedGuards[$loadedKey] ?? false) === true) {
        return;
    }

    $loadedGuards[$loadedKey] = true;
    $GLOBALS['__codegenie_config_cache_guard_loaded'] = $loadedGuards;

    if (! $envFlagEnabled('CONFIG_CACHE_GUARD_ENABLED')) {
        return;
    }

    $resolveBasePath = static function () use ($composerAutoloadPath): ?string {
        $candidates = [];

        if ($composerAutoloadPath !== null && is_file($composerAutoloadPath)) {
            $candidates[] = dirname(dirname($composerAutoloadPath));
        }

        $vendorBasedPath = dirname(__DIR__, 4);
        $candidates[] = $vendorBasedPath;

        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (is_string($scriptFilename) && $scriptFilename !== '') {
            $publicPath = dirname($scriptFilename);
            $candidates[] = dirname($publicPath);
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

        if (is_string($documentRoot) && $documentRoot !== '') {
            $candidates[] = dirname($documentRoot);
        }

        $cwd = getcwd();

        if ($cwd !== false) {
            $candidates[] = $cwd;

            if (basename($cwd) === 'public') {
                $candidates[] = dirname($cwd);
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);

            if ($candidate === '') {
                continue;
            }

            if (
                is_dir($candidate.'/bootstrap/cache')
                && (is_file($candidate.'/artisan') || is_dir($candidate.'/config') || is_dir($candidate.'/routes'))
            ) {
                return $candidate;
            }
        }

        return null;
    };

    $basePath = $resolveBasePath();

    if ($basePath === null) {
        return;
    }

    $cacheDir = $basePath.'/bootstrap/cache';

    if (! is_dir($cacheDir)) {
        return;
    }

    $failureCooldownSeconds = (int) ($envString('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN') ?: 60);

    if ($failureCooldownSeconds < 1) {
        $failureCooldownSeconds = 60;
    }

    $failHard = $envFlagEnabled('CONFIG_CACHE_GUARD_FAIL_HARD', false);
    $autoRepair = $envFlagEnabled('CONFIG_CACHE_GUARD_AUTO_REPAIR', true);
    $createConfigWhenMissing = $envFlagEnabled('CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE', false);

    /**
     * @return list<string>
     */
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

    /**
     * @return list<string>
     */
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

    /**
     * @param  array<int, mixed>  $files
     */
    $buildSignature = static function (array $files) use ($basePath): ?string {
        $validFiles = [];

        foreach ($files as $file) {
            if (is_string($file) && is_file($file)) {
                $validFiles[] = $file;
            }
        }

        $files = array_values(array_unique($validFiles));

        if ($files === []) {
            return null;
        }

        sort($files, SORT_STRING);

        $parts = [];

        foreach ($files as $file) {
            $stats = @stat($file);

            if (! is_array($stats)) {
                return null;
            }

            $parts[] = implode('|', [
                str_starts_with($file, $basePath.'/') ? str_replace($basePath.'/', '', $file) : $file,
                (string) $stats['mtime'],
                (string) $stats['ctime'],
                (string) $stats['size'],
                (string) $stats['ino'],
            ]);
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
        clearstatcache(true, $path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    };

    /**
     * @param  array<int, mixed>  $paths
     */
    $removeCachedFiles = static function (array $paths) use ($invalidateOpcache): void {
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            @unlink($path);
            $invalidateOpcache($path);
        }
    };

    /**
     * @param  array<int, mixed>  $paths
     */
    $cacheExists = static function (array $paths): bool {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                return true;
            }
        }

        return false;
    };

    $markerContents = static function (string $title, string $target, string $reason, string $message, string $action): string {
        return implode(PHP_EOL, [
            $title,
            'generated_at='.gmdate('c'),
            'target='.$target,
            'reason='.$reason,
            'message='.$message,
            'action='.$action,
            'note=No .env values, secrets, tokens or command output are stored in this file.',
            '',
        ]);
    };

    $writeFailureMarker = static function (string $path, string $target, string $reason, string $message, string $action) use ($markerContents): void {
        @file_put_contents(
            $path,
            $markerContents('Codegenie Laravel Config Cache Guard failure', $target, $reason, $message, $action),
            LOCK_EX
        );
    };

    $writePendingMarker = static function (string $path, string $target, string $reason, string $message, string $action) use ($markerContents): void {
        @file_put_contents(
            $path,
            $markerContents('Codegenie Laravel Config Cache Guard pending auto repair', $target, $reason, $message, $action),
            LOCK_EX
        );
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
        echo '<p>No .env values, secrets, tokens or command output are shown.</p>';
        echo '</body></html>';

        exit;
    };

    $fail = static function (string $failedPath, string $name, string $reason, string $message, string $action) use ($writeFailureMarker, $showFailure, $failHard): void {
        $writeFailureMarker($failedPath, $name, $reason, $message, $action);

        if ($failHard) {
            $showFailure($name, $reason, $message, $action);
        }
    };

    $queueAutoRepairOrFail = static function (string $pendingPath, string $failedPath, string $name, string $reason, string $message, string $action) use ($autoRepair, $writePendingMarker, $showFailure, $failHard, $fail): void {
        if ($autoRepair) {
            @unlink($failedPath);

            $writePendingMarker(
                $pendingPath,
                $name,
                $reason,
                $message,
                'Laravel will try to rebuild this cache through Artisan::call() after the application boots.'
            );

            if ($failHard) {
                $showFailure(
                    $name,
                    $reason,
                    $message,
                    'A pending auto repair marker was written, but fail-hard mode stops this request before Laravel can boot. Disable CONFIG_CACHE_GUARD_FAIL_HARD to allow in-app auto repair.'
                );
            }

            return;
        }

        $fail($failedPath, $name, $reason, $message, $action);
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
        $candidates = [
            $envString('CONFIG_CACHE_GUARD_PHP_BINARY'),
            $envString('PHP_CLI_BINARY'),
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/alt/php85/usr/bin/php',
            '/opt/alt/php84/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            PHP_BINARY,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
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

    /**
     * @return array<int, mixed>
     */
    $arrayFromCallback = static function (callable $callback): array {
        $values = $callback();

        return is_array($values) ? $values : [];
    };

    /**
     * @param  array<string, mixed>  $target
     */
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
        $arrayFromCallback,
        $failureCooldownSeconds,
        $fail,
        $queueAutoRepairOrFail
    ): void {
        $artisanCommand = $target['artisan_command'] ?? null;
        $cachedFilesCallback = $target['cached_files'] ?? null;
        $createWhenMissing = ($target['create_when_missing'] ?? false) === true;
        $failedPath = $target['failed_path'] ?? null;
        $failOnRecentFailure = ($target['fail_on_recent_failure'] ?? true) !== false;
        $lockPath = $target['lock_path'] ?? null;
        $name = $target['name'] ?? null;
        $pendingPath = $target['pending_path'] ?? null;
        $signaturePath = $target['signature_path'] ?? null;
        $sourceFilesCallback = $target['source_files'] ?? null;

        if (
            ! is_string($artisanCommand)
            || ! is_callable($cachedFilesCallback)
            || ! is_string($failedPath)
            || ! is_string($lockPath)
            || ! is_string($name)
            || ! is_string($pendingPath)
            || ! is_string($signaturePath)
            || ! is_callable($sourceFilesCallback)
        ) {
            return;
        }

        $sourceFiles = $arrayFromCallback($sourceFilesCallback);
        $currentSignature = $buildSignature($sourceFiles);

        if ($currentSignature === null) {
            return;
        }

        $cachedFiles = $arrayFromCallback($cachedFilesCallback);
        $targetCacheExists = $cacheExists($cachedFiles);

        if (! $targetCacheExists && ! $createWhenMissing) {
            return;
        }

        $storedSignature = $readSignature($signaturePath);

        if ($storedSignature === $currentSignature && $targetCacheExists) {
            return;
        }

        if ($isRecentlyFailed($failedPath, $failureCooldownSeconds)) {
            $removeCachedFiles($cachedFiles);
            @unlink($pendingPath);

            if ($failOnRecentFailure) {
                $fail(
                    $failedPath,
                    $name,
                    'recent_failure_cooldown',
                    'Automatic '.$name.' cache refresh recently failed and is waiting before retrying.',
                    'Fix the cause shown in this marker, then clear failure markers or wait for the cooldown.'
                );
            }

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

            $sourceFiles = $arrayFromCallback($sourceFilesCallback);
            $currentSignature = $buildSignature($sourceFiles);

            if ($currentSignature === null) {
                return;
            }

            $cachedFiles = $arrayFromCallback($cachedFilesCallback);
            $targetCacheExists = $cacheExists($cachedFiles);

            if (! $targetCacheExists && ! $createWhenMissing) {
                return;
            }

            $storedSignature = $readSignature($signaturePath);

            if ($storedSignature === $currentSignature && $targetCacheExists) {
                return;
            }

            if (! $canUseExec()) {
                $removeCachedFiles($cachedFiles);
                $queueAutoRepairOrFail(
                    $pendingPath,
                    $failedPath,
                    $name,
                    'exec_disabled',
                    'Automatic '.$name.' cache refresh cannot run before Laravel boots because PHP exec() is unavailable or disabled on this hosting account.',
                    'Ask your hosting provider to enable exec(), or let the in-app auto repair fallback rebuild after Laravel boots.'
                );

                return;
            }

            $phpBinary = $resolvePhpBinary();

            if ($phpBinary === null) {
                $removeCachedFiles($cachedFiles);
                $queueAutoRepairOrFail(
                    $pendingPath,
                    $failedPath,
                    $name,
                    'php_cli_not_found',
                    'Automatic '.$name.' cache refresh cannot run before Laravel boots because no PHP CLI binary was found.',
                    'Set CONFIG_CACHE_GUARD_PHP_BINARY to the full PHP CLI path, or let the in-app auto repair fallback rebuild after Laravel boots.'
                );

                return;
            }

            $rebuilt = $runArtisan($artisanCommand, $phpBinary);
            $cachedFiles = $arrayFromCallback($cachedFilesCallback);
            $targetCacheExists = $cacheExists($cachedFiles);

            if ($rebuilt && $targetCacheExists) {
                $writeSignature($signaturePath, $currentSignature);
                @unlink($failedPath);
                @unlink($pendingPath);

                foreach ($cachedFiles as $cachedFile) {
                    if (is_string($cachedFile)) {
                        $invalidateOpcache($cachedFile);
                    }
                }

                return;
            }

            $removeCachedFiles($cachedFiles);
            $queueAutoRepairOrFail(
                $pendingPath,
                $failedPath,
                $name,
                'artisan_command_failed',
                'The '.$artisanCommand.' command did not complete successfully before Laravel booted.',
                'Check whether this application can run the command successfully, or let the in-app auto repair fallback try after Laravel boots.'
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
                'create_when_missing' => $createConfigWhenMissing,
                'failed_path' => $cacheDir.'/config-cache-refresh.failed',
                'lock_path' => $cacheDir.'/config-cache-refresh.lock',
                'name' => 'config',
                'pending_path' => $cacheDir.'/config-cache-refresh.pending',
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
                'pending_path' => $cacheDir.'/route-cache-refresh.pending',
                'signature_path' => $cacheDir.'/route-source.signature',
                'source_files' => static function () use ($basePath, $collectPhpFiles, $envFiles): array {
                    $files = array_merge($collectPhpFiles($basePath.'/routes'), $envFiles());

                    foreach ([
                        $basePath.'/bootstrap/app.php',
                        $basePath.'/app/Providers/RouteServiceProvider.php',
                    ] as $routeSourceFile) {
                        if (is_file($routeSourceFile)) {
                            $files[] = $routeSourceFile;
                        }
                    }

                    return $files;
                },
            ]);
        }
    }
})(is_string($composerAutoloadPath) ? $composerAutoloadPath : null);
