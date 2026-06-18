<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard;

use Codegenie\ConfigCacheGuard\Console\InstallConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Console\StatusConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
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

            return;
        }

        $this->app->booted(static function (): void {
            DeploymentCacheRepairer::runPending(
                base_path(),
                base_path('bootstrap/cache')
            );
        });
    }
}
