<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard;

use Codegenie\ConfigCacheGuard\Console\InstallConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Console\StatusConfigCacheGuardCommand;
use Illuminate\Support\ServiceProvider;

final class ConfigCacheGuardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallConfigCacheGuardCommand::class,
                StatusConfigCacheGuardCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/repair.php');
    }
}
