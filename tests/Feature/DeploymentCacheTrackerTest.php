<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheTracker;

function makeDeploymentCacheTrackerProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-tracker-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/app/Providers', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/composer.json', "{\"name\":\"codegenie/test\"}\n");
    file_put_contents($basePath.'/bootstrap/app.php', "<?php return 'tracker';\n");
    file_put_contents($basePath.'/bootstrap/providers.php', "<?php return [];\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");
    file_put_contents($basePath.'/app/Providers/AppServiceProvider.php', "<?php return 'provider';\n");

    return $basePath;
}

function removeDeploymentCacheTrackerProject(string $path): void
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

it('records a successful native config cache command and clears old repair state', function (): void {
    $basePath = makeDeploymentCacheTrackerProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config.php', "<?php return ['app' => ['name' => 'Codegenie']];\n");
        file_put_contents($cachePath.'/config-cache-refresh.pending', "reason=stale\n");
        file_put_contents($cachePath.'/config-cache-refresh.failed', "reason=stale\n");

        $recorded = DeploymentCacheTracker::recordSuccessfulCommand(
            'config:cache',
            0,
            $basePath,
            $cachePath
        );
        $currentSignature = DeploymentCacheSignatures::config($basePath);
        $storedSignature = @file_get_contents($cachePath.'/config-source.signature');

        expect($recorded)->toBeTrue()
            ->and($currentSignature)->not->toBeNull()
            ->and(is_string($storedSignature) ? trim($storedSignature) : null)->toBe($currentSignature)
            ->and(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse()
            ->and(is_file($cachePath.'/config-cache-refresh.failed'))->toBeFalse()
            ->and(is_file($cachePath.'/config-cache-refresh.succeeded'))->toBeTrue();
    } finally {
        removeDeploymentCacheTrackerProject($basePath);
    }
});

it('does not record a failed native cache command', function (): void {
    $basePath = makeDeploymentCacheTrackerProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config.php', "<?php return [];\n");
        file_put_contents($cachePath.'/config-source.signature', 'previous-signature');
        file_put_contents($cachePath.'/config-cache-refresh.pending', "reason=still-pending\n");

        expect(DeploymentCacheTracker::recordSuccessfulCommand(
            'config:cache',
            1,
            $basePath,
            $cachePath
        ))->toBeFalse()
            ->and((string) file_get_contents($cachePath.'/config-source.signature'))->toBe('previous-signature')
            ->and(is_file($cachePath.'/config-cache-refresh.pending'))->toBeTrue()
            ->and(is_file($cachePath.'/config-cache-refresh.succeeded'))->toBeFalse();
    } finally {
        removeDeploymentCacheTrackerProject($basePath);
    }
});

it('prepares the signature route cache after a successful native route cache command', function (): void {
    $basePath = makeDeploymentCacheTrackerProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $routeContents = "<?php return ['compiled' => true];\n";

    try {
        file_put_contents($cachePath.'/routes-v7.php', $routeContents);
        file_put_contents($cachePath.'/route-cache-refresh.pending', "reason=stale\n");
        file_put_contents($cachePath.'/route-cache-refresh.failed', "reason=stale\n");

        $recorded = DeploymentCacheTracker::recordSuccessfulCommand(
            'route:cache',
            0,
            $basePath,
            $cachePath
        );
        $currentSignature = DeploymentCacheSignatures::routes($basePath);
        $storedSignature = @file_get_contents($cachePath.'/route-source.signature');
        $versionedPath = $cachePath.'/routes-'.$currentSignature.'.php';

        expect($recorded)->toBeTrue()
            ->and($currentSignature)->not->toBeNull()
            ->and(is_string($storedSignature) ? trim($storedSignature) : null)->toBe($currentSignature)
            ->and(is_file($versionedPath))->toBeTrue()
            ->and((string) file_get_contents($versionedPath))->toBe($routeContents)
            ->and(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse()
            ->and(is_file($cachePath.'/route-cache-refresh.failed'))->toBeFalse()
            ->and(is_file($cachePath.'/route-cache-refresh.succeeded'))->toBeTrue();
    } finally {
        removeDeploymentCacheTrackerProject($basePath);
    }
});
