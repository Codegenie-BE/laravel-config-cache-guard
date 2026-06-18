<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Http\Middleware;

use Closure;
use Codegenie\ConfigCacheGuard\Support\DeploymentCacheRepairer;
use Codegenie\ConfigCacheGuard\Support\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RefreshAfterRouteCacheRepair
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRefresh($request) && DeploymentCacheRepairer::routeCacheWasRepairedFor($request)) {
            return $this->redirectToCurrentUrl($request);
        }

        return $next($request);
    }

    private function shouldRefresh(Request $request): bool
    {
        if (! Environment::flag('CONFIG_CACHE_GUARD_AUTO_REFRESH', true)) {
            return false;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        return ! $request->expectsJson() && ! $request->ajax();
    }

    private function redirectToCurrentUrl(Request $request): RedirectResponse
    {
        return redirect()
            ->to($request->fullUrl(), 302)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('X-Config-Cache-Guard-Refresh', 'route-cache-repaired');
    }
}
