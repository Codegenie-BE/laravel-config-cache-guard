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
$prepareReleaseWorkflow = (string) file_get_contents($repositoryPath.'/.github/workflows/prepare-release.yml');

$expectedRuntimePairs = [];

foreach ($nonEolLaravel as $laravelVersion) {
    $laravelPolicy = $policy['laravel'][$laravelVersion];

    foreach ($laravelPolicy['php'] as $phpVersion) {
        if (in_array($phpVersion, $nonEolPhp, true)) {
            $expectedRuntimePairs[] = [$phpVersion, $laravelVersion];
        }
    }
}

$jobSection = static function (string $workflowContents, string $job): string {
    $hasJob = preg_match(
        '/^  '.preg_quote($job, '/').':\s*$/mi',
        $workflowContents,
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
        $workflowContents,
        $nextJobMatch,
        PREG_OFFSET_CAPTURE,
        $bodyStart
    ) === 1;
    $end = $hasNextJob ? $nextJobMatch[0][1] : false;

    return $end === false
        ? substr($workflowContents, $start)
        : substr($workflowContents, $start, $end - $start);
};

$jobs = [
    'quality' => $jobSection($workflow, 'quality'),
    'compatibility' => $jobSection($workflow, 'compatibility'),
    'end-to-end' => $jobSection($workflow, 'end-to-end'),
    'portability' => $jobSection($workflow, 'portability'),
    'alpine-portability' => $jobSection($workflow, 'alpine-portability'),
    'minimum-dependencies' => $jobSection($workflow, 'minimum-dependencies'),
    'dependency-review' => $jobSection($workflow, 'dependency-review'),
    'coverage' => $jobSection($workflow, 'coverage'),
    'ci-gate' => $jobSection($workflow, 'ci-gate'),
    'release' => $jobSection($workflow, 'release'),
];

foreach ($jobs as $name => $job) {
    if ($job === '') {
        $errors[] = 'The GitHub Actions workflow is missing the '.$name.' job.';
    }
}

foreach (['quality', 'compatibility', 'end-to-end', 'portability', 'alpine-portability'] as $jobName) {
    if (! str_contains($jobs[$jobName], 'COMPOSER_CACHE_DIR: ${{ github.workspace }}/../.composer-cache')) {
        $errors[] = 'The '.$jobName.' job must keep the Composer cache outside the repository checkout.';
    }
}

foreach ([
    'runs-on: ubuntu-latest',
    "php-version: '8.5'",
    "composer require --dev 'orchestra/testbench:~11.0' 'pestphp/pest:~4.0'",
    'run: composer check',
] as $requirement) {
    if (! str_contains($jobs['quality'], $requirement)) {
        $errors[] = 'The quality job must contain: '.$requirement.'.';
    }
}

preg_match_all(
    '/- php:\s*[\'\"](\d+\.\d+)[\'\"]\s*\R\s*laravel:\s*[\'\"](\d+)[\'\"]\s*\R\s*testbench:\s*[\'\"]([^\'\"]+)[\'\"]\s*\R\s*pest:\s*[\'\"]([^\'\"]+)[\'\"]/m',
    $jobs['compatibility'],
    $compatibilityMatches,
    PREG_SET_ORDER
);

if (count($compatibilityMatches) !== count($expectedRuntimePairs)) {
    $errors[] = 'The Linux compatibility matrix must contain every supported PHP/Laravel pair exactly once.';
}

foreach ($expectedRuntimePairs as [$phpVersion, $laravelVersion]) {
    $testbench = $policy['laravel'][$laravelVersion]['testbench'];
    $pest = $policy['laravel'][$laravelVersion]['pest'];
    $matches = array_filter(
        $compatibilityMatches,
        static fn (array $match): bool => $match[1] === $phpVersion
            && $match[2] === $laravelVersion
            && $match[3] === $testbench
            && $match[4] === $pest
    );

    if (count($matches) !== 1) {
        $errors[] = 'The Linux compatibility matrix must contain PHP '.$phpVersion.' / Laravel '.$laravelVersion
            .' with Testbench '.$testbench.' and Pest '.$pest.' exactly once.';
    }
}

foreach ($compatibilityMatches as $match) {
    if (! in_array([$match[1], $match[2]], $expectedRuntimePairs, true)) {
        $errors[] = 'The Linux compatibility matrix contains unsupported PHP '.$match[1].' / Laravel '.$match[2].'.';
    }
}

