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

        $files = self::collectPhpFiles($configDir);

        foreach (self::envFiles($basePath) as $envFile) {
            $files[] = $envFile;
        }

        return self::build($basePath, $files);
    }

    public static function routes(string $basePath): ?string
    {
        $files = self::collectPhpFiles($basePath.'/routes');
        $packagePath = dirname(__DIR__, 2);

        foreach ([
            $basePath.'/bootstrap/app.php',
            $basePath.'/app/Providers/RouteServiceProvider.php',
            $packagePath.'/routes/repair.php',
        ] as $routeSourceFile) {
            if (is_file($routeSourceFile)) {
                $files[] = $routeSourceFile;
            }
        }

        foreach (self::envFiles($basePath) as $envFile) {
            $files[] = $envFile;
        }

        return self::build($basePath, $files, [
            'repair_enabled='.(Environment::flag('CONFIG_CACHE_GUARD_REPAIR_ENABLED', true) ? 'yes' : 'no'),
            'repair_token_configured='.(Environment::string('CONFIG_CACHE_GUARD_REPAIR_TOKEN') !== null ? 'yes' : 'no'),
            'repair_allow_get='.(Environment::flag('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET', false) ? 'yes' : 'no'),
        ]);
    }

    public static function write(string $path, ?string $signature): void
    {
        if ($signature === null) {
            return;
        }

        @file_put_contents($path, $signature, LOCK_EX);
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
     * @param  list<string>  $values
     */
    private static function build(string $basePath, array $files, array $values = []): ?string
    {
        $files = array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => is_file($file)
        )));

        if ($files === [] && $values === []) {
            return null;
        }

        sort($files, SORT_STRING);
        sort($values, SORT_STRING);

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

        foreach ($values as $value) {
            $parts[] = 'value|'.$value;
        }

        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        return hash($algorithm, implode("\n", $parts));
    }
}
