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

function legacyGuardRequireLine(): string
{
    return "require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';";
}

it('does not require a manual public index change for current installations', function (): void {
    writePublicIndex(<<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
PHP_INDEX);

    $this->artisan('config-cache-guard:install')
        ->expectsOutputToContain('loaded by Composer automatically')
        ->assertExitCode(0);

    expect(readPublicIndex())->not->toContain('laravel-config-cache-guard/bootstrap/guard.php');
});

it('reports a legacy public index require line when it exists', function (): void {
    writePublicIndex(<<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';

require __DIR__ . '/../vendor/autoload.php';
PHP_INDEX);

    $this->artisan('config-cache-guard:install')
        ->expectsOutputToContain('legacy manual require line')
        ->assertExitCode(0);

    expect(readPublicIndex())->toContain(legacyGuardRequireLine());
});

it('removes the legacy public index require line when requested', function (): void {
    writePublicIndex(<<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';

require __DIR__ . '/../vendor/autoload.php';
PHP_INDEX);

    $this->artisan('config-cache-guard:install', ['--remove-legacy' => true])
        ->assertExitCode(0);

    expect(readPublicIndex())->not->toContain('laravel-config-cache-guard/bootstrap/guard.php');
    expect(readPublicIndex())->toContain("require __DIR__ . '/../vendor/autoload.php';");
});

it('does not change public index during a dry run legacy cleanup', function (): void {
    $original = <<<'PHP_INDEX'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';

require __DIR__ . '/../vendor/autoload.php';
PHP_INDEX;

    writePublicIndex($original);

    $this->artisan('config-cache-guard:install', [
        '--dry-run' => true,
        '--remove-legacy' => true,
    ])->assertExitCode(0);

    expect(readPublicIndex())->toBe($original);
});
