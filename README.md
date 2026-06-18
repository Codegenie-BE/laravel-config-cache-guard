# Laravel Config Cache Guard

[![Tests](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/codegenie-be/laravel-config-cache-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-config-cache-guard)
[![Total Downloads](https://img.shields.io/packagist/dt/codegenie-be/laravel-config-cache-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-config-cache-guard)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/13.x/releases)

**by [Codegenie](https://www.codegenie.be)**

Prevent Laravel from running with stale cached configuration or stale cached routes after `.env`, `config/*.php` or `routes/*.php` changes.

Built for Laravel 12 and 13 apps on shared hosting, FTP deployments and simple production setups where `php artisan config:cache` or `php artisan route:cache` can accidentally be forgotten.

> This package is a safety net. The best production flow is still to rebuild Laravel deployment caches during deployment.

## Quick start

```bash
composer require codegenie-be/laravel-config-cache-guard
php artisan config-cache-guard:status
```

No `public/index.php` change is required. The guard is loaded automatically by Composer when Laravel requires `vendor/autoload.php`, before `bootstrap/app.php` bootstraps the application.

## Why this exists

Laravel can cache configuration into:

```text
bootstrap/cache/config.php
```

Laravel can also cache routes into files such as:

```text
bootstrap/cache/routes-v7.php
```

Those caches are good for production performance, but they also mean changes in `.env`, `config/*.php` or `routes/*.php` are not reflected until the relevant cache is rebuilt.

This is easy to forget on shared hosting, FTP deployments or hosting panels where deploy hooks are limited. This package checks whether source metadata changed before Laravel bootstraps. If it changed, it prevents Laravel from using stale deployment cache and tries to rebuild safely.

When shell functions such as `exec()` are disabled, the package removes stale config cache, points Laravel at a current signature-based route cache path, and queues an internal in-app auto repair. After Laravel boots without stale deployment cache, the package can rebuild through Laravel's own `Artisan::call()` without SSH, tokens or public repair URLs.

## What it does

On normal HTTP requests, the guard performs small metadata checks against:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is provided as a real server environment variable
- `config/**/*.php` when config cache guarding is active
- `routes/**/*.php` when a route cache file already exists
- `bootstrap/app.php` when it exists, because modern Laravel apps often register routes there
- `app/Providers/RouteServiceProvider.php` when it exists

It only checks file metadata such as timestamps, file size and inode metadata. It does not read or store secret values.

By default, config cache guarding refreshes an existing `bootstrap/cache/config.php` file. It does not force config caching on projects that are not using config cache. You can opt into creating config cache when missing with `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true`.

When the config signature changed and config cache exists, the guard takes a file lock and tries:

```bash
php artisan config:cache
```

When the route signature changed and a route cache file already exists, the guard takes a file lock and tries:

```bash
php artisan route:cache
```

If pre-bootstrap rebuilding cannot run because `exec()` or a PHP CLI binary is unavailable, stale config cache is removed and stale route cache is bypassed with a signature-based route cache path. An internal pending marker is written, then the service provider processes that marker with `Artisan::call()` after Laravel boots.

## What it does not do

- It does not read, log or store `.env` values.
- It does not use Redis, queues, workers, cron or a database.
- It does not require you to manually register middleware.
- It does not expose an unauthenticated public repair endpoint.
- It does not require a secret repair token for automatic in-app repair.
- It does not rebuild config cache or route cache on every request.
- It does not automatically start route caching when your app is not already using route cache.
- It does not run `cache:clear`, `optimize:clear`, `view:clear` or `event:clear`.
- It does not replace a proper deployment process.

The pre-bootstrap guard is loaded through Composer `autoload.files`. The package service provider only registers Artisan commands and processes internal pending repair markers after Laravel has booted.

## When to use this package

Use it when:

- you deploy Laravel through FTP or shared hosting
- your deploy process sometimes forgets `php artisan config:cache` or `php artisan route:cache`
- your hosting panel has limited deployment hooks
- your hosting disables `exec()`, SSH or direct command access
- you want a small safety net against stale config cache or stale route cache
- you want to avoid queues, Redis, cron or background workers

Do not use it as a replacement for a correct deployment pipeline.

## How it works

```text
HTTP request
  -> public/index.php
  -> Laravel requires vendor/autoload.php
  -> Composer autoloads the pre-bootstrap guard
  -> guard checks config and route source metadata
  -> unchanged: continue immediately
  -> changed config: remove stale config cache before Laravel can use it
  -> changed routes: point Laravel at a current signature-based route cache path
  -> exec/PHP CLI available: run config:cache or route:cache before Laravel boots
  -> exec/PHP CLI unavailable: write pending repair marker
  -> Laravel boots without stale deployment cache
  -> service provider processes pending marker with Artisan::call()
  -> browser GET/HEAD requests are redirected once after route repair
  -> next request uses the refreshed cache file
```

This order is important. A Laravel middleware or normal service provider is too late to prevent Laravel from loading old cached config or old cached routes. The Composer-loaded guard prevents stale cache from being used. The in-app auto repair fallback only runs after Laravel has safely booted without stale deployment cache.

## Installation

```bash
composer require codegenie-be/laravel-config-cache-guard
php artisan config-cache-guard:status
```

That is enough for normal Laravel projects. No manual require line is needed in `public/index.php`.

### Upgrading from older versions

Older versions asked you to add a manual require line to `public/index.php`:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

That line is now legacy. It is safe because the guard is idempotent, but it is no longer needed.

Remove it manually, or run:

```bash
php artisan config-cache-guard:install --remove-legacy
```

A dry run is available:

```bash
php artisan config-cache-guard:install --remove-legacy --dry-run
```

## Status check

```bash
php artisan config-cache-guard:status
```

This checks:

- whether Composer autoload integration is active
- whether a legacy `public/index.php` require line still exists
- whether the guard is enabled
- whether the config guard is enabled
- whether the route guard is enabled
- whether the in-app auto repair fallback is enabled
- which failure cooldown is configured
- whether fail-hard mode is enabled
- whether `bootstrap/cache` is writable
- whether cached config exists
- whether cached routes exist
- whether the config and route signature files exist
- whether pending repair markers exist and why
- whether failed-rebuild markers exist and why
- whether `exec()` is available
- which PHP CLI binary will be used

Clear old failure and pending markers after fixing a hosting issue:

```bash
php artisan config-cache-guard:status --clear-failures
```

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- A writable `bootstrap/cache` directory
- Optional: `exec()` and a working PHP CLI binary for pre-bootstrap rebuilding

When `exec()` is unavailable, the in-app auto repair fallback can still rebuild through `Artisan::call()` after Laravel boots uncached.

## Compatibility

| Laravel | Package target | PHP range | Framework status |
| --- | --- | --- | --- |
| 12 | Supported | 8.2 - 8.5 | Security fixes until February 24, 2027 |
| 13 | Supported | 8.3 - 8.5 | Security fixes until March 17, 2028 |

PHP 8.2 is security fixes only until December 31, 2026. For new production projects, prefer PHP 8.4 or PHP 8.5 when your hosting supports it.

Useful references:

- PHP supported versions: https://www.php.net/supported-versions.php
- Laravel release support policy: https://laravel.com/docs/13.x/releases

## Environment options

Pre-bootstrap options should preferably be real server environment variables because the guard runs before Laravel bootstraps.

| Variable | Default | Description |
| --- | --- | --- |
| `CONFIG_CACHE_GUARD_ENABLED` | `true` | Set to `false`, `0`, `off` or `no` to disable the entire guard. |
| `CONFIG_CACHE_GUARD_CONFIG` | `true` | Set to `false`, `0`, `off` or `no` to disable config cache guarding only. |
| `CONFIG_CACHE_GUARD_ROUTES` | `true` | Set to `false`, `0`, `off` or `no` to disable route cache guarding only. |
| `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE` | `false` | Set to `true` to let the guard create `bootstrap/cache/config.php` even when no config cache exists yet. |
| `CONFIG_CACHE_GUARD_AUTO_REPAIR` | `true` | Allows the service provider to process pending repair markers through `Artisan::call()` after Laravel boots. |
| `CONFIG_CACHE_GUARD_AUTO_REFRESH` | `true` | Redirects normal GET/HEAD browser requests once after a successful route-cache auto repair, so the browser immediately reloads against the refreshed route cache. |
| `CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE` | `true` | Stores refreshed route caches in a signature-based `routes-*.php` file and sets `APP_ROUTES_CACHE` before Laravel boots. This avoids stale opcache reads of `routes-v7.php` on shared hosting. |
| `CONFIG_CACHE_GUARD_FAILURE_COOLDOWN` | `60` | Number of seconds to wait after a failed rebuild before trying again. |
| `CONFIG_CACHE_GUARD_FAIL_HARD` | `false` | Show a safe 503 error page when pre-bootstrap refresh cannot continue. Leave this `false` when you want in-app auto repair to run automatically. |
| `CONFIG_CACHE_GUARD_PHP_BINARY` | auto-detect | Optional full path to the PHP CLI binary. |
| `PHP_CLI_BINARY` | auto-detect | Secondary PHP CLI binary override. |
| `APP_ROUTES_CACHE` | Laravel default | Optional Laravel route cache path override. Explicit custom paths are respected; guard-managed signature paths are only used when no custom path is configured. |
| `APP_ENV` | optional | When provided externally, `.env.{APP_ENV}` is included in metadata signatures. |

Example:

```env
CONFIG_CACHE_GUARD_ENABLED=true
CONFIG_CACHE_GUARD_CONFIG=true
CONFIG_CACHE_GUARD_ROUTES=true
CONFIG_CACHE_GUARD_AUTO_REPAIR=true
CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

## Shared hosting without exec

Some shared hosts disable `exec()`. In that case, the pre-bootstrap guard cannot start `php artisan config:cache` or `php artisan route:cache` as a CLI command before Laravel boots.

This package handles that without a public endpoint:

```text
1. stale config cache is removed immediately
2. stale route cache is bypassed by pointing Laravel at a signature-based route cache path
3. a safe pending marker is written
4. Laravel boots without using the stale route cache
5. the service provider rebuilds through Artisan::call()
6. browser GET/HEAD requests are redirected once after route repair so the next request uses the refreshed cache file
```

If the in-app repair fails, a safe `.failed` marker is written in `bootstrap/cache`. It contains a reason and suggested action, but no `.env` values, secrets, tokens or command output.

## Files written by the guard

The guard may create or update these files inside `bootstrap/cache`:

| File | Purpose |
| --- | --- |
| `config.php` | Laravel's cached configuration, created by `php artisan config:cache`. |
| `config-source.signature` | Metadata signature of `.env` and `config/**/*.php`. |
| `config-cache-refresh.lock` | File lock to avoid concurrent config cache rebuilds. |
| `config-cache-refresh.pending` | Internal marker used by the in-app auto repair fallback. |
| `config-cache-refresh.failed` | Safe diagnostic marker after a failed config rebuild attempt. |
| `routes-*.php` | Laravel's cached routes, created by `php artisan route:cache`. |
| `route-source.signature` | Metadata signature of route source files. |
| `route-cache-refresh.lock` | File lock to avoid concurrent route cache rebuilds. |
| `route-cache-refresh.pending` | Internal marker used by the in-app auto repair fallback. |
| `route-cache-refresh.failed` | Safe diagnostic marker after a failed route rebuild attempt. |

## Failure behavior

| Situation | Behavior |
| --- | --- |
| No relevant source change | Continue immediately. |
| No config cache exists and `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=false` | Do nothing for config cache. |
| Config changed and pre-bootstrap rebuild succeeds | Continue with refreshed cached config. |
| Routes changed and pre-bootstrap rebuild succeeds | Continue with refreshed cached routes in the current signature-based route cache file. |
| Config rebuild needs `exec()` but `exec()` is disabled | Remove stale cached config and write a pending auto repair marker. |
| Route rebuild needs `exec()` but `exec()` is disabled | Point Laravel at the current signature-based route cache path, keep older route cache files for cleanup, and write a pending auto repair marker. |
| PHP CLI is not found | Use the same pending auto repair fallback behavior for the affected cache target. |
| Pre-bootstrap rebuild fails | Use the same pending auto repair fallback behavior for the affected cache target. |
| In-app auto repair succeeds | Rebuild through Laravel without `exec()`, update signatures and remove pending markers. |
| In-app auto repair fails | Remove stale cache file and write a safe failed marker. |

Removing stale config cache files is intentional. For routes, the guard avoids stale reads by switching Laravel to a route-cache filename derived from the current route source signature. Explicit custom `APP_ROUTES_CACHE` paths are respected. Running uncached for one request is slower, but safer than continuing with old configuration or old routes.

## Testing manually

After installation, you can test the config guard like this:

```bash
php artisan config:cache
php artisan config-cache-guard:status
```

Then change a value in a file such as `config/app.php` or update its modified time:

```bash
touch config/app.php
```

Load the application once in the browser. If `exec()` and PHP CLI are available, the guard should rebuild `bootstrap/cache/config.php` and update `bootstrap/cache/config-source.signature`.

If `exec()` is disabled, the first request removes the stale config cache and queues in-app auto repair. A following request should use the refreshed config cache if the repair succeeded.

To test the route guard, first make sure your app already uses route cache:

```bash
php artisan route:cache
php artisan config-cache-guard:status
```

Then change a route file or update its modified time:

```bash
touch routes/web.php
```

Load the application once in the browser. If `exec()` and PHP CLI are available, the guard should rebuild `bootstrap/cache/routes-*.php` and update `bootstrap/cache/route-source.signature`.

If `exec()` is disabled, the first request points Laravel at a signature-based route cache path and queues in-app auto repair. Browser GET/HEAD requests are redirected once after route repair, so a following request should use the refreshed route cache if the repair succeeded.

## Recommended production flow

Use this package as a fallback, not as your primary deployment strategy.

A solid deployment should still include:

```bash
php artisan config:cache
php artisan route:cache
```

Only run `php artisan route:cache` in deployments when your application supports Laravel route caching.

This package protects you when those steps are forgotten, skipped or not available on shared hosting.

## Known limitations

- Pre-bootstrap rebuilding requires `exec()` and a working PHP CLI binary.
- In-app auto repair works without `exec()`, but it runs after Laravel has booted without stale deployment cache. Route repairs redirect browser GET/HEAD requests once so the browser retries against the refreshed route cache.
- `CONFIG_CACHE_GUARD_FAIL_HARD=true` intentionally stops the request with a safe 503 page, so in-app auto repair cannot run during that same request.
- Change detection is metadata-based for performance. It uses file timestamps, size and inode metadata instead of reading file contents or `.env` values.
- Config cache creation when missing is opt-in through `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true`.
- Route cache guarding only activates automatically when a `bootstrap/cache/routes-*.php` file already exists.
- The package does not clear application cache, view cache, event cache, OPcache or Redis.
- This package is a fallback safety net. It should not replace a correct deployment pipeline that runs Laravel's deployment cache commands.

## Troubleshooting

### The status command says `exec available: no`

Your hosting disables `exec()`. The guard can still remove stale cached config and bypass stale cached routes. With `CONFIG_CACHE_GUARD_AUTO_REPAIR=true`, it can then rebuild through `Artisan::call()` after Laravel boots without stale deployment cache.

### I see `config-cache-refresh.pending` or `route-cache-refresh.pending`

A stale cache target was handled and the package queued an in-app repair. Load the application once more, then run:

```bash
php artisan config-cache-guard:status
```

If the pending marker remains, check whether Laravel can run the relevant cache command.

### I see `config-cache-refresh.failed` or `route-cache-refresh.failed`

Open the file. It contains a safe diagnostic reason and suggested action. It does not contain `.env` values, secrets, tokens or command output.

After fixing the issue, clear old markers:

```bash
php artisan config-cache-guard:status --clear-failures
```

Or remove them manually from `bootstrap/cache`.

### public/index.php still contains the old require line

Current versions do not need this line anymore:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

Remove it manually, or run:

```bash
php artisan config-cache-guard:install --remove-legacy
```

### The wrong PHP binary is detected

Set the binary manually:

```env
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

Then run:

```bash
php artisan config-cache-guard:status
```

### I do not want in-app auto repair

Disable only the in-app fallback:

```env
CONFIG_CACHE_GUARD_AUTO_REPAIR=false
```

### I do not want route cache guarding

Disable only route cache guarding with a real server environment variable:

```env
CONFIG_CACHE_GUARD_ROUTES=false
```

### I want to disable the guard temporarily

Use a real server environment variable:

```env
CONFIG_CACHE_GUARD_ENABLED=false
```

## Uninstall

Remove the package:

```bash
composer remove codegenie-be/laravel-config-cache-guard
```

If you installed an older version that added a manual require line to `public/index.php`, remove it too:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

Optional cleanup:

```bash
rm -f bootstrap/cache/config-source.signature
rm -f bootstrap/cache/config-cache-refresh.lock
rm -f bootstrap/cache/config-cache-refresh.pending
rm -f bootstrap/cache/config-cache-refresh.failed
rm -f bootstrap/cache/route-source.signature
rm -f bootstrap/cache/route-cache-refresh.lock
rm -f bootstrap/cache/route-cache-refresh.pending
rm -f bootstrap/cache/route-cache-refresh.failed
```

## Security and privacy

This package is intentionally small and file-based.

- It does not read `.env` values.
- It does not store secrets.
- It does not send data to external services.
- It does not use a database.
- It does not require Redis, queues, workers or cron.
- It uses file locks to avoid concurrent rebuilds.
- The automatic rebuild commands are fixed to `php artisan config:cache` and `php artisan route:cache`; paths are escaped and no user input is passed to the shell.
- The in-app auto repair fallback uses Laravel's own `Artisan::call()` and does not expose command output.

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## License

The MIT License. See [LICENSE.md](LICENSE.md).

## About Codegenie

Codegenie builds Laravel websites and web applications with a focus on simplicity, reliability and production-friendly deployment.

https://www.codegenie.be
