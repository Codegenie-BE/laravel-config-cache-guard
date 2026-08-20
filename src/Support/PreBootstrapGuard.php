<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use RuntimeException;

/**
 * Minimal pre-bootstrap safety layer.
 *
 * It never waits for a lock, starts a child process or runs Artisan. Its only
 * job is to prevent known-stale deployment cache from being loaded and to
 * queue repair for Laravel's after-response phase.
 */
final class PreBootstrapGuard
{
    private const LOADED_KEY = '__codegenie_config_cache_guard_loaded';

    private const ENVIRONMENT_NAMES = [
        'APP_CONFIG_CACHE',
        'APP_ENV',
        'APP_ROUTES_CACHE',
        'CONFIG_CACHE_GUARD_ALLOW_CLI',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_FAIL_HARD',
        'CONFIG_CACHE_GUARD_FAILURE_COOLDOWN',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_SIGNATURE_MODE',
        'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE',
    ];

    private function __construct(
        private readonly string $basePath,
        private readonly string $cachePath,
        private readonly bool $autoRepair,
        private readonly bool $failHard,
        private readonly bool $versionedRouteCache,
        private readonly string $signatureMode,
    ) {}

    public static function run(?string $composerAutoloadPath): void
    {
        Environment::capture(self::ENVIRONMENT_NAMES);

        if (
            in_array(PHP_SAPI, ['cli', 'phpdbg'], true)
            && ! Environment::flag('CONFIG_CACHE_GUARD_ALLOW_CLI', false)
        ) {
            return;
        }

        if (($GLOBALS[self::LOADED_KEY] ?? false) === true) {
            return;
        }

        $GLOBALS[self::LOADED_KEY] = true;

        if (! Environment::flag('CONFIG_CACHE_GUARD_ENABLED')) {
            return;
        }

        $basePath = self::resolveBasePath($composerAutoloadPath);

        if ($basePath === null) {
            return;
        }

        $bootstrapPath = is_dir($basePath.'/.laravel')
            ? $basePath.'/.laravel'
            : $basePath.'/bootstrap';
        $cachePath = $bootstrapPath.'/cache';

        if (! is_dir($cachePath)) {
            return;
        }

        (new self(
            $basePath,
            $cachePath,
            Environment::flag('CONFIG_CACHE_GUARD_AUTO_REPAIR', true),
            Environment::flag('CONFIG_CACHE_GUARD_FAIL_HARD', false),
            Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true),
            DeploymentCacheSignatures::mode(),
        ))->handle();
    }

    private function handle(): void
    {
        $configEnabled = Environment::flag('CONFIG_CACHE_GUARD_CONFIG');
        $routeEnabled = Environment::flag('CONFIG_CACHE_GUARD_ROUTES');

        if (! $configEnabled && ! $routeEnabled) {
            return;
        }

        if (! $routeEnabled || ! $this->versionedRouteCache) {
            RouteCacheFiles::clearManagedPath();
        }

        $configCachePath = ConfigCacheFile::current($this->basePath, $this->cachePath);
        $configCacheExists = $configEnabled && is_file($configCachePath);
        $storedRouteSignature = self::readSignature($this->cachePath.'/route-source.signature');
        $routeCachePath = $routeEnabled
            ? RouteCacheFiles::existingFast($this->cachePath, $storedRouteSignature)
            : null;

        // A completely uncached application is optimized after the response.
        // Avoid all source traversal on its visitor-facing pre-bootstrap path.
        if (! $configCacheExists && $routeCachePath === null) {
            return;
        }

        $state = DeploymentSourceManifest::signatures(
            $this->basePath,
            $this->cachePath,
            $this->signatureMode,
        );

        if ($configCacheExists) {
            $this->guardConfig($configCachePath, $state['config']);
        }

        if ($routeCachePath !== null) {
            $this->guardRoutes($routeCachePath, $state['routes']);
        }
    }

    private function guardConfig(string $cachePath, ?string $currentSignature): void
    {
        $signaturePath = $this->cachePath.'/config-source.signature';
        $storedSignature = self::readSignature($signaturePath);

        if ($currentSignature !== null && $storedSignature === $currentSignature) {
            RepairState::clear($this->cachePath, 'config');

            return;
        }

        $this->removeCacheOrStop($cachePath, 'config');

        if ($currentSignature === null) {
            FailureMarker::write(
                RepairState::failedPath($this->cachePath, 'config'),
                'config',
                'source_signature_unavailable',
                'The config cache was removed because deployment source state could not be verified safely.',
                'Check that config, provider, bootstrap, Composer and environment source files are readable.',
            );

            return;
        }

        $this->queueTarget(
            'config',
            $currentSignature,
            'stale_cache',
            'Stale Laravel config cache was removed before Laravel booted.',
        );
    }

    private function guardRoutes(string $cachePath, ?string $currentSignature): void
    {
        $storedSignature = self::readSignature($this->cachePath.'/route-source.signature');
        $configuredPath = Environment::string('APP_ROUTES_CACHE');
        $customPath = $configuredPath !== null && ! RouteCacheFiles::isManagedPath($configuredPath);

        if ($currentSignature === null) {
            $this->removeCacheOrStop($cachePath, 'route');
            FailureMarker::write(
                RepairState::failedPath($this->cachePath, 'route'),
                'route',
                'source_signature_unavailable',
                'The route cache was bypassed because deployment source state could not be verified safely.',
                'Check that route, config, provider, bootstrap, Composer and environment source files are readable.',
            );

            return;
        }

        if ($this->versionedRouteCache && ! $customPath) {
            $expectedPath = RouteCacheFiles::useVersioned(
                $this->basePath,
                $this->cachePath,
                $currentSignature,
            );

            if ($storedSignature === $currentSignature && is_file($expectedPath)) {
                RepairState::clear($this->cachePath, 'route');

                return;
            }

            if (is_file($expectedPath)) {
                $this->removeCacheOrStop($expectedPath, 'route');
            }

            $this->queueTarget(
                'route',
                $currentSignature,
                'stale_cache',
                'Stale Laravel route cache was bypassed before Laravel booted.',
            );

            return;
        }

        if ($storedSignature === $currentSignature && is_file($cachePath)) {
            RepairState::clear($this->cachePath, 'route');

            return;
        }

        $this->removeCacheOrStop($cachePath, 'route');
        $this->queueTarget(
            'route',
            $currentSignature,
            'stale_cache',
            'Stale Laravel route cache was removed before Laravel booted.',
        );
    }

    private function queueTarget(
        string $target,
        string $signature,
        string $reason,
        string $message,
    ): void {
        if (! $this->autoRepair) {
            FailureMarker::write(
                RepairState::failedPath($this->cachePath, $target),
                $target,
                'auto_repair_disabled',
                $message.' Automatic repair is disabled.',
                'Rebuild the cache manually or enable the default automatic repair behavior.',
                $signature,
            );

            if ($this->failHard) {
                $this->showFailure($target, 'auto_repair_disabled', $message);
            }

            return;
        }

        $queued = RepairState::queue(
            $this->cachePath,
            $target,
            $signature,
            $reason,
            $message,
            'Laravel will rebuild this cache through Artisan::call() after the current HTTP response is sent.',
        );

        if ($queued) {
            return;
        }

        FailureMarker::write(
            RepairState::failedPath($this->cachePath, $target),
            $target,
            'pending_marker_write_failed',
            'The stale cache was bypassed, but the pending repair marker could not be stored.',
            'Fix ownership and write permissions for the active Laravel bootstrap cache directory.',
            $signature,
        );

        if ($this->failHard) {
            $this->showFailure($target, 'pending_marker_write_failed', $message);
        }
    }

    private function removeCacheOrStop(string $path, string $target): void
    {
        if (! is_file($path)) {
            return;
        }

        @unlink($path);
        self::invalidateOpcache($path);
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return;
        }

        FailureMarker::write(
            RepairState::failedPath($this->cachePath, $target),
            $target,
            'stale_cache_removal_failed',
            'Laravel deployment cache is stale, but the guard could not remove the unsafe cache file.',
            'Fix ownership and write permissions for the configured cache file before retrying the request.',
        );

        $this->showFailure(
            $target,
            'stale_cache_removal_failed',
            'Laravel deployment cache is stale, but it could not be removed safely.',
        );
    }

    private function showFailure(string $target, string $reason, string $message): never
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            throw new RuntimeException($message);
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Laravel deployment cache unavailable</title></head><body>';
        echo '<h1>Laravel deployment cache unavailable</h1>';
        echo '<p><strong>Target:</strong> '.htmlspecialchars($target, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p><strong>Reason:</strong> '.htmlspecialchars($reason, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>';
        echo '<p>No .env values, secrets, tokens or command output are shown.</p>';
        echo '</body></html>';

        exit;
    }

    private static function resolveBasePath(?string $composerAutoloadPath): ?string
    {
        if ($composerAutoloadPath !== null && is_file($composerAutoloadPath)) {
            $candidate = dirname(dirname($composerAutoloadPath));

            if (self::looksLikeLaravelApplication($candidate)) {
                return rtrim($candidate, '/\\');
            }
        }

        foreach ([
            dirname(__DIR__, 5),
            self::serverBasePath(),
            getcwd() ?: null,
        ] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if (basename($candidate) === 'public') {
                $candidate = dirname($candidate);
            }

            if (self::looksLikeLaravelApplication($candidate)) {
                return rtrim($candidate, '/\\');
            }
        }

        return null;
    }

    private static function serverBasePath(): ?string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (is_string($script) && $script !== '') {
            return dirname(dirname($script));
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

        return is_string($documentRoot) && $documentRoot !== ''
            ? dirname($documentRoot)
            : null;
    }

    private static function looksLikeLaravelApplication(string $path): bool
    {
        return (is_dir($path.'/bootstrap/cache') || is_dir($path.'/.laravel/cache'))
            && (is_file($path.'/artisan') || is_dir($path.'/config') || is_dir($path.'/routes'));
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