foreach ([
    'runs-on: ubuntu-latest',
    'name: Compatibility / Linux x64 / PHP ${{ matrix.runtime.php }} / Laravel ${{ matrix.runtime.laravel }}',
    'run: composer test',
] as $requirement) {
    if (! str_contains($jobs['compatibility'], $requirement)) {
        $errors[] = 'The compatibility job must contain: '.$requirement.'.';
    }
}

$minimumRuntimePairs = [];
foreach ($policy['laravel'] as $laravelVersion => $laravelPolicy) {
    $minimumPhp = $laravelPolicy['php'][0] ?? null;
    if (is_string($minimumPhp) && in_array($minimumPhp, $nonEolPhp, true)) {
        $minimumRuntimePairs[] = [$minimumPhp, (string) $laravelVersion];
    }
}

preg_match_all(
    '/- php:\s*[\'\"](\d+\.\d+)[\'\"]\s*\R\s*laravel:\s*[\'\"](\d+)[\'\"]/m',
    $jobs['end-to-end'],
    $e2eMatches,
    PREG_SET_ORDER
);

if (count($e2eMatches) !== count($minimumRuntimePairs)) {
    $errors[] = 'The Linux E2E matrix must contain one lowest-supported PHP runtime per Laravel major.';
}

foreach ($minimumRuntimePairs as [$phpVersion, $laravelVersion]) {
    $pair = "- php: '".$phpVersion."'\n            laravel: '".$laravelVersion."'";
    if (substr_count($jobs['end-to-end'], $pair) !== 1) {
        $errors[] = 'The Linux E2E matrix must contain PHP '.$phpVersion.' / Laravel '.$laravelVersion.' exactly once.';
    }
}

if (! str_contains($jobs['end-to-end'], 'composer test:e2e -- --laravel=${{ matrix.runtime.laravel }}')) {
    $errors[] = 'The Linux E2E job must execute the real Laravel application suite.';
}

$latestPhp = $nonEolPhp[count($nonEolPhp) - 1] ?? null;
$nativePlatforms = $policy['platforms']['native'];
$portabilityPlatforms = array_values(array_filter(
    $nativePlatforms,
    static fn (array $platform): bool => $platform['runner'] !== 'ubuntu-latest'
));

foreach ($portabilityPlatforms as $platform) {
    $entry = '- name: '.$platform['name']."\n            runner: ".$platform['runner'];
    if (substr_count($jobs['portability'], $entry) !== 1) {
        $errors[] = 'The portability matrix must start '.$platform['name'].' on '.$platform['runner'].' exactly once.';
    }
}

preg_match_all('/^\s{10}- name:/m', $jobs['portability'], $portabilityPlatformMatches);
if (count($portabilityPlatformMatches[0]) !== count($portabilityPlatforms)) {
    $errors[] = 'The portability matrix must contain only the documented non-Linux-x64 native platforms.';
}

foreach ([
    "php-version: '".$latestPhp."'",
    "composer require --dev 'orchestra/testbench:~10.0' 'pestphp/pest:~3.0'",
    'composer test:e2e -- --laravel=12',
    'git checkout -- composer.json',
    "php -r \"if (is_file('composer.lock')) { unlink('composer.lock'); }\"",
    "composer require --dev 'orchestra/testbench:~11.0' 'pestphp/pest:~4.0'",
    'composer test:e2e -- --laravel=13',
] as $requirement) {
    if (! str_contains($jobs['portability'], $requirement)) {
        $errors[] = 'The portability job must contain: '.$requirement.'.';
    }
}

if (substr_count($jobs['portability'], 'uses: shivammathur/setup-php@') !== 1) {
    $errors[] = 'Each native portability job must set up PHP only once and reuse that runner for Laravel 12 and 13.';
}

