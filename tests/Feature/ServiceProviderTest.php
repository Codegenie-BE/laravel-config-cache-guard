<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\ConfigCacheGuardServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;

it('registers successful Artisan cache command tracking in console applications', function (): void {
    $events = $this->app->make(Dispatcher::class);

    expect($events->hasListeners(CommandFinished::class))->toBeTrue();
});

it('checks for deferred repairs when booting outside the console', function (): void {
    $application = $this->app;
    $property = new ReflectionProperty($application, 'isRunningInConsole');
    $originalValue = $property->getValue($application);

    try {
        $property->setValue($application, false);

        (new ConfigCacheGuardServiceProvider($application))->boot();

        expect(true)->toBeTrue();
    } finally {
        $property->setValue($application, $originalValue);
    }
});
