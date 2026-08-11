<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;

function makeSignatureRuntimeProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-signatures-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/app/Providers', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/composer.json', "{\"name\":\"codegenie/test\"}\n");
    file_put_contents($basePath.'/composer.lock', "{\"content-hash\":\"one\"}\n");
    file_put_contents($basePath.'/bootstrap/app.php', "<?php return 'app-one';\n");
    file_put_contents($basePath.'/bootstrap/providers.php', "<?php return [];\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");
    file_put_contents($basePath.'/app/Providers/AppServiceProvider.php', "<?php return 'provider-one';\n");

    return $basePath;
}

function removeSignatureRuntimeProject(string $path): void
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

it('invalidates config signatures when dependency or provider metadata changes', function (): void {
    $basePath = makeSignatureRuntimeProject();

    try {
        $initial = DeploymentCacheSignatures::config($basePath);

        file_put_contents($basePath.'/composer.lock', "{\"content-hash\":\"two-and-longer\"}\n");
        $afterComposerChange = DeploymentCacheSignatures::config($basePath);

        file_put_contents(
            $basePath.'/app/Providers/AppServiceProvider.php',
            "<?php return 'provider-two-and-longer';\n"
        );
        $afterProviderChange = DeploymentCacheSignatures::config($basePath);

        expect($initial)->not->toBeNull();
        expect($afterComposerChange)->not->toBe($initial);
        expect($afterProviderChange)->not->toBe($afterComposerChange);
    } finally {
        removeSignatureRuntimeProject($basePath);
    }
});

it('invalidates route signatures when configuration used by route registration changes', function (): void {
    $basePath = makeSignatureRuntimeProject();

    try {
        $initial = DeploymentCacheSignatures::routes($basePath);

        file_put_contents(
            $basePath.'/config/app.php',
            "<?php return ['name' => 'Codegenie', 'feature' => true];\n"
        );
        $afterConfigChange = DeploymentCacheSignatures::routes($basePath);

        expect($initial)->not->toBeNull();
        expect($afterConfigChange)->not->toBe($initial);
    } finally {
        removeSignatureRuntimeProject($basePath);
    }
});

it('uses Laravel 13 dot-laravel bootstrap sources when that directory exists', function (): void {
    $basePath = makeSignatureRuntimeProject();

    try {
        mkdir($basePath.'/.laravel', 0777, true);
        file_put_contents($basePath.'/.laravel/app.php', "<?php return 'dot-laravel-one';\n");
        file_put_contents($basePath.'/.laravel/providers.php', "<?php return [];\n");

        $initial = DeploymentCacheSignatures::routes($basePath);

        file_put_contents(
            $basePath.'/.laravel/app.php',
            "<?php return 'dot-laravel-two-and-longer';\n"
        );
        $afterBootstrapChange = DeploymentCacheSignatures::routes($basePath);

        expect($initial)->not->toBeNull();
        expect($afterBootstrapChange)->not->toBe($initial);
    } finally {
        removeSignatureRuntimeProject($basePath);
    }
});
