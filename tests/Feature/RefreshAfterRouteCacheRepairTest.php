<?php

declare(strict_types=1);

use Codegenie\ConfigCacheGuard\Http\Middleware\RefreshAfterRouteCacheRepair;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    putenv('CONFIG_CACHE_GUARD_AUTO_REFRESH');
});

afterEach(function (): void {
    putenv('CONFIG_CACHE_GUARD_AUTO_REFRESH');
});

it('redirects browser get requests after route cache auto repair', function (): void {
    $request = Request::create('https://example.test/dashboard?tab=routes', 'GET');
    $request->attributes->set(DeploymentCacheRepairer::ROUTE_CACHE_REPAIRED_ATTRIBUTE, true);

    $response = (new RefreshAfterRouteCacheRepair)->handle(
        $request,
        static fn (): Response => new Response('stale response')
    );

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe('https://example.test/dashboard?tab=routes');
    expect($response->headers->get('X-Config-Cache-Guard-Refresh'))->toBe('route-cache-repaired');
});

it('redirects before dispatching through stale routes after route cache auto repair', function (): void {
    $request = Request::create('https://example.test/new-route', 'GET');
    $request->attributes->set(DeploymentCacheRepairer::ROUTE_CACHE_REPAIRED_ATTRIBUTE, true);

    $response = (new RefreshAfterRouteCacheRepair)->handle(
        $request,
        static function (): Response {
            throw new RuntimeException('The stale route pipeline should not run.');
        }
    );

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe('https://example.test/new-route');
});

it('does not redirect json requests after route cache auto repair', function (): void {
    $request = Request::create('https://example.test/api/status', 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $request->attributes->set(DeploymentCacheRepairer::ROUTE_CACHE_REPAIRED_ATTRIBUTE, true);

    $response = (new RefreshAfterRouteCacheRepair)->handle(
        $request,
        static fn (): Response => new Response('json response')
    );

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('json response');
});

it('does not redirect non-idempotent requests after route cache auto repair', function (): void {
    $request = Request::create('https://example.test/forms', 'POST');
    $request->attributes->set(DeploymentCacheRepairer::ROUTE_CACHE_REPAIRED_ATTRIBUTE, true);

    $response = (new RefreshAfterRouteCacheRepair)->handle(
        $request,
        static fn (): Response => new Response('post response')
    );

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('post response');
});

it('can disable browser refresh after route cache auto repair', function (): void {
    putenv('CONFIG_CACHE_GUARD_AUTO_REFRESH=false');

    $request = Request::create('https://example.test/dashboard', 'GET');
    $request->attributes->set(DeploymentCacheRepairer::ROUTE_CACHE_REPAIRED_ATTRIBUTE, true);

    $response = (new RefreshAfterRouteCacheRepair)->handle(
        $request,
        static fn (): Response => new Response('refresh disabled')
    );

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('refresh disabled');
}
);
