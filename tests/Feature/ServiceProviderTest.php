<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\ConfigCacheGuardServiceProvider;

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
