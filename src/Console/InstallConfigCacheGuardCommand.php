<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Console;

use Illuminate\Console\Command;

final class InstallConfigCacheGuardCommand extends Command
{
    protected $signature = 'config-cache-guard:install
        {--dry-run : Show what would be changed without writing to public/index.php}
        {--remove-legacy : Remove the old manual public/index.php require line when it exists}';

    protected $description = 'Check the Codegenie Laravel Config Cache Guard installation.';

    public function handle(): int
    {
        $indexPath = public_path('index.php');

        if (! is_file($indexPath)) {
            $this->info('Codegenie Config Cache Guard is loaded by Composer automatically.');
            $this->warn('public/index.php was not found, so legacy cleanup could not be checked.');

            return self::SUCCESS;
        }

        $contents = file_get_contents($indexPath);

        if ($contents === false) {
            $this->error('public/index.php could not be read.');

            return self::FAILURE;
        }

        $legacyLinePresent = str_contains($contents, 'laravel-config-cache-guard/bootstrap/guard.php');

        if ($this->option('remove-legacy')) {
            return $this->removeLegacyRequireLine($indexPath, $contents, $legacyLinePresent);
        }

        $this->info('Codegenie Config Cache Guard is loaded by Composer automatically.');
        $this->line('No manual public/index.php change is required for current versions.');

        if ($legacyLinePresent) {
            $this->warn('A legacy manual require line still exists in public/index.php. It is safe but no longer needed.');
            $this->line('Run this to remove it: php artisan config-cache-guard:install --remove-legacy');
        } else {
            $this->info('No legacy public/index.php require line was found.');
        }

        return self::SUCCESS;
    }

    private function removeLegacyRequireLine(string $indexPath, string $contents, bool $legacyLinePresent): int
    {
        if (! $legacyLinePresent) {
            $this->info('No legacy public/index.php require line was found.');

            return self::SUCCESS;
        }

        $updatedContents = $this->withoutLegacyRequireLine($contents);

        if ($updatedContents === $contents) {
            $this->warn('A legacy reference was detected, but it could not be removed automatically.');
            $this->line('Remove this line manually when present:');
            $this->line($this->legacyRequireLine());

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run only. The legacy public/index.php require line would be removed.');

            return self::SUCCESS;
        }

        if (! is_writable($indexPath)) {
            $this->error('public/index.php is not writable.');
            $this->line('Remove this line manually when present:');
            $this->line($this->legacyRequireLine());

            return self::FAILURE;
        }

        if (file_put_contents($indexPath, $updatedContents, LOCK_EX) === false) {
            $this->error('public/index.php could not be updated.');

            return self::FAILURE;
        }

        $this->info('Legacy public/index.php require line removed successfully.');
        $this->line('The guard is now loaded through Composer autoload only.');

        return self::SUCCESS;
    }

    private function withoutLegacyRequireLine(string $contents): string
    {
        $patterns = [
            "require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';",
            "require_once __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';",
            "require __DIR__.'/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';",
            "require_once __DIR__.'/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';",
        ];

        foreach ($patterns as $pattern) {
            $contents = str_replace($pattern.PHP_EOL.PHP_EOL, '', $contents);
            $contents = str_replace(PHP_EOL.$pattern, '', $contents);
            $contents = str_replace($pattern, '', $contents);
        }

        return preg_replace("/\n{3,}/", PHP_EOL.PHP_EOL, $contents) ?? $contents;
    }

    private function legacyRequireLine(): string
    {
        return "require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';";
    }
}
