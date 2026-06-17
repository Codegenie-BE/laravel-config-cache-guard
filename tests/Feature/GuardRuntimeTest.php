<?php

declare(strict_types=1);

function makeGuardRuntimeProject(): array
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-'.bin2hex(random_bytes(8));
    $packagePath = $basePath.'/vendor/codegenie-be/laravel-config-cache-guard';

    mkdir($packagePath.'/bootstrap', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);

    copy(dirname(__DIR__, 2).'/bootstrap/guard.php', $packagePath.'/bootstrap/guard.php');

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php\n\nreturn ['name' => 'Codegenie'];\n");

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

it('does nothing when the guard is disabled', function (): void {
    [$basePath, $guardPath] = makeGuardRuntimeProject();

    try {
        $cachedConfigPath = $basePath.'/bootstrap/cache/config.php';
        file_put_contents($cachedConfigPath, '<?php return [];');

        putenv('CONFIG_CACHE_GUARD_ENABLED=false');

        include $guardPath;

        expect(is_file($cachedConfigPath))->toBeTrue();
    } finally {
        putenv('CONFIG_CACHE_GUARD_ENABLED');
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
    } finally {
        putenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN');
        removeGuardRuntimeProject($basePath);
    }
});
