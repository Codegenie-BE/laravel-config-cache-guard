<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\BoundedProcess;
use Codegenie\ConfigCacheGuard\Support\FileLock;

it('runs a successful process without invoking a shell', function (): void {
    expect(BoundedProcess::run([
        PHP_BINARY,
        '-r',
        'exit(0);',
    ], dirname(__DIR__, 2), 5))->toBeTrue();
});

it('reports a failed process', function (): void {
    expect(BoundedProcess::run([
        PHP_BINARY,
        '-r',
        'exit(7);',
    ], dirname(__DIR__, 2), 5))->toBeFalse();
});

it('terminates a process that exceeds its timeout', function (): void {
    $startedAt = microtime(true);
    $succeeded = BoundedProcess::run([
        PHP_BINARY,
        '-r',
        'sleep(5);',
    ], dirname(__DIR__, 2), 1);

    expect($succeeded)->toBeFalse()
        ->and(microtime(true) - $startedAt)->toBeLessThan(3.0);
});

it('bounds lock acquisition and succeeds after release', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'config-cache-guard-lock-');

    expect($path)->toBeString();

    $first = fopen($path, 'c');
    $second = fopen($path, 'c');

    expect($first)->toBeResource()
        ->and($second)->toBeResource();

    try {
        expect(FileLock::acquire($first))->toBeTrue();

        $startedAt = microtime(true);

        expect(FileLock::acquire($second, 75))->toBeFalse()
            ->and(microtime(true) - $startedAt)->toBeLessThan(0.5);

        FileLock::release($first);

        expect(FileLock::acquire($second, 75))->toBeTrue();
    } finally {
        FileLock::release($first);
        FileLock::release($second);
        fclose($first);
        fclose($second);
        @unlink($path);
    }
});
