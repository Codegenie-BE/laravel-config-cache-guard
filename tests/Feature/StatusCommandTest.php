<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\SuccessMarker;

it('can run the status command', function (): void {
    $this->artisan('config-cache-guard:status')
        ->assertExitCode(0);
});

it('shows successful repair metadata in the status command', function (): void {
    $cachePath = base_path('bootstrap/cache');
    $createdCachePath = false;

    if (! is_dir($cachePath)) {
        mkdir($cachePath, 0777, true);
        $createdCachePath = true;
    }

    try {
        SuccessMarker::write(
            $cachePath.'/config-cache-refresh.succeeded',
            'config',
            $cachePath.'/config.php',
            'config-signature'
        );
        SuccessMarker::write(
            $cachePath.'/route-cache-refresh.succeeded',
            'route',
            $cachePath.'/routes-current.php',
            'route-signature',
            2
        );

        $this->artisan('config-cache-guard:status')
            ->expectsOutputToContain('config last successful repair')
            ->expectsOutputToContain('route last successful repair')
            ->expectsOutputToContain('route stale cleanup last result')
            ->assertExitCode(0);

        expect(SuccessMarker::staleCleanupSummary($cachePath.'/route-cache-refresh.succeeded'))->toContain('2 files');
    } finally {
        @unlink($cachePath.'/config-cache-refresh.succeeded');
        @unlink($cachePath.'/route-cache-refresh.succeeded');

        if ($createdCachePath) {
            @rmdir($cachePath);
        }
    }
});
