<?php

declare(strict_types=1);

return [
    'sources' => [
        'github_runners' => 'https://docs.github.com/actions/reference/runners/github-hosted-runners',
        'php' => 'https://www.php.net/supported-versions.php',
        'laravel' => 'https://laravel.com/docs/13.x/releases',
    ],
    'platforms' => [
        'native' => [
            ['name' => 'Linux x64', 'runner' => 'ubuntu-latest'],
            ['name' => 'Windows x64', 'runner' => 'windows-latest'],
            ['name' => 'macOS ARM64', 'runner' => 'macos-latest'],
            ['name' => 'Linux ARM64', 'runner' => 'ubuntu-24.04-arm'],
        ],
        'container' => [
            [
                'dockerfile' => '.github/docker/php-alpine.Dockerfile',
                'name' => 'Alpine Linux x64',
                'runner' => 'ubuntu-latest',
            ],
        ],
    ],
    'php' => [
        '8.2' => ['security_fixes_until' => '2026-12-31'],
        '8.3' => ['security_fixes_until' => '2027-12-31'],
        '8.4' => ['security_fixes_until' => '2028-12-31'],
        '8.5' => ['security_fixes_until' => '2029-12-31'],
    ],
    'laravel' => [
        '12' => [
            'php' => ['8.2', '8.3', '8.4', '8.5'],
            'security_fixes_until' => '2027-02-24',
            'testbench' => '^10.0',
            'pest' => '^3.0',
        ],
        '13' => [
            'php' => ['8.3', '8.4', '8.5'],
            'security_fixes_until' => '2028-03-17',
            'testbench' => '^11.0',
            'pest' => '^4.0',
        ],
    ],
];
