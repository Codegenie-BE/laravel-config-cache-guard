<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Http\Controllers;

use Codegenie\ConfigCacheGuard\Support\DeploymentCacheSignatures;
use Codegenie\ConfigCacheGuard\Support\Environment;
use Codegenie\ConfigCacheGuard\Support\FailureMarker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * @phpstan-type RepairResult array{ok: bool, status: string, message: string}
 * @phpstan-type RepairPayload array{ok: bool, message: string, results?: array<string, RepairResult>}
 */
final class RepairConfigCacheGuardController
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        $token = Environment::string('CONFIG_CACHE_GUARD_REPAIR_TOKEN');

        if (! Environment::flag('CONFIG_CACHE_GUARD_REPAIR_ENABLED', true) || $token === null) {
            return $this->notFound($request);
        }

        $providedToken = $request->headers->get('X-Config-Cache-Guard-Token') ?: $this->requestString($request, 'token');

        if ($providedToken === '' || ! hash_equals($token, $providedToken)) {
            return $this->notFound($request);
        }

        if ($request->isMethod('GET') && ! Environment::flag('CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET', false)) {
            return $this->respond($request, 405, [
                'ok' => false,
                'message' => 'GET repair requests are disabled. Use POST with the X-Config-Cache-Guard-Token header, or explicitly enable CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET.',
            ]);
        }

        $basePath = base_path();
        $cachePath = $basePath.'/bootstrap/cache';
        /** @var array<string, RepairResult> $results */
        $results = [];

        if (Environment::flag('CONFIG_CACHE_GUARD_CONFIG')) {
            $results['config'] = $this->repairConfigCache($basePath, $cachePath);
        } else {
            $results['config'] = [
                'ok' => true,
                'status' => 'skipped',
                'message' => 'Config guard is disabled through CONFIG_CACHE_GUARD_CONFIG.',
            ];
        }

        if ($this->shouldRepairRoutes($cachePath, $request)) {
            $results['routes'] = $this->repairRouteCache($basePath, $cachePath);
        } else {
            $results['routes'] = [
                'ok' => true,
                'status' => 'skipped',
                'message' => 'Route cache repair was skipped because no cached route file or failed route marker exists. Pass routes=1 to force route:cache.',
            ];
        }

        $ok = collect($results)->every(static fn (array $result): bool => $result['ok']);

        return $this->respond($request, $ok ? 200 : 500, [
            'ok' => $ok,
            'message' => $ok
                ? 'Laravel deployment cache repair completed.'
                : 'Laravel deployment cache repair completed with errors.',
            'results' => $results,
        ]);
    }

    /**
     * @return RepairResult
     */
    private function repairConfigCache(string $basePath, string $cachePath): array
    {
        try {
            $exitCode = Artisan::call('config:cache');
            $configCachePath = $cachePath.'/config.php';

            if ($exitCode === 0 && is_file($configCachePath)) {
                DeploymentCacheSignatures::write(
                    $cachePath.'/config-source.signature',
                    DeploymentCacheSignatures::config($basePath)
                );

                @unlink($cachePath.'/config-cache-refresh.failed');

                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($configCachePath, true);
                }

                return [
                    'ok' => true,
                    'status' => 'rebuilt',
                    'message' => 'Config cache was rebuilt using Artisan::call without exec().',
                ];
            }
        } catch (Throwable) {
            // A safe diagnostic marker is written below. Command output and exception details are intentionally not exposed.
        }

        @unlink($cachePath.'/config.php');

        FailureMarker::write(
            $cachePath.'/config-cache-refresh.failed',
            'config',
            'artisan_call_failed',
            'The repair endpoint could not rebuild the Laravel config cache through Artisan::call.',
            'Check whether the application can run php artisan config:cache successfully. The stale config cache was removed.'
        );

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => 'Config cache could not be rebuilt. The stale config cache was removed.',
        ];
    }

    /**
     * @return RepairResult
     */
    private function repairRouteCache(string $basePath, string $cachePath): array
    {
        try {
            $exitCode = Artisan::call('route:cache');
            $routeCachePaths = $this->routeCachePaths($cachePath);

            if ($exitCode === 0 && $routeCachePaths !== []) {
                DeploymentCacheSignatures::write(
                    $cachePath.'/route-source.signature',
                    DeploymentCacheSignatures::routes($basePath)
                );

                @unlink($cachePath.'/route-cache-refresh.failed');

                foreach ($routeCachePaths as $routeCachePath) {
                    if (function_exists('opcache_invalidate')) {
                        @opcache_invalidate($routeCachePath, true);
                    }
                }

                return [
                    'ok' => true,
                    'status' => 'rebuilt',
                    'message' => 'Route cache was rebuilt using Artisan::call without exec().',
                ];
            }
        } catch (Throwable) {
            // A safe diagnostic marker is written below. Command output and exception details are intentionally not exposed.
        }

        foreach ($this->routeCachePaths($cachePath) as $routeCachePath) {
            @unlink($routeCachePath);
        }

        FailureMarker::write(
            $cachePath.'/route-cache-refresh.failed',
            'route',
            'artisan_call_failed',
            'The repair endpoint could not rebuild the Laravel route cache through Artisan::call.',
            'Check whether the application can run php artisan route:cache successfully. This can fail when the application contains non-cacheable routes.'
        );

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => 'Route cache could not be rebuilt. Any stale route cache files were removed.',
        ];
    }

    private function shouldRepairRoutes(string $cachePath, Request $request): bool
    {
        if (! Environment::flag('CONFIG_CACHE_GUARD_ROUTES')) {
            return false;
        }

        if ($this->requestString($request, 'routes') === '1') {
            return true;
        }

        return $this->routeCachePaths($cachePath) !== []
            || is_file($cachePath.'/route-cache-refresh.failed');
    }

    /**
     * @return list<string>
     */
    private function routeCachePaths(string $cachePath): array
    {
        return glob($cachePath.'/routes-*.php') ?: [];
    }

    private function notFound(Request $request): JsonResponse|Response
    {
        return $this->respond($request, 404, [
            'ok' => false,
            'message' => 'Not found.',
        ]);
    }

    /**
     * @param  RepairPayload  $payload
     */
    private function respond(Request $request, int $status, array $payload): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json($payload, $status);
        }

        return response($this->html($payload), $status)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * @param  RepairPayload  $payload
     */
    private function html(array $payload): string
    {
        $ok = $payload['ok'];
        $title = $ok ? 'Config Cache Guard repair completed' : 'Config Cache Guard repair issue';
        $message = htmlspecialchars($payload['message'], ENT_QUOTES, 'UTF-8');
        $rows = '';

        foreach (($payload['results'] ?? []) as $target => $result) {
            $rows .= '<tr><th>'.htmlspecialchars((string) $target, ENT_QUOTES, 'UTF-8').'</th><td>'.htmlspecialchars($result['status'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8').'</td></tr>';
        }

        $table = $rows !== ''
            ? '<table><thead><tr><th>Target</th><th>Status</th><th>Message</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title><style>body{font-family:ui-sans-serif,system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px;line-height:1.6;color:#172033}code{background:#f3f4f6;padding:2px 5px;border-radius:4px}table{border-collapse:collapse;width:100%;margin-top:20px}th,td{border:1px solid #d7dce3;padding:10px;text-align:left;vertical-align:top}th{background:#f8fafc}</style></head><body><h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1><p>'.$message.'</p>'.$table.'<p><strong>Security note:</strong> no .env values, secrets, tokens or command output are shown.</p></body></html>';
    }

    private function requestString(Request $request, string $key): string
    {
        foreach ([$request->query($key), $request->input($key)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
