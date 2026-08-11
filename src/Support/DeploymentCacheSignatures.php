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
    public static function config(string $basePath): ?string
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

        return self::build($basePath, $files);
    }

    public static function routes(string $basePath): ?string
    {
        $files = array_merge(
            self::collectPhpFiles($basePath.'/config'),
            self::collectPhpFiles($basePath.'/routes'),
            self::deploymentSourceFiles($basePath),
            self::envFiles($basePath)
        );

        return self::build($basePath, $files);
    }

    public static function write(string $path, ?string $signature): bool
    {
        if ($signature === null) {
            return false;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            return false;
        }

        $temporaryPath = @tempnam($directory, '.config-cache-guard-');

        if ($temporaryPath === false) {
            return false;
        }

        try {
            $written = @file_put_contents($temporaryPath, $signature, LOCK_EX);

            if ($written !== strlen($signature)) {
                return false;
            }

            if (! @rename($temporaryPath, $path)) {
                if (is_file($path) && ! @unlink($path)) {
                    return false;
                }

                if (! @rename($temporaryPath, $path)) {
                    return false;
                }
            }

            clearstatcache(true, $path);
            $storedSignature = @file_get_contents($path);

            return is_string($storedSignature) && hash_equals($signature, $storedSignature);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private static function bootstrapPath(string $basePath): string
    {
        $basePath = rtrim($basePath, '/\\');
        $laravelPath = $basePath.'/.laravel';

        return is_dir($laravelPath) ? $laravelPath : $basePath.'/bootstrap';
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
    private static function build(string $basePath, array $files): ?string
    {
        $files = array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => is_file($file)
        )));

        if ($files === []) {
            return null;
        }

        sort($files, SORT_STRING);

        $parts = [];

        foreach ($files as $file) {
            $stats = @stat($file);

            if (! is_array($stats)) {
                return null;
            }

            $parts[] = implode('|', [
                str_starts_with($file, $basePath.'/') ? str_replace($basePath.'/', '', $file) : $file,
                (string) $stats['mtime'],
                (string) $stats['ctime'],
                (string) $stats['size'],
                (string) $stats['ino'],
            ]);
        }

        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        return hash($algorithm, implode("\n", $parts));
    }
}
