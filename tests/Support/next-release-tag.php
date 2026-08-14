<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Tests\Support\ReleaseChangelog;

require_once __DIR__.'/ReleaseChangelog.php';

$bump = $argv[1] ?? '';
$changelogPath = $argv[2] ?? dirname(__DIR__, 2).'/CHANGELOG.md';
$changelog = @file_get_contents($changelogPath);

if (! is_string($changelog)) {
    fwrite(STDERR, '[release-pr] CHANGELOG.md could not be read.'.PHP_EOL);
    exit(1);
}

try {
    fwrite(STDOUT, ReleaseChangelog::nextTag($changelog, $bump).PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[release-pr] '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
