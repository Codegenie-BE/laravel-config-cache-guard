<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use Illuminate\Container\Container;

final class RouteCacheFiles
{
    public static function current(string $cachePath): string
    {
        $container = Container::getInstance();

        if ($container->bound('app')) {
            $path = $container->make('app')->getCachedRoutesPath();

            if ($path !== '' && self::isInCachePath($path, $cachePath)) {
                return $path;
            }
        }

        $configuredPath = Environment::string('APP_ROUTES_CACHE');

        if ($configuredPath !== null) {
            return self::resolveConfiguredPath($configuredPath, $cachePath);
        }

        return self::defaultPath($cachePath);
    }

    /**
     * Resolve the active route cache without enumerating the cache directory.
     */
    public static function existingFast(string $cachePath, ?string $storedSignature = null): ?string
    {
        $configuredPath = Environment::string('APP_ROUTES_CACHE');

        if ($configuredPath !== null) {
            $resolved = self::resolveConfiguredPath($configuredPath, $cachePath);

            if (is_file($resolved)) {
                return $resolved;
            }

            if (! self::isManagedPath($configuredPath)) {
                return null;
            }
        }

        if (is_string($storedSignature) && preg_match('/^[a-f0-9]{16,128}$/i', $storedSignature) === 1) {
            $versioned = self::forSignature($cachePath, strtolower($storedSignature));

            if (is_file($versioned)) {
                return $versioned;
            }
        }

        $defaultPath = self::defaultPath($cachePath);

        return is_file($defaultPath) ? $defaultPath : null;
    }

    public static function forSignature(string $cachePath, string $signature): string
    {
        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-'.strtolower($signature).'.php';
    }

    public static function useVersioned(string $basePath, string $cachePath, string $signature): string
    {
        $path = self::forSignature($cachePath, $signature);
        $relative = self::relativeToBase($basePath, $path);
        Environment::set('APP_ROUTES_CACHE', $relative);

        return $path;
    }

    public static function clearManagedPath(): void
    {
        $configuredPath = Environment::string('APP_ROUTES_CACHE');

        if ($configuredPath !== null && self::isManagedPath($configuredPath)) {
            Environment::set('APP_ROUTES_CACHE', null);
        }
    }

    /**
     * @return list<string>
     */
    public static function all(string $cachePath): array
    {
        $paths = glob(rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-*.php') ?: [];
        $currentPath = self::current($cachePath);

        if (is_file($currentPath)) {
            $paths[] = $currentPath;
        }

        return array_values(array_unique(array_filter($paths, 'is_string')));
    }

    /**
     * @param  list<string>  $keepPaths
     * @return list<string>
     */
    public static function stale(string $cachePath, array $keepPaths = []): array
    {
        $keep = [self::normalizePath(self::current($cachePath))];

        foreach ($keepPaths as $keepPath) {
            $keep[] = self::normalizePath($keepPath);
        }

        $keep = array_values(array_unique($keep));

        return array_values(array_filter(
            self::all($cachePath),
            static fn (string $path): bool => ! in_array(self::normalizePath($path), $keep, true)
        ));
    }

    /**
     * @param  list<string>  $keepPaths
     */
    public static function removeStale(string $cachePath, array $keepPaths = []): int
    {
        $removed = 0;

        foreach (self::stale($cachePath, $keepPaths) as $path) {
            $existed = is_file($path);

            @unlink($path);
            self::invalidateOpcache($path);

            if ($existed && ! is_file($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    public static function seedVersioned(string $cacheFile, string $cachePath, string $signature): ?string
    {
        $configuredPath = Environment::string('APP_ROUTES_CACHE');

        if ($configuredPath !== null && ! self::isManagedPath($configuredPath)) {
            return $cacheFile;
        }

        $versionedPath = self::forSignature($cachePath, $signature);

        if (self::normalizePath($cacheFile) === self::normalizePath($versionedPath)) {
            return $cacheFile;
        }

        return AtomicFile::copy($cacheFile, $versionedPath) ? $versionedPath : null;
    }

    public static function isManagedPath(string $path): bool
    {
        return preg_match(
            '#^(?:bootstrap|\.laravel)/cache/routes-[a-f0-9]{16,128}\.php$#i',
            str_replace('\\', '/', $path),
        ) === 1;
    }

    private static function defaultPath(string $cachePath): string
    {
        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-v7.php';
    }

    private static function resolveConfiguredPath(string $path, string $cachePath): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return dirname($cachePath, 2).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function relativeToBase(string $basePath, string $path): string
    {
        $base = self::normalizePath($basePath);
        $normalized = self::normalizePath($path);
        $prefix = $base.'/';

        return str_starts_with($normalized, $prefix)
            ? substr($normalized, strlen($prefix))
            : $normalized;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function isInCachePath(string $path, string $cachePath): bool
    {
        return self::normalizePath(dirname($path)) === self::normalizePath($cachePath);
    }

    private static function invalidateOpcache(string $path): void
    {
        clearstatcache(true, $path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
