<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use JsonException;

final class DeploymentSourceManifest
{
    private const FILE_NAME = 'deployment-source.manifest.json';

    /**
     * Return current deployment signatures. A valid manifest avoids recursive
     * source discovery and only verifies already-known files and directories.
     *
     * @return array{config: ?string, routes: ?string, reused: bool}
     */
    public static function signatures(string $basePath, string $cachePath, ?string $mode = null): array
    {
        $mode = DeploymentCacheSignatures::mode($mode);
        $manifest = self::read($cachePath);

        if ($manifest !== null && self::isCurrent($basePath, $manifest, $mode)) {
            return [
                'config' => self::nullableString($manifest['config_signature'] ?? null),
                'routes' => self::nullableString($manifest['route_signature'] ?? null),
                'reused' => true,
            ];
        }

        return self::refresh($basePath, $cachePath, $mode);
    }

    /**
     * Force one complete source traversal and replace the manifest atomically.
     *
     * @return array{config: ?string, routes: ?string, reused: bool}
     */
    public static function refresh(string $basePath, string $cachePath, ?string $mode = null): array
    {
        $snapshot = DeploymentCacheSignatures::snapshot($basePath, $mode);

        if ($snapshot === null) {
            return ['config' => null, 'routes' => null, 'reused' => false];
        }

        self::write($cachePath, $snapshot);

        return [
            'config' => $snapshot['config_signature'],
            'routes' => $snapshot['route_signature'],
            'reused' => false,
        ];
    }

    public static function path(string $cachePath): string
    {
        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.self::FILE_NAME;
    }

    public static function remove(string $cachePath): void
    {
        $path = self::path($cachePath);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function isCurrent(string $basePath, array $manifest, string $mode): bool
    {
        if (($manifest['version'] ?? null) !== 1) {
            return false;
        }

        if (($manifest['mode'] ?? null) !== $mode) {
            return false;
        }

        if (($manifest['algorithm'] ?? null) !== DeploymentCacheSignatures::algorithm()) {
            return false;
        }

        if (($manifest['runtime_identity'] ?? null) !== DeploymentCacheSignatures::runtimeIdentity($basePath)) {
            return false;
        }

        if (($manifest['app_env'] ?? null) !== Environment::string('APP_ENV')) {
            return false;
        }

        if (($manifest['bootstrap_path'] ?? null) !== DeploymentCacheSignatures::bootstrapRelativePath($basePath)) {
            return false;
        }

        $files = $manifest['files'] ?? null;
        $directories = $manifest['directories'] ?? null;
        $absentFiles = $manifest['absent_files'] ?? null;
        $absentDirectories = $manifest['absent_directories'] ?? null;

        if (! is_array($files) || ! is_array($directories) || ! is_array($absentFiles) || ! is_array($absentDirectories)) {
            return false;
        }

        foreach ($files as $relativePath => $fingerprint) {
            if (! is_string($relativePath) || ! is_string($fingerprint)) {
                return false;
            }

            $path = self::absolutePath($basePath, $relativePath);

            if (DeploymentCacheSignatures::fingerprintFile($path, $mode) !== $fingerprint) {
                return false;
            }
        }

        foreach ($directories as $relativePath => $fingerprint) {
            if (! is_string($relativePath) || ! is_string($fingerprint)) {
                return false;
            }

            $path = self::absolutePath($basePath, $relativePath);

            if (DeploymentCacheSignatures::fingerprintDirectory($path) !== $fingerprint) {
                return false;
            }
        }

        foreach ($absentFiles as $relativePath) {
            if (! is_string($relativePath) || is_file(self::absolutePath($basePath, $relativePath))) {
                return false;
            }
        }

        foreach ($absentDirectories as $relativePath) {
            if (! is_string($relativePath) || is_dir(self::absolutePath($basePath, $relativePath))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function write(string $cachePath, array $manifest): bool
    {
        try {
            $json = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return false;
        }

        return AtomicFile::write(self::path($cachePath), $json.PHP_EOL);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(string $cachePath): ?array
    {
        $path = self::path($cachePath);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function absolutePath(string $basePath, string $relativePath): string
    {
        if (
            str_starts_with($relativePath, '/')
            || str_starts_with($relativePath, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $relativePath) === 1
        ) {
            return $relativePath;
        }

        return rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
