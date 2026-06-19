<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class SuccessMarker
{
    public static function write(
        string $path,
        string $target,
        string $cacheFile,
        ?string $sourceSignature,
        int $cleanedStaleFiles = 0
    ): void {
        $contents = implode(PHP_EOL, array_filter([
            'Codegenie Laravel Config Cache Guard success',
            'generated_at='.gmdate('c'),
            'target='.$target,
            'cache_file='.$cacheFile,
            $sourceSignature === null ? null : 'source_signature='.$sourceSignature,
            'cleaned_stale_files='.$cleanedStaleFiles,
            'note=No .env values, secrets, tokens or command output are stored in this file.',
            '',
        ], static fn (?string $line): bool => $line !== null));

        @file_put_contents($path, $contents, LOCK_EX);
    }

    public static function summary(string $path): ?string
    {
        $fields = self::fields($path);

        if ($fields === []) {
            return null;
        }

        $parts = array_filter([
            $fields['generated_at'] ?? null,
            isset($fields['cache_file']) ? 'cache: '.$fields['cache_file'] : null,
        ]);

        return implode(' - ', $parts) ?: 'present';
    }

    public static function staleCleanupSummary(string $path): ?string
    {
        $fields = self::fields($path);

        if ($fields === []) {
            return null;
        }

        $cleanedFiles = $fields['cleaned_stale_files'] ?? '0';
        $generatedAt = $fields['generated_at'] ?? 'unknown time';

        return $cleanedFiles.' files at '.$generatedAt;
    }

    /**
     * @return array<string, string>
     */
    private static function fields(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($contents)) {
            return [];
        }

        $fields = [];

        foreach ($contents as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $fields[$key] = $value;
        }

        return $fields;
    }
}
