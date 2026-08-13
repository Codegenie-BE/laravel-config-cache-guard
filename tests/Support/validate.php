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

$expectedRuntimePairs = [];

foreach ($nonEolLaravel as $laravelVersion) {
    $laravelPolicy = $policy['laravel'][$laravelVersion];

    foreach ($laravelPolicy['php'] as $phpVersion) {
        if (! in_array($phpVersion, $nonEolPhp, true)) {
            continue;
        }

        $expectedRuntimePairs[] = [$phpVersion, $laravelVersion];
    }
}

$jobSection = static function (string $workflow, string $job): string {
    $hasJob = preg_match(
        '/^  '.preg_quote($job, '/').':\s*$/mi',
        $workflow,
        $jobMatch,
        PREG_OFFSET_CAPTURE
    ) === 1;

    if (! $hasJob) {
        return '';
    }

    $start = $jobMatch[0][1];
    $bodyStart = $start + strlen($jobMatch[0][0]);
    $hasNextJob = preg_match(
        '/^  [a-z0-9_-]+:\s*$/mi',
        $workflow,
        $nextJobMatch,
        PREG_OFFSET_CAPTURE,
        $bodyStart
    ) === 1;
    $end = $hasNextJob ? $nextJobMatch[0][1] : false;

    return $end === false
        ? substr($workflow, $start)
        : substr($workflow, $start, $end - $start);
};

$jobs = [
    'native package tests' => $jobSection($workflow, 'tests'),
    'native E2E tests' => $jobSection($workflow, 'end-to-end'),
    'Alpine package tests' => $jobSection($workflow, 'alpine-tests'),
    'Alpine E2E tests' => $jobSection($workflow, 'alpine-end-to-end'),
];

foreach ($jobs as $label => $job) {
    if ($job === '') {
        $errors[] = 'The GitHub Actions workflow is missing the '.$label.' job.';
    }
}

$nativeJobRequirements = [
    'native package tests' => [
        'name: Tests / ${{ matrix.platform.name }} / PHP ${{ matrix.runtime.php }} / Laravel ${{ matrix.runtime.laravel }}',
        'runs-on: ${{ matrix.platform.runner }}',
        "run: composer require --dev 'orchestra/testbench:\${{ matrix.runtime.testbench }}' 'pestphp/pest:\${{ matrix.runtime.pest }}' --no-update --no-interaction",
    ],
    'native E2E tests' => [
        'name: E2E / ${{ matrix.platform.name }} / PHP ${{ matrix.runtime.php }} / Laravel ${{ matrix.runtime.laravel }}',
        'runs-on: ${{ matrix.platform.runner }}',
    ],
];

foreach ($nativeJobRequirements as $label => $requirements) {
    foreach ($requirements as $requirement) {
        if (! str_contains($jobs[$label], $requirement)) {
            $errors[] = 'The '.$label.' job must contain: '.$requirement.'.';
        }
    }
}

foreach ($jobs as $label => $job) {
    if (! str_contains($job, 'COMPOSER_CACHE_DIR: ${{ github.workspace }}/../.composer-cache')) {
        $errors[] = 'The '.$label.' job must keep the Composer cache outside the repository checkout.';
    }
}

foreach ($policy['platforms']['native'] as $platform) {
    $platformEntry = '- name: '.$platform['name']."\n"
        .'            runner: '.$platform['runner'];

    foreach (['native package tests', 'native E2E tests'] as $label) {
        if (substr_count($jobs[$label], $platformEntry) !== 1) {
            $errors[] = 'The '.$label.' matrix must contain '.$platform['name'].' on '
                .$platform['runner'].' exactly once.';
        }
    }
}

foreach ($expectedRuntimePairs as [$phpVersion, $laravelVersion]) {
    $testbench = $policy['laravel'][$laravelVersion]['testbench'];
    $pest = $policy['laravel'][$laravelVersion]['pest'];
    $packageEntry = "- php: '".$phpVersion."'\n"
        ."            laravel: '".$laravelVersion."'\n"
        ."            testbench: '".$testbench."'\n"
        ."            pest: '".$pest."'";
    $e2eEntry = "- php: '".$phpVersion."'\n"
        ."            laravel: '".$laravelVersion."'";

    foreach (['native package tests'] as $label) {
        if (substr_count($jobs[$label], $packageEntry) !== 1) {
            $errors[] = 'The '.$label.' matrix must contain PHP '.$phpVersion.' / Laravel '
                .$laravelVersion.' with Testbench '.$testbench.' and Pest '.$pest.' exactly once.';
        }
    }

    foreach (['native E2E tests'] as $label) {
        if (substr_count($jobs[$label], $e2eEntry) !== 1) {
            $errors[] = 'The '.$label.' matrix must contain PHP '.$phpVersion.' / Laravel '
                .$laravelVersion.' exactly once.';
        }
    }
}

