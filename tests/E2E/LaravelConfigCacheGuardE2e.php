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

final class LaravelConfigCacheGuardE2e
{
    private const PACKAGE_NAME = 'codegenie-be/laravel-config-cache-guard';

    private readonly string $applicationPath;

    private readonly string $bootstrapPath;

    private readonly string $cachePath;

    private readonly string $defaultConfigCachePath;

    private ?Process $server = null;

    private string $serverUrl = '';

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

        $this->info('Testing zero-config automatic cache creation');
        $this->testAutomaticCacheCreation();

        $this->info('Testing non-blocking stale cache repair');
        $this->testNonBlockingStaleRepair(false, 'fast', 'E2eFastController', 'fast-refreshed-route');

        $this->info('Testing non-blocking repair with process functions disabled');
        $this->testNonBlockingStaleRepair(true, 'restricted', 'E2eRestrictedController', 'restricted-refreshed-route');

        $this->info('Testing a custom APP_CONFIG_CACHE path');
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
        $this->write(
            $this->bootstrapPath.'/providers.php',
            $this->read($this->applicationPath.'/bootstrap/providers.php'),
        );

        $publicIndexPath = $this->applicationPath.'/public/index.php';
        $this->write(
            $publicIndexPath,
            $this->replaceOnce(
                "__DIR__.'/../bootstrap/app.php'",
                "__DIR__.'/../.laravel/app.php'",
                $this->read($publicIndexPath),
                'public bootstrap path',
            ),
        );

