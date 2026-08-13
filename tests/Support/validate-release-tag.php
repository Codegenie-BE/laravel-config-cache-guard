<?php

declare(strict_types=1);

$repositoryPath = dirname(__DIR__, 2);
$tag = $argv[1] ?? '';

$fail = static function (string $message): never {
    fwrite(STDERR, '[release-tag] '.$message.PHP_EOL);
    exit(1);
};

if (preg_match('/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $tag) !== 1) {
    $fail('Release tags must use strict stable SemVer, for example v1.4.0.');
}

$changelog = @file_get_contents($repositoryPath.'/CHANGELOG.md');

if (! is_string($changelog)) {
    $fail('CHANGELOG.md could not be read.');
}

if (preg_match('/^## '.preg_quote($tag, '/').' - \d{4}-\d{2}-\d{2}$/m', $changelog) !== 1) {
    $fail('CHANGELOG.md must contain a dated section for '.$tag.'.');
}

if (preg_match('/^## Unreleased\R(?<contents>.*?)(?=^## |\z)/ms', $changelog, $matches) !== 1) {
    $fail('CHANGELOG.md must retain an Unreleased section.');
}

if (preg_match('/^-\s+/m', $matches['contents']) === 1) {
    $fail('Move every Unreleased changelog entry into '.$tag.' before publishing.');
}

fwrite(STDOUT, '[release-tag] '.$tag.' is valid and documented.'.PHP_EOL);