$expectedPairCounts = [
    'native package tests' => count($expectedRuntimePairs),
    'native E2E tests' => count($expectedRuntimePairs),
    'Alpine package tests' => 2,
    'Alpine E2E tests' => 1,
];

foreach ($jobs as $label => $job) {
    preg_match_all(
        '/php:\s*[\'\"](\d+\.\d+)[\'\"]\s*\R\s*laravel:\s*[\'\"](\d+)[\'\"]/m',
        $job,
        $matrixPairMatches,
        PREG_SET_ORDER
    );

    if (count($matrixPairMatches) !== $expectedPairCounts[$label]) {
        $errors[] = 'The '.$label.' matrix must contain exactly '.$expectedPairCounts[$label]
            .' compatible PHP/Laravel runtime entries.';
    }

    foreach ($matrixPairMatches as $match) {
        if (! in_array([$match[1], $match[2]], $expectedRuntimePairs, true)) {
            $errors[] = 'The '.$label.' matrix contains unsupported PHP '
                .$match[1].' / Laravel '.$match[2].'.';
        }
    }
}

$minimumRuntimePair = $expectedRuntimePairs[0] ?? null;
$latestRuntimePair = $expectedRuntimePairs[count($expectedRuntimePairs) - 1] ?? null;

foreach ([
    'Alpine package tests' => array_filter([$minimumRuntimePair, $latestRuntimePair]),
    'Alpine E2E tests' => array_filter([$latestRuntimePair]),
] as $label => $requiredPairs) {
    foreach ($requiredPairs as [$phpVersion, $laravelVersion]) {
        $pair = "php: '".$phpVersion."'\n"
            ."            laravel: '".$laravelVersion."'";

        if (substr_count($jobs[$label], $pair) !== 1) {
            $errors[] = 'The '.$label.' matrix must contain representative PHP '.$phpVersion
                .' / Laravel '.$laravelVersion.' exactly once.';
        }
    }
}

foreach (['native package tests', 'native E2E tests'] as $label) {
    foreach (['Linux x64', 'Windows x64'] as $fullMatrixPlatform) {
        if (str_contains($jobs[$label], 'platform: {name: '.$fullMatrixPlatform)) {
            $errors[] = 'The '.$label.' matrix must not exclude any '.$fullMatrixPlatform.' runtime pair.';
        }
    }
}

foreach ([
    'native package tests' => 5,
    'native E2E tests' => 6,
] as $label => $expectedPeripheralExclusions) {
    foreach (['macOS ARM64', 'Linux ARM64'] as $platform) {
        if (substr_count($jobs[$label], 'platform: {name: '.$platform) !== $expectedPeripheralExclusions) {
            $errors[] = 'The '.$label.' matrix must keep only the documented representative pairs on '.$platform.'.';
        }
    }
}

$alpinePlatform = $policy['platforms']['container'][0] ?? null;

if (! is_array($alpinePlatform)) {
    $errors[] = 'The support policy must define the Alpine container platform.';
} else {
    $alpineDockerfile = $repositoryPath.'/'.$alpinePlatform['dockerfile'];

    foreach (['Alpine package tests', 'Alpine E2E tests'] as $label) {
        foreach ([
            'runs-on: '.$alpinePlatform['runner'],
            $alpinePlatform['name'],
            '--file '.$alpinePlatform['dockerfile'],
        ] as $requirement) {
            if (! str_contains($jobs[$label], $requirement)) {
                $errors[] = 'The '.$label.' job must contain: '.$requirement.'.';
            }
        }
    }

    if (! is_file($alpineDockerfile)) {
        $errors[] = 'The Alpine CI Dockerfile is missing at '.$alpinePlatform['dockerfile'].'.';
    } else {
        $dockerfile = (string) file_get_contents($alpineDockerfile);

        foreach (['ARG PHP_VERSION', 'FROM php:${PHP_VERSION}-cli-alpine', 'FROM composer:2'] as $requirement) {
            if (! str_contains($dockerfile, $requirement)) {
                $errors[] = 'The Alpine CI Dockerfile must contain: '.$requirement.'.';
            }
        }
    }
}

$minimumDependenciesJob = $jobSection($workflow, 'minimum-dependencies');
$dependencyReviewJob = $jobSection($workflow, 'dependency-review');
$coverageJob = $jobSection($workflow, 'coverage');
$ciGateJob = $jobSection($workflow, 'ci-gate');
$releaseJob = $jobSection($workflow, 'release');

foreach ([
    'minimum dependency tests' => $minimumDependenciesJob,
    'dependency review' => $dependencyReviewJob,
    'coverage' => $coverageJob,
    'stable CI gate' => $ciGateJob,
    'signed release' => $releaseJob,
] as $label => $job) {
    if ($job === '') {
        $errors[] = 'The GitHub Actions workflow is missing the '.$label.' job.';
    }
}