        $artisanPath = $this->applicationPath.'/artisan';
        $this->write(
            $artisanPath,
            $this->replaceOnce(
                "__DIR__.'/bootstrap/app.php'",
                "__DIR__.'/.laravel/app.php'",
                $this->read($artisanPath),
                'Artisan bootstrap path',
            ),
        );
    }

    private function writeApplicationFixture(): void
    {
        $environment = $this->read($this->applicationPath.'/.env.example');
        $environment = $this->setEnvironmentValue($environment, 'APP_ENV', 'testing');
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
        $this->writeController('E2eFastController', 'fast-refreshed-route');
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
        $installedGuardPath = $this->applicationPath.'/vendor/'.self::PACKAGE_NAME.'/bootstrap/guard.php';
        $autoloadFilesPath = $this->applicationPath.'/vendor/composer/autoload_files.php';
        $this->assertFileExists($installedGuardPath);
        self::assert(! is_link(dirname($installedGuardPath, 2)), 'Composer symlinked the package instead of copying it.');
        self::assert(
            hash_file('sha256', $installedGuardPath) === hash_file('sha256', $this->repositoryPath.'/bootstrap/guard.php'),
            'The installed guard does not match the package source.',
        );
        self::assert(
            str_contains($this->read($autoloadFilesPath), self::PACKAGE_NAME.'/bootstrap/guard.php'),
            'Composer did not register bootstrap/guard.php in autoload_files.php.',
        );
        $status = $this->runArtisan(['config-cache-guard:status']);
        self::assert(str_contains($status, 'Composer autoload integration'), 'Status command is not registered.');
        self::assert(str_contains($status, 'Deployment source manifest'), 'Status does not expose manifest state.');
    }

    private function testAutomaticCacheCreation(): void
    {
        $this->runArtisan(['config:clear']);
        $this->runArtisan(['route:clear']);
        $this->clearGuardState();

        $this->withServer(false, function (): void {
            $first = $this->requestJson();
            $this->assertResponse($first, 'initial-config', 'initial-route', false, false);
            $this->waitForRepair('Automatic cache creation did not complete after the response.');

            $second = $this->requestJson();
            $this->assertResponse($second, 'initial-config', 'initial-route', true, true);
            $this->assertDefaultCachePaths($second);
            $this->assertHealthyRepairState();
        });
    }

    private function testNonBlockingStaleRepair(
        bool $disableProcessControl,
        string $configSuffix,
        string $controller,
        string $routeValue,
    ): void {
        $this->removeSuccessMarkers();
        $configValue = $configSuffix.'-refreshed-config-value-longer';
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', $configValue);
        $this->writeRoute($controller);

        $this->withServer($disableProcessControl, function () use ($configValue, $routeValue): void {
            $first = $this->requestJson();
            $this->assertResponse($first, $configValue, $routeValue, false, false);
            self::assert(
                ! is_file($this->defaultConfigCachePath),
                'Known-stale config cache remained available to Laravel during the stale request.',
            );

            $this->waitForRepair('Stale deployment cache repair did not finish after the response.');

            $second = $this->requestJson();
            $this->assertResponse($second, $configValue, $routeValue, true, true);
            $this->assertDefaultCachePaths($second);
            $this->assertHealthyRepairState();
        });
    }

    private function testCustomConfigCachePath(): void
    {
        $customRelativePath = 'storage/framework/e2e/custom-config.php';
        $customAbsolutePath = $this->applicationPath.'/'.$customRelativePath;
        self::ensureDirectory(dirname($customAbsolutePath));

        $this->runArtisan(['config:clear']);
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'custom-cache-baseline');
        $this->runArtisan(['config:cache'], ['APP_CONFIG_CACHE' => $customRelativePath]);
        $this->assertFileExists($customAbsolutePath);
        self::assert(! is_file($this->defaultConfigCachePath), 'Laravel unexpectedly kept the default config cache.');
        $this->removeSuccessMarkers();
        $this->setApplicationEnvironmentValue('E2E_CONFIG_VALUE', 'custom-cache-refreshed-value-longer');

        $this->withServer(false, function () use ($customAbsolutePath): void {
            $first = $this->requestJson();
            $this->assertResponse(
                $first,
                'custom-cache-refreshed-value-longer',
                'restricted-refreshed-route',
                false,
                true,
            );
            self::assert(
                self::comparablePath((string) ($first['config_path'] ?? '')) === self::comparablePath($customAbsolutePath),
                'Laravel did not use the custom APP_CONFIG_CACHE path.',
            );
            self::assert(! is_file($this->defaultConfigCachePath), 'The guard created the default config cache unexpectedly.');

            $this->waitUntil(
                fn (): bool => is_file($customAbsolutePath)
                    && ! is_file($this->cachePath.'/config-cache-refresh.pending')
                    && is_file($this->cachePath.'/config-cache-refresh.succeeded'),
                'Custom config cache repair did not finish after the response.',
            );

            $second = $this->requestJson();
            $this->assertResponse(
                $second,
                'custom-cache-refreshed-value-longer',
                'restricted-refreshed-route',
                true,
                true,
            );
            self::assert(
                self::comparablePath((string) ($second['config_path'] ?? '')) === self::comparablePath($customAbsolutePath),
                'Laravel stopped using the custom config cache after repair.',
            );
            $this->assertFileExists($this->cachePath.'/config-source.signature');
        }, [
            'APP_CONFIG_CACHE' => $customRelativePath,
            'CONFIG_CACHE_GUARD_ROUTES' => 'false',
        ]);
    }

    private function waitForRepair(string $failureMessage): void
    {
        $this->waitUntil(
            fn (): bool => is_file($this->defaultConfigCachePath)
                && ! is_file($this->cachePath.'/config-cache-refresh.pending')
                && ! is_file($this->cachePath.'/route-cache-refresh.pending')
                && is_file($this->cachePath.'/config-cache-refresh.succeeded')
                && is_file($this->cachePath.'/route-cache-refresh.succeeded')
                && is_file($this->cachePath.'/config-source.signature')
                && is_file($this->cachePath.'/route-source.signature'),
            $failureMessage,
        );
    }

    /** @param callable(): void $callback */
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
    private function startServer(bool $disableProcessControl, array $environment): void
    {
        $this->stopServer();
        $port = self::availablePort();
        $routerPath = $this->applicationPath.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $command = [PHP_BINARY];

        if ($disableProcessControl) {
            array_push(
                $command,
                '-d',
                'disable_functions=exec,proc_open,proc_get_status,proc_terminate,proc_close',
            );
        }

        array_push($command, '-d', 'display_errors=1', '-S', '127.0.0.1:'.$port, $routerPath);
        $this->server = new Process(
            $command,
            $this->applicationPath.'/public',
            $this->guardEnvironment($environment),
        );
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

    /** @return array<string, mixed> */
    private function requestJson(): array
    {
        self::assert($this->server !== null, 'The E2E HTTP server is not running.');
        $deadline = microtime(true) + 40;
        $lastError = 'No HTTP response received.';

        while (microtime(true) < $deadline) {
            $context = stream_context_create([
                'http' => [
                    'ignore_errors' => true,
                    'timeout' => 5,
                ],
            ]);
            $body = @file_get_contents($this->serverUrl, false, $context);

            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);

                if (is_array($decoded)) {
                    return $decoded;
                }

                $lastError = 'Invalid JSON response: '.$body;
            } elseif ($this->server !== null && ! $this->server->isRunning()) {
                throw new RuntimeException('HTTP server stopped unexpectedly.'.$this->serverOutput());
            }

            usleep(100_000);
        }

        throw new RuntimeException($lastError."\n".$this->serverOutput());
    }

    /** @param array<string, mixed> $response */
    private function assertResponse(
        array $response,
        string $expectedConfig,
        string $expectedRoute,
        ?bool $configCached = null,
        ?bool $routesCached = null,
    ): void {
        self::assert(
            ($response['config'] ?? null) === $expectedConfig,
            'Expected config '.$expectedConfig.', received '.json_encode($response['config'] ?? null).'.',
        );
        self::assert(
            ($response['route'] ?? null) === $expectedRoute,
            'Expected route '.$expectedRoute.', received '.json_encode($response['route'] ?? null).'.',
        );

        if ($configCached !== null) {
            self::assert(
                ($response['config_cached'] ?? null) === $configCached,
                'The config cache state was not '.($configCached ? 'cached' : 'uncached').'.',
            );
        }

        if ($routesCached !== null) {
            self::assert(
                ($response['routes_cached'] ?? null) === $routesCached,
                'The route cache state was not '.($routesCached ? 'cached' : 'uncached').'.',
            );
        }
    }

    /** @param array<string, mixed> $response */
    private function assertDefaultCachePaths(array $response): void
    {
        self::assert(
            self::comparablePath((string) ($response['config_path'] ?? ''))
                === self::comparablePath($this->defaultConfigCachePath),
            'Laravel did not use the expected config cache path.',
        );
        self::assert(
            str_starts_with(
                self::comparablePath((string) ($response['routes_path'] ?? '')),
                self::comparablePath($this->cachePath).'/routes-',
            ),
            'Laravel did not use the guard-managed versioned route cache path.',
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
            self::assert(! is_file($this->cachePath.'/'.$file), 'Unexpected repair marker: '.$file);
        }
    }

    private function clearGuardState(): void
    {
        foreach (glob($this->cachePath.'/*cache-refresh.*') ?: [] as $file) {
            @unlink($file);
        }

        foreach ([
            'config-source.signature',
            'route-source.signature',
            'deployment-source.manifest.json',
            'deployment-cache-repair.lock',
        ] as $file) {
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
        $replacement = $name.'='.$value;
        $updated = preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $replacement, $contents, 1, $count);
        self::assert(is_string($updated), 'Could not update '.$name.' in the test environment.');

        return $count === 0
            ? rtrim($contents).PHP_EOL.$replacement.PHP_EOL
            : $updated;
    }

    /** @param list<string> $arguments @param array<string, string|false> $environment */
    private function runArtisan(array $arguments, array $environment = []): string
    {
        return $this->runProcess(
            array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-interaction', '--no-ansi']),
            $this->applicationPath,
            $environment,
        );
    }

    /** @param list<string> $command @param array<string, string|false> $environment */
    private function runProcess(array $command, string $workingDirectory, array $environment = []): string
    {
        $process = new Process($command, $workingDirectory, $this->guardEnvironment($environment));
        $process->setTimeout(900);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                implode(' ', $command)." failed.\n".$process->getOutput().$process->getErrorOutput(),
            );
        }

        return $process->getOutput().$process->getErrorOutput();
    }

    /** @param array<string, string|false> $overrides @return array<string, string|false> */
    private function guardEnvironment(array $overrides = []): array
    {
        return array_merge([
            'APP_CONFIG_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
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
        self::assert(is_resource($socket), 'Could not reserve an HTTP port: '.$errorMessage.' ('.$errorCode.').');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assert(is_string($address), 'Could not determine the reserved HTTP port.');
        $separator = strrchr($address, ':');
        self::assert(is_string($separator), 'Could not parse the reserved HTTP port.');
        $port = (int) substr($separator, 1);
        self::assert($port > 0, 'The reserved HTTP port is invalid.');

        return $port;
    }

    /** @return non-empty-list<string> */
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
        $resolved = realpath($path);
        $normalized = self::normalizePath(is_string($resolved) ? $resolved : $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}

/** @return array{laravel: list<string>, keep: bool, packageArchive: ?string} */
function e2eOptions(array $arguments): array
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

    $currentPhp = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $compatible = array_values(array_filter(
        $supported,
        static fn (string $version): bool => in_array($currentPhp, $policy['laravel'][$version]['php'], true),
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
            throw new RuntimeException('Laravel '.$version.' E2E does not support PHP '.$currentPhp.'.');
        }
    }

    return ['laravel' => $versions, 'keep' => $keep, 'packageArchive' => $packageArchive];
}

/** @return array{path: string, temporaryPath: string} */
function extractE2ePackageArchive(string $archive): array
{
    $archivePath = realpath($archive);

    if (! is_string($archivePath) || ! is_file($archivePath)) {
        throw new InvalidArgumentException('Package archive does not exist: '.$archive);
    }

    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('The ZIP extension is required to test a release package archive.');
    }

    $temporaryPath = rtrim(sys_get_temp_dir(), '/\\').'/laravel-config-cache-guard-artifact-'.bin2hex(random_bytes(5));
    assertDirectoryCreated($temporaryPath);
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
        new RecursiveDirectoryIterator($temporaryPath, FilesystemIterator::SKIP_DOTS),
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

function assertDirectoryCreated(string $path): void
{
    if (! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException('Could not create directory '.$path.'.');
    }
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
        $extracted = extractE2ePackageArchive($options['packageArchive']);
        $repositoryPath = $extracted['path'];
        $packageExtractionPath = $extracted['temporaryPath'];
    }

    foreach ($options['laravel'] as $laravelMajor) {
        $temporaryPath = rtrim(sys_get_temp_dir(), '/\\')
            .'/laravel-config-cache-guard-e2e-'.$laravelMajor.'-'.bin2hex(random_bytes(5));
        $runner = new LaravelConfigCacheGuardE2e(
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
        LaravelConfigCacheGuardE2e::removeDirectory($packageExtractionPath);
    }
}

if ($exitCode !== 0) {
    exit($exitCode);
}

fwrite(STDOUT, '[e2e] All requested Laravel end-to-end scenarios passed.'.PHP_EOL);
