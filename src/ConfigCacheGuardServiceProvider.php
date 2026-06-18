<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard;

use Codegenie\ConfigCacheGuard\Console\InstallConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Console\StatusConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Http\Middleware\RefreshAfterRouteCacheRepair;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Support\ServiceProvider;

final class ConfigCacheGuardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/repair.php');
        $this->registerRefreshMiddleware();

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

    private function registerRefreshMiddleware(): void
    {
        if (! $this->app->bound(HttpKernelContract::class)) {
            return;
        }

        $kernel = $this->app->make(HttpKernelContract::class);
        $kernel->pushMiddleware(RefreshAfterRouteCacheRepair::class);
    }
}
