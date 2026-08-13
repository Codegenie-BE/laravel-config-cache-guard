<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Console;

use Codegenie\ConfigCacheGuard\Support\ConfigCacheFile;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\Environment;
use Codegenie\ConfigCacheGuard\Support\FailureMarker;
use Codegenie\ConfigCacheGuard\Support\RouteCacheFiles;
use Codegenie\ConfigCacheGuard\Support\SuccessMarker;
use Illuminate\Console\Command;

final class StatusConfigCacheGuardCommand extends Command
{
    protected $signature = 'config-cache-guard:status {--clear-failures : Remove config and route failure and pending markers}';

    protected $description = 'Show the current Codegenie Laravel Config Cache Guard status.';

    public function handle(): int
    {
        $indexPath = public_path('index.php');
        $cachePath = app()->bootstrapPath('cache');
        $cachedConfigPath = ConfigCacheFile::current(base_path(), $cachePath);
        $configSignaturePath = $cachePath.'/config-source.signature';
        $configFailedPath = $cachePath.'/config-cache-refresh.failed';
        $configPendingPath = $cachePath.'/config-cache-refresh.pending';
        $configSucceededPath = $cachePath.'/config-cache-refresh.succeeded';
        $routeSignaturePath = $cachePath.'/route-source.signature';
        $routeFailedPath = $cachePath.'/route-cache-refresh.failed';
        $routePendingPath = $cachePath.'/route-cache-refresh.pending';
        $routeSucceededPath = $cachePath.'/route-cache-refresh.succeeded';
        $routeCachePaths = $this->routeCachePaths($cachePath);

        if ($this->option('clear-failures')) {
            @unlink($configFailedPath);
            @unlink($configPendingPath);
            @unlink($routeFailedPath);
            @unlink($routePendingPath);

            $this->info('Config Cache Guard failure and pending markers were cleared.');
        }

        $legacyIndexRequire = false;

        if (is_file($indexPath)) {
            $contents = file_get_contents($indexPath);
            $legacyIndexRequire = is_string($contents)
                && str_contains($contents, 'laravel-config-cache-guard/bootstrap/guard.php');
        }

        $guardEnabled = Environment::flag('CONFIG_CACHE_GUARD_ENABLED');
        $configGuardEnabled = Environment::flag('CONFIG_CACHE_GUARD_CONFIG');
        $routeGuardEnabled = Environment::flag('CONFIG_CACHE_GUARD_ROUTES');
        $autoRepairEnabled = Environment::flag('CONFIG_CACHE_GUARD_AUTO_REPAIR', true);
        $createConfigWhenMissing = Environment::flag('CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE', false);
        $failHard = Environment::flag('CONFIG_CACHE_GUARD_FAIL_HARD', false);
        $currentRouteCachePath = $this->effectiveRouteCachePath($cachePath, $routeGuardEnabled);
        $execAvailable = $this->canUseExec();
        $phpBinary = $this->resolvePhpBinary();
        $cacheWritable = is_writable($cachePath);
        $customConfigCachePath = $this->normalizePath($cachedConfigPath)
            !== $this->normalizePath($cachePath.'/config.php');
        $configCacheWritable = is_file($cachedConfigPath)
            ? is_writable($cachedConfigPath)
            : is_writable(dirname($cachedConfigPath));

        $this->table(['Check', 'Status'], [
            ['Composer autoload integration', 'yes'],
            ['Legacy public/index.php require', $legacyIndexRequire ? 'yes (remove recommended)' : 'no'],
            ['Guard enabled', $guardEnabled ? 'yes' : 'no'],
            ['Config guard enabled', $configGuardEnabled ? 'yes' : 'no'],
            ['Route guard enabled', $routeGuardEnabled ? 'yes' : 'no'],
            ['Create config cache when missing', $createConfigWhenMissing ? 'yes' : 'no'],
            ['Auto repair fallback enabled', $autoRepairEnabled ? 'yes' : 'no'],
            ['Versioned route cache enabled', Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true) ? 'yes' : 'no'],
            ['Source signature mode', DeploymentCacheSignatures::mode()],
            ['Failure cooldown', $this->failureCooldownSeconds().' seconds'],
            ['Fail hard', $failHard ? 'yes' : 'no'],
            ['Active Laravel cache path', $cachePath],
            ['Active Laravel cache path writable', $cacheWritable ? 'yes' : 'no'],
            ['Current config cache path', $cachedConfigPath],
            ['Custom config cache path', $customConfigCachePath ? 'yes' : 'no'],
            ['Config cache path writable', $configCacheWritable ? 'yes' : 'no'],
            ['Cached config exists', is_file($cachedConfigPath) ? 'yes' : 'no'],
            ['config signature exists', is_file($configSignaturePath) ? 'yes' : 'no'],
            ['config pending repair', FailureMarker::summary($configPendingPath) ?? 'no'],
            ['config failed marker', FailureMarker::summary($configFailedPath) ?? 'no'],
            ['config last successful repair', SuccessMarker::summary($configSucceededPath) ?? 'no'],
            ['cached routes exist', $routeCachePaths === [] ? 'no' : 'yes ('.count($routeCachePaths).')'],
            ['current route cache path', $currentRouteCachePath],
            ['current route cache exists', is_file($currentRouteCachePath) ? 'yes' : 'no'],
            ['route signature exists', is_file($routeSignaturePath) ? 'yes' : 'no'],
            ['route pending repair', FailureMarker::summary($routePendingPath) ?? 'no'],
            ['route failed marker', FailureMarker::summary($routeFailedPath) ?? 'no'],
            ['route last successful repair', SuccessMarker::summary($routeSucceededPath) ?? 'no'],
            ['route stale cleanup last result', SuccessMarker::staleCleanupSummary($routeSucceededPath) ?? 'no'],
            ['exec available', $execAvailable ? 'yes' : 'no'],
            ['PHP CLI binary', $phpBinary ?? 'not found'],
        ]);

