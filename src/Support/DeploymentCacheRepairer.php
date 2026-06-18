<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use Illuminate\Support\Facades\Artisan;
use Throwable;

final class DeploymentCacheRepairer
{
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

        if (Environment::flag('CONFIG_CACHE_GUARD_CONFIG') && is_file($cachePath.'/config-cache-refresh.pending')) {
            self::withLock(
                $cachePath.'/config-cache-refresh.lock',
                static fn (): bool => self::repairConfig($basePath, $cachePath, $artisanCall)
            );
        }

        if (Environment::flag('CONFIG_CACHE_GUARD_ROUTES') && is_file($cachePath.'/route-cache-refresh.pending')) {
            self::withLock(
                $cachePath.'/route-cache-refresh.lock',
                static fn (): bool => self::repairRoutes($basePath, $cachePath, $artisanCall)
            );
        }
    }

    /**
     * @param  callable(string): int  $artisanCall
     */
    private static function repairConfig(string $basePath, string $cachePath, callable $artisanCall): bool
    {
        try {
            $exitCode = $artisanCall('config:cache');
            $configCachePath = $cachePath.'/config.php';

            if ($exitCode === 0 && is_file($configCachePath)) {
                DeploymentCacheSignatures::write(
                    $cachePath.'/config-source.signature',
                    DeploymentCacheSignatures::config($basePath)
                );

                @unlink($cachePath.'/config-cache-refresh.pending');
                @unlink($cachePath.'/config-cache-refresh.failed');
                self::invalidateOpcache($configCachePath);

                return true;
            }
        } catch (Throwable) {
            // A safe diagnostic marker is written below. Command output and exception details are intentionally not exposed.
        }

        @unlink($cachePath.'/config.php');
        @unlink($cachePath.'/config-cache-refresh.pending');

        FailureMarker::write(
            $cachePath.'/config-cache-refresh.failed',
            'config',
            'auto_repair_failed',
            'The in-app auto repair fallback could not rebuild the Laravel config cache through Artisan::call().',
            'Check whether the application can run php artisan config:cache successfully. The stale config cache was removed.'
        );

        return false;
    }

    /**
     * @param  callable(string): int  $artisanCall
     */
    private static function repairRoutes(string $basePath, string $cachePath, callable $artisanCall): bool
    {
        try {
            $exitCode = $artisanCall('route:cache');
            $routeCachePaths = self::routeCachePaths($cachePath);

            if ($exitCode === 0 && $routeCachePaths !== []) {
                DeploymentCacheSignatures::write(
                    $cachePath.'/route-source.signature',
                    DeploymentCacheSignatures::routes($basePath)
                );

                @unlink($cachePath.'/route-cache-refresh.pending');
                @unlink($cachePath.'/route-cache-refresh.failed');

                foreach ($routeCachePaths as $routeCachePath) {
                    self::invalidateOpcache($routeCachePath);
                }

                return true;
            }
        } catch (Throwable) {
            // A safe diagnostic marker is written below. Command output and exception details are intentionally not exposed.
        }

        foreach (self::routeCachePaths($cachePath) as $routeCachePath) {
            @unlink($routeCachePath);
        }

        @unlink($cachePath.'/route-cache-refresh.pending');

        FailureMarker::write(
            $cachePath.'/route-cache-refresh.failed',
            'route',
            'auto_repair_failed',
            'The in-app auto repair fallback could not rebuild the Laravel route cache through Artisan::call().',
            'Check whether the application can run php artisan route:cache successfully. This can fail when the application contains non-cacheable routes.'
        );

        return false;
    }

    /**
     * @param  callable(): bool  $callback
     */
    private static function withLock(string $lockPath, callable $callback): void
    {
        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            return;
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<string>
     */
    private static function routeCachePaths(string $cachePath): array
    {
        return glob($cachePath.'/routes-*.php') ?: [];
    }

    private static function invalidateOpcache(string $path): void
    {
        clearstatcache(true, $path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
