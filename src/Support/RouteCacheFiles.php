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

        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-v7.php';
    }

    /**
     * @return list<string>
     */
    public static function all(string $cachePath): array
    {
        $paths = glob(rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-*.php') ?: [];

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * @return list<string>
     */
    public static function stale(string $cachePath): array
    {
        $currentPath = self::normalizePath(self::current($cachePath));

        return array_values(array_filter(
            self::all($cachePath),
            static fn (string $path): bool => self::normalizePath($path) !== $currentPath
        ));
    }

    public static function removeStale(string $cachePath): void
    {
        foreach (self::stale($cachePath) as $path) {
            @unlink($path);
            self::invalidateOpcache($path);
        }
    }

    private static function resolveConfiguredPath(string $path, string $cachePath): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return dirname($cachePath, 2).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
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
