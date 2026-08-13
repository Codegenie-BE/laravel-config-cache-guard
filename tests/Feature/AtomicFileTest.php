<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\AtomicFile;

it('atomically creates and replaces a verified file', function (): void {
    $directory = sys_get_temp_dir().'/config-cache-guard-atomic-'.bin2hex(random_bytes(8));
    $path = $directory.'/marker';

    mkdir($directory, 0777, true);

    try {
        expect(AtomicFile::write($path, 'first'))->toBeTrue()
            ->and((string) file_get_contents($path))->toBe('first')
            ->and(AtomicFile::write($path, 'second'))->toBeTrue()
            ->and((string) file_get_contents($path))->toBe('second');
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});

it('fails safely when the destination directory does not exist', function (): void {
    $path = sys_get_temp_dir().'/config-cache-guard-missing-'.bin2hex(random_bytes(8)).'/marker';

    expect(AtomicFile::write($path, 'contents'))->toBeFalse()
        ->and(is_file($path))->toBeFalse();
});
