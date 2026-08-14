<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Tests\Support;

use DateTimeImmutable;
use RuntimeException;

final class ReleaseChangelog
{
    private const TAG_PATTERN = 'v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)';

    public static function latestTag(string $changelog): string
    {
        preg_match_all(
            '/^## (?<tag>'.self::TAG_PATTERN.') - (?<date>\d{4}-\d{2}-\d{2})$/m',
            $changelog,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            throw new RuntimeException('CHANGELOG.md does not contain a stable release section.');
        }

        $previousVersion = null;

        foreach ($matches as $match) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match['date']);
            $dateErrors = DateTimeImmutable::getLastErrors();

            if ($date === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                throw new RuntimeException('Release '.$match['tag'].' has an invalid calendar date.');
            }

            $version = substr($match['tag'], 1);

            if ($previousVersion !== null && version_compare($previousVersion, $version, '<=')) {
                throw new RuntimeException('Stable changelog sections must be ordered from newest to oldest.');
            }

            $previousVersion = $version;
        }

        return $matches[0]['tag'];
    }

    public static function nextTag(string $changelog, string $bump): string
    {
        if (! in_array($bump, ['patch', 'minor', 'major'], true)) {
            throw new RuntimeException('The release bump must be patch, minor or major.');
        }

        $latestTag = self::latestTag($changelog);
        $parts = array_map('intval', explode('.', substr($latestTag, 1)));
        [$major, $minor, $patch] = $parts;

        if ($bump === 'major') {
            return 'v'.($major + 1).'.0.0';
        }

        if ($bump === 'minor') {
            return 'v'.$major.'.'.($minor + 1).'.0';
        }

        return 'v'.$major.'.'.$minor.'.'.($patch + 1);
    }

    public static function prepare(string $changelog, string $tag, DateTimeImmutable $date): string
    {
        if (preg_match('/^'.self::TAG_PATTERN.'$/D', $tag) !== 1) {
            throw new RuntimeException('Release tags must use strict stable SemVer, for example v1.4.0.');
        }

        if (version_compare(substr($tag, 1), substr(self::latestTag($changelog), 1), '<=')) {
            throw new RuntimeException('The prepared release must be newer than the latest changelog release.');
        }

        if (preg_match('/^## '.preg_quote($tag, '/').' - /m', $changelog) === 1) {
            throw new RuntimeException($tag.' already has a changelog section.');
        }

        if (preg_match('/^## Unreleased\R(?<contents>.*?)(?=^## |\z)/ms', $changelog, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('CHANGELOG.md must contain an Unreleased section.');
        }

        $contents = trim($matches['contents'][0]);

        if (preg_match('/^-\s+/m', $contents) !== 1) {
            throw new RuntimeException('The Unreleased section does not contain any release notes.');
        }

        $replacement = "## Unreleased\n\n## {$tag} - {$date->format('Y-m-d')}\n\n{$contents}\n\n";
        $fullMatch = $matches[0];

        return substr_replace($changelog, $replacement, $fullMatch[1], strlen($fullMatch[0]));
    }
}
