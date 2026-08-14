<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Tests\Support\ReleaseChangelog;

require_once __DIR__.'/ReleaseChangelog.php';

$changelogPath = $argv[1] ?? dirname(__DIR__, 2).'/CHANGELOG.md';
$changelog = @file_get_contents($changelogPath);

if (! is_string($changelog)) {
    fwrite(STDERR, '[release] CHANGELOG.md could not be read.'.PHP_EOL);
    exit(1);
}

try {
    fwrite(STDOUT, ReleaseChangelog::latestTag($changelog).PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[release] '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
