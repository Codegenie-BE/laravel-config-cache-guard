<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class DeploymentCacheSignatures
{
    public static function mode(?string $mode = null): string
    {
        $mode = strtolower($mode ?? Environment::string('CONFIG_CACHE_GUARD_SIGNATURE_MODE') ?? 'metadata');

        return $mode === 'content' ? 'content' : 'metadata';
    }

    public static function config(string $basePath, ?string $mode = null): ?string
    {
        $configDir = $basePath.'/config';

        if (! is_dir($configDir)) {
            return null;
        }

        $files = array_merge(
            self::collectPhpFiles($configDir),
            self::deploymentSourceFiles($basePath),
            self::envFiles($basePath)
        );

        return self::build($basePath, $files, $mode, self::runtimeIdentity($basePath));
    }

    public static function routes(string $basePath, ?string $mode = null): ?string
    {
        $files = array_merge(
            self::collectPhpFiles($basePath.'/config'),
            self::collectPhpFiles($basePath.'/routes'),
            self::deploymentSourceFiles($basePath),
            self::envFiles($basePath)
        );

        return self::build($basePath, $files, $mode);
    }

    public static function write(string $path, ?string $signature): bool
    {
        return $signature !== null && AtomicFile::write($path, $signature);
    }

    private static function bootstrapPath(string $basePath): string
    {
        $basePath = rtrim($basePath, '/\\');
        $laravelPath = $basePath.'/.laravel';

        return is_dir($laravelPath) ? $laravelPath : $basePath.'/bootstrap';
    }

    /**
     * Bind cached configuration to the runtime location that produced it.
     * Laravel config values may contain absolute application or storage paths,
     * so a cache built at another path must not be treated as portable.
     */
    private static function runtimeIdentity(string $basePath): string
    {
        $normalizedBasePath = self::normalizePath($basePath);
        $realBasePath = realpath($basePath);
        $normalizedRealBasePath = is_string($realBasePath)
            ? self::normalizePath($realBasePath)
            : $normalizedBasePath;
        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        return hash($algorithm, implode("\n", [
            'os='.PHP_OS_FAMILY,
            'base='.$normalizedBasePath,
            'real='.$normalizedRealBasePath,
        ]));
    }

    private static function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    /**
     * @return list<string>
     */
    private static function deploymentSourceFiles(string $basePath): array
    {
        $files = self::collectPhpFiles($basePath.'/app/Providers');
        $bootstrapPath = self::bootstrapPath($basePath);

        foreach ([
            $basePath.'/composer.json',
            $basePath.'/composer.lock',
            $bootstrapPath.'/app.php',
            $bootstrapPath.'/providers.php',
        ] as $sourceFile) {
            if (is_file($sourceFile)) {
                $files[] = $sourceFile;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function envFiles(string $basePath): array
    {
        $files = [];
        $envPath = $basePath.'/.env';

        if (is_file($envPath)) {
            $files[] = $envPath;
        }

        $externalAppEnv = Environment::string('APP_ENV');

        if ($externalAppEnv !== null) {
            $environmentEnvPath = $basePath.'/.env.'.$externalAppEnv;

            if (is_file($environmentEnvPath)) {
                $files[] = $environmentEnvPath;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function collectPhpFiles(string $directory): array
    {
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
    }

    /**
     * @param  list<string>  $files
     */
    private static function build(string $basePath, array $files, ?string $mode, ?string $runtimeIdentity = null): ?string
    {
        $files = array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => is_file($file)
        )));

        if ($files === []) {
            return null;
        }

        sort($files, SORT_STRING);

        $parts = $runtimeIdentity === null ? [] : ['runtime|'.$runtimeIdentity];
        $contentMode = self::mode($mode) === 'content';
        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        foreach ($files as $file) {
            $relativePath = str_starts_with($file, $basePath.'/')
                ? str_replace($basePath.'/', '', $file)
                : $file;

            if ($contentMode) {
                $contentHash = @hash_file($algorithm, $file);

                if (! is_string($contentHash)) {
                    return null;
                }

                $parts[] = $relativePath.'|'.$contentHash;

                continue;
            }

            $stats = @stat($file);

            if (! is_array($stats)) {
                return null;
            }

            $parts[] = implode('|', [
                $relativePath,
                (string) $stats['mtime'],
                (string) $stats['ctime'],
                (string) $stats['size'],
                (string) $stats['ino'],
            ]);
        }

        return hash($algorithm, implode("\n", $parts));
    }
}
