<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Tests\E2E;

use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class LaravelConfigCacheGuardE2e
{
    private const PACKAGE_NAME = 'codegenie-be/laravel-config-cache-guard';

    private readonly string $applicationPath;

    private readonly string $bootstrapPath;

    private readonly string $cachePath;

    private readonly string $defaultConfigCachePath;

    private ?Process $server = null;

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
            $this->info('Activating a real .laravel bootstrap path');
            $this->activateDotLaravelBootstrapPath();
        }

        $this->writeApplicationFixture();

        $this->info($this->artifactPackage
            ? 'Installing the package from the extracted release artifact'
            : 'Installing the package through a copied Composer path repository');
        $this->installPackage();
        $this->assertComposerInstallation();

        $this->info('Building real Laravel config and route caches');
        $this->runArtisan(['config:cache']);
        $this->runArtisan(['route:cache']);
        $this->assertFileExists($this->defaultConfigCachePath);
        $this->assertRouteCacheExists();

        $this->info('Testing pre-bootstrap repair through the PHP CLI');
        $this->testExecRepair();

        $this->info('Testing deferred in-app repair with process control disabled');
        $this->testDeferredRepair();

        $this->info('Testing an externally configured APP_CONFIG_CACHE path');
        $this->testCustomConfigCachePath();

        $this->info('Laravel '.$this->laravelMajor.' end-to-end scenarios passed');
    }

    public function cleanup(): void
    {
        $this->stopServer();

        if ($this->keepApplication) {
            $this->info('Keeping the test application at '.$this->applicationPath);

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
            'Laravel bootstrap application assignment'
        );

        $updatedAppContents = preg_replace(
            '/->create\(\);\s*$/',
            "->create();\n\n\$app->useBootstrapPath(__DIR__);\n\nreturn \$app;\n",
            $appContents,
            1,
            $replacementCount
        );

        self::assert(
            is_string($updatedAppContents) && $replacementCount === 1,
            'Could not configure the Laravel application to use .laravel as its bootstrap path.'
        );

        $this->write($this->bootstrapPath.'/app.php', $updatedAppContents);
        $this->write(
            $this->bootstrapPath.'/providers.php',
            $this->read($this->applicationPath.'/bootstrap/providers.php')
        );

        $publicIndexPath = $this->applicationPath.'/public/index.php';
        $this->write(
            $publicIndexPath,
            $this->replaceOnce(
                "__DIR__.'/../bootstrap/app.php'",
                "__DIR__.'/../.laravel/app.php'",
                $this->read($publicIndexPath),
                'public bootstrap path'
            )
        );

        $artisanPath = $this->applicationPath.'/artisan';
        $this->write(
            $artisanPath,
            $this->replaceOnce(
                "__DIR__.'/bootstrap/app.php'",
                "__DIR__.'/.laravel/app.php'",
                $this->read($artisanPath),
                'Artisan bootstrap path'
            )
        );
    }

    private function writeApplicationFixture(): void
    {
        $environmentPath = $this->applicationPath.'/.env';
        $environment = $this->read($this->applicationPath.'/.env.example');
        $environment = $this->setEnvironmentValue($environment, 'APP_ENV', 'testing');
        $environment = $this->setEnvironmentValue(
            $environment,
            'APP_KEY',
            'base64:'.base64_encode('config-cache-guard-e2e-key-0001x')
        );
        $environment = $this->setEnvironmentValue($environment, 'APP_DEBUG', 'false');
        $environment = $this->setEnvironmentValue($environment, 'CACHE_STORE', 'array');
        $environment = $this->setEnvironmentValue($environment, 'LOG_CHANNEL', 'stderr');
        $environment = $this->setEnvironmentValue($environment, 'SESSION_DRIVER', 'array');
        $environment = $this->setEnvironmentValue($environment, 'E2E_CONFIG_VALUE', 'initial-config');
        $this->write($environmentPath, $environment);

        $this->write(
            $this->applicationPath.'/config/e2e.php',
            <<<'PHP'
<?php

return [
    'value' => env('E2E_CONFIG_VALUE', 'missing'),
];
PHP
        );

        $this->writeController('E2eInitialController', 'initial-route');
        $this->writeController('E2eExecController', 'exec-refreshed-route');
        $this->writeController('E2eDeferredController', 'deferred-refreshed-route');
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
                    'versions' => [
                        self::PACKAGE_NAME => 'dev-e2e',
                    ],
                ],
            ],
        ];
        $composer['require'][self::PACKAGE_NAME] = 'dev-e2e';

        $encodedComposer = json_encode(
            $composer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $this->write($composerPath, $encodedComposer.PHP_EOL);

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
        $installedGuardPath = $this->applicationPath.'/vendor/'.self::PACKAGE_NAME.'/bootstrap/guard.php';
        $autoloadFilesPath = $this->applicationPath.'/vendor/composer/autoload_files.php';

        $this->assertFileExists($installedGuardPath);
        self::assert(! is_link(dirname($installedGuardPath, 2)), 'Composer symlinked the package instead of copying it.');
        self::assert(
            hash_file('sha256', $installedGuardPath) === hash_file('sha256', $this->repositoryPath.'/bootstrap/guard.php'),
            'The installed guard does not match the checked-out package source.'
        );
        self::assert(
            str_contains($this->read($autoloadFilesPath), self::PACKAGE_NAME.'/bootstrap/guard.php'),
            'Composer did not register bootstrap/guard.php in autoload_files.php.'
        );

        $status = $this->runArtisan(['config-cache-guard:status']);
        self::assert(
            str_contains($status, 'Composer autoload integration'),
            'The installed package did not register its status command.'
        );
        self::assert(
            str_contains($status, 'Active Laravel cache path'),
            'The status command did not report the active bootstrap cache path field.'
        );
    }

    private function testExecRepair(): void
    {
        $this->withServer(false, function (): void {
            $initial = $this->requestJson();
            $this->assertResponse($initial, 'initial-config', 'initial-route', true, true);
            $this->assertDefaultCachePaths($initial);
            $this->assertRepairMarkers();

            $this->removeSuccessMarkers();
            $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'exec-refreshed-config-value');
            $this->writeRoute('E2eExecController');

            $refreshed = $this->requestJson();
            $this->assertResponse(
                $refreshed,
                'exec-refreshed-config-value',
                'exec-refreshed-route',
                true,
                true
            );
            $this->assertDefaultCachePaths($refreshed);
            $this->assertRepairMarkers();
        });
    }

    private function testDeferredRepair(): void
    {
        $this->removeSuccessMarkers();
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'deferred-refreshed-config-value');
        $this->writeRoute('E2eDeferredController');

        $this->withServer(true, function (): void {
            $uncached = $this->requestJson();
            $this->assertResponse(
                $uncached,
                'deferred-refreshed-config-value',
                'deferred-refreshed-route'
            );

            $this->waitUntil(
                fn (): bool => is_file($this->defaultConfigCachePath)
                    && ! is_file($this->cachePath.'/config-cache-refresh.pending')
                    && ! is_file($this->cachePath.'/route-cache-refresh.pending')
                    && is_file($this->cachePath.'/config-cache-refresh.succeeded')
                    && is_file($this->cachePath.'/route-cache-refresh.succeeded'),
                'Deferred repair did not finish after the HTTP response.'
            );

            $cached = $this->requestJson();
            $this->assertResponse(
                $cached,
                'deferred-refreshed-config-value',
                'deferred-refreshed-route',
                true,
                true
            );
            $this->assertDefaultCachePaths($cached);
            $this->assertRepairMarkers();
        });
    }

    private function testCustomConfigCachePath(): void
    {
        $customRelativePath = 'storage/framework/e2e/custom-config.php';
        $customAbsolutePath = $this->applicationPath.'/'.$customRelativePath;
        self::ensureDirectory(dirname($customAbsolutePath));

        $this->runArtisan(['config:clear']);
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'custom-cache-baseline');
        $this->runArtisan(['config:cache'], [
            'APP_CONFIG_CACHE' => $customRelativePath,
        ]);

        $this->assertFileExists($customAbsolutePath);
        self::assert(! is_file($this->defaultConfigCachePath), 'Laravel unexpectedly kept the default config cache.');

        $this->removeSuccessMarkers();
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'custom-cache-refreshed-value');

        $this->withServer(false, function () use ($customAbsolutePath): void {
            $response = $this->requestJson();
            $this->assertResponse(
                $response,
                'custom-cache-refreshed-value',
                'deferred-refreshed-route',
                true
            );
            self::assert(
                self::comparablePath((string) $response['config_path']) === self::comparablePath($customAbsolutePath),
                'Laravel did not use the externally configured APP_CONFIG_CACHE path.'
            );
            self::assert(! is_file($this->defaultConfigCachePath), 'The guard rebuilt the wrong config cache file.');
            $this->assertFileExists($customAbsolutePath);
            $this->assertFileExists($this->cachePath.'/config-source.signature');
            $this->assertFileExists($this->cachePath.'/config-cache-refresh.succeeded');
        }, [
            'APP_CONFIG_CACHE' => $customRelativePath,
            'CONFIG_CACHE_GUARD_ROUTES' => 'false',
        ]);
    }

    /**
     * @param  callable(): void  $callback
     * @param  array<string, string|false>  $environment
     */
    private function withServer(bool $disableProcessControl, callable $callback, array $environment = []): void
    {
        $this->startServer($disableProcessControl, $environment);

        try {
            $callback();
        } finally {
            $this->stopServer();
        }
    }

    /**
     * @param  array<string, string|false>  $environment
     */
    private function startServer(bool $disableProcessControl, array $environment): void
    {
        $this->stopServer();

        $port = self::availablePort();
        $routerPath = $this->applicationPath.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $command = [PHP_BINARY];

        if ($disableProcessControl) {
            $command[] = '-d';
            $command[] = 'disable_functions=exec,proc_open,proc_get_status,proc_terminate,proc_close';
        }

        array_push(
            $command,
            '-d',
            'display_errors=1',
            '-S',
            '127.0.0.1:'.$port,
            $routerPath
        );

        $this->server = new Process(
            $command,
            $this->applicationPath.'/public',
            $this->guardEnvironment($environment)
        );
        $this->server->setTimeout(null);
        $this->server->start();
        $this->serverUrl = 'http://127.0.0.1:'.$port.'/e2e';
    }

    private string $serverUrl = '';

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

    /**
     * @return array<string, mixed>
     */
    private function requestJson(): array
    {
        self::assert($this->server !== null, 'The E2E HTTP server is not running.');

        $deadline = microtime(true) + 40;
        $lastError = 'No HTTP response was received.';

        while (microtime(true) < $deadline) {
            if (! $this->server->isRunning()) {
                throw new RuntimeException(
                    "The E2E HTTP server stopped unexpectedly.\n".$this->serverOutput()
                );
            }

            $responseHeaders = [];
            set_error_handler(static function (int $severity, string $message) use (&$lastError): bool {
                $lastError = $message;

                return true;
            });

            try {
                $body = file_get_contents($this->serverUrl, false, stream_context_create([
                    'http' => [
                        'ignore_errors' => true,
                        'timeout' => 20,
                    ],
                ]));
                $responseHeaders = $http_response_header ?? [];
            } finally {
                restore_error_handler();
            }

            if ($body === false || $responseHeaders === []) {
                usleep(200_000);

                continue;
            }

            preg_match('/\s(\d{3})\s/', $responseHeaders[0], $statusMatch);
            $status = isset($statusMatch[1]) ? (int) $statusMatch[1] : 0;

            if ($status !== 200) {
                throw new RuntimeException(
                    'The E2E request returned HTTP '.$status.'. Body: '.$body."\n".$this->serverOutput()
                );
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    'The E2E response is not valid JSON: '.$body."\n".$this->serverOutput(),
                    previous: $exception
                );
            }

            self::assert(is_array($decoded), 'The E2E JSON response is not an object.');

            return $decoded;
        }

        throw new RuntimeException($lastError."\n".$this->serverOutput());
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertResponse(
        array $response,
        string $expectedConfig,
        string $expectedRoute,
        ?bool $configCached = null,
        ?bool $routesCached = null,
    ): void {
        self::assert(
            ($response['config'] ?? null) === $expectedConfig,
            'Expected config value '.$expectedConfig.', received '.json_encode($response['config'] ?? null).'.'
        );
        self::assert(
            ($response['route'] ?? null) === $expectedRoute,
            'Expected route value '.$expectedRoute.', received '.json_encode($response['route'] ?? null).'.'
        );

        if ($configCached !== null) {
            self::assert(
                ($response['config_cached'] ?? null) === $configCached,
                'The config cache state was not '.($configCached ? 'cached' : 'uncached').'.'
            );
        }

        if ($routesCached !== null) {
            self::assert(
                ($response['routes_cached'] ?? null) === $routesCached,
                'The route cache state was not '.($routesCached ? 'cached' : 'uncached').'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertDefaultCachePaths(array $response): void
    {
        $actualConfigPath = self::comparablePath((string) ($response['config_path'] ?? ''));
        $expectedConfigPath = self::comparablePath($this->defaultConfigCachePath);
        self::assert(
            $actualConfigPath === $expectedConfigPath,
            'Laravel did not use the expected config cache path. Expected '
                .$expectedConfigPath.', got '.$actualConfigPath.'.'
        );
        self::assert(
            str_starts_with(
                self::comparablePath((string) ($response['routes_path'] ?? '')),
                self::comparablePath($this->cachePath).'/routes-'
            ),
            'Laravel did not use the guard-managed versioned route cache path.'
        );
    }

    private function assertRepairMarkers(): void
    {
        foreach ([
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
            self::assert(! is_file($this->cachePath.'/'.$file), 'Unexpected repair marker: '.$file);
        }
    }

    private function removeSuccessMarkers(): void
    {
        @unlink($this->cachePath.'/config-cache-refresh.succeeded');
        @unlink($this->cachePath.'/route-cache-refresh.succeeded');
    }

    private function assertRouteCacheExists(): void
    {
        self::assert(
            (glob($this->cachePath.'/routes-*.php') ?: []) !== [],
            'Laravel did not create a route cache file.'
        );
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
PHP
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
PHP
        );
    }

    private function setApplicationEnvironmentValue(string $name, string $value): void
    {
        $path = $this->applicationPath.'/.env';
        $this->write($path, $this->setEnvironmentValue($this->read($path), $name, $value));
    }

    private function setEnvironmentValue(string $contents, string $name, string $value): string
    {
        $replacement = $name.'='.$value;
        $updated = preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $replacement, $contents, 1, $count);

        self::assert(is_string($updated), 'Could not update '.$name.' in the test environment.');

        if ($count === 0) {
            return rtrim($contents).PHP_EOL.$replacement.PHP_EOL;
        }

        return $updated;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function runArtisan(array $arguments, array $environment = []): string
    {
        return $this->runProcess(
            array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-interaction', '--no-ansi']),
            $this->applicationPath,
            $environment
        );
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $environment
     */
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

    /**
     * @param  array<string, string|false>  $overrides
     * @return array<string, string|false>
     */
    private function guardEnvironment(array $overrides = []): array
    {
        return array_merge([
            'APP_CONFIG_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
            'CONFIG_CACHE_GUARD_ALLOW_CLI' => false,
            'CONFIG_CACHE_GUARD_AUTO_REPAIR' => 'true',
            'CONFIG_CACHE_GUARD_CONFIG' => 'true',
            'CONFIG_CACHE_GUARD_ENABLED' => 'true',
            'CONFIG_CACHE_GUARD_FAIL_HARD' => 'false',
            'CONFIG_CACHE_GUARD_MANAGED_APP_ROUTES_CACHE' => false,
            'CONFIG_CACHE_GUARD_PHP_BINARY' => PHP_BINARY,
            'CONFIG_CACHE_GUARD_ROUTES' => 'true',
            'CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE' => 'true',
            'COMPOSER_PROCESS_TIMEOUT' => '0',
            'PHP_CLI_BINARY' => PHP_BINARY,
        ], $overrides);
    }

    private function replaceOnce(string $search, string $replace, string $contents, string $description): string
    {
        self::assert(str_contains($contents, $search), 'Could not find the '.$description.' marker.');

        return preg_replace('/'.preg_quote($search, '/').'/', addcslashes($replace, '\\$'), $contents, 1)
            ?? throw new RuntimeException('Could not replace the '.$description.' marker.');
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
        $written = file_put_contents($path, $contents);

        self::assert($written === strlen($contents), 'Could not write '.$path.'.');
    }

    private function assertFileExists(string $path): void
    {
        self::assert(is_file($path), 'Expected file does not exist: '.$path);
    }

    private function waitUntil(callable $condition, string $failureMessage): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(200_000);
        }

        throw new RuntimeException($failureMessage."\n".$this->serverOutput());
    }

    private function serverOutput(): string
    {
        if ($this->server === null) {
            return '';
        }

        return $this->server->getOutput().$this->server->getErrorOutput();
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
        if (is_dir($path)) {
            return;
        }

        self::assert(@mkdir($path, 0777, true) || is_dir($path), 'Could not create directory '.$path.'.');
    }

    public static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
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

        self::assert(is_resource($socket), 'Could not reserve an HTTP port: '.$errorMessage.' ('.$errorCode.').');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        self::assert(is_string($address), 'Could not determine the reserved HTTP port.');
        $port = (int) substr(strrchr($address, ':'), 1);
        self::assert($port > 0, 'The reserved HTTP port is invalid.');

        return $port;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function composerCommand(): array
    {
        $configured = getenv('COMPOSER_BINARY');

        if (! is_string($configured) || $configured === '') {
            return ['composer'];
        }

        return str_ends_with(strtolower($configured), '.phar')
            ? [PHP_BINARY, $configured]
            : [$configured];
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function comparablePath(string $path): string
    {
        $resolvedPath = realpath($path);
        $normalized = self::normalizePath(is_string($resolvedPath) ? $resolvedPath : $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}

/**
 * @return array{laravel: list<string>, keep: bool, packageArchive: ?string}
 */
function e2eOptions(array $arguments): array
{
    $policy = require dirname(__DIR__).'/Support/policy.php';
    $supportedLaravelVersions = array_map('strval', array_keys($policy['laravel']));
    $requestedVersion = getenv('LARAVEL_E2E_VERSION');
    $keep = false;
    $packageArchive = null;

    foreach ($arguments as $argument) {
        if ($argument === '--keep') {
            $keep = true;
        } elseif (str_starts_with($argument, '--laravel=')) {
            $requestedVersion = substr($argument, strlen('--laravel='));
        } elseif (str_starts_with($argument, '--package-archive=')) {
            $packageArchive = substr($argument, strlen('--package-archive='));
        }
    }

    $currentPhpVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $compatibleLaravelVersions = array_values(array_filter(
        $supportedLaravelVersions,
        static fn (string $version): bool => in_array($currentPhpVersion, $policy['laravel'][$version]['php'], true)
    ));

    if ($requestedVersion === false || $requestedVersion === '' || $requestedVersion === 'all') {
        $versions = $compatibleLaravelVersions;
    } elseif (in_array($requestedVersion, $supportedLaravelVersions, true)) {
        $versions = [$requestedVersion];
    } else {
        throw new InvalidArgumentException(
            'Use --laravel='.implode(', --laravel=', $supportedLaravelVersions).' or --laravel=all.'
        );
    }

    foreach ($versions as $version) {
        if (! in_array($version, $compatibleLaravelVersions, true)) {
            throw new RuntimeException(
                'Laravel '.$version.' end-to-end testing does not support PHP '.$currentPhpVersion.'.'
            );
        }
    }

    return ['laravel' => $versions, 'keep' => $keep, 'packageArchive' => $packageArchive];
}

/**
 * @return array{path: string, temporaryPath: string}
 */
function extractE2ePackageArchive(string $archive): array
{
    $archivePath = realpath($archive);

    if (! is_string($archivePath) || ! is_file($archivePath)) {
        throw new InvalidArgumentException('Package archive does not exist: '.$archive);
    }

    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('The ZIP extension is required to test a release package archive.');
    }

    $temporaryPath = rtrim(sys_get_temp_dir(), '/\\')
        .'/laravel-config-cache-guard-artifact-'.bin2hex(random_bytes(5));

    if (! mkdir($temporaryPath, 0700, true) && ! is_dir($temporaryPath)) {
        throw new RuntimeException('Could not create the package artifact extraction directory.');
    }

    $zip = new ZipArchive;

    if ($zip->open($archivePath) !== true) {
        LaravelConfigCacheGuardE2e::removeDirectory($temporaryPath);

        throw new RuntimeException('Could not open the package archive: '.$archivePath);
    }

    try {
        if (! $zip->extractTo($temporaryPath)) {
            throw new RuntimeException('Could not extract the package archive: '.$archivePath);
        }
    } finally {
        $zip->close();
    }

    if (is_file($temporaryPath.'/composer.json')) {
        return ['path' => $temporaryPath, 'temporaryPath' => $temporaryPath];
    }

    $composerFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'composer.json') {
            $composerFiles[] = $file->getPathname();
        }
    }

    if (count($composerFiles) !== 1) {
        LaravelConfigCacheGuardE2e::removeDirectory($temporaryPath);

        throw new RuntimeException('The package archive must contain exactly one composer.json.');
    }

    return ['path' => dirname($composerFiles[0]), 'temporaryPath' => $temporaryPath];
}

$repositoryPath = realpath(dirname(__DIR__, 2));

if (! is_string($repositoryPath)) {
    fwrite(STDERR, "Could not resolve the package repository path.\n");
    exit(1);
}

$packageExtractionPath = null;
$exitCode = 0;

try {
    $options = e2eOptions(array_slice($argv, 1));

    if ($options['packageArchive'] !== null) {
        $extractedPackage = extractE2ePackageArchive($options['packageArchive']);
        $repositoryPath = $extractedPackage['path'];
        $packageExtractionPath = $extractedPackage['temporaryPath'];
    }

    foreach ($options['laravel'] as $laravelMajor) {
        $temporaryPath = rtrim(sys_get_temp_dir(), '/\\')
            .'/laravel-config-cache-guard-e2e-'.$laravelMajor.'-'.bin2hex(random_bytes(5));
        $runner = new LaravelConfigCacheGuardE2e(
            $repositoryPath,
            $laravelMajor,
            $temporaryPath,
            $options['keep'],
            $options['packageArchive'] !== null
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
        LaravelConfigCacheGuardE2e::removeDirectory($packageExtractionPath);
    }
}

if ($exitCode !== 0) {
    exit($exitCode);
}

fwrite(STDOUT, '[e2e] All requested Laravel end-to-end scenarios passed.'.PHP_EOL);
