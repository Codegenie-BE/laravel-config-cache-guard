<?php

declare(strict_types=1);

function makeGuardRuntimeProject(): array
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-'.bin2hex(random_bytes(8));
    $packagePath = $basePath.'/vendor/codegenie-be/laravel-config-cache-guard';

    mkdir($packagePath.'/bootstrap', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);

    copy(dirname(__DIR__, 2).'/bootstrap/guard.php', $packagePath.'/bootstrap/guard.php');

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php\n\nreturn ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'ok');\n");

    return [$basePath, $packagePath.'/bootstrap/guard.php'];
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
    putenv('CONFIG_CACHE_GUARD_ENABLED');
    putenv('CONFIG_CACHE_GUARD_CONFIG');
    putenv('CONFIG_CACHE_GUARD_ROUTES');
    putenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN');
    putenv('CONFIG_CACHE_GUARD_PHP_BINARY');
    putenv('CONFIG_CACHE_GUARD_FAIL_HARD');
    putenv('CONFIG_CACHE_GUARD_REPAIR_ENABLED');
    putenv('CONFIG_CACHE_GUARD_REPAIR_TOKEN');
    putenv('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET');
    putenv('PHP_CLI_BINARY');
}

it('does nothing when the guard is disabled', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachedConfigPath = $basePath.'/bootstrap/cache/config.php';
        file_put_contents($cachedConfigPath, '<?php return [];');

        putenv('CONFIG_CACHE_GUARD_ENABLED=false');

        include $guardPath;

        expect(is_file($cachedConfigPath))->toBeTrue();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('removes stale cached config during the failure cooldown', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachedConfigPath = $basePath.'/bootstrap/cache/config.php';
        $failedPath = $basePath.'/bootstrap/cache/config-cache-refresh.failed';

        file_put_contents($cachedConfigPath, '<?php return [];');
        touch($failedPath, time());

        putenv('CONFIG_CACHE_GUARD_ENABLED');
        putenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60');

        include $guardPath;

        expect(is_file($cachedConfigPath))->toBeFalse();
        expect((string) file_get_contents($failedPath))->toContain('reason=recent_failure_cooldown');
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('removes stale cached routes during the route failure cooldown', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachedRoutePath = $basePath.'/bootstrap/cache/routes-v7.php';
        $failedPath = $basePath.'/bootstrap/cache/route-cache-refresh.failed';

        file_put_contents($cachedRoutePath, '<?php return [];');
        touch($failedPath, time());

        putenv('CONFIG_CACHE_GUARD_CONFIG=false');
        putenv('CONFIG_CACHE_GUARD_ROUTES=true');
        putenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60');

        include $guardPath;

        expect(is_file($cachedRoutePath))->toBeFalse();
        expect((string) file_get_contents($failedPath))->toContain('reason=recent_failure_cooldown');
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('does not remove stale cached routes when the route guard is disabled', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachedRoutePath = $basePath.'/bootstrap/cache/routes-v7.php';
        $failedPath = $basePath.'/bootstrap/cache/route-cache-refresh.failed';

        file_put_contents($cachedRoutePath, '<?php return [];');
        touch($failedPath, time());

        putenv('CONFIG_CACHE_GUARD_CONFIG=false');
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        putenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60');

        include $guardPath;

        expect(is_file($cachedRoutePath))->toBeTrue();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('does not create route cache when no cached route file exists', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        putenv('CONFIG_CACHE_GUARD_CONFIG=false');
        putenv('CONFIG_CACHE_GUARD_ROUTES=true');

        include $guardPath;

        expect(glob($basePath.'/bootstrap/cache/routes-*.php') ?: [])->toBe([]);
        expect(is_file($basePath.'/bootstrap/cache/route-cache-refresh.failed'))->toBeFalse();
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});

it('writes a safe diagnostic marker when automatic config rebuild cannot run', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachedConfigPath = $basePath.'/bootstrap/cache/config.php';
        $failedPath = $basePath.'/bootstrap/cache/config-cache-refresh.failed';

        file_put_contents($cachedConfigPath, '<?php return [];');

        putenv('CONFIG_CACHE_GUARD_ENABLED=true');
        putenv('CONFIG_CACHE_GUARD_CONFIG=true');
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        putenv('CONFIG_CACHE_GUARD_PHP_BINARY=/definitely/missing/php');

        include $guardPath;

        $contents = (string) file_get_contents($failedPath);

        expect(is_file($cachedConfigPath))->toBeFalse();
        expect($contents)->toContain('reason=artisan_command_failed');
        expect($contents)->toContain('repair_endpoint=/_config-cache-guard/repair');
        expect($contents)->toContain('No .env values, secrets, tokens or command output');
        expect($contents)->not->toContain('APP_NAME=Codegenie');
    } finally {
        resetGuardRuntimeEnvironment();
        removeGuardRuntimeProject($basePath);
    }
});
