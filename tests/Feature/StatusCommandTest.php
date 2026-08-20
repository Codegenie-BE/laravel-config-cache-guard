<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\ConfigCacheFile;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\SuccessMarker;

/**
 * @param  array<string, string|null>  $overrides
 * @param  callable(): void  $callback
 */
function withStatusGuardEnvironment(array $overrides, callable $callback): void
{
    $key = '__codegenie_config_cache_guard_external_environment';
    $originalSnapshotExists = array_key_exists($key, $GLOBALS);
    $originalSnapshot = $GLOBALS[$key] ?? null;
    $capturedEnvironment = is_array($originalSnapshot) ? $originalSnapshot : [];
    $originalEnvironment = [];

    foreach ($overrides as $name => $value) {
        $current = getenv($name);
        $originalEnvironment[$name] = is_string($current) ? $current : null;

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $capturedEnvironment[$name] = $value;
    }

    $GLOBALS[$key] = $capturedEnvironment;

    try {
        $callback();
    } finally {
        foreach ($originalEnvironment as $name => $value) {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        if ($originalSnapshotExists) {
            $GLOBALS[$key] = $originalSnapshot;
        } else {
            unset($GLOBALS[$key]);
        }
    }
}

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

it('returns a failure in strict mode while repair state remains and clears it explicitly', function (): void {
    $cachePath = base_path('bootstrap/cache');
    $pendingPath = $cachePath.'/config-cache-refresh.pending';
    $createdCachePath = false;

    if (! is_dir($cachePath)) {
        mkdir($cachePath, 0777, true);
        $createdCachePath = true;
    }

    try {
        file_put_contents($pendingPath, "reason=process_control_unavailable\nmessage=Repair is pending.\n");

        $this->artisan('config-cache-guard:status --strict')
            ->expectsOutputToContain('repair state is still pending or failed')
            ->assertExitCode(1);

        $this->artisan('config-cache-guard:status --clear-failures')
            ->expectsOutputToContain('failure and pending markers were cleared')
            ->assertExitCode(0);

        expect(is_file($pendingPath))->toBeFalse();
    } finally {
        @unlink($pendingPath);

        if ($createdCachePath) {
            @rmdir($cachePath);
        }
    }
});

it('rejects a stale config deployment signature in strict mode', function (): void {
    $cachePath = app()->bootstrapPath('cache');
    $configCachePath = ConfigCacheFile::current(base_path(), $cachePath);
    $signaturePath = $cachePath.'/config-source.signature';
    $hadCache = is_file($configCachePath);
    $originalCache = $hadCache ? @file_get_contents($configCachePath) : null;
    $hadSignature = is_file($signaturePath);
    $originalSignature = $hadSignature ? @file_get_contents($signaturePath) : null;

    try {
        file_put_contents($configCachePath, "<?php return ['app' => ['name' => 'Codegenie']];\n");
        file_put_contents($signaturePath, str_repeat('0', 64));

        $this->artisan('config-cache-guard:status --strict')
            ->expectsOutputToContain('config signature state')
            ->expectsOutputToContain('stale')
            ->assertExitCode(1);
    } finally {
        if ($hadCache && is_string($originalCache)) {
            file_put_contents($configCachePath, $originalCache);
        } else {
            @unlink($configCachePath);
        }

        if ($hadSignature && is_string($originalSignature)) {
            file_put_contents($signaturePath, $originalSignature);
        } else {
            @unlink($signaturePath);
        }
    }
});

it('accepts a current config deployment signature in strict mode', function (): void {
    $cachePath = app()->bootstrapPath('cache');
    $configCachePath = ConfigCacheFile::current(base_path(), $cachePath);
    $signaturePath = $cachePath.'/config-source.signature';
    $hadCache = is_file($configCachePath);
    $originalCache = $hadCache ? @file_get_contents($configCachePath) : null;
    $hadSignature = is_file($signaturePath);
    $originalSignature = $hadSignature ? @file_get_contents($signaturePath) : null;

    try {
        file_put_contents($configCachePath, "<?php return ['app' => ['name' => 'Codegenie']];\n");
        $signature = DeploymentCacheSignatures::config(base_path());

        expect($signature)->not->toBeNull();
        file_put_contents($signaturePath, (string) $signature);

        $this->artisan('config-cache-guard:status --strict')
            ->expectsOutputToContain('config signature state')
            ->expectsOutputToContain('current')
            ->assertExitCode(0);
    } finally {
        if ($hadCache && is_string($originalCache)) {
            file_put_contents($configCachePath, $originalCache);
        } else {
            @unlink($configCachePath);
        }

        if ($hadSignature && is_string($originalSignature)) {
            file_put_contents($signaturePath, $originalSignature);
        } else {
            @unlink($signaturePath);
        }
    }
});

it('requires the current signature route cache path in strict mode', function (): void {
    $cachePath = app()->bootstrapPath('cache');
    $routeCachePath = $cachePath.'/routes-v7.php';
    $signaturePath = $cachePath.'/route-source.signature';
    $hadRouteCache = is_file($routeCachePath);
    $originalRouteCache = $hadRouteCache ? @file_get_contents($routeCachePath) : null;
    $hadSignature = is_file($signaturePath);
    $originalSignature = $hadSignature ? @file_get_contents($signaturePath) : null;
    $versionedPath = null;
    $hadVersionedCache = false;
    $originalVersionedCache = null;

    try {
        $routeContents = "<?php return ['compiled' => true];\n";
        file_put_contents($routeCachePath, $routeContents);
        $signature = DeploymentCacheSignatures::routes(base_path());

        expect($signature)->not->toBeNull();
        file_put_contents($signaturePath, (string) $signature);
        $versionedPath = $cachePath.'/routes-'.$signature.'.php';
        $hadVersionedCache = is_file($versionedPath);
        $originalVersionedCache = $hadVersionedCache ? @file_get_contents($versionedPath) : null;
        @unlink($versionedPath);

        $this->artisan('config-cache-guard:status --strict')
            ->expectsOutputToContain('route signature state')
            ->expectsOutputToContain('missing current cache')
            ->assertExitCode(1);

        file_put_contents($versionedPath, $routeContents);

        $this->artisan('config-cache-guard:status --strict')
            ->expectsOutputToContain('route signature state')
            ->expectsOutputToContain('current')
            ->assertExitCode(0);
    } finally {
        if ($hadRouteCache && is_string($originalRouteCache)) {
            file_put_contents($routeCachePath, $originalRouteCache);
        } else {
            @unlink($routeCachePath);
        }

        if ($hadSignature && is_string($originalSignature)) {
            file_put_contents($signaturePath, $originalSignature);
        } else {
            @unlink($signaturePath);
        }

        if (is_string($versionedPath)) {
            if ($hadVersionedCache && is_string($originalVersionedCache)) {
                file_put_contents($versionedPath, $originalVersionedCache);
            } else {
                @unlink($versionedPath);
            }
        }
    }
});

it('reports missing cache creation as disabled when auto repair is disabled', function (): void {
    $cachePath = app()->bootstrapPath('cache');
    $configCachePath = ConfigCacheFile::current(base_path(), $cachePath);
    $hadConfigCache = is_file($configCachePath);
    $originalConfigCache = $hadConfigCache ? @file_get_contents($configCachePath) : null;

    try {
        @unlink($configCachePath);

        withStatusGuardEnvironment([
            'CONFIG_CACHE_GUARD_ENABLED' => 'true',
            'CONFIG_CACHE_GUARD_CONFIG' => 'true',
            'CONFIG_CACHE_GUARD_ROUTES' => 'false',
            'CONFIG_CACHE_GUARD_AUTO_REPAIR' => 'false',
        ], function (): void {
            $this->artisan('config-cache-guard:status --strict')
                ->expectsOutputToContain('Create config cache when missing')
                ->expectsOutputToContain('automatic creation is disabled through CONFIG_CACHE_GUARD_AUTO_REPAIR')
                ->assertExitCode(1);
        });
    } finally {
        if ($hadConfigCache && is_string($originalConfigCache)) {
            file_put_contents($configCachePath, $originalConfigCache);
        } else {
            @unlink($configCachePath);
        }
    }
});

it('ignores missing caches for guard targets that are explicitly disabled', function (): void {
    $cachePath = app()->bootstrapPath('cache');
    $routeCachePath = $cachePath.'/routes-v7.php';
    $signaturePath = $cachePath.'/route-source.signature';
    $hadRouteCache = is_file($routeCachePath);
    $originalRouteCache = $hadRouteCache ? @file_get_contents($routeCachePath) : null;
    $hadSignature = is_file($signaturePath);
    $originalSignature = $hadSignature ? @file_get_contents($signaturePath) : null;
    $versionedPath = null;
    $hadVersionedCache = false;
    $originalVersionedCache = null;

    try {
        $routeContents = "<?php return ['compiled' => true];\n";
        file_put_contents($routeCachePath, $routeContents);
        $signature = DeploymentCacheSignatures::routes(base_path());

        expect($signature)->not->toBeNull();
        file_put_contents($signaturePath, (string) $signature);
        $versionedPath = $cachePath.'/routes-'.$signature.'.php';
        $hadVersionedCache = is_file($versionedPath);
        $originalVersionedCache = $hadVersionedCache ? @file_get_contents($versionedPath) : null;
        file_put_contents($versionedPath, $routeContents);

        withStatusGuardEnvironment([
            'CONFIG_CACHE_GUARD_ENABLED' => 'true',
            'CONFIG_CACHE_GUARD_CONFIG' => 'false',
            'CONFIG_CACHE_GUARD_ROUTES' => 'true',
            'CONFIG_CACHE_GUARD_AUTO_REPAIR' => 'true',
        ], function (): void {
            $this->artisan('config-cache-guard:status --strict')
                ->expectsOutputToContain('Automatic route cache protection is available')
                ->assertExitCode(0);
        });
    } finally {
        if ($hadRouteCache && is_string($originalRouteCache)) {
            file_put_contents($routeCachePath, $originalRouteCache);
        } else {
            @unlink($routeCachePath);
        }

        if ($hadSignature && is_string($originalSignature)) {
            file_put_contents($signaturePath, $originalSignature);
        } else {
            @unlink($signaturePath);
        }

        if (is_string($versionedPath)) {
            if ($hadVersionedCache && is_string($originalVersionedCache)) {
                file_put_contents($versionedPath, $originalVersionedCache);
            } else {
                @unlink($versionedPath);
            }
        }
    }
});
