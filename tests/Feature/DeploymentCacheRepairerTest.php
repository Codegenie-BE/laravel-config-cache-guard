<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;

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

beforeEach(function (): void {
    putenv('APP_ROUTES_CACHE');
    unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
});

afterEach(function (): void {
    putenv('APP_ROUTES_CACHE');
    unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
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

        putenv('APP_ROUTES_CACHE=bootstrap/cache/routes-current.php');
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

it('repairs pending route cache into a custom route cache file outside the default glob', function (): void {
    $basePath = makeRepairerRuntimeProject();
    $cachePath = $basePath.'/bootstrap/cache';

    try {
        $currentRoutePath = $basePath.'/storage/framework/custom-routes.php';
        $staleRoutePath = $cachePath.'/routes-v7.php';

        putenv('APP_ROUTES_CACHE=storage/framework/custom-routes.php');
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
