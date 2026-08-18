<?php

declare(strict_types=1);

it('keeps temporary one-shot audit scaffolding out of the maintained repository', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_file($root.'/.audit-branch-marker'))->toBeFalse()
        ->and(glob($root.'/.github/workflows/audit-codemod*.yml') ?: [])->toBe([])
        ->and(glob($root.'/.github/workflows/audit-codemod*.yaml') ?: [])->toBe([]);

    foreach (array_merge(
        glob($root.'/.github/workflows/*.yml') ?: [],
        glob($root.'/.github/workflows/*.yaml') ?: [],
    ) as $workflowPath) {
        $workflow = (string) file_get_contents($workflowPath);

        expect($workflow)->not->toContain('One-shot audit codemod');
    }
});