        if (! $guardEnabled) {
            $this->warn('Result: installed, but currently disabled through CONFIG_CACHE_GUARD_ENABLED.');

            return self::SUCCESS;
        }

        if (! $cacheWritable) {
            $this->error('Result: installed, but the active Laravel cache directory is not writable. Fix permissions before using the guard.');

            return self::SUCCESS;
        }

        if ($configGuardEnabled && (is_file($cachedConfigPath) || $createConfigWhenMissing) && ! $configCacheWritable) {
            $this->error('Result: installed, but the configured config cache path is not writable. Stale config cannot be replaced safely.');

            return self::SUCCESS;
        }

        if (! $configGuardEnabled && ! $routeGuardEnabled) {
            $this->warn('Result: installed, but both config and route guards are disabled.');

            return self::SUCCESS;
        }

        if ($legacyIndexRequire) {
            $this->warn('Notice: public/index.php still contains the old manual require line. It is safe, but no longer needed.');
            $this->line('Run: php artisan config-cache-guard:install --remove-legacy');
        }

        if ($customConfigCachePath) {
            $this->warn('Notice: a custom config cache path is active. Ensure APP_CONFIG_CACHE is configured at process or web-server level; a value placed only in .env is not visible to the pre-bootstrap guard.');
        }

        if (! $execAvailable || $phpBinary === null) {
            if ($autoRepairEnabled) {
                $this->warn('Result: pre-bootstrap rebuild through exec is unavailable, but in-app auto repair can rebuild through Artisan::call() after the current HTTP response is sent.');
            } else {
                $this->warn('Result: automatic rebuild from web requests is unavailable. Enable CONFIG_CACHE_GUARD_AUTO_REPAIR or configure exec/PHP CLI.');
            }

            return self::SUCCESS;
        }

        if ($routeGuardEnabled && $routeCachePaths === []) {
            $message = $configGuardEnabled
                ? 'Result: ready. Config cache refresh is available when config cache exists. Route cache refresh will activate when a route cache file exists.'
                : 'Result: ready. Route cache refresh will activate when a route cache file exists.';

            $this->info($message);

            return self::SUCCESS;
        }

        $targets = array_filter([
            $configGuardEnabled ? 'config' : null,
            $routeGuardEnabled ? 'route' : null,
        ]);

        $this->info('Result: ready. Automatic '.implode(' and ', $targets).' cache refresh is available.');

        return self::SUCCESS;
    }

    private function failureCooldownSeconds(): int
    {
        $seconds = (int) (Environment::string('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN') ?: 60);

        return $seconds > 0 ? $seconds : 60;
    }

    /**
     * @return list<string>
     */
    private function routeCachePaths(string $cachePath): array
    {
        return RouteCacheFiles::all($cachePath);
    }

    private function effectiveRouteCachePath(string $cachePath, bool $routeGuardEnabled): string
    {
        if (
            ! $routeGuardEnabled
            || Environment::string('APP_ROUTES_CACHE') !== null
            || ! Environment::flag('CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE', true)
        ) {
            return RouteCacheFiles::current($cachePath);
        }

        $signature = DeploymentCacheSignatures::routes(base_path());

        if ($signature === null) {
            return RouteCacheFiles::current($cachePath);
        }

        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.'routes-'.$signature.'.php';
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function canUseExec(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $disabledFunctions = array_filter(array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions'))
        ));

        return ! in_array('exec', $disabledFunctions, true);
    }

    private function resolvePhpBinary(): ?string
    {
        $candidates = [
            Environment::string('CONFIG_CACHE_GUARD_PHP_BINARY'),
            Environment::string('PHP_CLI_BINARY'),
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/alt/php85/usr/bin/php',
            '/opt/alt/php84/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            PHP_BINARY,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $basename = strtolower(basename($candidate));

            if (
                str_contains($basename, 'fpm')
                || str_contains($basename, 'cgi')
                || str_contains($basename, 'lsphp')
            ) {
                continue;
            }

            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
