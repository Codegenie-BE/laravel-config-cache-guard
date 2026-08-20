<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard;

use Codegenie\ConfigCacheGuard\Console\InstallConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Console\StatusConfigCacheGuardCommand;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheTracker;
use Codegenie\ConfigCacheGuard\Support\Environment;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
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

            $app = $this->app;
            $events = $app->make(Dispatcher::class);

            $events->listen(CommandFinished::class, static function (CommandFinished $event) use ($app): void {
                DeploymentCacheTracker::recordSuccessfulCommand(
                    $event->command,
                    $event->exitCode,
                    $app->basePath(),
                    $app->bootstrapPath('cache')
                );
            });

            // Explicit CLI guard mode is a deployment/diagnostic path, not a
            // visitor-facing hot path. If pre-bootstrap protection queued a
            // repair, complete it after Laravel has booted but before the
            // requested Artisan command runs. Normal CLI and Composer usage
            // remains untouched because ALLOW_CLI is false by default.
            if (Environment::flag('CONFIG_CACHE_GUARD_ALLOW_CLI', false)) {
                $app->booted(static function () use ($app): void {
                    DeploymentCacheRepairer::runPending(
                        $app->basePath(),
                        $app->bootstrapPath('cache'),
                    );
                });
            }

            return;
        }

        DeploymentCacheRepairer::runPendingAfterResponse(
            $this->app,
            $this->app->basePath(),
            $this->app->bootstrapPath('cache'),
            null,
            ! $this->app->runningUnitTests(),
        );
    }
}
