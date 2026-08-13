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

            if (! @rename($temporaryPath, $path)) {
                if (is_file($path) && ! @unlink($path)) {
                    return false;
                }

                if (! @rename($temporaryPath, $path)) {
                    return false;
                }
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
}
