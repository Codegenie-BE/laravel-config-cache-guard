<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Tests\E2E;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class LaravelConfigCacheGuardProductionE2e
{
    private const PACKAGE_NAME = 'codegenie-be/laravel-config-cache-guard';
    private const REQUEST_HARD_LIMIT_MS = 750.0;
    private const HEALTHY_P95_LIMIT_MS = 250.0;
    private const REPAIR_HARD_LIMIT_MS = 5000.0;
    private const HEALTHY_SAMPLE_COUNT = 20;

    private readonly string $applicationPath;
    private readonly string $bootstrapPath;
    private readonly string $cachePath;
    private readonly string $defaultConfigCachePath;
    private ?Process $server = null;
    private string $serverUrl = '';

    /** @var list<array{scenario:string, metric:string, milliseconds:float, limit_ms:float}> */
    private array $performance = [];

    public function __construct(
        private readonly string $repositoryPath,
        private readonly string $laravelMajor,
        private readonly string $temporaryPath,
        private readonly bool $keepApplication,
        private readonly bool $artifactPackage = false,
    ) {
        $this->applicationPath = $this->temporaryPath.'/application';
        $this->bootstrapPath = $this->laravelMajor === '13'
            ? $this->applicationPath.'/.laravel'
            : $this->applicationPath.'/bootstrap';
        $this->cachePath = $this->bootstrapPath.'/cache';
        $this->defaultConfigCachePath = $this->cachePath.'/config.php';
    }

    public function run(): void
    {
        $this->info('Creating a fresh Laravel '.$this->laravelMajor.' application');
        $this->createApplication();

        if ($this->laravelMajor === '13') {
            $this->activateDotLaravelBootstrapPath();
        }

        $this->writeApplicationFixture();
        $this->installPackage();
        $this->assertComposerInstallation();

        $this->scenarioAutomaticCacheCreation();
        $this->scenarioStaleRepair(false, 'normal', 'E2eNormalController', 'normal-refreshed-route');
        $this->scenarioStaleRepair(true, 'restricted', 'E2eRestrictedController', 'restricted-refreshed-route');
        $this->scenarioCustomConfigCache();
        $this->scenarioHealthyBurst();
        $this->writePerformanceResults();

        $this->info('Laravel '.$this->laravelMajor.' production E2E scenarios passed');
    }

    public function cleanup(): void
    {
        $this->stopServer();

        if ($this->keepApplication) {
            $this->info('Keeping test application at '.$this->applicationPath);
            return;
        }

        self::removeDirectory($this->temporaryPath);
    }

    private function createApplication(): void
    {
        if (is_dir($this->temporaryPath)) {
            self::removeDirectory($this->temporaryPath);
        }

        self::ensureDirectory($this->temporaryPath);
        $this->runProcess(array_merge(self::composerCommand(), [
            'create-project',
            'laravel/laravel:^'.$this->laravelMajor.'.0',
            $this->applicationPath,
            '--prefer-dist',
            '--no-dev',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
        ]), $this->temporaryPath);
    }

    private function activateDotLaravelBootstrapPath(): void
    {
        self::ensureDirectory($this->cachePath);
        $appContents = $this->read($this->applicationPath.'/bootstrap/app.php');
        $appContents = $this->replaceOnce(
            'return Application::configure(',
            '$app = Application::configure(',
            $appContents,
            'Laravel bootstrap application assignment',
        );
        $updated = preg_replace(
            '/->create\(\);\s*$/',
            "->create();\n\n\$app->useBootstrapPath(__DIR__);\n\nreturn \$app;\n",
            $appContents,
            1,
            $replacementCount,
        );
        self::assert(
            is_string($updated) && $replacementCount === 1,
            'Could not configure Laravel to use .laravel as its bootstrap path.',
        );
        $this->write($this->bootstrapPath.'/app.php', $updated);
        $this->write($this->bootstrapPath.'/providers.php', $this->read($this->applicationPath.'/bootstrap/providers.php'));

        $publicIndex = $this->applicationPath.'/public/index.php';
        $this->write($publicIndex, $this->replaceOnce(
            "__DIR__.'/../bootstrap/app.php'",
            "__DIR__.'/../.laravel/app.php'",
            $this->read($publicIndex),
            'public bootstrap path',
        ));

        $artisan = $this->applicationPath.'/artisan';
        $this->write($artisan, $this->replaceOnce(
            "__DIR__.'/bootstrap/app.php'",
            "__DIR__.'/.laravel/app.php'",
            $this->read($artisan),
            'Artisan bootstrap path',
        ));
    }

    private function writeApplicationFixture(): void
    {
        $environment = $this->read($this->applicationPath.'/.env.example');
        $environment = $this->setEnvironmentValue($environment, 'APP_ENV', 'production');
        $environment = $this->setEnvironmentValue(
            $environment,
            'APP_KEY',
            'base64:'.base64_encode('config-cache-guard-e2e-key-0001x'),
        );
        $environment = $this->setEnvironmentValue($environment, 'APP_DEBUG', 'false');
        $environment = $this->setEnvironmentValue($environment, 'CACHE_STORE', 'array');
        $environment = $this->setEnvironmentValue($environment, 'LOG_CHANNEL', 'stderr');
        $environment = $this->setEnvironmentValue($environment, 'SESSION_DRIVER', 'array');
        $environment = $this->setEnvironmentValue($environment, 'E2E_CONFIG_VALUE', 'initial-config');
        $this->write($this->applicationPath.'/.env', $environment);
        $this->write(
            $this->applicationPath.'/config/e2e.php',
            "<?php\n\nreturn ['value' => env('E2E_CONFIG_VALUE', 'missing')];\n",
        );

        $this->writeController('E2eInitialController', 'initial-route');
        $this->writeController('E2eNormalController', 'normal-refreshed-route');
        $this->writeController('E2eRestrictedController', 'restricted-refreshed-route');
        $this->writeRoute('E2eInitialController');
    }

    private function installPackage(): void
    {
        $composerPath = $this->applicationPath.'/composer.json';
        $composer = json_decode($this->read($composerPath), true, 512, JSON_THROW_ON_ERROR);
        self::assert(is_array($composer), 'The Laravel application composer.json is invalid.');

        $composer['repositories'] = [
            'config-cache-guard-e2e' => [
                'type' => 'path',
                'url' => self::normalizePath($this->repositoryPath),
                'options' => [
                    'symlink' => false,
                    'versions' => [self::PACKAGE_NAME => 'dev-e2e'],
                ],
            ],
        ];
        $composer['require'][self::PACKAGE_NAME] = 'dev-e2e';
        $this->write(
            $composerPath,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $this->runProcess(array_merge(self::composerCommand(), [
            'update',
            self::PACKAGE_NAME,
            '--with-all-dependencies',
            '--prefer-dist',
            '--no-dev',
            '--no-interaction',
            '--no-progress',
        ]), $this->applicationPath);
    }

    private function assertComposerInstallation(): void
    {
        $installedGuard = $this->applicationPath.'/vendor/'.self::PACKAGE_NAME.'/bootstrap/guard.php';
        $autoloadFiles = $this->applicationPath.'/vendor/composer/autoload_files.php';
        $this->assertFileExists($installedGuard);
        self::assert(! is_link(dirname($installedGuard, 2)), 'Composer symlinked the package instead of copying it.');
        self::assert(
            hash_file('sha256', $installedGuard) === hash_file('sha256', $this->repositoryPath.'/bootstrap/guard.php'),
            'The installed guard does not match the package source.',
        );
        self::assert(
            str_contains($this->read($autoloadFiles), self::PACKAGE_NAME.'/bootstrap/guard.php'),
            'Composer did not register bootstrap/guard.php in autoload_files.php.',
        );
        $status = $this->runArtisan(['config-cache-guard:status']);
        self::assert(str_contains($status, 'Composer autoload integration'), 'Status command is not registered.');
    }

    private function scenarioAutomaticCacheCreation(): void
    {
        $scenario = 'automatic-cache-creation';
        $this->info('Scenario '.$scenario);
        $this->runArtisan(['config:clear']);
        $this->runArtisan(['route:clear']);
        $this->clearGuardState();

        $this->withServer(false, function () use ($scenario): void {
            [$first, $requestMs] = $this->requestJsonTimed();
            $this->recordBudget($scenario, 'first_response', $requestMs, self::REQUEST_HARD_LIMIT_MS);
            $this->assertResponse($first, 'initial-config', 'initial-route', false, false);

            $repairMs = $this->timeUntil(
                fn (): bool => $this->repairComplete(),
                'Automatic cache creation did not finish.',
            );
            $this->recordBudget($scenario, 'repair', $repairMs, self::REPAIR_HARD_LIMIT_MS);

            [$second, $secondMs] = $this->requestJsonTimed();
            $this->recordBudget($scenario, 'cached_response', $secondMs, self::REQUEST_HARD_LIMIT_MS);
            $this->assertResponse($second, 'initial-config', 'initial-route', true, true);
            $this->assertDefaultCachePaths($second);
            $this->assertHealthyRepairState();
        });
    }

    private function scenarioStaleRepair(
        bool $disableProcessControl,
        string $suffix,
        string $controller,
        string $routeValue,
    ): void {
        $scenario = $disableProcessControl ? 'stale-repair-restricted' : 'stale-repair';
        $this->info('Scenario '.$scenario);
        $this->removeSuccessMarkers();
        $configValue = $suffix.'-refreshed-config-value-longer';
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', $configValue);
        $this->writeRoute($controller);

        $this->withServer($disableProcessControl, function () use ($scenario, $configValue, $routeValue): void {
            [$first, $requestMs] = $this->requestJsonTimed();
            $this->recordBudget($scenario, 'first_response', $requestMs, self::REQUEST_HARD_LIMIT_MS);
            $this->assertResponse($first, $configValue, $routeValue, false, false);

            $repairMs = $this->timeUntil(
                fn (): bool => $this->repairComplete(),
                'Stale deployment cache repair did not finish.',
            );
            $this->recordBudget($scenario, 'repair', $repairMs, self::REPAIR_HARD_LIMIT_MS);

            [$second, $secondMs] = $this->requestJsonTimed();
            $this->recordBudget($scenario, 'cached_response', $secondMs, self::REQUEST_HARD_LIMIT_MS);
            $this->assertResponse($second, $configValue, $routeValue, true, true);
            $this->assertDefaultCachePaths($second);
            $this->assertHealthyRepairState();
        });
    }

    private function scenarioCustomConfigCache(): void
    {
        $scenario = 'custom-config-cache';
        $this->info('Scenario '.$scenario);
        $customRelative = 'storage/framework/e2e/custom-config.php';
        $customAbsolute = $this->applicationPath.'/'.$customRelative;
        self::ensureDirectory(dirname($customAbsolute));

        $this->runArtisan(['config:clear']);
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'custom-cache-baseline');
        $this->runArtisan(['config:cache'], ['APP_CONFIG_CACHE' => $customRelative]);
        $this->assertFileExists($customAbsolute);
        $this->removeSuccessMarkers();
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'custom-cache-refreshed-value-longer');

        $this->withServer(false, function () use ($scenario, $customRelative, $customAbsolute): void {
            [$first, $requestMs] = $this->requestJsonTimed();
            $this->recordBudget($scenario, 'first_response', $requestMs, self::REQUEST_HARD_LIMIT_MS);
            $this->assertResponse($first, 'custom-cache-refreshed-value-longer', 'restricted-refreshed-route', false, true);
            self::assert(
                self::comparablePath((string) ($first['config_path'] ?? '')) === self::comparablePath($customAbsolute),
                'Laravel did not use the custom APP_CONFIG_CACHE path.',
            );

            $repairMs = $this->timeUntil(
                fn (): bool => is_file($customAbsolute)
                    && ! is_file($this->cachePath.'/config-cache-refresh.pending')
                    && is_file($this->cachePath.'/config-cache-refresh.succeeded'),
                'Custom config cache repair did not finish.',
            );
            $this->recordBudget($scenario, 'repair', $repairMs, self::REPAIR_HARD_LIMIT_MS);

            [$second, $secondMs] = $this->requestJsonTimed();
            $this->recordBudget($scenario, 'cached_response', $secondMs, self::REQUEST_HARD_LIMIT_MS);
            $this->assertResponse($second, 'custom-cache-refreshed-value-longer', 'restricted-refreshed-route', true, true);
            self::assert(
                self::comparablePath((string) ($second['config_path'] ?? '')) === self::comparablePath($customAbsolute),
                'Laravel stopped using the custom APP_CONFIG_CACHE path after repair.',
            );
        }, ['APP_CONFIG_CACHE' => $customRelative]);
    }

    private function scenarioHealthyBurst(): void
    {
        $scenario = 'healthy-cached-burst';
        $this->info('Scenario '.$scenario);
        $durations = [];

        $this->withServer(false, function () use (&$durations): void {
            $this->requestJsonTimed();

            for ($i = 0; $i < self::HEALTHY_SAMPLE_COUNT; $i++) {
                [$response, $milliseconds] = $this->requestJsonTimed();
                $this->assertResponse(
                    $response,
                    'custom-cache-refreshed-value-longer',
                    'restricted-refreshed-route',
                    true,
                    true,
                );
                $durations[] = $milliseconds;
            }
        });

        sort($durations, SORT_NUMERIC);
        $p50 = self::percentile($durations, 0.50);
        $p95 = self::percentile($durations, 0.95);
        $this->recordBudget($scenario, 'p50', $p50, self::HEALTHY_P95_LIMIT_MS);
        $this->recordBudget($scenario, 'p95', $p95, self::HEALTHY_P95_LIMIT_MS);
    }

    private function repairComplete(): bool
    {
        return is_file($this->defaultConfigCachePath)
            && ! is_file($this->cachePath.'/config-cache-refresh.pending')
            && ! is_file($this->cachePath.'/route-cache-refresh.pending')
            && is_file($this->cachePath.'/config-cache-refresh.succeeded')
            && is_file($this->cachePath.'/route-cache-refresh.succeeded')
            && is_file($this->cachePath.'/config-source.signature')
            && is_file($this->cachePath.'/route-source.signature');
    }

    private function recordBudget(string $scenario, string $metric, float $milliseconds, float $limit): void
    {
        $this->performance[] = [
            'scenario' => $scenario,
            'metric' => $metric,
            'milliseconds' => round($milliseconds, 3),
            'limit_ms' => $limit,
        ];
        $this->info(sprintf('PERF %-30s %-18s %8.3f ms (limit %.0f ms)', $scenario, $metric, $milliseconds, $limit));
        self::assert(
            $milliseconds <= $limit,
            sprintf('%s %s took %.3f ms, above the %.0f ms hard limit.', $scenario, $metric, $milliseconds, $limit),
        );
    }

    private function writePerformanceResults(): void
    {
        $directory = dirname(__DIR__, 2).'/build/performance';
        self::ensureDirectory($directory);
        $path = $directory.'/e2e-laravel-'.$this->laravelMajor.'-php-'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.json';
        $payload = [
            'laravel' => $this->laravelMajor,
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'platform' => PHP_OS_FAMILY,
            'metrics' => $this->performance,
        ];
        $this->write($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /** @param callable(): void $callback @param array<string, string|false> $environment */
    private function withServer(bool $disableProcessControl, callable $callback, array $environment = []): void
    {
        $this->startServer($disableProcessControl, $environment);
        try {
            $callback();
        } finally {
            $this->stopServer();
        }
    }

    /** @param array<string, string|false> $environment */
    private function startServer(bool $disableProcessControl, array $environment = []): void
    {
        $this->stopServer();
        $port = self::availablePort();
        $router = $this->applicationPath.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $command = [PHP_BINARY];

        if ($disableProcessControl) {
            array_push($command, '-d', 'disable_functions=exec,proc_open,proc_get_status,proc_terminate,proc_close');
        }

        array_push($command, '-d', 'display_errors=1', '-S', '127.0.0.1:'.$port, $router);
        $this->server = new Process($command, $this->applicationPath.'/public', $this->guardEnvironment($environment));
        $this->server->setTimeout(null);
        $this->server->start();
        $this->serverUrl = 'http://127.0.0.1:'.$port.'/e2e';
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }

        if ($this->server->isRunning()) {
            $this->server->stop(3);
        }

        $this->server = null;
        $this->serverUrl = '';
    }

    /** @return array{0:array<string,mixed>,1:float} */
    private function requestJsonTimed(): array
    {
        self::assert($this->server !== null, 'The E2E HTTP server is not running.');
        $deadline = hrtime(true) + 5_000_000_000;
        $lastError = 'No HTTP response received.';

        while (hrtime(true) < $deadline) {
            $started = hrtime(true);
            $body = @file_get_contents($this->serverUrl, false, stream_context_create([
                'http' => ['ignore_errors' => true, 'timeout' => 2],
            ]));
            $milliseconds = (hrtime(true) - $started) / 1_000_000;

            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    return [$decoded, $milliseconds];
                }
                $lastError = 'Invalid JSON response: '.$body;
            } elseif ($this->server !== null && ! $this->server->isRunning()) {
                throw new RuntimeException('HTTP server stopped unexpectedly.'.$this->serverOutput());
            }

            usleep(50_000);
        }

        throw new RuntimeException($lastError."\n".$this->serverOutput());
    }

    /** @param callable(): bool $condition */
    private function timeUntil(callable $condition, string $failureMessage): float
    {
        $started = hrtime(true);
        $deadline = $started + (int) (self::REPAIR_HARD_LIMIT_MS * 1_000_000);

        while (hrtime(true) < $deadline) {
            if ($condition()) {
                return (hrtime(true) - $started) / 1_000_000;
            }
            usleep(20_000);
        }

        throw new RuntimeException($failureMessage."\n".$this->serverOutput());
    }

    /** @param array<string,mixed> $response */
    private function assertResponse(
        array $response,
        string $expectedConfig,
        string $expectedRoute,
        ?bool $configCached = null,
        ?bool $routesCached = null,
    ): void {
        self::assert(($response['config'] ?? null) === $expectedConfig, 'Unexpected config value.');
        self::assert(($response['route'] ?? null) === $expectedRoute, 'Unexpected route value.');

        if ($configCached !== null) {
            self::assert(($response['config_cached'] ?? null) === $configCached, 'Unexpected config cache state.');
        }
        if ($routesCached !== null) {
            self::assert(($response['routes_cached'] ?? null) === $routesCached, 'Unexpected route cache state.');
        }
    }

    /** @param array<string,mixed> $response */
    private function assertDefaultCachePaths(array $response): void
    {
        self::assert(
            self::comparablePath((string) ($response['config_path'] ?? '')) === self::comparablePath($this->defaultConfigCachePath),
            'Laravel did not use the expected config cache path.',
        );
        self::assert(
            str_starts_with(
                self::comparablePath((string) ($response['routes_path'] ?? '')),
                self::comparablePath($this->cachePath).'/routes-',
            ),
            'Laravel did not use the signature-based route cache path.',
        );
    }

    private function assertHealthyRepairState(): void
    {
        foreach ([
            'deployment-source.manifest.json',
            'config-source.signature',
            'config-cache-refresh.succeeded',
            'route-source.signature',
            'route-cache-refresh.succeeded',
        ] as $file) {
            $this->assertFileExists($this->cachePath.'/'.$file);
        }

        foreach ([
            'config-cache-refresh.pending',
            'config-cache-refresh.failed',
            'route-cache-refresh.pending',
            'route-cache-refresh.failed',
        ] as $file) {
            self::assert(! is_file($this->cachePath.'/'.$file), 'Unexpected repair marker '.$file.'.');
        }
    }

    private function clearGuardState(): void
    {
        foreach (glob($this->cachePath.'/*cache-refresh.*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (['config-source.signature', 'route-source.signature', 'deployment-source.manifest.json', 'deployment-cache-repair.lock'] as $file) {
            @unlink($this->cachePath.'/'.$file);
        }
        foreach (glob($this->cachePath.'/routes-*.php') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function removeSuccessMarkers(): void
    {
        @unlink($this->cachePath.'/config-cache-refresh.succeeded');
        @unlink($this->cachePath.'/route-cache-refresh.succeeded');
    }

    private function writeController(string $className, string $routeValue): void
    {
        $this->write(
            $this->applicationPath.'/app/Http/Controllers/'.$className.'.php',
            <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class {$className}
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'config' => config('e2e.value'),
            'route' => '{$routeValue}',
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'config_path' => str_replace('\\\\', '/', app()->getCachedConfigPath()),
            'routes_path' => str_replace('\\\\', '/', app()->getCachedRoutesPath()),
        ]);
    }
}
PHP,
        );
    }

    private function writeRoute(string $controller): void
    {
        $this->write(
            $this->applicationPath.'/routes/web.php',
            <<<PHP
<?php

use App\Http\Controllers\{$controller};
use Illuminate\Support\Facades\Route;

Route::get('/e2e', {$controller}::class);
PHP,
        );
    }

    private function setApplicationEnvironmentValue(string $name, string $value): void
    {
        $path = $this->applicationPath.'/.env';
        $this->write($path, $this->setEnvironmentValue($this->read($path), $name, $value));
    }

    private function setEnvironmentValue(string $contents, string $name, string $value): string
    {
        $line = $name.'='.$value;
        $updated = preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $line, $contents, 1, $count);
        self::assert(is_string($updated), 'Could not update '.$name.'.');
        return $count === 0 ? rtrim($contents).PHP_EOL.$line.PHP_EOL : $updated;
    }

    /** @param list<string> $arguments @param array<string,string|false> $environment */
    private function runArtisan(array $arguments, array $environment = []): string
    {
        return $this->runProcess(
            array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-interaction', '--no-ansi']),
            $this->applicationPath,
            $environment,
        );
    }

    /** @param list<string> $command @param array<string,string|false> $environment */
    private function runProcess(array $command, string $workingDirectory, array $environment = []): string
    {
        $process = new Process($command, $workingDirectory, $this->guardEnvironment($environment));
        $process->setTimeout(900);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(implode(' ', $command)." failed.\n".$process->getOutput().$process->getErrorOutput());
        }
        return $process->getOutput().$process->getErrorOutput();
    }

    /** @param array<string,string|false> $overrides @return array<string,string|false> */
    private function guardEnvironment(array $overrides = []): array
    {
        return array_merge([
            'APP_ENV' => 'production',
            'APP_CONFIG_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
        ], $overrides);
    }

    private function replaceOnce(string $search, string $replace, string $contents, string $description): string
    {
        self::assert(str_contains($contents, $search), 'Could not find '.$description.'.');
        return preg_replace('/'.preg_quote($search, '/').'/', addcslashes($replace, '\\$'), $contents, 1)
            ?? throw new RuntimeException('Could not replace '.$description.'.');
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        self::assert(is_string($contents), 'Could not read '.$path.'.');
        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        self::ensureDirectory(dirname($path));
        self::assert(file_put_contents($path, $contents) === strlen($contents), 'Could not write '.$path.'.');
    }

    private function assertFileExists(string $path): void
    {
        self::assert(is_file($path), 'Expected file does not exist: '.$path);
    }

    private function serverOutput(): string
    {
        return $this->server === null ? '' : $this->server->getOutput().$this->server->getErrorOutput();
    }

    private function info(string $message): void
    {
        fwrite(STDOUT, '[e2e laravel '.$this->laravelMajor.'] '.$message.PHP_EOL);
    }

    private static function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            self::assert(@mkdir($path, 0777, true) || is_dir($path), 'Could not create directory '.$path.'.');
        }
    }

    public static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($path);
    }

    private static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assert(is_resource($socket), 'Could not reserve HTTP port: '.$errorMessage.' ('.$errorCode.').');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assert(is_string($address), 'Could not determine reserved HTTP port.');
        $separator = strrchr($address, ':');
        self::assert(is_string($separator), 'Could not parse reserved HTTP port.');
        $port = (int) substr($separator, 1);
        self::assert($port > 0, 'Reserved HTTP port is invalid.');
        return $port;
    }

    /** @param list<float> $values */
    private static function percentile(array $values, float $percentile): float
    {
        self::assert($values !== [], 'Cannot calculate percentile from an empty sample.');
        $index = (int) ceil(count($values) * $percentile) - 1;
        return $values[max(0, min(count($values) - 1, $index))];
    }

    /** @return non-empty-list<string> */
    private static function composerCommand(): array
    {
        $configured = getenv('COMPOSER_BINARY');
        if (! is_string($configured) || $configured === '') {
            return ['composer'];
        }
        return str_ends_with(strtolower($configured), '.phar') ? [PHP_BINARY, $configured] : [$configured];
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function comparablePath(string $path): string
    {
        $resolved = realpath($path);
        $normalized = self::normalizePath(is_string($resolved) ? $resolved : $path);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}

/** @return array{laravel:list<string>,keep:bool,packageArchive:?string} */
function productionE2eOptions(array $arguments): array
{
    $policy = require dirname(__DIR__).'/Support/policy.php';
    $supported = array_map('strval', array_keys($policy['laravel']));
    $requested = getenv('LARAVEL_E2E_VERSION');
    $keep = false;
    $packageArchive = null;

    foreach ($arguments as $argument) {
        if ($argument === '--keep') {
            $keep = true;
        } elseif (str_starts_with($argument, '--laravel=')) {
            $requested = substr($argument, strlen('--laravel='));
        } elseif (str_starts_with($argument, '--package-archive=')) {
            $packageArchive = substr($argument, strlen('--package-archive='));
        }
    }

    $php = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $compatible = array_values(array_filter(
        $supported,
        static fn (string $version): bool => in_array($php, $policy['laravel'][$version]['php'], true),
    ));

    if ($requested === false || $requested === '' || $requested === 'all') {
        $versions = $compatible;
    } elseif (in_array($requested, $supported, true)) {
        $versions = [$requested];
    } else {
        throw new InvalidArgumentException('Use --laravel='.implode(', --laravel=', $supported).' or --laravel=all.');
    }

    foreach ($versions as $version) {
        if (! in_array($version, $compatible, true)) {
            throw new RuntimeException('Laravel '.$version.' E2E does not support PHP '.$php.'.');
        }
    }

    return ['laravel' => $versions, 'keep' => $keep, 'packageArchive' => $packageArchive];
}

/** @return array{path:string,temporaryPath:string} */
function extractProductionE2ePackageArchive(string $archive): array
{
    $archivePath = realpath($archive);
    if (! is_string($archivePath) || ! is_file($archivePath)) {
        throw new InvalidArgumentException('Package archive does not exist: '.$archive);
    }
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZIP extension is required for release archive E2E.');
    }

    $temporaryPath = rtrim(sys_get_temp_dir(), '/\\').'/laravel-config-cache-guard-artifact-'.bin2hex(random_bytes(5));
    if (! mkdir($temporaryPath, 0700, true) && ! is_dir($temporaryPath)) {
        throw new RuntimeException('Could not create package extraction directory.');
    }

    $zip = new ZipArchive;
    if ($zip->open($archivePath) !== true) {
        LaravelConfigCacheGuardProductionE2e::removeDirectory($temporaryPath);
        throw new RuntimeException('Could not open package archive.');
    }
    try {
        if (! $zip->extractTo($temporaryPath)) {
            throw new RuntimeException('Could not extract package archive.');
        }
    } finally {
        $zip->close();
    }

    if (is_file($temporaryPath.'/composer.json')) {
        return ['path' => $temporaryPath, 'temporaryPath' => $temporaryPath];
    }

    $composerFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporaryPath, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'composer.json') {
            $composerFiles[] = $file->getPathname();
        }
    }
    if (count($composerFiles) !== 1) {
        LaravelConfigCacheGuardProductionE2e::removeDirectory($temporaryPath);
        throw new RuntimeException('Package archive must contain exactly one composer.json.');
    }

    return ['path' => dirname($composerFiles[0]), 'temporaryPath' => $temporaryPath];
}

