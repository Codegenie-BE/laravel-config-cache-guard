<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const PACKAGE_NAME = 'codegenie-be/laravel-config-cache-guard';

$repositoryPath = dirname(__DIR__, 2);
$laravelMajor = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--laravel=')) {
        $laravelMajor = substr($argument, strlen('--laravel='));
    }
}

if (! in_array($laravelMajor, ['12', '13'], true)) {
    fwrite(STDERR, '[smoke] --laravel must be 12 or 13.'.PHP_EOL);
    exit(1);
}

$temporaryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'config-cache-guard-smoke-'.bin2hex(random_bytes(8));
$applicationPath = $temporaryPath.DIRECTORY_SEPARATOR.'application';
$configCachePath = $applicationPath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
$signaturePath = $applicationPath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config-source.signature';

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
};

$composerCommand = static function (): array {
    $composerBinary = getenv('COMPOSER_BINARY');

    if (is_string($composerBinary) && $composerBinary !== '') {
        return str_ends_with(strtolower($composerBinary), '.phar')
            ? [PHP_BINARY, $composerBinary]
            : [$composerBinary];
    }

    return ['composer'];
};

$run = static function (array $command, string $workingDirectory, array $environment = []): string {
    $process = new Process($command, $workingDirectory, $environment === [] ? null : $environment);
    $process->setTimeout(null);
    $process->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! $process->isSuccessful()) {
        throw new RuntimeException('Process failed: '.implode(' ', $command));
    }

    return $process->getOutput();
};

$setEnvironmentValue = static function (string $contents, string $key, string $value): string {
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $contents) === 1) {
        $updated = preg_replace($pattern, $line, $contents, 1);

        if (is_string($updated)) {
            return $updated;
        }
    }

    return rtrim($contents).PHP_EOL.$line.PHP_EOL;
};

$writeEnvironmentValue = static function (string $path, string $key, string $value) use ($setEnvironmentValue): void {
    $contents = (string) file_get_contents($path);
    $updated = $setEnvironmentValue($contents, $key, $value);

    if (file_put_contents($path, $updated) === false) {
        throw new RuntimeException('Could not update '.$path.'.');
    }
};

$assertCachedValue = static function (string $path, string $expected): void {
    if (! is_file($path)) {
        throw new RuntimeException('Laravel config cache was not created at '.$path.'.');
    }

    $config = require $path;
    $actual = is_array($config) ? ($config['e2e']['value'] ?? null) : null;

    if ($actual !== $expected) {
        throw new RuntimeException('Expected cached e2e value '.$expected.', got '.var_export($actual, true).'.');
    }
};

$exitCode = 0;

try {
    if (! mkdir($temporaryPath, 0700, true) && ! is_dir($temporaryPath)) {
        throw new RuntimeException('Could not create the smoke-test directory.');
    }

    fwrite(STDOUT, '[smoke laravel '.$laravelMajor.'] Fetching the Laravel skeleton without installing vendor dependencies'.PHP_EOL);
    $run(array_merge($composerCommand(), [
        'create-project',
        'laravel/laravel:^'.$laravelMajor.'.0',
        $applicationPath,
        '--prefer-dist',
        '--no-install',
        '--no-dev',
        '--no-interaction',
        '--no-progress',
        '--no-scripts',
    ]), $temporaryPath);

    $environmentPath = $applicationPath.DIRECTORY_SEPARATOR.'.env';
    $environment = (string) file_get_contents($applicationPath.DIRECTORY_SEPARATOR.'.env.example');
    $environment = $setEnvironmentValue($environment, 'APP_ENV', 'testing');
    $environment = $setEnvironmentValue($environment, 'APP_KEY', 'base64:'.base64_encode('config-cache-guard-smoke-key-001'));
    $environment = $setEnvironmentValue($environment, 'APP_DEBUG', 'false');
    $environment = $setEnvironmentValue($environment, 'CACHE_STORE', 'array');
    $environment = $setEnvironmentValue($environment, 'LOG_CHANNEL', 'stderr');
    $environment = $setEnvironmentValue($environment, 'SESSION_DRIVER', 'array');
    $environment = $setEnvironmentValue($environment, 'E2E_CONFIG_VALUE', 'smoke-initial');

    if (file_put_contents($environmentPath, $environment) === false) {
        throw new RuntimeException('Could not create the Laravel smoke-test environment file.');
    }

    $configDirectory = $applicationPath.DIRECTORY_SEPARATOR.'config';

    if (! is_dir($configDirectory) && ! mkdir($configDirectory, 0777, true) && ! is_dir($configDirectory)) {
        throw new RuntimeException('Could not create the Laravel config directory.');
    }

    if (file_put_contents(
        $configDirectory.DIRECTORY_SEPARATOR.'e2e.php',
        "<?php\n\nreturn ['value' => env('E2E_CONFIG_VALUE', 'missing')];\n",
    ) === false) {
        throw new RuntimeException('Could not create the smoke-test config file.');
    }

    $composerPath = $applicationPath.DIRECTORY_SEPARATOR.'composer.json';
    $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
    $composer['repositories'] = [
        'config-cache-guard-e2e' => [
            'type' => 'path',
            'url' => str_replace('\\', '/', $repositoryPath),
            'options' => [
                'symlink' => false,
                'versions' => [PACKAGE_NAME => 'dev-e2e'],
            ],
        ],
    ];
    $composer['require'][PACKAGE_NAME] = 'dev-e2e';

    if (file_put_contents(
        $composerPath,
        json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    ) === false) {
        throw new RuntimeException('Could not update the Laravel composer.json file.');
    }

    fwrite(STDOUT, '[smoke laravel '.$laravelMajor.'] Resolving Laravel and the package in one Composer install phase'.PHP_EOL);
    $run(array_merge($composerCommand(), [
        'update',
        '--with-all-dependencies',
        '--prefer-dist',
        '--no-dev',
        '--no-interaction',
        '--no-progress',
    ]), $applicationPath);

    $artisan = $applicationPath.DIRECTORY_SEPARATOR.'artisan';
    $run([PHP_BINARY, $artisan, 'config:cache'], $applicationPath);
    $run([PHP_BINARY, $artisan, 'config-cache-guard:status'], $applicationPath);
    $assertCachedValue($configCachePath, 'smoke-initial');

    if (! is_file($signaturePath)) {
        throw new RuntimeException('The guard did not persist a config source signature.');
    }

    $writeEnvironmentValue($environmentPath, 'E2E_CONFIG_VALUE', 'smoke-refreshed-value-longer');

    fwrite(STDOUT, '[smoke laravel '.$laravelMajor.'] Verifying explicit CLI stale-cache repair'.PHP_EOL);
    $run(
        [PHP_BINARY, $artisan, 'config-cache-guard:status'],
        $applicationPath,
        ['CONFIG_CACHE_GUARD_ALLOW_CLI' => 'true'],
    );
    $assertCachedValue($configCachePath, 'smoke-refreshed-value-longer');
    fwrite(STDOUT, '[smoke laravel '.$laravelMajor.'] Portability smoke scenarios passed'.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[smoke laravel '.$laravelMajor.'] '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    $removeDirectory($temporaryPath);
}

exit($exitCode);
