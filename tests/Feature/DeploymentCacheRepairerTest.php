<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\Process\Process;

function makeRepairerRuntimeProject(): string
{
    $basePath = sys_get_temp_dir().'/config-cache-guard-repair-'.bin2hex(random_bytes(8));

    mkdir($basePath.'/bootstrap/cache', 0777, true);
    mkdir($basePath.'/config', 0777, true);
    mkdir($basePath.'/routes', 0777, true);
    mkdir($basePath.'/storage/framework', 0777, true);

    file_put_contents($basePath.'/.env', "APP_NAME=Codegenie\n");
    file_put_contents($basePath.'/config/app.php', "<?php\n\nreturn ['name' => 'Codegenie'];\n");
    file_put_contents($basePath.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'ok');\n");

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
    $capturedEnvironment = $GLOBALS[$key] ?? [];

    if (! is_array($capturedEnvironment)) {
        $capturedEnvironment = [];
    }

    $capturedEnvironment[$name] = $value;
    $GLOBALS[$key] = $capturedEnvironment;
}

beforeEach(function (): void {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE'] as $name) {
        setRepairerEnvironment($name, null);
    }
});

afterEach(function (): void {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE'] as $name) {
        setRepairerEnvironment($name, null);
    }
});

it('repairs pending config cache through a callable without exec', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nreason=exec_disabled\n");

        $calls = [];
        $callable = static function (string $command) use (&$calls, $cachePath): int {
            $calls[] = $command;

            if ($command === 'config:cache') {
                file_put_contents($cachePath.'/config.php', '<?php return [];');
            }

            return 0;
        };

        DeploymentCacheRepairer::runPending($basePath, $cachePath, $callable);

        expect($calls)->toBe(['config:cache']);
        expect(is_file($cachePath.'/config.php'))->toBeTrue();
        expect(is_file($cachePath.'/config-source.signature'))->toBeTrue();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/config-cache-refresh.failed'))->toBeFalse();
        expect((string) file_get_contents($cachePath.'/config-cache-refresh.succeeded'))->toContain('target=config');
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('removes rebuilt config when its source signature cannot be persisted', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        mkdir($cachePath.'/config-source.signature');
        file_put_contents(
            $cachePath.'/config-cache-refresh.pending',
            "target=config\nreason=exec_disabled\nsource_signature=".str_repeat('b', 32)."\n"
        );

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use ($cachePath): int {
                if ($command === 'config:cache') {
                    file_put_contents($cachePath.'/config.php', '<?php return [];');
                }

                return 0;
            }
        );

        expect(is_file($cachePath.'/config.php'))->toBeFalse();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect((string) file_get_contents($cachePath.'/config-cache-refresh.failed'))
            ->toContain('reason=signature_write_failed');
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('persists the exact pre-bootstrap source signature after deferred repair', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $sourceSignature = str_repeat('a', 32);

    try {
        file_put_contents(
            $cachePath.'/config-cache-refresh.pending',
            "target=config\nreason=exec_disabled\nsource_signature={$sourceSignature}\n"
        );

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static function (string $command) use ($cachePath): int {
                if ($command === 'config:cache') {
                    file_put_contents($cachePath.'/config.php', '<?php return [];');
                }

                return 0;
            }
        );

        expect((string) file_get_contents($cachePath.'/config-source.signature'))
            ->toBe($sourceSignature);
        expect((string) file_get_contents($cachePath.'/config-cache-refresh.succeeded'))
            ->toContain('source_signature='.$sourceSignature);
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('repairs pending config cache into a configured custom cache file', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        $customConfigPath = $basePath.'/storage/framework/custom-config.php';

        setRepairerEnvironment('APP_CONFIG_CACHE', 'storage/framework/custom-config.php');
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nreason=exec_disabled\n");

        $calls = [];
        $callable = static function (string $command) use (&$calls, $customConfigPath): int {
            $calls[] = $command;

            if ($command === 'config:cache') {
                file_put_contents($customConfigPath, '<?php return [];');
            }

            return 0;
        };

        DeploymentCacheRepairer::runPending($basePath, $cachePath, $callable);

        $successMarker = (string) file_get_contents($cachePath.'/config-cache-refresh.succeeded');
        $normalizedCustomConfigPath = str_replace('\\', '/', $customConfigPath);
        $expectedCacheFileHash = hash('sha256', $normalizedCustomConfigPath);

        expect($calls)->toBe(['config:cache']);
        expect(is_file($customConfigPath))->toBeTrue();
        expect(is_file($cachePath.'/config.php'))->toBeFalse();
        expect(is_file($cachePath.'/config-source.signature'))->toBeTrue();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect($successMarker)->toContain('cache_file_hash='.$expectedCacheFileHash);
        expect(str_contains($successMarker, $customConfigPath))->toBeFalse();
        expect(str_contains($successMarker, $normalizedCustomConfigPath))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('renders web views with shared errors before deferred repair runs', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

        mkdir($basePath.'/resources/views', 0777, true);
        file_put_contents(
            $basePath.'/resources/views/guard-errors-regression.blade.php',
            '@if($errors->any()) errors @else ok @endif'
        );
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nreason=exec_disabled\n");

        View::addLocation($basePath.'/resources/views');

        $calls = [];
        $uri = '/guard-errors-regression-'.bin2hex(random_bytes(4));

        Route::middleware('web')->get($uri, static function () use (&$calls) {
            expect($calls)->toBe([]);

            return view('guard-errors-regression');
        });

        DeploymentCacheRepairer::runPendingAfterResponse(
            $this->app,
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

        $this->get($uri)
            ->assertOk()
            ->assertSeeText('ok');

        if ($calls === []) {
            $this->app->terminate();
        }

        expect($calls)->toBe(['config:cache']);
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('defers pending repairs until the application terminates', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nreason=exec_disabled\n");

        $calls = [];
        $callable = static function (string $command) use (&$calls, $cachePath): int {
            $calls[] = $command;

            if ($command === 'config:cache') {
                file_put_contents($cachePath.'/config.php', '<?php return [];');
            }

            return 0;
        };

        DeploymentCacheRepairer::runPendingAfterResponse($this->app, $basePath, $cachePath, $callable);

        expect($calls)->toBe([]);
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeTrue();

        $this->app->terminate();

        expect($calls)->toBe(['config:cache']);
        expect(is_file($cachePath.'/config.php'))->toBeTrue();
        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('writes a safe failure marker when pending config repair fails', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/config-cache-refresh.pending', "target=config\nreason=exec_disabled\n");

        DeploymentCacheRepairer::runPending(
            $basePath,
            $cachePath,
            static fn (string $command): int => 1
        );

        $failed = (string) file_get_contents($cachePath.'/config-cache-refresh.failed');

        expect(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
        expect($failed)->toContain('reason=auto_repair_failed');
        expect($failed)->toContain('No .env values, secrets, tokens or command output');
        expect($failed)->not->toContain('APP_NAME=Codegenie');
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('repairs pending route cache through a callable without exec', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        file_put_contents($cachePath.'/route-cache-refresh.pending', "target=route\nreason=exec_disabled\n");

        $calls = [];
        $callable = static function (string $command) use (&$calls, $cachePath): int {
            $calls[] = $command;

            if ($command === 'route:cache') {
                file_put_contents($cachePath.'/routes-v7.php', '<?php return [];');
            }

            return 0;
        };

        DeploymentCacheRepairer::runPending($basePath, $cachePath, $callable);

        expect($calls)->toBe(['route:cache']);
        expect(is_file($cachePath.'/routes-v7.php'))->toBeTrue();
        expect(is_file($cachePath.'/route-source.signature'))->toBeTrue();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.failed'))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('repairs pending route cache into the configured current route cache file', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        $currentRoutePath = $cachePath.'/routes-current.php';
        $staleRoutePath = $cachePath.'/routes-v7.php';

        setRepairerEnvironment('APP_ROUTES_CACHE', 'bootstrap/cache/routes-current.php');
        file_put_contents($cachePath.'/route-cache-refresh.pending', "target=route\nreason=exec_disabled\n");
        file_put_contents($staleRoutePath, '<?php return [];');

        $calls = [];
        $callable = static function (string $command) use (&$calls, $currentRoutePath): int {
            $calls[] = $command;

            if ($command === 'route:cache') {
                file_put_contents($currentRoutePath, '<?php return [];');
            }

            return 0;
        };

        DeploymentCacheRepairer::runPending($basePath, $cachePath, $callable);

        expect($calls)->toBe(['route:cache']);
        expect(is_file($currentRoutePath))->toBeTrue();
        expect(is_file($staleRoutePath))->toBeFalse();
        expect(is_file($cachePath.'/route-source.signature'))->toBeTrue();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.failed'))->toBeFalse();
        expect((string) file_get_contents($cachePath.'/route-cache-refresh.succeeded'))->toContain('cleaned_stale_files=1');
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('repairs pending route cache into a custom route cache file outside the default glob', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        $currentRoutePath = $basePath.'/storage/framework/custom-routes.php';
        $staleRoutePath = $cachePath.'/routes-v7.php';

        setRepairerEnvironment('APP_ROUTES_CACHE', 'storage/framework/custom-routes.php');
        file_put_contents($cachePath.'/route-cache-refresh.pending', "target=route\nreason=exec_disabled\n");
        file_put_contents($staleRoutePath, '<?php return [];');

        $calls = [];
        $callable = static function (string $command) use (&$calls, $currentRoutePath): int {
            $calls[] = $command;

            if ($command === 'route:cache') {
                file_put_contents($currentRoutePath, '<?php return [];');
            }

            return 0;
        };

        DeploymentCacheRepairer::runPending($basePath, $cachePath, $callable);

        expect($calls)->toBe(['route:cache']);
        expect(is_file($currentRoutePath))->toBeTrue();
        expect(is_file($staleRoutePath))->toBeFalse();
        expect(is_file($cachePath.'/route-source.signature'))->toBeTrue();
        expect(is_file($cachePath.'/route-cache-refresh.pending'))->toBeFalse();
        expect(is_file($cachePath.'/route-cache-refresh.failed'))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});

it('allows only one concurrent process to repair the same pending cache', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';
    $workerPath = $basePath.'/repair-worker.php';
    $counterPath = $basePath.'/repair-count';
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';

    try {
        file_put_contents(
            $cachePath.'/config-cache-refresh.pending',
            "target=config\nreason=exec_disabled\n"
        );
        file_put_contents($workerPath, sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            require %s;

            putenv('CONFIG_CACHE_GUARD_ENABLED=true');
            putenv('CONFIG_CACHE_GUARD_AUTO_REPAIR=true');
            putenv('CONFIG_CACHE_GUARD_CONFIG=true');
            putenv('CONFIG_CACHE_GUARD_ROUTES=false');

            $basePath = __DIR__;
            $cachePath = $basePath.'/bootstrap/cache';

            \Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer::runPending(
                $basePath,
                $cachePath,
                static function (string $command) use ($basePath, $cachePath): int {
                    if ($command !== 'config:cache') {
                        return 1;
                    }

                    file_put_contents($basePath.'/repair-count', "1\n", FILE_APPEND | LOCK_EX);
                    usleep(600000);
                    file_put_contents($cachePath.'/config.php', '<?php return [];');

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

        expect(is_file($counterPath))->toBeTrue();

        $second->start();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue()
            ->and($second->isSuccessful())->toBeTrue()
            ->and(file($counterPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->toHaveCount(1)
            ->and(is_file($cachePath.'/config.php'))->toBeTrue()
            ->and(is_file($cachePath.'/config-source.signature'))->toBeTrue()
            ->and(is_file($cachePath.'/config-cache-refresh.pending'))->toBeFalse();
    } finally {
        removeRepairerRuntimeProject($basePath);
    }
});
