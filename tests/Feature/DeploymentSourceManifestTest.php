<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentSourceManifest;

function makeManifestRuntimeProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-manifest-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/app/Providers', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/composer.json', "{\"name\":\"codegenie/test\"}\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");
    file_put_contents($basePath.'/app/Providers/AppServiceProvider.php', "<?php return true;\n");

    return $basePath;
}

function removeManifestRuntimeProject(string $path): void
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

it('reuses a current source manifest instead of repeating source discovery', function (): void {
    $basePath = makeManifestRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        $first = DeploymentSourceManifest::signatures($basePath, $cachePath);
        $second = DeploymentSourceManifest::signatures($basePath, $cachePath);

        expect($first['reused'])->toBeFalse();
        expect($second['reused'])->toBeTrue();
        expect($second['config'])->toBe($first['config']);
        expect($second['routes'])->toBe($first['routes']);
        expect(is_file(DeploymentSourceManifest::path($cachePath)))->toBeTrue();
    } finally {
        removeManifestRuntimeProject($basePath);
    }
});

it('invalidates the manifest when a known source file changes', function (): void {
    $basePath = makeManifestRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $configPath = $basePath.'/config/app.php';

    try {
        $first = DeploymentSourceManifest::signatures($basePath, $cachePath);
        file_put_contents($configPath, "<?php return ['name' => 'Changed deployment'];\n");
        clearstatcache(true, $configPath);
        $second = DeploymentSourceManifest::signatures($basePath, $cachePath);

        expect($second['reused'])->toBeFalse();
        expect($second['config'])->not->toBe($first['config']);
        expect($second['routes'])->not->toBe($first['routes']);
    } finally {
        removeManifestRuntimeProject($basePath);
    }
});

it('invalidates the manifest when a new source file is added', function (): void {
    $basePath = makeManifestRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $routesPath = $basePath.'/routes';

    try {
        $first = DeploymentSourceManifest::signatures($basePath, $cachePath);
        file_put_contents($routesPath.'/admin.php', "<?php return [];\n");
        touch($routesPath, time() + 2);
        clearstatcache(true, $routesPath);
        $second = DeploymentSourceManifest::signatures($basePath, $cachePath);

        expect($second['reused'])->toBeFalse();
        expect($second['routes'])->not->toBe($first['routes']);
    } finally {
        removeManifestRuntimeProject($basePath);
    }
});

it('preserves content mode detection for same-size rewrites', function (): void {
    $basePath = makeManifestRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $configPath = $basePath.'/config/app.php';

    try {
        $original = (string) file_get_contents($configPath);
        $changed = str_replace('Codegenie', 'Guardrail', $original);
        $mtime = filemtime($configPath);
        expect(strlen($changed))->toBe(strlen($original));

        $first = DeploymentSourceManifest::signatures($basePath, $cachePath, 'content');
        file_put_contents($configPath, $changed);

        if (is_int($mtime)) {
            touch($configPath, $mtime);
        }

        clearstatcache(true, $configPath);
        $second = DeploymentSourceManifest::signatures($basePath, $cachePath, 'content');

        expect($second['reused'])->toBeFalse();
        expect($second['config'])->not->toBe($first['config']);
    } finally {
        removeManifestRuntimeProject($basePath);
    }
});

it('stores only relative source paths and one-way fingerprints', function (): void {
    $basePath = makeManifestRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        DeploymentSourceManifest::signatures($basePath, $cachePath);
        $contents = (string) file_get_contents(DeploymentSourceManifest::path($cachePath));

        expect($contents)->toContain('config/app.php');
        expect($contents)->not->toContain(str_replace('\\', '/', $basePath));
        expect($contents)->not->toContain('APP_NAME=Codegenie');
    } finally {
        removeManifestRuntimeProject($basePath);
    }
});
