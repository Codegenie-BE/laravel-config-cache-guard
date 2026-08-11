<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function normalizeTestPath(string $path): string
{
    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}