if ($minimumDependenciesJob !== '') {
    $minimumRuntimePairs = [];

    foreach ($policy['laravel'] as $laravelVersion => $laravelPolicy) {
        $minimumPhp = $laravelPolicy['php'][0] ?? null;

        if (is_string($minimumPhp)) {
            $minimumRuntimePairs[] = [$minimumPhp, (string) $laravelVersion];
        }
    }

    preg_match_all(
        '/php:\s*[\'\"](\d+\.\d+)[\'\"]\s*\R\s*laravel:\s*[\'\"](\d+)[\'\"]/m',
        $minimumDependenciesJob,
        $minimumPairMatches,
        PREG_SET_ORDER
    );

    if (count($minimumPairMatches) !== count($minimumRuntimePairs)) {
        $errors[] = 'The minimum dependency matrix must contain exactly one lowest-PHP job per supported Laravel major.';
    }

    foreach ($minimumRuntimePairs as [$phpVersion, $laravelVersion]) {
        $pair = "php: '".$phpVersion."'\n"
            ."            laravel: '".$laravelVersion."'";

        if (substr_count($minimumDependenciesJob, $pair) !== 1) {
            $errors[] = 'The minimum dependency matrix must contain PHP '.$phpVersion
                .' / Laravel '.$laravelVersion.' exactly once.';
        }
    }

    if (! str_contains($minimumDependenciesJob, 'composer update --prefer-lowest --prefer-stable')) {
        $errors[] = 'The minimum dependency job must install Composer dependencies with --prefer-lowest --prefer-stable.';
    }
}

foreach ([
    'timeout-minutes: 20',
    'run: composer test:coverage',
] as $requirement) {
    if (! str_contains($coverageJob, $requirement)) {
        $errors[] = 'The coverage job must contain: '.$requirement.'.';
    }
}

foreach (['tests', 'end-to-end', 'alpine-tests', 'alpine-end-to-end', 'minimum-dependencies', 'dependency-review', 'coverage'] as $requiredJob) {
    if (! str_contains($ciGateJob, '      - '.$requiredJob)) {
        $errors[] = 'The stable CI gate must depend on '.$requiredJob.'.';
    }
}

if (! str_contains($ciGateJob, 'name: CI gate')) {
    $errors[] = 'The stable CI gate must keep the immutable check name "CI gate".';
}

foreach ([
    "if: startsWith(github.ref, 'refs/tags/v')",
    '      - ci-gate',
    'Require a verified annotated tag',
    'tests/Support/validate-release-tag.php',
    '--package-archive=',
    'git merge-base --is-ancestor',
    'actions/attest-build-provenance@',
    'gh release create',
] as $requirement) {
    if (! str_contains($releaseJob, $requirement)) {
        $errors[] = 'The release job must contain: '.$requirement.'.';
    }
}

foreach ([
    "cron: '17 3 * * 1'",
    'actions/dependency-review-action@',
] as $requirement) {
    if (! str_contains($workflow, $requirement)) {
        $errors[] = 'The workflow must contain: '.$requirement.'.';
    }
}

$phpunit = (string) file_get_contents($repositoryPath.'/phpunit.xml');

if (! str_contains($phpunit, '<file>bootstrap/guard.php</file>')) {
    $errors[] = 'Coverage must include the real pre-bootstrap guard.';
}

if (($composer['scripts']['test:e2e:archive'][1] ?? null) !== '@php tests/Support/release.php') {
    $errors[] = 'composer test:e2e:archive must build and test the exact release artifact.';
}

if (! str_contains($jobs['native package tests'], 'timeout-minutes: 20')) {
    $errors[] = 'The native package matrix must have a 20-minute timeout.';
}

$workflowPaths = array_merge(
    glob($repositoryPath.'/.github/workflows/*.yml') ?: [],
    glob($repositoryPath.'/.github/workflows/*.yaml') ?: []
);

foreach ($workflowPaths as $workflowPath) {
    $workflowContents = (string) file_get_contents($workflowPath);
    preg_match_all('/^\s*uses:\s*([^\s#]+)/m', $workflowContents, $actionMatches);

    foreach ($actionMatches[1] as $actionReference) {
        if (str_starts_with($actionReference, './')) {
            continue;
        }

        $separator = strrpos($actionReference, '@');
        $revision = $separator === false ? '' : substr($actionReference, $separator + 1);

        if (preg_match('/^[a-f0-9]{40}$/i', $revision) !== 1) {
            $errors[] = basename($workflowPath).' must pin '.$actionReference.' to a full 40-character commit SHA.';
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
        .' and Laravel '.implode(', ', $nonEolLaravel)
        .' are non-EOL, fully represented on Linux x64 and Windows x64, and covered by representative'
        .' minimum/latest jobs on macOS ARM64, Linux ARM64 and Alpine Linux.'
        .PHP_EOL
);
