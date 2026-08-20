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
    private static ?string $algorithm = null;

    public static function mode(?string $mode = null): string
    {
        $mode = strtolower($mode ?? Environment::string('CONFIG_CACHE_GUARD_SIGNATURE_MODE') ?? 'metadata');

        return $mode === 'content' ? 'content' : 'metadata';
    }

    public static function config(string $basePath, ?string $mode = null): ?string
    {
        $snapshot = self::snapshot($basePath, $mode);

        return $snapshot['config_signature'] ?? null;
    }

    public static function routes(string $basePath, ?string $mode = null): ?string
    {
        $snapshot = self::snapshot($basePath, $mode);

        return $snapshot['route_signature'] ?? null;
    }

    /**
     * Calculate config and route signatures from one filesystem traversal.
     *
     * @return array{config: ?string, routes: ?string}
     */
    public static function both(string $basePath, ?string $mode = null): array
    {
        $snapshot = self::snapshot($basePath, $mode);

        return [
            'config' => $snapshot['config_signature'] ?? null,
            'routes' => $snapshot['route_signature'] ?? null,
        ];
    }

    /**
     * Build a manifest-ready snapshot. Only relative source paths, metadata or
     * one-way content hashes are returned; source contents and raw base paths
     * are never persisted.
     *
     * @return array{
     *     version: int,
     *     mode: string,
     *     algorithm: string,
     *     runtime_identity: string,
     *     app_env: ?string,
     *     bootstrap_path: string,
     *     config_signature: ?string,
     *     route_signature: ?string,
     *     files: array<string, string>,
     *     directories: array<string, string>,
     *     absent_files: list<string>,
     *     absent_directories: list<string>
     * }|null
     */
    public static function snapshot(string $basePath, ?string $mode = null): ?array
    {
        $basePath = rtrim($basePath, '/\\');
        $mode = self::mode($mode);
        $configTree = self::scanPhpTree($basePath, $basePath.'/config', $mode);
        $routeTree = self::scanPhpTree($basePath, $basePath.'/routes', $mode);
        $providerTree = self::scanPhpTree($basePath, $basePath.'/app/Providers', $mode);

        if ($configTree === null || $routeTree === null || $providerTree === null) {
            return null;
        }

        $bootstrapPath = self::bootstrapPath($basePath);
        $bootstrapRelative = self::relativePath($basePath, $bootstrapPath);
        $appEnv = Environment::string('APP_ENV');
        $sharedFiles = [];
        $absentFiles = [];
        $optionalFiles = [
            $basePath.'/composer.json',
            $basePath.'/composer.lock',
            $bootstrapPath.'/app.php',
            $bootstrapPath.'/providers.php',
            $basePath.'/.env',
        ];

        if ($appEnv !== null) {
            $optionalFiles[] = $basePath.'/.env.'.$appEnv;
        }

        foreach ($optionalFiles as $file) {
            $relativePath = self::relativePath($basePath, $file);

            if (! is_file($file)) {
                $absentFiles[] = $relativePath;

                continue;
            }

            $fingerprint = self::fingerprintFile($file, $mode);

            if ($fingerprint === null) {
                return null;
            }

            $sharedFiles[$relativePath] = $fingerprint;
        }

        $configFiles = array_merge($configTree['files'], $providerTree['files'], $sharedFiles);
        $routeFiles = array_merge(
            $configTree['files'],
            $routeTree['files'],
            $providerTree['files'],
            $sharedFiles,
        );
        $allFiles = array_merge($configFiles, $routeTree['files']);
        $directories = array_merge(
            $configTree['directories'],
            $routeTree['directories'],
            $providerTree['directories'],
        );
        $absentDirectories = array_values(array_unique(array_merge(
            $configTree['absent_directories'],
            $routeTree['absent_directories'],
            $providerTree['absent_directories'],
        )));

        ksort($allFiles, SORT_STRING);
        ksort($directories, SORT_STRING);
        sort($absentFiles, SORT_STRING);
        sort($absentDirectories, SORT_STRING);
        $runtimeIdentity = self::runtimeIdentity($basePath);

        return [
            'version' => 1,
            'mode' => $mode,
            'algorithm' => self::algorithm(),
            'runtime_identity' => $runtimeIdentity,
            'app_env' => $appEnv,
            'bootstrap_path' => $bootstrapRelative,
            'config_signature' => is_dir($basePath.'/config')
                ? self::aggregate($configFiles, $runtimeIdentity)
                : null,
            'route_signature' => self::aggregate($routeFiles),
            'files' => $allFiles,
            'directories' => $directories,
            'absent_files' => array_values(array_unique($absentFiles)),
            'absent_directories' => $absentDirectories,
        ];
    }

    public static function write(string $path, ?string $signature): bool
    {
        return $signature !== null && AtomicFile::write($path, $signature);
    }

    public static function runtimeIdentity(string $basePath): string
    {
        $normalizedBasePath = self::normalizePath($basePath);
        $realBasePath = realpath($basePath);
        $normalizedRealBasePath = is_string($realBasePath)
            ? self::normalizePath($realBasePath)
            : $normalizedBasePath;

        return hash(self::algorithm(), implode("\n", [
            'os='.PHP_OS_FAMILY,
            'base='.$normalizedBasePath,
            'real='.$normalizedRealBasePath,
        ]));
    }

    public static function algorithm(): string
    {
        return self::$algorithm ??= in_array('xxh128', hash_algos(), true)
            ? 'xxh128'
            : 'sha256';
    }

    public static function fingerprintFile(string $path, ?string $mode = null): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        if (self::mode($mode) === 'content') {
            $hash = @hash_file(self::algorithm(), $path);

            return is_string($hash) ? $hash : null;
        }

        $stats = @stat($path);

        if (! is_array($stats)) {
            return null;
        }

        return implode('|', [
            (string) $stats['mtime'],
            (string) $stats['ctime'],
            (string) $stats['size'],
            (string) $stats['ino'],
        ]);
    }

    public static function fingerprintDirectory(string $path): ?string
    {
        if (! is_dir($path)) {
            return null;
        }

        $stats = @stat($path);
        $entries = @scandir($path);

        if (! is_array($stats) || ! is_array($entries)) {
            return null;
        }

        $relevantEntries = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = rtrim($path, '/\\').DIRECTORY_SEPARATOR.$entry;

            if (is_dir($entryPath)) {
                $relevantEntries[] = 'd:'.$entry;

                continue;
            }

            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'php') {
                $relevantEntries[] = 'f:'.$entry;
            }
        }

        sort($relevantEntries, SORT_STRING);
        $context = hash_init(self::algorithm());

        foreach ($relevantEntries as $entry) {
            hash_update($context, $entry."\n");
        }

        return implode('|', [
            (string) $stats['mtime'],
            (string) $stats['ctime'],
            (string) $stats['size'],
            (string) $stats['ino'],
            hash_final($context),
        ]);
    }

    public static function bootstrapRelativePath(string $basePath): string
    {
        return self::relativePath(rtrim($basePath, '/\\'), self::bootstrapPath($basePath));
    }

    private static function bootstrapPath(string $basePath): string
    {
        $basePath = rtrim($basePath, '/\\');
        $laravelPath = $basePath.'/.laravel';

        return is_dir($laravelPath) ? $laravelPath : $basePath.'/bootstrap';
    }

    /**
     * @return array{
     *     files: array<string, string>,
     *     directories: array<string, string>,
     *     absent_directories: list<string>
     * }|null
     */
    private static function scanPhpTree(string $basePath, string $directory, string $mode): ?array
    {
        $relativeDirectory = self::relativePath($basePath, $directory);

        if (! is_dir($directory)) {
            return [
                'files' => [],
                'directories' => [],
                'absent_directories' => [$relativeDirectory],
            ];
        }

        $files = [];
        $directories = [];
        $rootFingerprint = self::fingerprintDirectory($directory);

        if ($rootFingerprint === null) {
            return null;
        }

        $directories[$relativeDirectory] = $rootFingerprint;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo) {
                    continue;
                }

                $path = $entry->getPathname();
                $relativePath = self::relativePath($basePath, $path);

                if ($entry->isDir()) {
                    $fingerprint = self::fingerprintDirectory($path);

                    if ($fingerprint === null) {
                        return null;
                    }

                    $directories[$relativePath] = $fingerprint;

                    continue;
                }

                if (! $entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                    continue;
                }

                $fingerprint = self::fingerprintFile($path, $mode);

                if ($fingerprint === null) {
                    return null;
                }

                $files[$relativePath] = $fingerprint;
            }
        } catch (Throwable) {
            return null;
        }

        ksort($files, SORT_STRING);
        ksort($directories, SORT_STRING);

        return [
            'files' => $files,
            'directories' => $directories,
            'absent_directories' => [],
        ];
    }

    /**
     * @param  array<string, string>  $entries
     */
    private static function aggregate(array $entries, ?string $runtimeIdentity = null): ?string
    {
        if ($entries === []) {
            return null;
        }

        ksort($entries, SORT_STRING);
        $context = hash_init(self::algorithm());

        if ($runtimeIdentity !== null) {
            hash_update($context, 'runtime|'.$runtimeIdentity."\n");
        }

        foreach ($entries as $relativePath => $fingerprint) {
            hash_update($context, $relativePath.'|'.$fingerprint."\n");
        }

        return hash_final($context);
    }

    private static function relativePath(string $basePath, string $path): string
    {
        $basePath = self::normalizePath($basePath);
        $path = self::normalizePath($path);
        $prefix = $basePath.'/';

        return str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix))
            : $path;
    }

    private static function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
