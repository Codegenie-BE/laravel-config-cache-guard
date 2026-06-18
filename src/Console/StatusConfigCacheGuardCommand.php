<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Console;

use Codegenie\ConfigCacheGuard\Support\Environment;
use Codegenie\ConfigCacheGuard\Support\FailureMarker;
use Illuminate\Console\Command;

final class StatusConfigCacheGuardCommand extends Command
{
    protected $signature = 'config-cache-guard:status {--clear-failures : Remove config and route failure markers}';

    protected $description = 'Show the current Codegenie Laravel Config Cache Guard status.';

    public function handle(): int
    {
        $indexPath = public_path('index.php');
        $cachePath = base_path('bootstrap/cache');
        $cachedConfigPath = $cachePath.'/config.php';
        $configSignaturePath = $cachePath.'/config-source.signature';
        $configFailedPath = $cachePath.'/config-cache-refresh.failed';
        $routeSignaturePath = $cachePath.'/route-source.signature';
        $routeFailedPath = $cachePath.'/route-cache-refresh.failed';
        $routeCachePaths = $this->routeCachePaths($cachePath);

        if ($this->option('clear-failures')) {
            @unlink($configFailedPath);
            @unlink($routeFailedPath);

            $this->info('Config Cache Guard failure markers were cleared.');
        }

        $installed = false;

        if (is_file($indexPath)) {
            $contents = file_get_contents($indexPath);
            $installed = is_string($contents)
                && str_contains($contents, 'laravel-config-cache-guard/bootstrap/guard.php');
        }

        $guardEnabled = Environment::flag('CONFIG_CACHE_GUARD_ENABLED');
        $configGuardEnabled = Environment::flag('CONFIG_CACHE_GUARD_CONFIG');
        $routeGuardEnabled = Environment::flag('CONFIG_CACHE_GUARD_ROUTES');
        $repairRouteEnabled = Environment::flag('CONFIG_CACHE_GUARD_REPAIR_ENABLED', true);
        $repairTokenConfigured = Environment::string('CONFIG_CACHE_GUARD_REPAIR_TOKEN') !== null;
        $repairGetAllowed = Environment::flag('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET', false);
        $failHard = Environment::flag('CONFIG_CACHE_GUARD_FAIL_HARD', false);
        $execAvailable = $this->canUseExec();
        $phpBinary = $this->resolvePhpBinary();
        $cacheWritable = is_writable($cachePath);

        $this->table(['Check', 'Status'], [
            ['Guard enabled', $guardEnabled ? 'yes' : 'no'],
            ['Config guard enabled', $configGuardEnabled ? 'yes' : 'no'],
            ['Route guard enabled', $routeGuardEnabled ? 'yes' : 'no'],
            ['Failure cooldown', $this->failureCooldownSeconds().' seconds'],
            ['Fail hard', $failHard ? 'yes' : 'no'],
            ['Installed in public/index.php', $installed ? 'yes' : 'no'],
            ['bootstrap/cache path', $cachePath],
            ['bootstrap/cache writable', $cacheWritable ? 'yes' : 'no'],
            ['cached config exists', is_file($cachedConfigPath) ? 'yes' : 'no'],
            ['config signature exists', is_file($configSignaturePath) ? 'yes' : 'no'],
            ['config failed marker', FailureMarker::summary($configFailedPath) ?? 'no'],
            ['cached routes exist', $routeCachePaths === [] ? 'no' : 'yes ('.count($routeCachePaths).')'],
            ['route signature exists', is_file($routeSignaturePath) ? 'yes' : 'no'],
            ['route failed marker', FailureMarker::summary($routeFailedPath) ?? 'no'],
            ['exec available', $execAvailable ? 'yes' : 'no'],
            ['PHP CLI binary', $phpBinary ?? 'not found'],
            ['Repair endpoint enabled', $repairRouteEnabled && $repairTokenConfigured ? 'yes' : 'no'],
            ['Repair token configured', $repairTokenConfigured ? 'yes' : 'no'],
            ['Repair GET allowed', $repairGetAllowed ? 'yes' : 'no'],
            ['Repair endpoint', $repairRouteEnabled && $repairTokenConfigured ? url('/_config-cache-guard/repair') : 'disabled'],
        ]);

        if (! $installed) {
            $this->warn('Result: not installed. Run: php artisan config-cache-guard:install');

            return self::SUCCESS;
        }

        if (! $guardEnabled) {
            $this->warn('Result: installed, but currently disabled through CONFIG_CACHE_GUARD_ENABLED.');

            return self::SUCCESS;
        }

        if (! $cacheWritable) {
            $this->error('Result: installed, but bootstrap/cache is not writable. Fix permissions before using the guard.');

            return self::SUCCESS;
        }

        if (! $configGuardEnabled && ! $routeGuardEnabled) {
            $this->warn('Result: installed, but both config and route guards are disabled.');

            return self::SUCCESS;
        }

        if (! $execAvailable || $phpBinary === null) {
            if ($repairRouteEnabled && $repairTokenConfigured) {
                $this->warn('Result: automatic rebuild from web requests is unavailable, but the protected repair endpoint can rebuild through Laravel without exec().');
            } else {
                $this->warn('Result: automatic rebuild from web requests is unavailable. Configure CONFIG_CACHE_GUARD_REPAIR_TOKEN to enable the protected repair endpoint without exec().');
            }

            return self::SUCCESS;
        }

        if ($routeGuardEnabled && $routeCachePaths === []) {
            $message = $configGuardEnabled
                ? 'Result: ready. Config cache refresh is available. Route cache refresh will activate when a route cache file exists.'
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
        return glob($cachePath.'/routes-*.php') ?: [];
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
