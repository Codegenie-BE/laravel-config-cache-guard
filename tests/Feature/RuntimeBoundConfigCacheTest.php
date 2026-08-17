<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;

function makeRuntimeBoundConfigProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-runtime-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/vendor', 0777, true);
    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/storage/framework/views', 0777, true);

    file_put_contents($basePath.'/vendor/autoload.php', "<?php\n");
    file_put_contents($basePath.'/artisan', "<?php exit(1);\n");
    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");

    return $basePath;
}

function removeRuntimeBoundConfigProject(string $path): void
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

function resetRuntimeBoundConfigEnvironment(): void
{
    foreach ([
        'CONFIG_CACHE_GUARD_ALLOW_CLI',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE',
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_FAIL_HARD',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_SIGNATURE_MODE',
    ] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    unset(
        $GLOBALS['__codegenie_config_cache_guard_loaded'],
        $GLOBALS['__codegenie_config_cache_guard_external_environment'],
        $GLOBALS['_composer_autoload_path']
    );
}

it('binds content config signatures to the runtime application path', function (): void {
    $firstPath = makeRuntimeBoundConfigProject();
    $secondPath = makeRuntimeBoundConfigProject();

    try {
        $firstSignature = DeploymentCacheSignatures::config($firstPath, 'content');
        $secondSignature = DeploymentCacheSignatures::config($secondPath, 'content');

        expect($firstSignature)->not->toBeNull();
        expect($secondSignature)->not->toBeNull();
        expect($secondSignature)->not->toBe($firstSignature);
    } finally {
        removeRuntimeBoundConfigProject($firstPath);
        removeRuntimeBoundConfigProject($secondPath);
    }
});

it('rejects config cache signed before the application directory is moved', function (): void {
    $buildPath = makeRuntimeBoundConfigProject();
    $deployedPath = $buildPath.'-deployed';

    try {
        $sourceSignature = DeploymentCacheSignatures::config($buildPath, 'content');
        $cachedConfigPath = $buildPath.'/bootstrap/cache/config.php';
        $signaturePath = $buildPath.'/bootstrap/cache/config-source.signature';
        $compiledViewPath = $buildPath.'/storage/framework/views';

        expect($sourceSignature)->not->toBeNull();

        file_put_contents(
            $cachedConfigPath,
            "<?php return ['view' => ['compiled' => ".var_export($compiledViewPath, true)."]];\n"
        );
        file_put_contents($signaturePath, (string) $sourceSignature);

        expect(rename($buildPath, $deployedPath))->toBeTrue();

        $buildPath = '';
        $cachedConfigPath = $deployedPath.'/bootstrap/cache/config.php';
        $currentSignature = DeploymentCacheSignatures::config($deployedPath, 'content');

        expect($currentSignature)->not->toBe($sourceSignature);

        putenv('CONFIG_CACHE_GUARD_ALLOW_CLI=true');
        putenv('CONFIG_CACHE_GUARD_AUTO_REPAIR=false');
        putenv('CONFIG_CACHE_GUARD_CONFIG=true');
        putenv('CONFIG_CACHE_GUARD_ENABLED=true');
        putenv('CONFIG_CACHE_GUARD_FAIL_HARD=false');
        putenv('CONFIG_CACHE_GUARD_ROUTES=false');
        putenv('CONFIG_CACHE_GUARD_SIGNATURE_MODE=content');

        $GLOBALS['_composer_autoload_path'] = $deployedPath.'/vendor/autoload.php';

        include dirname(__DIR__, 2).'/bootstrap/guard.php';

        expect(is_file($cachedConfigPath))->toBeFalse();
        expect(is_file($deployedPath.'/bootstrap/cache/config-cache-refresh.failed'))->toBeTrue();
    } finally {
        resetRuntimeBoundConfigEnvironment();
        removeRuntimeBoundConfigProject($buildPath);
        removeRuntimeBoundConfigProject($deployedPath);
    }
});
