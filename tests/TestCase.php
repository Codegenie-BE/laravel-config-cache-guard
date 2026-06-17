<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Tests;

use Codegenie\ConfigCacheGuard\ConfigCacheGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConfigCacheGuardServiceProvider::class,
        ];
    }
}
