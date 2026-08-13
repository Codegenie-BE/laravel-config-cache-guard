<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$repositoryPath = dirname(__DIR__, 2);
$temporaryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'config-cache-guard-release-'.bin2hex(random_bytes(8));

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
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

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};

$exitCode = 0;

try {
    if (! mkdir($temporaryPath, 0700, true) && ! is_dir($temporaryPath)) {
        $fail('Could not create the temporary release directory.');
    }

    $composerBinary = getenv('COMPOSER_BINARY');
    $composerCommand = is_string($composerBinary) && $composerBinary !== ''
        ? (str_ends_with(strtolower($composerBinary), '.phar')
            ? [PHP_BINARY, $composerBinary]
            : [$composerBinary])
        : ['composer'];
    $archivePath = $temporaryPath.DIRECTORY_SEPARATOR.'laravel-config-cache-guard-e2e.zip';
    $archive = new Process(array_merge($composerCommand, [
        'archive',
        '--format=zip',
        '--dir='.$temporaryPath,
        '--file=laravel-config-cache-guard-e2e',
        '--no-interaction',
    ]), $repositoryPath);
    $archive->setTimeout(null);
    $archive->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! $archive->isSuccessful() || ! is_file($archivePath)) {
        $fail('Composer could not build the release ZIP.');
    }

    $e2e = new Process(array_merge([
        PHP_BINARY,
        $repositoryPath.'/tests/E2E/LaravelConfigCacheGuardE2e.php',
        '--package-archive='.$archivePath,
    ], array_slice($argv, 1)), $repositoryPath);
    $e2e->setTimeout(null);
    $e2e->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! $e2e->isSuccessful()) {
        $fail('The built release ZIP failed end-to-end testing.');
    }

    fwrite(STDOUT, '[release] Built release ZIP passed end-to-end testing.'.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[release] '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    $removeDirectory($temporaryPath);
}

exit($exitCode);
