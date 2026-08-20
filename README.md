# Laravel Config Cache Guard

[![Tests](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/codegenie-be/laravel-config-cache-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-config-cache-guard)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/13.x/releases)

**by [Codegenie](https://www.codegenie.be)**

Laravel Config Cache Guard keeps Laravel config and route caches fresh across FTP, shared-hosting and normal deployment workflows. It prevents known-stale deployment cache from being loaded before Laravel boots and can repair or create missing caches without requiring Redis, queues, cron, workers or package-specific environment variables.

## Quick start

```bash
composer require codegenie-be/laravel-config-cache-guard
```

That is enough for the normal zero-configuration flow.

No `public/index.php` change is required and no `CONFIG_CACHE_GUARD_*` variable needs to be added to `.env`.

Optionally inspect the current state with:

```bash
php artisan config-cache-guard:status
```

## Default behavior

On normal HTTP traffic the package:

- protects existing Laravel config cache before Laravel can load stale configuration
- protects existing Laravel route cache before stale routes can be dispatched
- automatically queues creation of missing config and route caches after the HTTP response
- uses Laravel's own `Artisan::call()` fallback, so missing-cache optimization does not make the visitor wait for cache compilation
- uses file locks so concurrent requests do not all rebuild the same pending cache
- stores deployment signatures in Laravel's active bootstrap cache directory
- uses signature-based route-cache copies when the default route cache path is used
- keeps working on shared hosting without SSH, Redis, queues, workers, cron or a database

After the first uncached HTTP response of a fresh deployment, later requests can use the generated config and route caches when Laravel can create them successfully.

## Missing cache creation

If `bootstrap/cache/config.php` (or the active custom config cache path) is missing, the package queues `config:cache` after the current response.

If route cache is missing, the package queues `route:cache` after the current response.

This is deliberately fail-safe:

- a cache-generation failure never makes Laravel use a broken cache file
- if `route:cache` is not supported by the application's route definitions, Laravel continues with normal uncached routing
- a failed missing-cache optimization is tied to the current source signature, so identical source state is not retried on every request
- when relevant source files change, the package may try the optimization again

### Laravel config-cache contract

Automatic config caching assumes the application follows Laravel's normal production convention: use `env()` inside configuration files and read configuration elsewhere through `config()`.

Code such as this is appropriate:

```php
config('services.mailgun.secret');
```

Direct `env()` access in application code outside `config/*.php` can behave differently after Laravel config cache is enabled. Applications that rely on that anti-pattern should be corrected before production caching is enabled.

## Stale cache protection

The Composer-loaded pre-bootstrap guard checks deployment source state before Laravel bootstraps.

Relevant sources include:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is available before Composer loads
- `config/**/*.php`
- `routes/**/*.php`
- `app/Providers/**/*.php`
- the active bootstrap `app.php` and `providers.php`
- `composer.json`
- `composer.lock`

Config signatures also include a one-way runtime identity derived from the application location and OS family. This prevents config cache generated at a different filesystem location from being accepted as portable when it may contain absolute runtime paths.

The default signature mode uses filesystem metadata. Optional content-signature mode remains available for deployments that intentionally preserve metadata across same-size rewrites.

## Route cache behavior

When Laravel uses its default route-cache location, successful route caching also creates a signature-based cache file:

```text
bootstrap/cache/routes-<source-signature>.php
```

The guard can point Laravel at the cache file for the current source signature before boot. This avoids relying on an old fixed `routes-v7.php` inode or stale OPcache entry after deployment.

Explicit custom `APP_ROUTES_CACHE` paths are respected when they are available before Composer loads.

## Concurrency

Deferred cache repair uses non-blocking file locks.

When multiple requests arrive together after a deployment, only the request that acquires the relevant repair lock performs that pending cache operation. Other requests do not wait for that after-response repair lock.

Missing-cache creation is queued after the current HTTP response, so the visitor who discovers a fresh uncached deployment does not wait for `config:cache` or `route:cache` to compile.

## Native deployment commands

A correct deployment pipeline may still build caches explicitly:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Successful native `config:cache` and `route:cache` commands are tracked automatically. The package records the corresponding source signatures immediately, and the normal route flow prepares the signature-based route-cache copy.

Only run `route:cache` manually when the application supports Laravel route caching.

## Shared hosting and FTP-only deployments

Destination-side shell access is optional.

For FTP-only or restricted shared hosting:

1. install or package production Composer dependencies before deployment
2. upload a clean release rather than overlaying an old vendor tree where possible
3. preserve the production `.env`
4. keep Laravel's active bootstrap cache directory writable by PHP
5. send normal HTTP traffic

If deployment cache is missing, the package queues creation after the response. If existing deployment cache is stale, the pre-bootstrap guard rejects or bypasses it before Laravel uses it and the existing repair flow refreshes it safely.

See [deployment recipes](docs/deployment-recipes.md) for concrete hosting flows.

## Status command

```bash
php artisan config-cache-guard:status
```

The command reports the active cache paths, cache/signature state, pending and failed repairs, recent successful repairs and deployment-health diagnostics.

Use strict mode in deployment automation:

```bash
php artisan config-cache-guard:status --strict
```

Clear repaired or intentionally resolved failure state with:

```bash
php artisan config-cache-guard:status --clear-failures
```

## Optional overrides

The package works without package-specific environment variables. Existing advanced overrides remain supported for compatibility when they are provided as real process or web-server environment variables before Composer loads.

Examples include disabling a guard target, selecting content-signature mode, custom cache paths, process timeouts and PHP CLI discovery for the legacy bounded pre-bootstrap repair path.

Normal applications should not need to configure them.

Laravel's own optional cache-path overrides are also respected:

- `APP_CONFIG_CACHE`
- `APP_ROUTES_CACHE`

No override is required for the standard Laravel paths.

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- writable active Laravel bootstrap cache directory (`bootstrap/cache` or `.laravel/cache`)
- writable configured cache destination when a custom Laravel cache path is used

No database, Redis, queue worker, cron task, public repair endpoint or secret repair token is required.

## Failure behavior

| Situation | Behavior |
| --- | --- |
| Cache is current | Continue normally. |
| Config cache is missing | Queue `config:cache` after the response. |
| Route cache is missing | Queue `route:cache` after the response. |
| Missing route cache cannot be created | Continue with normal uncached routing and remember the failed source signature. |
| Config source/runtime state changed | Reject stale config cache before Laravel can load it and repair safely. |
| Route source state changed | Bypass stale route cache and repair safely. |
| Pending repair lock is held by another request | Do not duplicate that repair. |
| Rebuilt cache signature cannot be stored | Do not retain untracked deployment cache. |
| Known-stale cache cannot be removed safely | Stop with a safe 503 instead of loading known-stale state. |

## Development

Install development dependencies and run:

```bash
composer install
composer check:all
```

Before a release, also run the real Laravel application E2E suite:

```bash
composer test:e2e
composer check:release
```

The repository validates Laravel 12 and 13 across their supported PHP matrix and includes cross-platform portability coverage.

## Security and privacy

The package is intentionally file-based and local to the Laravel application.

- no `.env` values are logged or persisted
- raw runtime filesystem paths are not persisted in deployment signatures
- no data is sent to an external service
- no database, Redis, queue, worker or cron dependency is required
- cache signatures are written atomically
- stale deployment cache is never knowingly preferred over safe uncached execution

Report security issues privately. See [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
