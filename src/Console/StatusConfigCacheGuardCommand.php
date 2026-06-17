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
        $cachedConfigPath = $cachePath.'/config.php';
        $signaturePath = $cachePath.'/config-source.signature';

        $installed = false;

        if (is_file($indexPath)) {
            $contents = file_get_contents($indexPath);
            $installed = is_string($contents)
                && str_contains($contents, 'laravel-config-cache-guard/bootstrap/guard.php');
        }

        $this->table(['Check', 'Status'], [
            ['Installed in public/index.php', $installed ? 'yes' : 'no'],
            ['bootstrap/cache writable', is_writable($cachePath) ? 'yes' : 'no'],
            ['cached config exists', is_file($cachedConfigPath) ? 'yes' : 'no'],
            ['signature exists', is_file($signaturePath) ? 'yes' : 'no'],
            ['exec available', $this->canUseExec() ? 'yes' : 'no'],
            ['PHP CLI binary', $this->resolvePhpBinary() ?? 'not found'],
        ]);

        if (! $installed) {
            $this->warn('The guard is not installed yet. Run: php artisan config-cache-guard:install');
        }

        return self::SUCCESS;
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
