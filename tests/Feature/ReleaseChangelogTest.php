<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Tests\Support\ReleaseChangelog;

require_once dirname(__DIR__).'/Support/ReleaseChangelog.php';

it('detects and increments the latest stable changelog version', function (): void {
    $changelog = <<<'CHANGELOG'
# Changelog

## Unreleased

- Pending change.

## v1.4.0 - 2026-08-14

- Previous change.

## v1.3.1 - 2026-08-11

- Older change.
CHANGELOG;

    expect(ReleaseChangelog::latestTag($changelog))->toBe('v1.4.0')
        ->and(ReleaseChangelog::nextTag($changelog, 'patch'))->toBe('v1.4.1')
        ->and(ReleaseChangelog::nextTag($changelog, 'minor'))->toBe('v1.5.0')
        ->and(ReleaseChangelog::nextTag($changelog, 'major'))->toBe('v2.0.0');
});

it('moves unreleased notes into a dated release section', function (): void {
    $changelog = <<<'CHANGELOG'
# Changelog

## Unreleased

- Pending change.
- Another change.

## v1.4.0 - 2026-08-14

- Previous change.
CHANGELOG;

    $prepared = ReleaseChangelog::prepare(
        $changelog,
        'v1.4.1',
        new DateTimeImmutable('2026-08-15'),
    );

    expect($prepared)->toContain("## Unreleased\n\n## v1.4.1 - 2026-08-15")
        ->and($prepared)->toContain("- Pending change.\n- Another change.")
        ->and(ReleaseChangelog::latestTag($prepared))->toBe('v1.4.1');
});

it('refuses empty, duplicate or non-increasing releases', function (string $changelog, string $tag, string $message): void {
    expect(fn (): string => ReleaseChangelog::prepare(
        $changelog,
        $tag,
        new DateTimeImmutable('2026-08-15'),
    ))->toThrow(RuntimeException::class, $message);
})->with([
    'empty unreleased section' => [
        "# Changelog\n\n## Unreleased\n\n## v1.4.0 - 2026-08-14\n\n- Previous.\n",
        'v1.4.1',
        'does not contain any release notes',
    ],
    'duplicate version' => [
        "# Changelog\n\n## Unreleased\n\n- Pending.\n\n## v1.4.0 - 2026-08-14\n\n- Previous.\n",
        'v1.4.0',
        'must be newer',
    ],
    'older version' => [
        "# Changelog\n\n## Unreleased\n\n- Pending.\n\n## v1.4.0 - 2026-08-14\n\n- Previous.\n",
        'v1.3.9',
        'must be newer',
    ],
]);

it('rejects unordered release sections and invalid bumps', function (): void {
    $unordered = "# Changelog\n\n## Unreleased\n\n## v1.3.0 - 2026-08-14\n\n- Older.\n\n## v1.4.0 - 2026-08-13\n\n- Newer.\n";
    $valid = "# Changelog\n\n## Unreleased\n\n## v1.4.0 - 2026-08-14\n\n- Change.\n";

    expect(fn (): string => ReleaseChangelog::latestTag($unordered))
        ->toThrow(RuntimeException::class, 'newest to oldest')
        ->and(fn (): string => ReleaseChangelog::nextTag($valid, 'banana'))
        ->toThrow(RuntimeException::class, 'patch, minor or major');
});
