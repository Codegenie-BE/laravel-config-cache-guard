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

it('warns when a custom config cache path requires pre-bootstrap environment configuration', function (): void {
    $key = '__codegenie_config_cache_guard_external_environment';
    $originalSnapshotExists = array_key_exists($key, $GLOBALS);
    $originalSnapshot = $GLOBALS[$key] ?? null;
    $capturedEnvironment = is_array($originalSnapshot) ? $originalSnapshot : [];

    try {
        putenv('APP_CONFIG_CACHE=storage/framework/custom-config.php');
        $_ENV['APP_CONFIG_CACHE'] = 'storage/framework/custom-config.php';
        $_SERVER['APP_CONFIG_CACHE'] = 'storage/framework/custom-config.php';
        $capturedEnvironment['APP_CONFIG_CACHE'] = 'storage/framework/custom-config.php';
        $GLOBALS[$key] = $capturedEnvironment;

        $this->artisan('config-cache-guard:status')
            ->expectsOutputToContain('a custom config cache path is active')
            ->assertExitCode(0);
    } finally {
        putenv('APP_CONFIG_CACHE');
        unset($_ENV['APP_CONFIG_CACHE'], $_SERVER['APP_CONFIG_CACHE']);

        if ($originalSnapshotExists) {
            $GLOBALS[$key] = $originalSnapshot;
        } else {
            unset($GLOBALS[$key]);
        }
    }
});
