<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\ConfigCacheFile;
use Codegenie\ConfigCacheGuard\Support\Environment;

it('keeps post-bootstrap dotenv values from changing captured guard flags', function (): void {
    $key = '__codegenie_config_cache_guard_external_environment';
    $originalSnapshotExists = array_key_exists($key, $GLOBALS);
    $originalSnapshot = $GLOBALS[$key] ?? null;
    $originalEnv = $_ENV['CONFIG_CACHE_GUARD_ENABLED'] ?? null;
    $originalServer = $_SERVER['CONFIG_CACHE_GUARD_ENABLED'] ?? null;
    $originalProcess = getenv('CONFIG_CACHE_GUARD_ENABLED');

    try {
        putenv('CONFIG_CACHE_GUARD_ENABLED');
        $_ENV['CONFIG_CACHE_GUARD_ENABLED'] = 'false';
        $_SERVER['CONFIG_CACHE_GUARD_ENABLED'] = 'false';
        $GLOBALS[$key] = ['CONFIG_CACHE_GUARD_ENABLED' => null];

        expect(Environment::flag('CONFIG_CACHE_GUARD_ENABLED'))->toBeTrue();

        $GLOBALS[$key] = ['CONFIG_CACHE_GUARD_ENABLED' => 'false'];
        $_ENV['CONFIG_CACHE_GUARD_ENABLED'] = 'true';
        $_SERVER['CONFIG_CACHE_GUARD_ENABLED'] = 'true';

        expect(Environment::flag('CONFIG_CACHE_GUARD_ENABLED'))->toBeFalse();

        putenv('CONFIG_CACHE_GUARD_ENABLED=true');

        expect(Environment::flag('CONFIG_CACHE_GUARD_ENABLED'))->toBeFalse();
    } finally {
        if (is_string($originalProcess)) {
            putenv('CONFIG_CACHE_GUARD_ENABLED='.$originalProcess);
        } else {
            putenv('CONFIG_CACHE_GUARD_ENABLED');
        }

        if ($originalEnv === null) {
            unset($_ENV['CONFIG_CACHE_GUARD_ENABLED']);
        } else {
            $_ENV['CONFIG_CACHE_GUARD_ENABLED'] = $originalEnv;
        }

        if ($originalServer === null) {
            unset($_SERVER['CONFIG_CACHE_GUARD_ENABLED']);
        } else {
            $_SERVER['CONFIG_CACHE_GUARD_ENABLED'] = $originalServer;
        }

        if ($originalSnapshotExists) {
            $GLOBALS[$key] = $originalSnapshot;
        } else {
            unset($GLOBALS[$key]);
        }
    }
});

it('ignores a custom config cache path that appears only after bootstrap', function (): void {
    $key = '__codegenie_config_cache_guard_external_environment';
    $originalSnapshotExists = array_key_exists($key, $GLOBALS);
    $originalSnapshot = $GLOBALS[$key] ?? null;
    $originalEnv = $_ENV['APP_CONFIG_CACHE'] ?? null;
    $originalServer = $_SERVER['APP_CONFIG_CACHE'] ?? null;
    $originalProcess = getenv('APP_CONFIG_CACHE');
    $basePath = sys_get_temp_dir().'/config-cache-guard-environment-'.bin2hex(random_bytes(8));
    $cachePath = $basePath.'/bootstrap/cache';

    mkdir($cachePath, 0777, true);

    try {
        putenv('APP_CONFIG_CACHE');
        $_ENV['APP_CONFIG_CACHE'] = 'storage/framework/from-dotenv.php';
        $_SERVER['APP_CONFIG_CACHE'] = 'storage/framework/from-dotenv.php';
        $GLOBALS[$key] = ['APP_CONFIG_CACHE' => null];

        expect(normalizeTestPath(ConfigCacheFile::current($basePath, $cachePath)))
            ->toBe(normalizeTestPath($cachePath.'/config.php'));

        putenv('APP_CONFIG_CACHE=storage/framework/from-process.php');

        expect(normalizeTestPath(ConfigCacheFile::current($basePath, $cachePath)))
            ->toBe(normalizeTestPath($cachePath.'/config.php'));

        unset($GLOBALS[$key]);

        expect(normalizeTestPath(ConfigCacheFile::current($basePath, $cachePath)))
            ->toBe(normalizeTestPath($basePath.'/storage/framework/from-process.php'));
    } finally {
        @rmdir($cachePath);
        @rmdir(dirname($cachePath));
        @rmdir($basePath);

        if (is_string($originalProcess)) {
            putenv('APP_CONFIG_CACHE='.$originalProcess);
        } else {
            putenv('APP_CONFIG_CACHE');
        }

        if ($originalEnv === null) {
            unset($_ENV['APP_CONFIG_CACHE']);
        } else {
            $_ENV['APP_CONFIG_CACHE'] = $originalEnv;
        }

        if ($originalServer === null) {
            unset($_SERVER['APP_CONFIG_CACHE']);
        } else {
            $_SERVER['APP_CONFIG_CACHE'] = $originalServer;
        }

        if ($originalSnapshotExists) {
            $GLOBALS[$key] = $originalSnapshot;
        } else {
            unset($GLOBALS[$key]);
        }
    }
});
