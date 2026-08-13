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

$jobSection = static function (string $workflow, string $job, ?string $nextJob = null): string {
    $startMarker = '  '.$job.':';
    $start = strpos($workflow, $startMarker);

    if ($start === false) {
        return '';
    }

    if ($nextJob === null) {
        return substr($workflow, $start);
    }

    $end = strpos($workflow, '  '.$nextJob.':', $start + strlen($startMarker));

    return $end === false
        ? substr($workflow, $start)
        : substr($workflow, $start, $end - $start);
};

$jobs = [
    'native package tests' => $jobSection($workflow, 'tests', 'end-to-end'),
    'native E2E tests' => $jobSection($workflow, 'end-to-end', 'alpine-tests'),
    'Alpine package tests' => $jobSection($workflow, 'alpine-tests', 'alpine-end-to-end'),
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

    foreach (['native package tests', 'Alpine package tests'] as $label) {
        if (substr_count($jobs[$label], $packageEntry) !== 1) {
            $errors[] = 'The '.$label.' matrix must contain PHP '.$phpVersion.' / Laravel '
                .$laravelVersion.' with Testbench '.$testbench.' and Pest '.$pest.' exactly once.';
        }
    }

    foreach (['native E2E tests', 'Alpine E2E tests'] as $label) {
        if (substr_count($jobs[$label], $e2eEntry) !== 1) {
            $errors[] = 'The '.$label.' matrix must contain PHP '.$phpVersion.' / Laravel '
                .$laravelVersion.' exactly once.';
        }
    }
}

foreach ($jobs as $label => $job) {
    preg_match_all(
        '/php:\s*[\'\"](\d+\.\d+)[\'\"]\s*\R\s*laravel:\s*[\'\"](\d+)[\'\"]/m',
        $job,
        $matrixPairMatches,
        PREG_SET_ORDER
    );

    if (count($matrixPairMatches) !== count($expectedRuntimePairs)) {
        $errors[] = 'The '.$label.' matrix must contain exactly '.count($expectedRuntimePairs)
            .' compatible PHP/Laravel runtime entries.';
    }

    foreach ($matrixPairMatches as $match) {
        if (! in_array([$match[1], $match[2]], $expectedRuntimePairs, true)) {
            $errors[] = 'The '.$label.' matrix contains unsupported PHP '
                .$match[1].' / Laravel '.$match[2].'.';
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
        .' are non-EOL and fully represented across '.count($policy['platforms']['native'])
        .' native platforms plus Alpine Linux in both package and E2E CI.'
        .PHP_EOL
);
