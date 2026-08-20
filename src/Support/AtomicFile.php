<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class AtomicFile
{
    public static function write(string $path, string $contents): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            return false;
        }

        $temporaryPath = @tempnam($directory, '.config-cache-guard-');

        if ($temporaryPath === false) {
            return false;
        }

        try {
            $written = @file_put_contents($temporaryPath, $contents, LOCK_EX);

            if ($written !== strlen($contents)) {
                return false;
            }

            if (! self::replace($temporaryPath, $path)) {
                return false;
            }

            clearstatcache(true, $path);
            $storedContents = @file_get_contents($path);

            return is_string($storedContents) && hash_equals($contents, $storedContents);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public static function copy(string $source, string $path): bool
    {
        if (! is_file($source)) {
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
            if (! @copy($source, $temporaryPath)) {
                return false;
            }

            $sourceSize = @filesize($source);
            $temporarySize = @filesize($temporaryPath);

            if (! is_int($sourceSize) || ! is_int($temporarySize) || $sourceSize !== $temporarySize) {
                return false;
            }

            if (! self::replace($temporaryPath, $path)) {
                return false;
            }

            clearstatcache(true, $path);

            return is_file($path) && @filesize($path) === $sourceSize;
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private static function replace(string $temporaryPath, string $path): bool
    {
        if (@rename($temporaryPath, $path)) {
            return true;
        }

        if (is_file($path) && ! @unlink($path)) {
            return false;
        }

        return @rename($temporaryPath, $path);
    }
}
