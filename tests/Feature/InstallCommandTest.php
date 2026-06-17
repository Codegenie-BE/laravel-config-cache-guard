<?php

declare(strict_types=1);

beforeEach(function (): void {
    @mkdir(public_path(), 0777, true);
});

function writePublicIndex(string $contents): void
{
    file_put_contents(public_path('index.php'), $contents);
}

function readPublicIndex(): string
{
    return (string) file_get_contents(public_path('index.php'));
}

function guardRequireLine(): string
{
    return "require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';";
}

it('installs the guard after the Laravel start marker and before Composer autoloading', function (): void {
    writePublicIndex(<<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
PHP_INDEX);

    $this->artisan('config-cache-guard:install')
        ->assertExitCode(0);

    $contents = readPublicIndex();

    $startPosition = strpos($contents, "define('LARAVEL_START', microtime(true));");
    $guardPosition = strpos($contents, guardRequireLine());
    $autoloadPosition = strpos($contents, "require __DIR__ . '/../vendor/autoload.php';");

    expect($contents)->toContain(guardRequireLine());
    expect($startPosition)->not->toBeFalse();
    expect($guardPosition)->not->toBeFalse();
    expect($autoloadPosition)->not->toBeFalse();
    expect($startPosition)->toBeLessThan($guardPosition);
    expect($guardPosition)->toBeLessThan($autoloadPosition);
});

it('does not install the guard twice', function (): void {
    writePublicIndex(<<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';
PHP_INDEX);

    $this->artisan('config-cache-guard:install')->assertExitCode(0);
    $this->artisan('config-cache-guard:install')->assertExitCode(0);

    expect(substr_count(readPublicIndex(), 'laravel-config-cache-guard/bootstrap/guard.php'))->toBe(1);
});

it('does not change public index during a dry run', function (): void {
    $original = <<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';
PHP_INDEX;

    writePublicIndex($original);

    $this->artisan('config-cache-guard:install', ['--dry-run' => true])
        ->assertExitCode(0);

    expect(readPublicIndex())->toBe($original);
});
