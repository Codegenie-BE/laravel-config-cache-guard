<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class RepairState
{
    public static function queue(
        string $cachePath,
        string $target,
        string $sourceSignature,
        string $reason,
        string $message,
        string $action,
    ): bool {
        $sourceSignature = strtolower($sourceSignature);
        $failedPath = self::failedPath($cachePath, $target);
        $pendingPath = self::pendingPath($cachePath, $target);

        if (self::retrySuppressed($cachePath, $target, $sourceSignature)) {
            return true;
        }

        if (FailureMarker::sourceSignature($pendingPath) === $sourceSignature) {
            return true;
        }

        if (is_file($failedPath)) {
            @unlink($failedPath);
        }

        return AtomicFile::write(
            $pendingPath,
            implode(PHP_EOL, [
                'Codegenie Laravel Config Cache Guard pending auto repair',
                'generated_at='.gmdate('c'),
                'target='.$target,
                'reason='.$reason,
                'message='.$message,
                'action='.$action,
                'source_signature='.$sourceSignature,
                'note=No .env values, secrets, tokens or command output are stored in this file.',
                '',
            ]),
        );
    }

    public static function retrySuppressed(string $cachePath, string $target, string $sourceSignature): bool
    {
        $failedPath = self::failedPath($cachePath, $target);

        if (FailureMarker::sourceSignature($failedPath) !== strtolower($sourceSignature)) {
            return false;
        }

        return FailureMarker::isRecent($failedPath, self::failureCooldownSeconds());
    }

    public static function clear(string $cachePath, string $target): void
    {
        foreach ([self::pendingPath($cachePath, $target), self::failedPath($cachePath, $target)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function pendingPath(string $cachePath, string $target): string
    {
        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.$target.'-cache-refresh.pending';
    }

    public static function failedPath(string $cachePath, string $target): string
    {
        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.$target.'-cache-refresh.failed';
    }

    public static function hasPending(string $cachePath): bool
    {
        return is_file(self::pendingPath($cachePath, 'config'))
            || is_file(self::pendingPath($cachePath, 'route'));
    }

    private static function failureCooldownSeconds(): int
    {
        $value = Environment::string('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN');

        if ($value === null || preg_match('/^\d+$/D', $value) !== 1) {
            return 60;
        }

        $seconds = (int) $value;

        return $seconds >= 1 && $seconds <= 86_400 ? $seconds : 60;
    }
}
