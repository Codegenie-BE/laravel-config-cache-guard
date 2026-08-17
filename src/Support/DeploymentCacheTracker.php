<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class DeploymentCacheTracker
{
    public static function recordSuccessfulCommand(
        string $command,
        int $exitCode,
        string $basePath,
        string $cachePath
    ): bool {
        if ($exitCode !== 0 || ! Environment::flag('CONFIG_CACHE_GUARD_ENABLED')) {
            return false;
        }

        return match ($command) {
            'config:cache' => Environment::flag('CONFIG_CACHE_GUARD_CONFIG')
                && self::recordConfig($basePath, $cachePath),
            'route:cache' => Environment::flag('CONFIG_CACHE_GUARD_ROUTES')
                && self::recordRoutes($basePath, $cachePath),
            default => false,
        };
    }

    private static function recordConfig(string $basePath, string $cachePath): bool
    {
        $cacheFile = ConfigCacheFile::current($basePath, $cachePath);

        if (! is_file($cacheFile)) {
            return false;
        }

        $signature = DeploymentCacheSignatures::config($basePath);

        if ($signature === null || ! DeploymentCacheSignatures::write($cachePath.'/config-source.signature', $signature)) {
            return false;
        }

        self::clearRepairState($cachePath, 'config');
        SuccessMarker::write(
            $cachePath.'/config-cache-refresh.succeeded',
            'config',
            $cacheFile,
            $signature
        );

        return true;
    }

    private static function recordRoutes(string $basePath, string $cachePath): bool
    {
        $cacheFile = RouteCacheFiles::current($cachePath);

        if (! is_file($cacheFile)) {
            return false;
        }

        $signature = DeploymentCacheSignatures::routes($basePath);

        if ($signature === null) {
            return false;
        }

        $trackedCacheFile = self::seedVersionedRouteCache($cacheFile, $cachePath, $signature);

        if (
            $trackedCacheFile === null
            || ! DeploymentCacheSignatures::write($cachePath.'/route-source.signature', $signature)
        ) {
            return false;
        }

        self::clearRepairState($cachePath, 'route');
        SuccessMarker::write(
            $cachePath.'/route-cache-refresh.succeeded',
            'route',
            $trackedCacheFile,
            $signature
        );

        return true;
    }

    private static function seedVersionedRouteCache(
        string $cacheFile,
        string $cachePath,
        string $signature
    ): ?string {
        if (
            Environment::string('APP_ROUTES_CACHE') !== null
            || ! Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true)
        ) {
            return $cacheFile;
        }

        $versionedPath = rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-'.$signature.'.php';

        if (self::normalizePath($cacheFile) === self::normalizePath($versionedPath)) {
            return $cacheFile;
        }

        $contents = @file_get_contents($cacheFile);

        if (! is_string($contents) || ! AtomicFile::write($versionedPath, $contents)) {
            return null;
        }

        return $versionedPath;
    }

    private static function clearRepairState(string $cachePath, string $target): void
    {
        @unlink($cachePath.'/'.$target.'-cache-refresh.pending');
        @unlink($cachePath.'/'.$target.'-cache-refresh.failed');
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
