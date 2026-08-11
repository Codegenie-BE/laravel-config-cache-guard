<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$repositoryPath = dirname(__DIR__, 2);
$temporaryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'config-cache-guard-distribution-'.bin2hex(random_bytes(8));
$archivePath = null;

$fail = static function (string $message): never {
    fwrite(STDERR, '[distribution] '.$message.PHP_EOL);
    exit(1);
};

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

if (! mkdir($temporaryPath, 0700, true) && ! is_dir($temporaryPath)) {
    $fail('Could not create the temporary archive directory.');
}

try {
    $composerBinary = getenv('COMPOSER_BINARY');
    $composerCommand = is_string($composerBinary) && $composerBinary !== ''
        ? (str_ends_with(strtolower($composerBinary), '.phar')
            ? [PHP_BINARY, $composerBinary]
            : [$composerBinary])
        : ['composer'];

    $process = new Process(array_merge($composerCommand, [
        'archive',
        '--format=tar',
        '--dir='.$temporaryPath,
        '--no-interaction',
    ]), $repositoryPath);
    $process->setTimeout(null);
    $process->run();

    if (! $process->isSuccessful()) {
        $fail('Composer could not build the package archive: '.trim($process->getErrorOutput() ?: $process->getOutput()));
    }

    $archives = glob($temporaryPath.DIRECTORY_SEPARATOR.'*.tar') ?: [];

    if (count($archives) !== 1) {
        $fail('Composer must create exactly one TAR archive.');
    }

    $archivePath = $archives[0];
    $extractPath = $temporaryPath.DIRECTORY_SEPARATOR.'extracted';

    if (! mkdir($extractPath, 0700, true) && ! is_dir($extractPath)) {
        $fail('Could not create the temporary extraction directory.');
    }

    (new PharData($archivePath))->extractTo($extractPath, null, true);

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($extractPath) + 1));
    }

    sort($files, SORT_STRING);

    $requiredFiles = [
        'CHANGELOG.md',
        'LICENSE.md',
        'README.md',
        'SECURITY.md',
        'bootstrap/guard.php',
        'composer.json',
    ];

    foreach (glob($repositoryPath.'/src/*.php') ?: [] as $sourceFile) {
        $requiredFiles[] = 'src/'.basename($sourceFile);
    }

    $sourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repositoryPath.'/src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($sourceIterator as $sourceFile) {
        if ($sourceFile->isFile() && strtolower($sourceFile->getExtension()) === 'php') {
            $requiredFiles[] = 'src/'.str_replace('\\', '/', substr(
                $sourceFile->getPathname(),
                strlen($repositoryPath.'/src') + 1
            ));
        }
    }

    foreach (array_unique($requiredFiles) as $requiredFile) {
        if (! in_array($requiredFile, $files, true)) {
            $fail('Required runtime file is missing from the archive: '.$requiredFile);
        }
    }

    $forbiddenPrefixes = ['.github/', 'docs/', 'tests/', 'vendor/'];
    $forbiddenFiles = [
        '.gitattributes',
        '.gitignore',
        'ADOPTERS.md',
        'CODE_OF_CONDUCT.md',
        'CONTRIBUTING.md',
        'SUPPORT.md',
        'composer.lock',
        'phpstan.neon',
        'phpunit.xml',
    ];

    foreach ($files as $file) {
        foreach ($forbiddenPrefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                $fail('Development-only path leaked into the package archive: '.$file);
            }
        }

        if (in_array($file, $forbiddenFiles, true)) {
            $fail('Development-only file leaked into the package archive: '.$file);
        }
    }

    if (count($files) > 40) {
        $fail('The package archive contains '.count($files).' files; review unexpected distribution growth.');
    }

    $archiveSize = filesize($archivePath);

    if (! is_int($archiveSize) || $archiveSize > 2_000_000) {
        $fail('The package archive exceeds the 2 MB release limit.');
    }

    fwrite(
        STDOUT,
        '[distribution] Composer archive contains '.count($files).' runtime files and '.number_format($archiveSize).' bytes.'.PHP_EOL
    );
} finally {
    $removeDirectory($temporaryPath);
}
