<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\FileLock;

it('never waits when the deployment repair lock is already held', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'config-cache-guard-lock-');

    expect($path)->toBeString();
    $first = fopen($path, 'c');
    $second = fopen($path, 'c');

    expect($first)->toBeResource()
        ->and($second)->toBeResource();

    try {
        expect(FileLock::acquire($first))->toBeTrue();
        $startedAt = microtime(true);

        expect(FileLock::acquire($second))->toBeFalse()
            ->and(microtime(true) - $startedAt)->toBeLessThan(0.25);

        FileLock::release($first);
        expect(FileLock::acquire($second))->toBeTrue();
    } finally {
        FileLock::release($first);
        FileLock::release($second);
        fclose($first);
        fclose($second);
        @unlink($path);
    }
});