$repositoryPath = realpath(dirname(__DIR__, 2));
if (! is_string($repositoryPath)) {
    fwrite(STDERR, "Could not resolve package repository path.\n");
    exit(1);
}

$packageExtractionPath = null;
$exitCode = 0;

try {
    $options = productionE2eOptions(array_slice($argv, 1));
    if ($options['packageArchive'] !== null) {
        $extracted = extractProductionE2ePackageArchive($options['packageArchive']);
        $repositoryPath = $extracted['path'];
        $packageExtractionPath = $extracted['temporaryPath'];
    }

    foreach ($options['laravel'] as $laravelMajor) {
        $temporaryPath = rtrim(sys_get_temp_dir(), '/\\').'/laravel-config-cache-guard-e2e-'.$laravelMajor.'-'.bin2hex(random_bytes(5));
        $runner = new LaravelConfigCacheGuardProductionE2e(
            $repositoryPath,
            $laravelMajor,
            $temporaryPath,
            $options['keep'],
            $options['packageArchive'] !== null,
        );
        try {
            $runner->run();
        } finally {
            $runner->cleanup();
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[e2e] FAILED: '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_string($packageExtractionPath)) {
        LaravelConfigCacheGuardProductionE2e::removeDirectory($packageExtractionPath);
    }
}

if ($exitCode !== 0) {
    exit($exitCode);
}

fwrite(STDOUT, '[e2e] All requested production E2E scenarios passed.'.PHP_EOL);
