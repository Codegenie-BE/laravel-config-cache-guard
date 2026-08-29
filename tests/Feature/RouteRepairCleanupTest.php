<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Codegenie\ConfigCacheGuard\Support\FailureMarker;

function makeRouteRepairCleanupProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-route-cleanup-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);
    mkdir($basePath.'/storage/framework', 0777, true);
    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");

    return $basePath;
}

function removeRouteRepairCleanupProject(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($path);
}

function setRouteRepairCleanupEnvironment(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    } else {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $key = '__codegenie_config_cache_guard_external_environment';
    $captured = $GLOBALS[$key] ?? [];
    $captured = is_array($captured) ? $captured : [];
    $captured[$name] = $value;
    $GLOBALS[$key] = $captured;
}

beforeEach(function (): void {
    setRouteRepairCleanupEnvironment('CONFIG_CACHE_GUARD_ENABLED', 'true');
    setRouteRepairCleanupEnvironment('CONFIG_CACHE_GUARD_AUTO_REPAIR', 'true');
    setRouteRepairCleanupEnvironment('CONFIG_CACHE_GUARD_CONFIG', 'false');
    setRouteRepairCleanupEnvironment('CONFIG_CACHE_GUARD_ROUTES', 'true');
    setRouteRepairCleanupEnvironment('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', 'true');
    setRouteRepairCleanupEnvironment('APP_ROUTES_CACHE', null);
});

afterEach(function (): void {
    foreach ([
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE',
        'APP_ROUTES_CACHE',
    ] as $name) {
        setRouteRepairCleanupEnvironment($name, null);
    }
});

it('removes a generated signature route cache when its signature cannot be stored', function (): void {
    $basePath = makeRouteRepairCleanupProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        mkdir($cachePath.'/route-source.signature');

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use ($cachePath): int {
                expect($command)->toBe('route:cache');
                file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');

                return 0;
            },
        );

        $routeCaches = glob($cachePath.'/routes-*.php') ?: [];
        $failure = FailureMarker::summary($cachePath.'/route-cache-refresh.failed');

        expect($routeCaches)->toBe([])
            ->and(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse()
            ->and($failure)->not->toBeNull()
            ->and($failure)->toContain('signature_write_failed');
    } finally {
        removeRouteRepairCleanupProject($basePath);
    }
});
