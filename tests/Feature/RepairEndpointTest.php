<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    putenv('CONFIG_CACHE_GUARD_REPAIR_ENABLED');
    putenv('CONFIG_CACHE_GUARD_REPAIR_TOKEN');
    putenv('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET');
    putenv('CONFIG_CACHE_GUARD_CONFIG');
    putenv('CONFIG_CACHE_GUARD_ROUTES');
});

afterEach(function (): void {
    putenv('CONFIG_CACHE_GUARD_REPAIR_ENABLED');
    putenv('CONFIG_CACHE_GUARD_REPAIR_TOKEN');
    putenv('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET');
    putenv('CONFIG_CACHE_GUARD_CONFIG');
    putenv('CONFIG_CACHE_GUARD_ROUTES');
});

it('does not expose the repair endpoint without a configured token', function (): void {
    $this->postJson('/_config-cache-guard/repair')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Not found.');
});

it('rejects an invalid repair token', function (): void {
    putenv('CONFIG_CACHE_GUARD_REPAIR_TOKEN=secret-token');

    $this->postJson('/_config-cache-guard/repair', ['token' => 'wrong-token'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Not found.');
});

it('rejects get repair requests unless explicitly allowed', function (): void {
    putenv('CONFIG_CACHE_GUARD_REPAIR_TOKEN=secret-token');
    putenv('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET=false');

    $this->getJson('/_config-cache-guard/repair?token=secret-token')
        ->assertStatus(405)
        ->assertJsonPath('ok', false);
});

it('can trigger a protected repair request without exec', function (): void {
    putenv('CONFIG_CACHE_GUARD_REPAIR_TOKEN=secret-token');
    putenv('CONFIG_CACHE_GUARD_CONFIG=true');
    putenv('CONFIG_CACHE_GUARD_ROUTES=false');

    $kernel = new class implements ConsoleKernelContract
    {
        public array $calls = [];

        public function bootstrap() {}

        public function handle($input, $output = null) {}

        public function call($command, array $parameters = [], $outputBuffer = null)
        {
            $this->calls[] = [$command, $parameters];

            return 0;
        }

        public function queue($command, array $parameters = []) {}

        public function all() {}

        public function output() {}

        public function terminate($input, $status) {}
    };

    $this->app->instance(ConsoleKernelContract::class, $kernel);
    Artisan::clearResolvedInstance(ConsoleKernelContract::class);

    @mkdir(base_path('bootstrap/cache'), 0777, true);
    file_put_contents(base_path('bootstrap/cache/config.php'), '<?php return [];');

    $this->postJson('/_config-cache-guard/repair', ['token' => 'secret-token'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('results.config.status', 'rebuilt');

    expect($kernel->calls)->toBe([['config:cache', []]]);
});
