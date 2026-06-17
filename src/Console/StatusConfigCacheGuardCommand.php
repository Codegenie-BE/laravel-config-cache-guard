<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Console;

use Illuminate\Console\Command;

final class StatusConfigCacheGuardCommand extends Command
{
    protected $signature = 'config-cache-guard:status';

    protected $description = 'Show the current Codegenie Laravel Config Cache Guard status.';

    public function handle(): int
    {
        $indexPath = public_path('index.php');
        $cachePath = base_path('bootstrap/cache');
        $cachedConfigPath = $cachePath . '/config.php';
        $signaturePath = $cachePath . '/config-source.signature';
        $failedPath = $cachePath . '/config-cache-refresh.failed';

        $installed = false;

        if (is_file($indexPath)) {
            $contents = file_get_contents($indexPath);
            $installed = is_string($contents)
                && str_contains($contents, 'laravel-config-cache-guard/bootstrap/guard.php');
        }

        $guardEnabled = $this->guardEnabled();
        $execAvailable = $this->canUseExec();
        $phpBinary = $this->resolvePhpBinary();
        $cacheWritable = is_writable($cachePath);

        $this->table(['Check', 'Status'], [
            ['Guard enabled', $guardEnabled ? 'yes' : 'no'],
            ['Failure cooldown', $this->failureCooldownSeconds() . ' seconds'],
            ['Installed in public/index.php', $installed ? 'yes' : 'no'],
            ['bootstrap/cache path', $cachePath],
            ['bootstrap/cache writable', $cacheWritable ? 'yes' : 'no'],
            ['cached config exists', is_file($cachedConfigPath) ? 'yes' : 'no'],
            ['signature exists', is_file($signaturePath) ? 'yes' : 'no'],
            ['failed marker exists', is_file($failedPath) ? 'yes' : 'no'],
            ['exec available', $execAvailable ? 'yes' : 'no'],
            ['PHP CLI binary', $phpBinary ?? 'not found'],
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

        if (! $execAvailable || $phpBinary === null) {
            $this->warn('Result: installed. Automatic rebuild from web requests is unavailable, but stale cached config can still be removed.');

            return self::SUCCESS;
        }

        $this->info('Result: ready. Automatic config cache refresh is available.');

        return self::SUCCESS;
    }

    private function guardEnabled(): bool
    {
        $enabled = getenv('CONFIG_CACHE_GUARD_ENABLED');

        return ! (is_string($enabled) && in_array(strtolower($enabled), ['0', 'false', 'off', 'no'], true));
    }

    private function failureCooldownSeconds(): int
    {
        $seconds = (int) (getenv('CONFIG_CACHE_GUARD_FAILURE_COOLDOWN') ?: 60);

        return $seconds > 0 ? $seconds : 60;
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
        $candidates = array_filter([
            getenv('CONFIG_CACHE_GUARD_PHP_BINARY') ?: null,
            getenv('PHP_CLI_BINARY') ?: null,
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/alt/php85/usr/bin/php',
            '/opt/alt/php84/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            PHP_BINARY,
        ]);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
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
