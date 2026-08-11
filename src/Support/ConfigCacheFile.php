<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class ConfigCacheFile
{
    public static function current(string $basePath, string $cachePath): string
    {
        $configuredPath = Environment::string('APP_CONFIG_CACHE');

        if ($configuredPath !== null) {
            return self::resolveConfiguredPath($configuredPath, $basePath);
        }

        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'config.php';
    }

    private static function resolveConfiguredPath(string $path, string $basePath): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
