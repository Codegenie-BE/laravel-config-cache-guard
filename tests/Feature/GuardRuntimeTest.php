<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\DeploymentSourceManifest;
use Codegenie\ConfigCacheGuard\Support\Environment;
use Codegenie\ConfigCacheGuard\Support\RouteCacheFiles;

function makeGuardRuntimeProject(): array
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/vendor', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);

    file_put_contents($basePath.'/vendor/autoload.php', "<?php\n");
    file_put_contents($basePath.'/artisan', "#!/usr/bin/env php\n<?php\n");
    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");

    putenv('CONFIG_CACHE_GUARD_ALLOW_CLI=true');
    $_ENV['CONFIG_CACHE_GUARD_ALLOW_CLI'] = 'true';
    $_SERVER['CONFIG_CACHE_GUARD_ALLOW_CLI'] = 'true';
    $GLOBALS['_composer_autoload_path'] = $basePath.'/vendor/autoload.php';

    return [$basePath, dirname(__DIR__, 2).'/bootstrap/guard.php'];
}

function removeGuardRuntimeProject(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($path);
}

function resetGuardRuntimeEnvironment(): void
{
    foreach ([
        'APP_CONFIG_CACHE',
        'APP_ENV',
        'APP_ROUTES_CACHE',
        'CONFIG_CACHE_GUARD_ALLOW_CLI',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_FAIL_HARD',
        'CONFIG_CACHE_GUARD_FAILURE_COOLDOWN',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_SIGNATURE_MODE',
        'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE',
    ] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    unset(
        $GLOBALS['__codegenie_config_cache_guard_loaded'],
        $GLOBALS['__codegenie_config_cache_guard_external_environment'],
        $GLOBALS['_composer_autoload_path'],
    );
}

it('does nothing when the guard is disabled', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachePath = $basePath.'/bootstrap/cache/config.php';
        file_put_contents($cachePath, '<?php return [];');
        putenv('CONFIG_CACHE_GUARD_ENABLED=false');
        $_ENV['CONFIG_CACHE_GUARD_ENABLED'] = 'false';

        include $guardPath;

        expect(is_file($cachePath))->toBeTrue();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('keeps current config and route caches on the manifest fast path', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        $signatures = DeploymentCacheSignatures::both($basePath);
        expect($signatures['config'])->not->toBeNull()
            ->and($signatures['routes'])->not->toBeNull();

        file_put_contents($cachePath.'/config.php', '<?php return [];');
        file_put_contents($cachePath.'/config-source.signature', (string) $signatures['config']);
        file_put_contents($cachePath.'/route-source.signature', (string) $signatures['routes']);
        file_put_contents(RouteCacheFiles::forSignature($cachePath, (string) $signatures['routes']), '<?php return [];');

        include $guardPath;

        expect(is_file($cachePath.'/config.php'))->toBeTrue();
        expect(Environment::string('APP_ROUTES_CACHE'))
            ->toEndWith('routes-'.$signatures['routes'].'.php');
        expect(is_file(DeploymentSourceManifest::path($cachePath)))->toBeTrue();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('removes stale config immediately and queues deferred repair without running Artisan', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        file_put_contents($cachePath.'/config-source.signature', str_repeat('0', 32));
        file_put_contents($basePath.'/artisan', "#!/usr/bin/env php\n<?php sleep(5);\n");

        include $guardPath;

        expect(is_file($cachePath.'/config.php'))->toBeFalse();
        expect((string) file_get_contents($cachePath.'/config-cache-refresh.pending'))
            ->toContain('reason=stale_cache')
            ->toContain('source_signature=');
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('bypasses stale routes through the current versioned path', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');
        file_put_contents($cachePath.'/route-source.signature', str_repeat('0', 32));
        putenv('CONFIG_CACHE_GUARD_CONFIG=false');
        $_ENV['CONFIG_CACHE_GUARD_CONFIG'] = 'false';

        $currentSignature = DeploymentCacheSignatures::routes($basePath);
        expect($currentSignature)->not->toBeNull();

        include $guardPath;

        $managedPath = Environment::string('APP_ROUTES_CACHE');
        expect($managedPath)->toBeString()
            ->and($managedPath)->toEndWith('routes-'.$currentSignature.'.php');
        expect(is_file($basePath.'/'.$managedPath))->toBeFalse();
        expect(is_file($cachePath.'/routes-v7.php'))->toBeTrue();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeTrue();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('removes stale routes when versioned route cache is explicitly disabled', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');
        file_put_contents($cachePath.'/route-source.signature', str_repeat('0', 32));
        putenv('CONFIG_CACHE_GUARD_CONFIG=false');
        putenv('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE=false');
        $_ENV['CONFIG_CACHE_GUARD_CONFIG'] = 'false';
        $_ENV['CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE'] = 'false';

        include $guardPath;

        expect(is_file($cachePath.'/routes-v7.php'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeTrue();
        expect(Environment::string('APP_ROUTES_CACHE'))->toBeNull();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('removes a stale explicit custom route cache path', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $customPath = $basePath.'/storage/framework/custom-routes.php';

    try {
        mkdir(dirname($customPath), 0777, true);
        file_put_contents($customPath, '<?php return [];');
        file_put_contents($cachePath.'/route-source.signature', str_repeat('0', 32));
        putenv('APP_ROUTES_CACHE=storage/framework/custom-routes.php');
        putenv('CONFIG_CACHE_GUARD_CONFIG=false');
        $_ENV['APP_ROUTES_CACHE'] = 'storage/framework/custom-routes.php';
        $_ENV['CONFIG_CACHE_GUARD_CONFIG'] = 'false';

        include $guardPath;

        expect(is_file($customPath))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeTrue();
        expect(Environment::string('APP_ROUTES_CACHE'))->toBe('storage/framework/custom-routes.php');
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('guards a custom config cache path', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $customPath = $basePath.'/storage/framework/custom-config.php';

    try {
        mkdir(dirname($customPath), 0777, true);
        file_put_contents($customPath, '<?php return [];');
        file_put_contents($cachePath.'/config-source.signature', str_repeat('0', 32));
        putenv('APP_CONFIG_CACHE=storage/framework/custom-config.php');
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        $_ENV['APP_CONFIG_CACHE'] = 'storage/framework/custom-config.php';
        $_ENV['CONFIG_CACHE_GUARD_ROUTES'] = 'false';

        include $guardPath;

        expect(is_file($customPath))->toBeFalse();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeTrue();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('uses the Laravel 13 dot-laravel cache directory when present', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachePath = $basePath.'/.laravel/cache';
        mkdir($cachePath, 0777, true);
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        file_put_contents($cachePath.'/config-source.signature', str_repeat('0', 32));
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        $_ENV['CONFIG_CACHE_GUARD_ROUTES'] = 'false';

        include $guardPath;

        expect(is_file($cachePath.'/config.php'))->toBeFalse();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeTrue();
        expect(is_file($basePath.'/bootstrap/cache/config-cache-refresh.pending'))->toBeFalse();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('does not scan or queue in pre-bootstrap when both deployment caches are missing', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        include $guardPath;

        expect(is_file(DeploymentSourceManifest::path($cachePath)))->toBeFalse();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('detects same-size source rewrites in content mode', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $configPath = $basePath.'/config/app.php';

    try {
        $original = (string) file_get_contents($configPath);
        $changed = str_replace('Codegenie', 'Guardrail', $original);
        $mtime = filemtime($configPath);
        expect(strlen($changed))->toBe(strlen($original));

        putenv('CONFIG_CACHE_GUARD_SIGNATURE_MODE=content');
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        $_ENV['CONFIG_CACHE_GUARD_SIGNATURE_MODE'] = 'content';
        $_ENV['CONFIG_CACHE_GUARD_ROUTES'] = 'false';
        file_put_contents($cachePath.'/config-source.signature', (string) DeploymentCacheSignatures::config($basePath, 'content'));
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        file_put_contents($configPath, $changed);

        if (is_int($mtime)) {
            touch($configPath, $mtime);
        }

        clearstatcache(true, $configPath);
        include $guardPath;

        expect(is_file($cachePath.'/config.php'))->toBeFalse();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeTrue();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('stops safely when a stale config cache cannot be removed', function (): void {
    if (! is_file('/proc/self/status')) {
        expect(true)->toBeTrue();

        return;
    }

    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        putenv('APP_CONFIG_CACHE=/proc/self/status');
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        $_ENV['APP_CONFIG_CACHE'] = '/proc/self/status';
        $_ENV['CONFIG_CACHE_GUARD_ROUTES'] = 'false';

        expect(static fn () => include $guardPath)
            ->toThrow(RuntimeException::class, 'could not be removed safely');
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('keeps the Composer entrypoint minimal and free of synchronous repair machinery', function (): void {
    $guard = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap/guard.php');

    expect(strlen($guard))->toBeLessThan(1024)
        ->and($guard)->not->toContain('BoundedProcess')
        ->and($guard)->not->toContain('FileLock')
        ->and($guard)->not->toContain('proc_open')
        ->and($guard)->not->toContain('CONFIG_CACHE_GUARD_PROCESS_TIMEOUT')
        ->and($guard)->not->toContain('CONFIG_CACHE_GUARD_LOCK_TIMEOUT');
});
