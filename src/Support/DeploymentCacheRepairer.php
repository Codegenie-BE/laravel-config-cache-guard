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
        if (! self::automaticRepairAvailable($cachePath)) {
            return;
        }

        $configMissing = Environment::flag('CONFIG_CACHE_GUARD_CONFIG')
            && ! is_file(ConfigCacheFile::current($basePath, $cachePath));
        $routeMissing = Environment::flag('CONFIG_CACHE_GUARD_ROUTES')
            && ! self::routeCacheExists($cachePath);

        if (! $configMissing && ! $routeMissing) {
            return;
        }

        $state = DeploymentSourceManifest::signatures($basePath, $cachePath);

        if ($configMissing && $state['config'] !== null) {
            RepairState::queue(
                $cachePath,
                'config',
                $state['config'],
                'cache_missing',
                'Laravel config cache is missing and will be created automatically.',
                'Laravel will create this cache through Artisan::call() after the current HTTP response is sent.',
            );
        }

        if ($routeMissing && $state['routes'] !== null) {
            RepairState::queue(
                $cachePath,
                'route',
                $state['routes'],
                'cache_missing',
                'Laravel route cache is missing and will be created automatically when the route definitions are cacheable.',
                'Laravel will create this cache through Artisan::call() after the current HTTP response is sent.',
            );
        }
    }

    /**
     * @param  null|callable(string): int  $artisanCall
     */
    public static function runPending(string $basePath, string $cachePath, ?callable $artisanCall = null): void
    {
        if (! self::automaticRepairAvailable($cachePath) || ! RepairState::hasPending($cachePath)) {
            return;
        }

        $artisanCall ??= static fn (string $command): int => Artisan::call($command);
        $lock = @fopen(rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'deployment-cache-repair.lock', 'c');

        if ($lock === false) {
            return;
        }

        try {
            if (! FileLock::acquire($lock)) {
                return;
            }

            $configPendingPath = RepairState::pendingPath($cachePath, 'config');
            $routePendingPath = RepairState::pendingPath($cachePath, 'route');
            $configPending = Environment::flag('CONFIG_CACHE_GUARD_CONFIG') && is_file($configPendingPath);
            $routePending = Environment::flag('CONFIG_CACHE_GUARD_ROUTES') && is_file($routePendingPath);

            if (! $configPending && ! $routePending) {
                return;
            }

            $configPendingSignature = $configPending
                ? FailureMarker::sourceSignature($configPendingPath)
                : null;
            $routePendingSignature = $routePending
                ? FailureMarker::sourceSignature($routePendingPath)
                : null;
            $configExitCode = $configPending ? self::callArtisan($artisanCall, 'config:cache') : null;
            $routeExitCode = $routePending ? self::callArtisan($artisanCall, 'route:cache') : null;
            $state = DeploymentSourceManifest::refresh($basePath, $cachePath);

            if ($configPending) {
                self::finalizeConfigRepair(
                    $basePath,
                    $cachePath,
                    $configExitCode,
                    $configPendingSignature,
                    $state['config'],
                );
            }

            if ($routePending) {
                self::finalizeRouteRepair(
                    $cachePath,
                    $routeExitCode,
                    $routePendingSignature,
                    $state['routes'],
                );
            }
        } finally {
            FileLock::release($lock);
            fclose($lock);
        }
    }

    private static function shouldRegisterTermination(
        string $basePath,
        string $cachePath,
        bool $createMissingCaches,
    ): bool {
        if (RepairState::hasPending($cachePath)) {
            return true;
        }

        if (! $createMissingCaches || ! self::automaticRepairAvailable($cachePath)) {
            return false;
        }

        $configMissing = Environment::flag('CONFIG_CACHE_GUARD_CONFIG')
            && ! is_file(ConfigCacheFile::current($basePath, $cachePath));
        $routeMissing = Environment::flag('CONFIG_CACHE_GUARD_ROUTES')
            && ! self::routeCacheExists($cachePath);

        if (! $configMissing && ! $routeMissing) {
            return false;
        }

        $configFailedPath = RepairState::failedPath($cachePath, 'config');
        $routeFailedPath = RepairState::failedPath($cachePath, 'route');

        if (($configMissing && ! is_file($configFailedPath)) || ($routeMissing && ! is_file($routeFailedPath))) {
            return true;
        }

        // A previous cache-generation failure is a stable state. Verify it via
        // the manifest fast path and only schedule another attempt when its
        // source signature changed or the bounded cooldown expired.
        $state = DeploymentSourceManifest::signatures($basePath, $cachePath);

        if (
            $configMissing
            && ($state['config'] === null || ! RepairState::retrySuppressed($cachePath, 'config', $state['config']))
        ) {
            return true;
        }

        return $routeMissing
            && ($state['routes'] === null || ! RepairState::retrySuppressed($cachePath, 'route', $state['routes']));
    }

    private static function automaticRepairAvailable(string $cachePath): bool
    {
        return Environment::flag('CONFIG_CACHE_GUARD_ENABLED')
            && Environment::flag('CONFIG_CACHE_GUARD_AUTO_REPAIR', true)
            && is_dir($cachePath)
            && is_writable($cachePath);
    }

    private static function routeCacheExists(string $cachePath): bool
    {
        return RouteCacheFiles::existingFast(
            $cachePath,
            self::readSignature($cachePath.'/route-source.signature'),
        ) !== null;
    }

    /**
     * @param  callable(string): int  $artisanCall
     */
    private static function callArtisan(callable $artisanCall, string $command): int
    {
        try {
            return $artisanCall($command);
        } catch (Throwable) {
            return 1;
        }
    }

    private static function finalizeConfigRepair(
        string $basePath,
        string $cachePath,
        ?int $exitCode,
        ?string $pendingSignature,
        ?string $currentSignature,
    ): void {
        $cacheFile = ConfigCacheFile::current($basePath, $cachePath);
        $pendingPath = RepairState::pendingPath($cachePath, 'config');
        $sourceChanged = $currentSignature !== null
            && $pendingSignature !== null
            && ! hash_equals($pendingSignature, $currentSignature);
        $signatureAttempted = false;
        $signatureWritten = false;

        if (
            $exitCode === 0
            && is_file($cacheFile)
            && $currentSignature !== null
            && ! $sourceChanged
        ) {
            $signatureAttempted = true;
            $signatureWritten = DeploymentCacheSignatures::write(
                $cachePath.'/config-source.signature',
                $currentSignature,
            );

            if ($signatureWritten) {
                RepairState::clear($cachePath, 'config');
                self::invalidateOpcache($cacheFile);
                SuccessMarker::write(
                    $cachePath.'/config-cache-refresh.succeeded',
                    'config',
                    $cacheFile,
                    $currentSignature,
                );

                return;
            }
        }

        $removed = self::removeCacheFile($cacheFile);
        @unlink($pendingPath);

        if ($sourceChanged && $currentSignature !== null) {
            RepairState::queue(
                $cachePath,
                'config',
                $currentSignature,
                'source_changed_during_repair',
                'Config sources changed while cache repair was running.',
                'Retry config cache generation after the current response.',
            );

            return;
        }

        $reason = match (true) {
            ! $removed => 'stale_cache_removal_failed',
            $currentSignature === null => 'source_signature_unavailable',
            $signatureAttempted && ! $signatureWritten => 'signature_write_failed',
            default => 'auto_repair_failed',
        };

        FailureMarker::write(
            RepairState::failedPath($cachePath, 'config'),
            'config',
            $reason,
            match ($reason) {
                'stale_cache_removal_failed' => 'Config cache repair failed and the resulting cache file could not be removed safely.',
                'source_signature_unavailable' => 'Config cache repair could not verify the current deployment source signature.',
                'signature_write_failed' => 'The config cache was generated, but its deployment source signature could not be stored safely.',
                default => 'Laravel config cache could not be generated safely through Artisan::call().',
            },
            'Check that config cache can be generated and that the active cache directory is writable.',
            $currentSignature ?? $pendingSignature,
        );
    }

    private static function finalizeRouteRepair(
        string $cachePath,
        ?int $exitCode,
        ?string $pendingSignature,
        ?string $currentSignature,
    ): void {
        $cacheFile = RouteCacheFiles::current($cachePath);
        $pendingPath = RepairState::pendingPath($cachePath, 'route');
        $sourceChanged = $currentSignature !== null
            && $pendingSignature !== null
            && ! hash_equals($pendingSignature, $currentSignature);
        $versionedAttempted = false;
        $versionedWritten = false;
        $signatureAttempted = false;
        $signatureWritten = false;

        if (
            $exitCode === 0
            && is_file($cacheFile)
            && $currentSignature !== null
            && ! $sourceChanged
        ) {
            $versionedAttempted = Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true);
            $trackedCacheFile = $versionedAttempted
                ? RouteCacheFiles::seedVersioned($cacheFile, $cachePath, $currentSignature)
                : $cacheFile;
            $versionedWritten = $trackedCacheFile !== null;

            if ($trackedCacheFile !== null) {
                $signatureAttempted = true;
                $signatureWritten = DeploymentCacheSignatures::write(
                    $cachePath.'/route-source.signature',
                    $currentSignature,
                );
            }

            if ($trackedCacheFile !== null && $signatureWritten) {
                RepairState::clear($cachePath, 'route');
                self::invalidateOpcache($cacheFile);
                self::invalidateOpcache($trackedCacheFile);
                $cleanedFiles = RouteCacheFiles::removeStale($cachePath, [$trackedCacheFile]);
                SuccessMarker::write(
                    $cachePath.'/route-cache-refresh.succeeded',
                    'route',
                    $trackedCacheFile,
                    $currentSignature,
                    $cleanedFiles,
                );

                return;
            }
        }

        $removed = self::removeCacheFile($cacheFile);
        @unlink($pendingPath);

        if ($sourceChanged && $currentSignature !== null) {
            RepairState::queue(
                $cachePath,
                'route',
                $currentSignature,
                'source_changed_during_repair',
                'Route sources changed while cache repair was running.',
                'Retry route cache generation after the current response.',
            );

            return;
        }

        $reason = match (true) {
            ! $removed => 'stale_cache_removal_failed',
            $currentSignature === null => 'source_signature_unavailable',
            $versionedAttempted && ! $versionedWritten => 'versioned_route_cache_write_failed',
            $signatureAttempted && ! $signatureWritten => 'signature_write_failed',
            default => 'auto_repair_failed',
        };

        FailureMarker::write(
            RepairState::failedPath($cachePath, 'route'),
            'route',
            $reason,
            match ($reason) {
                'stale_cache_removal_failed' => 'Route cache repair failed and the resulting cache file could not be removed safely.',
                'source_signature_unavailable' => 'Route cache repair could not verify the current deployment source signature.',
                'versioned_route_cache_write_failed' => 'The route cache was generated, but the signature-based route cache file could not be stored safely.',
                'signature_write_failed' => 'The route cache was generated, but its deployment source signature could not be stored safely.',
                default => 'Laravel route cache could not be generated safely. The application continues with uncached routing.',
            },
            'Make the route definitions cacheable or continue using normal uncached routing.',
            $currentSignature ?? $pendingSignature,
        );
    }

    private static function removeCacheFile(string $path): bool
    {
        if (! is_file($path)) {
            return true;
        }

        @unlink($path);
        self::invalidateOpcache($path);
        clearstatcache(true, $path);

        return ! is_file($path);
    }

    private static function readSignature(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }

    private static function invalidateOpcache(string $path): void
    {
        clearstatcache(true, $path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
