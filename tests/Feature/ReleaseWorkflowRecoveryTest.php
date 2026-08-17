<?php

declare(strict_types=1);

it('keeps a pending main release recoverable after CI reruns', function (): void {
    $workflow = file_get_contents(__DIR__.'/../../.github/workflows/tests.yml');

    expect($workflow)
        ->toBeString()
        ->toContain('pending_tag="$(php tests/Support/detect-release.php)"')
        ->toContain('tag_commit="$(git rev-list --max-count=1 "${pending_tag}" 2>/dev/null || true)"')
        ->toContain('if [ -z "$tag_commit" ] || [ "$tag_commit" = "$head" ]; then')
        ->toContain('always() &&')
        ->toContain("needs.ci-gate.result == 'success'");
});
