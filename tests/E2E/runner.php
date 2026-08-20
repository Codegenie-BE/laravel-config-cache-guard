<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$suite = 'full';
$forwardedArguments = [];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--suite=')) {
        $suite = substr($argument, strlen('--suite='));

        continue;
    }

    $forwardedArguments[] = $argument;
}

if (! in_array($suite, ['full', 'smoke'], true)) {
    fwrite(STDERR, '[e2e] --suite must be either full or smoke.'.PHP_EOL);
    exit(1);
}

$script = $suite === 'smoke'
    ? __DIR__.'/LaravelConfigCacheGuardSmoke.php'
    : __DIR__.'/LaravelConfigCacheGuardProductionE2e.php';

// These are real fresh Laravel applications exercising production deployment
// cache behavior. APP_ENV=testing would make Laravel treat the process as a
// unit-test runtime and disable production cache behavior.
$environment = ['APP_ENV' => 'production'];
$process = new Process(
    array_merge([PHP_BINARY, $script], $forwardedArguments),
    dirname(__DIR__, 2),
    $environment,
);
$process->setTimeout(null);
$process->run(static function (string $type, string $output): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
});

exit($process->getExitCode() ?? 1);
