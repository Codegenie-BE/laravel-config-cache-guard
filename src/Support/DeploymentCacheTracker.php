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

        $signature = DeploymentSourceManifest::signatures($basePath, $cachePath)['config'];

        if ($signature === null || ! DeploymentCacheSignatures::write($cachePath.'/config-source.signature', $signature)) {
            return false;
        }

        RepairState::clear($cachePath, 'config');
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

        $signature = DeploymentSourceManifest::signatures($basePath, $cachePath)['routes'];

        if ($signature === null) {
            return false;
        }

        $trackedCacheFile = Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true)
            ? RouteCacheFiles::seedVersioned($cacheFile, $cachePath, $signature)
            : $cacheFile;

        if (
            $trackedCacheFile === null
            || ! DeploymentCacheSignatures::write($cachePath.'/route-source.signature', $signature)
        ) {
            return false;
        }

        RepairState::clear($cachePath, 'route');
        SuccessMarker::write(
            $cachePath.'/route-cache-refresh.succeeded',
            'route',
            $trackedCacheFile,
            $signature
        );

        return true;
    }
}
