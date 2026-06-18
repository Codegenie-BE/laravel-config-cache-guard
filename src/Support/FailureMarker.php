<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class FailureMarker
{
    public static function write(string $path, string $target, string $reason, string $message, string $action): void
    {
        $contents = implode(PHP_EOL, [
            'Codegenie Laravel Config Cache Guard failure',
            'generated_at='.gmdate('c'),
            'target='.$target,
            'reason='.$reason,
            'message='.$message,
            'action='.$action,
            'repair_endpoint=/_config-cache-guard/repair',
            'note=No .env values, secrets, tokens or command output are stored in this file.',
            '',
        ]);

        @file_put_contents($path, $contents, LOCK_EX);
    }

    public static function summary(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($contents)) {
            return 'present';
        }

        $reason = null;
        $message = null;

        foreach ($contents as $line) {
            if (str_starts_with($line, 'reason=')) {
                $reason = substr($line, 7);
            }

            if (str_starts_with($line, 'message=')) {
                $message = substr($line, 8);
            }
        }

        return trim(implode(' - ', array_filter([$reason, $message]))) ?: 'present';
    }
}
