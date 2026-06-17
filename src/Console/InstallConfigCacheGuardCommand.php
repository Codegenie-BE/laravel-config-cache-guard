<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Console;

use Illuminate\Console\Command;

final class InstallConfigCacheGuardCommand extends Command
{
    protected $signature = 'config-cache-guard:install {--dry-run : Show what would be changed without writing to public/index.php}';

    protected $description = 'Install the Codegenie Laravel Config Cache Guard in public/index.php.';

    public function handle(): int
    {
        $indexPath = public_path('index.php');

        if (! is_file($indexPath)) {
            $this->error('public/index.php was not found. Install the guard manually.');
            $this->line($this->requireLine());

            return self::FAILURE;
        }

        $contents = file_get_contents($indexPath);

        if ($contents === false) {
            $this->error('public/index.php could not be read.');

            return self::FAILURE;
        }

        if (str_contains($contents, 'laravel-config-cache-guard/bootstrap/guard.php')) {
            $this->info('Codegenie Config Cache Guard is already installed.');

            return self::SUCCESS;
        }

        $updatedContents = $this->insertRequireLine($contents);

        if ($updatedContents === null) {
            $this->error('Could not find a safe insertion point in public/index.php.');
            $this->line('Add this line manually before vendor/autoload.php:');
            $this->line($this->requireLine());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run only. Add this line to public/index.php before vendor/autoload.php:');
            $this->line($this->requireLine());

            return self::SUCCESS;
        }

        if (! is_writable($indexPath)) {
            $this->error('public/index.php is not writable.');
            $this->line('Add this line manually before vendor/autoload.php:');
            $this->line($this->requireLine());

            return self::FAILURE;
        }

        if (file_put_contents($indexPath, $updatedContents, LOCK_EX) === false) {
            $this->error('public/index.php could not be updated.');

            return self::FAILURE;
        }

        $this->info('Codegenie Config Cache Guard installed successfully.');
        $this->line('public/index.php now loads the guard before Laravel bootstraps.');

        return self::SUCCESS;
    }

    private function insertRequireLine(string $contents): ?string
    {
        $line = $this->requireLine();
        $needle = "define('LARAVEL_START', microtime(true));";

        if (str_contains($contents, $needle)) {
            return str_replace($needle, $needle . PHP_EOL . PHP_EOL . $line, $contents);
        }

        $autoloadPatterns = [
            "require __DIR__.'/../vendor/autoload.php';",
            "require __DIR__ . '/../vendor/autoload.php';",
            "require_once __DIR__.'/../vendor/autoload.php';",
            "require_once __DIR__ . '/../vendor/autoload.php';",
        ];

        foreach ($autoloadPatterns as $autoloadLine) {
            if (str_contains($contents, $autoloadLine)) {
                return str_replace($autoloadLine, $line . PHP_EOL . PHP_EOL . $autoloadLine, $contents);
            }
        }

        return null;
    }

    private function requireLine(): string
    {
        return "require __DIR__ . '/../vendor/codegenie/laravel-config-cache-guard/bootstrap/guard.php';";
    }
}
