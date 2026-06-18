<?php

declare(strict_types=1);

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

    Artisan::shouldReceive('call')
        ->once()
        ->with('config:cache')
        ->andReturn(0);

    @mkdir(base_path('bootstrap/cache'), 0777, true);
    file_put_contents(base_path('bootstrap/cache/config.php'), '<?php return [];');

    $this->postJson('/_config-cache-guard/repair', ['token' => 'secret-token'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('results.config.status', 'rebuilt');
});
