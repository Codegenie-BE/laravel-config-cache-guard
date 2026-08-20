<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class FailureMarker
{
    public static function write(
        string $path,
        string $target,
        string $reason,
        string $message,
        string $action,
        ?string $sourceSignature = null,
    ): void {
        $contents = implode(PHP_EOL, array_filter([
            'Codegenie Laravel Config Cache Guard failure',
            'generated_at='.gmdate('c'),
            'target='.$target,
            'reason='.$reason,
            'message='.$message,
            'action='.$action,
            $sourceSignature === null ? null : 'source_signature='.strtolower($sourceSignature),
            'note=No .env values, secrets, tokens or command output are stored in this file.',
            '',
        ], static fn (?string $line): bool => $line !== null));

        AtomicFile::write($path, $contents);
    }

    public static function summary(string $path): ?string
    {
        $fields = self::fields($path);

        if ($fields === []) {
            return null;
        }

        return trim(implode(' - ', array_filter([
            $fields['reason'] ?? null,
            $fields['message'] ?? null,
        ]))) ?: 'present';
    }

    public static function sourceSignature(string $path): ?string
    {
        $signature = self::fields($path)['source_signature'] ?? null;

        return is_string($signature) && preg_match('/^[a-f0-9]{16,128}$/i', $signature) === 1
            ? strtolower($signature)
            : null;
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
