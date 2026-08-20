# Laravel Config Cache Guard

[![Tests](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/codegenie-be/laravel-config-cache-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-config-cache-guard)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/13.x/releases)

**by [Codegenie](https://www.codegenie.be)**

Laravel Config Cache Guard is a zero-configuration safety and performance layer for Laravel config and route cache. It prevents known-stale deployment cache from being loaded before Laravel boots, automatically creates missing caches when Laravel supports them, and repairs cache after the HTTP response without making visitors wait for Artisan compilation.

## Quick start

```bash
composer require codegenie-be/laravel-config-cache-guard
```

That is enough for the normal flow. No `public/index.php` change and no `CONFIG_CACHE_GUARD_*` variable is required.

Optionally inspect the current state with:

```bash
php artisan config-cache-guard:status
```

## Default behavior

On normal HTTP traffic the package:

- protects existing config cache before Laravel can load stale configuration
- protects existing route cache before stale routes can be dispatched
- automatically queues missing config and route cache creation after the response
- never starts a child PHP process or waits for a repair lock before Laravel boots
- performs cache generation through Laravel's own `Artisan::call()` after the response
- serializes config and route mutation behind one non-blocking deployment repair lock
- uses a deployment source manifest so healthy requests verify known sources without recursively rediscovering them
- stores signature-based route-cache copies when the default Laravel route-cache path is used
- works without SSH, Redis, queues, workers, cron, a database or a public repair endpoint

## Request path

The visitor-facing path deliberately contains detection and protection only:

```text
HTTP request
  -> Composer loads bootstrap/guard.php
  -> verify known deployment source state
  -> current cache: continue immediately
  -> stale config: remove it and queue repair
  -> stale routes: bypass/remove them and queue repair
  -> Laravel boots with safe cached or uncached state
  -> HTTP response is sent
  -> one terminating request acquires the non-blocking repair lock
  -> config:cache and/or route:cache run only when required
  -> signatures and the deployment source manifest are refreshed
```

The pre-bootstrap guard does not run `php artisan`, does not call `proc_open()` and does not wait for another request to finish cache compilation.

## Deployment source manifest

The active Laravel bootstrap cache directory contains a small file:

```text
deployment-source.manifest.json
```

It stores only:

- relative source paths
- filesystem metadata fingerprints, or one-way content hashes in content mode
- directory fingerprints used to detect added/removed source files
- the current config and route signatures
- a one-way runtime identity for config portability checks

It does **not** store `.env` values or raw application base paths.

When the manifest is current, the guard avoids repeated `RecursiveDirectoryIterator` discovery. It checks the already-known source files and directories directly. When a file or source directory changes, the manifest is rebuilt from one shared traversal and both config and route signatures are derived from that same snapshot.

## Sources covered

Deployment state includes:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is available before Composer loads
- `config/**/*.php`
- `routes/**/*.php`
- `app/Providers/**/*.php`
- the active bootstrap `app.php` and `providers.php`
- `composer.json`
- `composer.lock`

Config signatures also include a one-way runtime identity derived from the application location and OS family. A config cache signed at another runtime path is therefore not assumed to be portable.

The default signature mode uses filesystem metadata. Optional content mode hashes source bytes to detect same-size rewrites that preserve file metadata.

## Missing cache creation

If config cache is missing, the package queues `config:cache` after the current response.

If route cache is missing, the package queues `route:cache` after the current response.

This is fail-safe:

- a failed cache build is removed instead of retained as untracked state
- routes that Laravel cannot cache continue through normal uncached routing
- a failed source signature is remembered for a bounded cooldown so identical source state is not retried on every request
- when source state changes, the package can immediately try again

### Laravel config-cache contract

Automatic config caching assumes normal Laravel production conventions: use `env()` inside configuration files and read values elsewhere through `config()`.

```php
config('services.mailgun.secret');
```

Direct `env()` access in application code outside `config/*.php` can behave differently after `config:cache` is enabled and should be corrected before production caching is relied on.

## Route cache behavior

When Laravel uses its default route-cache location, a successful route build is also tracked as:

```text
bootstrap/cache/routes-<source-signature>.php
```

The pre-bootstrap guard points Laravel at the route cache for the **current** source signature. If that file does not exist, Laravel falls back to uncached route registration for the current request while repair is queued. An old route cache can remain on disk without being selected.

Explicit custom `APP_ROUTES_CACHE` paths remain supported when they are available before Composer loads. Stale custom route cache is removed rather than redirected to a package-managed versioned path.

## Concurrency

Deferred cache mutation uses one file:

```text
deployment-cache-repair.lock
```

The lock is non-blocking. If 20 requests finish at the same time after a deployment, one worker performs the pending config/route repair and the others return from termination without waiting.

Config and route cache commands are executed sequentially by that one repair owner, avoiding simultaneous deployment-cache mutation by different workers.

## Native deployment commands

A deployment pipeline with command access can still build caches explicitly:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Only run `route:cache` manually when the application's routes support Laravel route caching.

Successful native cache commands are tracked automatically. The package records current deployment signatures and prepares the signature-based route cache where appropriate.

## Shared hosting and FTP-only deployments

Destination-side shell access is optional.

For FTP-only or restricted shared hosting:

1. package or install production Composer dependencies before upload
2. upload a clean release instead of overlaying an old vendor tree when possible
3. preserve the production `.env`
4. keep Laravel's active bootstrap cache directory writable by PHP
5. send normal HTTP traffic

Missing cache is generated after a response. Existing stale cache is rejected or bypassed before Laravel uses it. No PHP CLI binary has to be configured for the HTTP fallback.

See [deployment recipes](docs/deployment-recipes.md) for concrete flows.

## Status command

```bash
php artisan config-cache-guard:status
```

The command reports active cache paths, deployment-source manifest state, config/route signatures, pending and failed repair state, recent successful repairs and the shared deferred repair lock.

Use strict mode in deployment automation:

```bash
php artisan config-cache-guard:status --strict
```

Clear intentionally resolved failure state with:

```bash
php artisan config-cache-guard:status --clear-failures
```

## Optional overrides

The normal package behavior needs no package-specific environment variables. Existing compatibility overrides remain available when configured before Composer loads, including:

- disabling the complete guard or one cache target
- disabling automatic after-response repair
- selecting `content` signature mode
- disabling signature-based route cache files
- enabling fail-hard diagnostics
- changing the failure retry cooldown

Laravel's own optional cache path overrides are also respected:

- `APP_CONFIG_CACHE`
- `APP_ROUTES_CACHE`

No override is required for standard Laravel paths.

## Files written by the package

Depending on active cache state, the Laravel bootstrap cache directory can contain:

```text
config-source.signature
route-source.signature
deployment-source.manifest.json
deployment-cache-repair.lock
config-cache-refresh.pending
config-cache-refresh.failed
config-cache-refresh.succeeded
route-cache-refresh.pending
route-cache-refresh.failed
route-cache-refresh.succeeded
routes-<source-signature>.php
```

Markers contain safe reason/action metadata and optional one-way source signatures, never `.env` values or command output.

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- writable active Laravel bootstrap cache directory (`bootstrap/cache` or `.laravel/cache`)
- writable configured cache destination when a custom Laravel cache path is used

## Failure behavior

| Situation | Behavior |
| --- | --- |
| Cache and manifest are current | Verify known sources and continue. |
| Config cache is missing | Queue `config:cache` after the response. |
| Route cache is missing | Queue `route:cache` after the response. |
| Route cache cannot be created | Continue with uncached routing and remember the source signature for the retry cooldown. |
| Config source/runtime state changed | Remove stale config before Laravel can load it and queue repair. |
| Route source state changed | Point Laravel away from stale routes and queue repair. |
| Repair lock is held by another request | Return immediately; do not duplicate repair. |
| Sources change during repair | Discard the just-built cache and requeue the new signature. |
| Rebuilt cache signature cannot be stored | Remove the untracked cache. |
| Known-stale cache cannot be removed | Stop with a safe 503 rather than load known-stale state. |

## Development

Install development dependencies and run:

```bash
composer install
composer check:all
```

Before a release, also run:

```bash
composer test:e2e
composer check:release
```

The repository validates Laravel 12 and 13 across their supported PHP matrix and includes Windows, macOS, Linux ARM64 and Alpine portability coverage.

## Security and privacy

- no `.env` values are logged or persisted
- raw runtime filesystem paths are not persisted in deployment signatures or the source manifest
- no data is sent to an external service
- no database, Redis, queue, worker or cron dependency is required
- cache/signature/manifest writes are atomic
- stale deployment cache is never knowingly preferred over safe uncached execution

Report security issues privately. See [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
