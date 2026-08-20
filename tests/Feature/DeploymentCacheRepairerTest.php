<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\FailureMarker;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

function makeRepairerRuntimeProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-repair-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);
    mkdir($basePath.'/storage/framework', 0777, true);

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php return ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php return [];\n");

    return $basePath;
}

function removeRepairerRuntimeProject(string $path): void
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

function setRepairerEnvironment(string $name, ?string $value): void
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
    foreach ([
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE',
    ] as $name) {
        setRepairerEnvironment($name, null);
    }
});

afterEach(function (): void {
    foreach ([
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'CONFIG_CACHE_GUARD_ENABLED',
        'CONFIG_CACHE_GUARD_AUTO_REPAIR',
        'CONFIG_CACHE_GUARD_CONFIG',
        'CONFIG_CACHE_GUARD_ROUTES',
        'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE',
    ] as $name) {
        setRepairerEnvironment($name, null);
    }
});

it('repairs pending config cache through Artisan call without a child process', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        @unlink($cachePath.'/route-cache-refresh.pending');
        $calls = [];

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use (&$calls, $cachePath): int {
                $calls[] = $command;

                if ($command === 'config:cache') {
                    file_put_contents($cachePath.'/config.php', '<?php return [];');
                }

                return 0;
            }
        );

        expect($calls)->toBe(['config:cache']);
        expect(is_file($cachePath.'/config.php'))->toBeTrue();
        expect(is_file($cachePath.'/config-source.signature'))->toBeTrue();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/deployment-source.manifest.json'))->toBeTrue();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('repairs a missing route cache and seeds the signature based copy', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        $calls = [];

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use (&$calls, $cachePath): int {
                $calls[] = $command;

                if ($command === 'route:cache') {
                    file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');
                }

                return 0;
            }
        );

        $signature = trim((string) file_get_contents($cachePath.'/route-source.signature'));

        expect($calls)->toBe(['route:cache']);
        expect($signature)->not->toBe('');
        expect(is_file($cachePath.'/routes-'.$signature.'.php'))->toBeTrue();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('repairs config and route under one shared non-blocking lock', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $workerPath = $basePath.'/repair-worker.php';
    $counterPath = $basePath.'/repair-count';
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';

    try {
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        file_put_contents($workerPath, sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            require %s;

            putenv('CONFIG_CACHE_GUARD_ENABLED=true');
            putenv('CONFIG_CACHE_GUARD_AUTO_REPAIR=true');
            putenv('CONFIG_CACHE_GUARD_CONFIG=true');
            putenv('CONFIG_CACHE_GUARD_ROUTES=true');

            $basePath = __DIR__;
            $cachePath = $basePath.'/bootstrap/cache';

            \Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer::runPending(
                $basePath,
                $cachePath,
                static function (string $command) use ($basePath, $cachePath): int {
                    file_put_contents($basePath.'/repair-count', $command."\n", FILE_APPEND | LOCK_EX);
                    usleep(350000);

                    if ($command === 'config:cache') {
                        file_put_contents($cachePath.'/config.php', '<?php return [];');
                    }

                    if ($command === 'route:cache') {
                        file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');
                    }

                    return 0;
                }
            );
            PHP, var_export($autoloadPath, true)));

        $first = new Process([PHP_BINARY, $workerPath], $basePath);
        $second = new Process([PHP_BINARY, $workerPath], $basePath);
        $first->start();

        $deadline = microtime(true) + 5;
        while (! is_file($counterPath) && microtime(true) < $deadline) {
            usleep(10000);
        }

        $second->start();
        $first->wait();
        $second->wait();

        $calls = file($counterPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        expect($first->isSuccessful())->toBeTrue();
        expect($second->isSuccessful())->toBeTrue();
        expect($calls)->toBe(['config:cache', 'route:cache']);
        expect(is_file($cachePath.'/deployment-cache-repair.lock'))->toBeTrue();
        expect(is_file($cachePath.'/config.php'))->toBeTrue();
        expect(is_file($cachePath.'/route-source.signature'))->toBeTrue();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('defers pending repair until application termination', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nsource_signature=".DeploymentCacheSignatures::config($basePath)."\n");
        $calls = [];

        DeploymentCacheRepairer::runPendingAfterResponse(
            $this->app,
            $basePath,
            $cachePath,
            static function (string $command) use (&$calls, $cachePath): int {
                $calls[] = $command;
                file_put_contents($cachePath.'/config.php', '<?php return [];');

                return 0;
            }
        );

        expect($calls)->toBe([]);
        $this->app->terminate();
        expect($calls)->toBe(['config:cache']);
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('requeues when sources change while repair is running', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $configPath = $basePath.'/config/app.php';

    try {
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);
        @unlink($cachePath.'/route-cache-refresh.pending');
        $initialSignature = FailureMarker::sourceSignature($cachePath.'/config-cache-refresh.pending');

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use ($cachePath, $configPath): int {
                file_put_contents($cachePath.'/config.php', '<?php return [];');
                file_put_contents($configPath, "<?php return ['name' => 'Changed deployment'];\n");
                clearstatcache(true, $configPath);

                return 0;
            }
        );

        $nextSignature = FailureMarker::sourceSignature($cachePath.'/config-cache-refresh.pending');

        expect($initialSignature)->not->toBeNull();
        expect($nextSignature)->not->toBeNull()->not->toBe($initialSignature);
        expect(is_file($cachePath.'/config.php'))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('remembers an uncacheable route signature and suppresses immediate repeated attempts', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config.php', '<?php return [];');
        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static fn (string $command): int => $command === 'route:cache' ? 1 : 0
        );

        $failedSignature = FailureMarker::sourceSignature($cachePath.'/route-cache-refresh.failed');
        expect($failedSignature)->not->toBeNull();

        DeploymentCacheRepairer::queueMissingCaches($basePath, $cachePath);

        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
        expect(FailureMarker::sourceSignature($cachePath.'/route-cache-refresh.failed'))->toBe($failedSignature);
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('keeps normal web response work ahead of deferred cache mutation', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nsource_signature=".DeploymentCacheSignatures::config($basePath)."\n");
        $calls = [];
        $uri = '/guard-deferred-'.bin2hex(random_bytes(4));

        Route::get($uri, static function () use (&$calls) {
            expect($calls)->toBe([]);

            return 'ok';
        });

        DeploymentCacheRepairer::runPendingAfterResponse(
            $this->app,
            $basePath,
            $cachePath,
            static function (string $command) use (&$calls, $cachePath): int {
                $calls[] = $command;
                file_put_contents($cachePath.'/config.php', '<?php return [];');

                return 0;
            }
        );

        $this->get($uri)->assertOk()->assertSeeText('ok');
        if ($calls === []) {
            $this->app->terminate();
        }

        expect($calls)->toBe(['config:cache']);
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});