$alpinePlatform = $policy['platforms']['container'][0] ?? null;
if (! is_array($alpinePlatform)) {
    $errors[] = 'The support policy must define the Alpine container platform.';
} else {
    $alpineDockerfile = $repositoryPath.'/'.$alpinePlatform['dockerfile'];

    foreach ([
        'runs-on: '.$alpinePlatform['runner'],
        $alpinePlatform['name'],
        '--build-arg PHP_VERSION='.$latestPhp,
        '--file '.$alpinePlatform['dockerfile'],
        'composer test:e2e -- --laravel=12',
        'git checkout -- composer.json',
        'rm -f composer.lock',
        'composer test:e2e -- --laravel=13',
    ] as $requirement) {
        if (! str_contains($jobs['alpine-portability'], $requirement)) {
            $errors[] = 'The Alpine portability job must contain: '.$requirement.'.';
        }
    }

    if (substr_count($jobs['alpine-portability'], 'docker build ') !== 1) {
        $errors[] = 'The Alpine portability job must build one reusable PHP environment per workflow run.';
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

preg_match_all(
    '/- php:\s*[\'\"](\d+\.\d+)[\'\"]\s*\R\s*laravel:\s*[\'\"](\d+)[\'\"]/m',
    $jobs['minimum-dependencies'],
    $minimumPairMatches,
    PREG_SET_ORDER
);

if (count($minimumPairMatches) !== count($minimumRuntimePairs)) {
    $errors[] = 'The minimum dependency matrix must contain exactly one lowest-PHP job per supported Laravel major.';
}

foreach ($minimumRuntimePairs as [$phpVersion, $laravelVersion]) {
    $pair = "- php: '".$phpVersion."'\n            laravel: '".$laravelVersion."'";
    if (substr_count($jobs['minimum-dependencies'], $pair) !== 1) {
        $errors[] = 'The minimum dependency matrix must contain PHP '.$phpVersion.' / Laravel '.$laravelVersion.' exactly once.';
    }
}

if (! str_contains($jobs['minimum-dependencies'], 'composer update --prefer-lowest --prefer-stable')) {
    $errors[] = 'The minimum dependency job must install Composer dependencies with --prefer-lowest --prefer-stable.';
}

foreach (['timeout-minutes: 20', 'run: composer test:coverage'] as $requirement) {
    if (! str_contains($jobs['coverage'], $requirement)) {
        $errors[] = 'The coverage job must contain: '.$requirement.'.';
    }
}

foreach (['quality', 'compatibility', 'end-to-end', 'portability', 'alpine-portability', 'minimum-dependencies', 'dependency-review', 'coverage'] as $requiredJob) {
    if (! str_contains($jobs['ci-gate'], '      - '.$requiredJob)) {
        $errors[] = 'The stable CI gate must depend on '.$requiredJob.'.';
    }
}

if (! str_contains($jobs['ci-gate'], 'name: CI gate')) {
    $errors[] = 'The stable CI gate must keep the immutable check name "CI gate".';
}

foreach ([
    "github.event_name == 'push' && github.ref == 'refs/heads/main'",
    '      - ci-gate',
    'Detect pending changelog release',
    'tests/Support/detect-release.php',
    'tests/Support/validate-release-tag.php',
    '--package-archive=',
    'git tag --annotate',
    'actions/attest-build-provenance@',
    'gh release create',
    'Verify Packagist publication',
    'repo.packagist.org/p2/codegenie-be/laravel-config-cache-guard.json',
    'any(.version == $tag and .source.reference == $commit)',
] as $requirement) {
    if (! str_contains($jobs['release'], $requirement)) {
        $errors[] = 'The release job must contain: '.$requirement.'.';
    }
}

if (str_contains($workflow, "tags:\n      - 'v*'")) {
    $errors[] = 'Release publication must be driven by protected main after CI, not by an externally pushed tag.';
}

foreach ([
    'workflow_dispatch:',
    'type: choice',
    '          - patch',
    '          - minor',
    '          - major',
    'actions: write',
    'contents: write',
    'pull-requests: write',
    'tests/Support/next-release-tag.php',
    'tests/Support/prepare-release.php',
    'tests/Support/validate-release-tag.php',
    'gh pr create',
    '--event pull_request',
    '.headSha == \"${release_sha}\" and .conclusion == \"action_required\"',
    'actions/runs/${approval_run_id}/approve',
    'gh pr merge "${pr_url}" --auto --squash',
    'if [ "${pr_state}" = "MERGED" ]',
    'git push origin --delete "${branch}"',
    'gh workflow run tests.yml --ref main',
] as $requirement) {
    if (! str_contains($prepareReleaseWorkflow, $requirement)) {
        $errors[] = 'The release-PR workflow must contain: '.$requirement.'.';
    }
}

if (str_contains($prepareReleaseWorkflow, 'gh workflow run tests.yml --ref "${branch}"')) {
    $errors[] = 'The release-PR workflow must not dispatch duplicate branch CI before approving its pull-request run.';
}

foreach (["cron: '17 3 * * 1'", 'actions/dependency-review-action@'] as $requirement) {
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
        .' are fully covered by the Linux compatibility matrix; Windows x64, macOS ARM64, Linux ARM64 and Alpine Linux each reuse one representative PHP environment to test Laravel 12 and 13.'
        .PHP_EOL
);
