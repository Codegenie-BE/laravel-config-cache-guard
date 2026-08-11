<?php

declare(strict_types=1);

return [
    'sources' => [
        'php' => 'https://www.php.net/supported-versions.php',
        'laravel' => 'https://laravel.com/docs/13.x/releases',
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
