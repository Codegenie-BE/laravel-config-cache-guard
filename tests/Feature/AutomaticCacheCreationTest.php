<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\FailureMarker;

function makeAutomaticCacheProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-auto-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);

    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");

    return $basePath;
}

function removeAutomaticCacheProject(string $path): void
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

function clearAutomaticCacheGuardOverrides(): void
{
    $key = '__codegenie_config_cache_guard_external_environment';
    $captured = $GLOBALS[$key] ?? [];
    $captured = is_array($captured) ? $captured : [];

    foreach ([
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE',
    ] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        $captured[$name] = null;
    }

    $GLOBALS[$key] = $captured;
}

beforeEach(function (): void {
    clearAutomaticCacheGuardOverrides();
});

afterEach(function (): void {
    clearAutomaticCacheGuardOverrides();
});

it('queues missing config and route caches without package environment variables', function (): void {
    $basePath = makeAutomaticCacheProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);

        $configPending = (string) file_get_contents($cachePath.'/config-cache-refresh.pending');
        $routePending = (string) file_get_contents($cachePath.'/route-cache-refresh.pending');

        expect($configPending)
            ->toContain('reason=cache_missing')
            ->toContain('source_signature=');
        expect($routePending)
            ->toContain('reason=cache_missing')
            ->toContain('source_signature=');
    } finally {
        removeAutomaticCacheProject($basePath);
    }
});

it('creates a missing route cache and seeds its versioned cache file', function (): void {
    $basePath = makeAutomaticCacheProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);

        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeTrue();

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use ($cachePath): int {
                if ($command === 'route:cache') {
                    file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');
                }

                return 0;
            }
        );

        $signature = DeploymentCacheSignatures::routes($basePath);

        expect($signature)->not->toBeNull();
        expect(is_file($cachePath.'/routes-v7.php'))->toBeTrue();
        expect(is_file($cachePath.'/routes-'.$signature.'.php'))->toBeTrue();
        expect((string) file_get_contents($cachePath.'/route-source.signature'))->toBe($signature);
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
    } finally {
        removeAutomaticCacheProject($basePath);
    }
});

it('does not repeatedly retry an uncacheable route signature until route sources change', function (): void {
    $basePath = makeAutomaticCacheProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $routePath = $basePath.'/routes/web.php';

    try {
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        $failedSignature = DeploymentCacheSignatures::routes($basePath);

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static fn (string $command): int => $command === 'route:cache' ? 1 : 0
        );

        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
        expect(FailureMarker::sourceSignature($cachePath.'/route-cache-refresh.failed'))
            ->toBe($failedSignature);

        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();

        file_put_contents($routePath, "<?php return ['changed' => true];\n");
        clearstatcache(true, $routePath);

        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);

        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeTrue();
        expect(is_file($cachePath.'/route-cache-refresh.failed'))->toBeFalse();
    } finally {
        removeAutomaticCacheProject($basePath);
    }
});
