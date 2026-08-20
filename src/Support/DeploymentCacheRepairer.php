<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class DeploymentCacheRepairer
{
    /**
     * @param  null|callable(string): int  $artisanCall
     */
    public static function runPendingAfterResponse(
        Application $app,
        string $basePath,
        string $cachePath,
        ?callable $artisanCall = null,
        bool $createMissingCaches = false,
    ): void {
        if (! self::shouldRegisterTermination($basePath, $cachePath, $createMissingCaches)) {
            return;
        }

        $app->terminating(static function () use (
            $basePath,
            $cachePath,
            $artisanCall,
            $createMissingCaches,
        ): void {
            if ($createMissingCaches) {
                self::queueMissingCaches($basePath, $cachePath);
            }

            self::runPending($basePath, $cachePath, $artisanCall);
        });
    }

    public static function queueMissingCaches(string $basePath, string $cachePath): void
    {
        if (
            ! Environment::flag('CONFIG_CACHE_GUARD_ENABLED')
            || ! Environment::flag('CONFIG_CACHE_GUARD_AUTO_REPAIR', true)
            || ! is_dir($cachePath)
            || ! is_writable($cachePath)
        ) {
            return;
        }

        if (Environment::flag('CONFIG_CACHE_GUARD_CONFIG')) {
            $configCachePath = ConfigCacheFile::current($basePath, $cachePath);

            if (! is_file($configCachePath)) {
                self::queueMissingTarget(
                    $cachePath,
                    'config',
                    DeploymentCacheSignatures::config($basePath),
                );
            }
        }

        if (Environment::flag('CONFIG_CACHE_GUARD_ROUTES') && ! self::routeCacheExists($basePath, $cachePath)) {
            self::queueMissingTarget(
                $cachePath,
                'route',
                DeploymentCacheSignatures::routes($basePath),
            );
        }
    }

    /**
     * @param  null|callable(string): int  $artisanCall
     */
    public static function runPending(string $basePath, string $cachePath, ?callable $artisanCall = null): void
    {
        if (! Environment::flag('CONFIG_CACHE_GUARD_ENABLED')) {
            return;
        }

        if (! Environment::flag('CONFIG_CACHE_GUARD_AUTO_REPAIR', true)) {
            return;
        }

        if (! is_dir($cachePath) || ! is_writable($cachePath)) {
            return;
        }

        $artisanCall ??= static fn (string $command): int => Artisan::call($command);

        $configPendingPath = $cachePath.'/config-cache-refresh.pending';

        if (Environment::flag('CONFIG_CACHE_GUARD_CONFIG') && is_file($configPendingPath)) {
            self::withLock(
                $cachePath.'/config-cache-refresh.lock',
                static function () use ($basePath, $cachePath, $artisanCall, $configPendingPath): void {
                    self::repairConfigIfPending($basePath, $cachePath, $configPendingPath, $artisanCall);
                }
            );
        }

        $routePendingPath = $cachePath.'/route-cache-refresh.pending';

        if (Environment::flag('CONFIG_CACHE_GUARD_ROUTES') && is_file($routePendingPath)) {
            self::withLock(
                $cachePath.'/route-cache-refresh.lock',
                static function () use ($basePath, $cachePath, $artisanCall, $routePendingPath): void {
                    self::repairRoutesIfPending($basePath, $cachePath, $routePendingPath, $artisanCall);
                }
            );
        }
    }

    private static function shouldRegisterTermination(
        string $basePath,
        string $cachePath,
        bool $createMissingCaches,
    ): bool {
        if (self::hasPendingRepair($cachePath)) {
            return true;
        }

        if (
            ! $createMissingCaches
            || ! Environment::flag('CONFIG_CACHE_GUARD_ENABLED')
            || ! Environment::flag('CONFIG_CACHE_GUARD_AUTO_REPAIR', true)
        ) {
            return false;
        }

        if (
            Environment::flag('CONFIG_CACHE_GUARD_CONFIG')
            && ! is_file(ConfigCacheFile::current($basePath, $cachePath))
        ) {
            return true;
        }

        return Environment::flag('CONFIG_CACHE_GUARD_ROUTES')
            && ! self::routeCacheExists($basePath, $cachePath);
    }

    private static function routeCacheExists(string $basePath, string $cachePath): bool
    {
        $configuredPath = Environment::string('APP_ROUTES_CACHE');

        if ($configuredPath !== null) {
            $resolvedPath = self::resolveConfiguredPath($configuredPath, $basePath);

            if (is_file($resolvedPath)) {
                return true;
            }
        }

        return (glob(rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-*.php') ?: []) !== [];
    }

    private static function queueMissingTarget(string $cachePath, string $target, ?string $sourceSignature): void
    {
        if ($sourceSignature === null) {
            return;
        }

        $sourceSignature = strtolower($sourceSignature);
        $failedPath = $cachePath.'/'.$target.'-cache-refresh.failed';
        $pendingPath = $cachePath.'/'.$target.'-cache-refresh.pending';

        if (FailureMarker::sourceSignature($failedPath) === $sourceSignature) {
            return;
        }

        if (self::pendingSourceSignature($pendingPath) === $sourceSignature) {
            return;
        }

        if (is_file($failedPath)) {
            @unlink($failedPath);
        }

        AtomicFile::write(
            $pendingPath,
            implode(PHP_EOL, [
                'Codegenie Laravel Config Cache Guard pending auto repair',
                'generated_at='.gmdate('c'),
                'target='.$target,
                'reason=cache_missing',
                'message=Laravel '.$target.' cache is missing and will be created automatically.',
                'action=Laravel will create this cache through Artisan::call() after the current HTTP response is sent.',
                'source_signature='.$sourceSignature,
                'note=No .env values, secrets, tokens or command output are stored in this file.',
                '',
            ])
        );
    }

    /**
     * Recheck the pending marker after the lock is acquired. Another request may
     * already have completed the repair while this request was waiting.
     *
     * @param  callable(string): int  $artisanCall
     */
    private static function repairConfigIfPending(
        string $basePath,
        string $cachePath,
        string $pendingPath,
        callable $artisanCall
    ): void {
        if (! is_file($pendingPath)) {
            return;
        }

        self::repairConfig($basePath, $cachePath, $artisanCall);
    }

    /**
     * Recheck the pending marker after the lock is acquired. Another request may
     * already have completed the repair while this request was waiting.
     *
     * @param  callable(string): int  $artisanCall
     */
    private static function repairRoutesIfPending(
        string $basePath,
        string $cachePath,
        string $pendingPath,
        callable $artisanCall
    ): void {
        if (! is_file($pendingPath)) {
            return;
        }

        self::repairRoutes($basePath, $cachePath, $artisanCall);
    }

    /**
     * @param  callable(string): int  $artisanCall
     */
    private static function repairConfig(string $basePath, string $cachePath, callable $artisanCall): void
    {
        $configCachePath = ConfigCacheFile::current($basePath, $cachePath);
        $pendingPath = $cachePath.'/config-cache-refresh.pending';
        $pendingSignature = self::pendingSourceSignature($pendingPath);
        $failureReason = 'auto_repair_failed';
        $failureMessage = 'The in-app auto repair fallback could not rebuild the Laravel config cache through Artisan::call().';
        $failureAction = 'Check whether the application can run php artisan config:cache successfully.';
        $failureSignature = $pendingSignature;

        try {
            $exitCode = $artisanCall('config:cache');

            if ($exitCode === 0 && is_file($configCachePath)) {
                $signature = $pendingSignature ?? DeploymentCacheSignatures::config($basePath);
                $failureSignature = $signature;

                if ($signature === null) {
                    $failureReason = 'source_signature_unavailable';
                    $failureMessage = 'The Laravel config cache was rebuilt, but its deployment source signature could not be calculated.';
                    $failureAction = 'Check that the application config and deployment source files are readable.';
                } elseif (! DeploymentCacheSignatures::write($cachePath.'/config-source.signature', $signature)) {
                    $failureReason = 'signature_write_failed';
                    $failureMessage = 'The Laravel config cache was rebuilt, but its deployment source signature could not be stored safely.';
                    $failureAction = 'Fix ownership and write permissions for the config source signature in the active Laravel cache directory.';
                } else {
                    @unlink($pendingPath);
                    @unlink($cachePath.'/config-cache-refresh.failed');
                    self::invalidateOpcache($configCachePath);
                    SuccessMarker::write(
                        $cachePath.'/config-cache-refresh.succeeded',
                        'config',
                        $configCachePath,
                        $signature
                    );

                    return;
                }
            }
        } catch (Throwable) {
            // A safe diagnostic marker is written below. Command output and exception details are intentionally not exposed.
        }

        $failureSignature ??= DeploymentCacheSignatures::config($basePath);
        $removed = self::removeCacheFile($configCachePath);
        @unlink($pendingPath);

        FailureMarker::write(
            $cachePath.'/config-cache-refresh.failed',
            'config',
            $removed ? $failureReason : 'stale_cache_removal_failed',
            $removed
                ? $failureMessage
                : 'The in-app auto repair fallback failed and the resulting config cache file could not be removed safely.',
            $removed
                ? $failureAction.' The config cache was removed so Laravel cannot load untracked deployment state.'
                : 'Fix the permissions for the configured config cache file before the next request. The pre-bootstrap guard will stop requests rather than load an unsafe cache file.',
            $failureSignature,
        );
    }

    /**
     * @param  callable(string): int  $artisanCall
     */
    private static function repairRoutes(string $basePath, string $cachePath, callable $artisanCall): void
    {
        $pendingPath = $cachePath.'/route-cache-refresh.pending';
        $pendingSignature = self::pendingSourceSignature($pendingPath);
        $failureReason = 'auto_repair_failed';
        $failureMessage = 'The in-app auto repair fallback could not rebuild the Laravel route cache through Artisan::call().';
        $failureAction = 'Check whether this application can run php artisan route:cache successfully. Route definitions that Laravel cannot cache remain fully supported through normal uncached routing.';
        $routeCachePath = RouteCacheFiles::current($cachePath);
        $failureSignature = $pendingSignature;

        try {
            $exitCode = $artisanCall('route:cache');
            $routeCachePath = RouteCacheFiles::current($cachePath);

            if ($exitCode === 0 && is_file($routeCachePath)) {
                $signature = $pendingSignature ?? DeploymentCacheSignatures::routes($basePath);
                $failureSignature = $signature;

                if ($signature === null) {
                    $failureReason = 'source_signature_unavailable';
                    $failureMessage = 'The Laravel route cache was rebuilt, but its deployment source signature could not be calculated.';
                    $failureAction = 'Check that the application route, config and deployment source files are readable.';
                } else {
                    $trackedCachePath = Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true)
                        ? RouteCacheFiles::seedVersioned($routeCachePath, $cachePath, $signature)
                        : $routeCachePath;

                    if ($trackedCachePath === null) {
                        $failureReason = 'versioned_route_cache_write_failed';
                        $failureMessage = 'The Laravel route cache was rebuilt, but the signature-based route cache file could not be stored safely.';
                        $failureAction = 'Fix ownership and write permissions for the active Laravel cache directory.';
                    } elseif (! DeploymentCacheSignatures::write($cachePath.'/route-source.signature', $signature)) {
                        $failureReason = 'signature_write_failed';
                        $failureMessage = 'The Laravel route cache was rebuilt, but its deployment source signature could not be stored safely.';
                        $failureAction = 'Fix ownership and write permissions for the route source signature in the active Laravel cache directory.';
                    } else {
                        @unlink($pendingPath);
                        @unlink($cachePath.'/route-cache-refresh.failed');
                        self::invalidateOpcache($routeCachePath);
                        self::invalidateOpcache($trackedCachePath);
                        $cleanedFiles = RouteCacheFiles::removeStale($cachePath, [$trackedCachePath]);
                        SuccessMarker::write(
                            $cachePath.'/route-cache-refresh.succeeded',
                            'route',
                            $trackedCachePath,
                            $signature,
                            $cleanedFiles
                        );

                        return;
                    }
                }
            }
        } catch (Throwable) {
            // A safe diagnostic marker is written below. Command output and exception details are intentionally not exposed.
        }

        $failureSignature ??= DeploymentCacheSignatures::routes($basePath);
        $removed = self::removeCacheFile($routeCachePath);
        @unlink($pendingPath);

        FailureMarker::write(
            $cachePath.'/route-cache-refresh.failed',
            'route',
            $removed ? $failureReason : 'stale_cache_removal_failed',
            $removed
                ? $failureMessage
                : 'The in-app auto repair fallback failed and the resulting route cache file could not be removed safely.',
            $removed
                ? $failureAction.' The application continues with normal uncached routing until the route sources change or the failure is cleared.'
                : 'Fix the permissions for the configured route cache file before the next request. The pre-bootstrap guard will stop requests rather than load an unsafe cache file.',
            $failureSignature,
        );
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function withLock(string $lockPath, callable $callback): void
    {
        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            return;
        }

        try {
            if (! FileLock::acquire($lock)) {
                return;
            }

            $callback();
        } finally {
            FileLock::release($lock);
            fclose($lock);
        }
    }

    private static function hasPendingRepair(string $cachePath): bool
    {
        return is_file($cachePath.'/config-cache-refresh.pending')
            || is_file($cachePath.'/route-cache-refresh.pending');
    }

    private static function pendingSourceSignature(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines)) {
            return null;
        }

        foreach ($lines as $line) {
            if (! str_starts_with($line, 'source_signature=')) {
                continue;
            }

            $signature = substr($line, strlen('source_signature='));

            return preg_match('/^[a-f0-9]{16,128}$/i', $signature) === 1
                ? strtolower($signature)
                : null;
        }

        return null;
    }

    private static function resolveConfiguredPath(string $path, string $basePath): string
    {
        if (
            str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
        ) {
            return $path;
        }

        return rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function removeCacheFile(string $path): bool
    {
        if (! is_file($path)) {
            return true;
        }

        @unlink($path);
        self::invalidateOpcache($path);

        return ! is_file($path);
    }

    private static function invalidateOpcache(string $path): void
    {
        clearstatcache(true, $path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
