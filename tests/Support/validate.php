<?php

declare(strict_types=1);

$repositoryPath = dirname(__DIR__, 2);
$policy = require __DIR__.'/policy.php';
$configuredDate = getenv('SUPPORT_POLICY_DATE');
$today = is_string($configuredDate) && $configuredDate !== ''
    ? $configuredDate
    : gmdate('Y-m-d');

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
    fwrite(STDERR, "SUPPORT_POLICY_DATE must use YYYY-MM-DD.\n");
    exit(1);
}

$errors = [];
$nonEolPhp = [];
$nonEolLaravel = [];

foreach ($policy['php'] as $version => $support) {
    if ($today <= $support['security_fixes_until']) {
        $nonEolPhp[] = $version;
    }
}

foreach ($policy['laravel'] as $version => $support) {
    if ($today <= $support['security_fixes_until']) {
        $nonEolLaravel[] = (string) $version;
    }
}

if ($nonEolPhp === []) {
    $errors[] = 'The PHP support policy has no non-EOL versions left. Refresh tests/Support/policy.php from '.$policy['sources']['php'].'.';
}

if ($nonEolLaravel === []) {
    $errors[] = 'The Laravel support policy has no non-EOL versions left. Refresh tests/Support/policy.php from '.$policy['sources']['laravel'].'.';
}

$composer = json_decode(
    (string) file_get_contents($repositoryPath.'/composer.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$expectedPhpConstraint = '^'.($nonEolPhp[0] ?? 'unsupported');
$expectedLaravelConstraint = implode('|', array_map(
    static fn (string $version): string => '^'.$version.'.0',
    $nonEolLaravel
));

if (($composer['require']['php'] ?? null) !== $expectedPhpConstraint) {
    $errors[] = 'composer.json must require '.$expectedPhpConstraint.' so it excludes EOL PHP branches on '.$today.'.';
}

foreach (['illuminate/console', 'illuminate/support'] as $package) {
    if (($composer['require'][$package] ?? null) !== $expectedLaravelConstraint) {
        $errors[] = 'composer.json must require '.$expectedLaravelConstraint.' for '.$package.' on '.$today.'.';
    }
}

$workflow = (string) file_get_contents($repositoryPath.'/.github/workflows/tests.yml');
preg_match_all('/^\s+php:\s*[\'\"](\d+\.\d+)[\'\"]\s*$/m', $workflow, $workflowPhpMatches);
preg_match_all('/^\s+laravel:\s*[\'\"](\d+)[\'\"]\s*$/m', $workflow, $workflowLaravelMatches);
$workflowPhp = array_values(array_unique($workflowPhpMatches[1]));
$workflowLaravel = array_values(array_unique($workflowLaravelMatches[1]));

foreach ($workflowPhp as $version) {
    if (! in_array($version, $nonEolPhp, true)) {
        $errors[] = 'The GitHub Actions matrix still uses EOL PHP '.$version.' on '.$today.'.';
    }
}

foreach ($workflowLaravel as $version) {
    if (! in_array($version, $nonEolLaravel, true)) {
        $errors[] = 'The GitHub Actions matrix still uses EOL Laravel '.$version.' on '.$today.'.';
    }
}

foreach ($nonEolLaravel as $laravelVersion) {
    $laravelPolicy = $policy['laravel'][$laravelVersion];

    foreach ($laravelPolicy['php'] as $phpVersion) {
        if (! in_array($phpVersion, $nonEolPhp, true)) {
            continue;
        }

        $matrixEntry = "- php: '".$phpVersion."'\n            laravel: '".$laravelVersion."'";

        if (! str_contains($workflow, $matrixEntry)) {
            $errors[] = 'The primary CI matrix is missing PHP '.$phpVersion.' / Laravel '.$laravelVersion.'.';
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '[support-policy] '.$error.PHP_EOL);
    }

    exit(1);
}

fwrite(
    STDOUT,
    '[support-policy] '.$today.': PHP '.implode(', ', $nonEolPhp)
        .' and Laravel '.implode(', ', $nonEolLaravel).' are non-EOL and fully represented in CI.'.PHP_EOL
);
