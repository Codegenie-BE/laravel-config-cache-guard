<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Tests\Support\ReleaseChangelog;

require_once __DIR__.'/ReleaseChangelog.php';

$tag = $argv[1] ?? '';
$changelogPath = $argv[2] ?? dirname(__DIR__, 2).'/CHANGELOG.md';
$releaseDate = $argv[3] ?? gmdate('Y-m-d');
$changelog = @file_get_contents($changelogPath);

if (! is_string($changelog)) {
    fwrite(STDERR, '[release-pr] CHANGELOG.md could not be read.'.PHP_EOL);
    exit(1);
}

$date = DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDate);
$dateErrors = DateTimeImmutable::getLastErrors();

if ($date === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
    fwrite(STDERR, '[release-pr] The release date must be a valid YYYY-MM-DD date.'.PHP_EOL);
    exit(1);
}

try {
    $preparedChangelog = ReleaseChangelog::prepare($changelog, $tag, $date);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[release-pr] '.$throwable->getMessage().PHP_EOL);
    exit(1);
}

if (file_put_contents($changelogPath, $preparedChangelog, LOCK_EX) === false) {
    fwrite(STDERR, '[release-pr] CHANGELOG.md could not be updated.'.PHP_EOL);
    exit(1);
}

fwrite(STDOUT, '[release-pr] Prepared '.$tag.' in CHANGELOG.md.'.PHP_EOL);
