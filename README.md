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
php artisan config-cache-guard:install
php artisan config-cache-guard:status
```

The installer adds the pre-bootstrap guard to `public/index.php` before Laravel bootstraps.

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

This is easy to forget on shared hosting, FTP deployments or hosting panels where deploy hooks are limited. This package checks whether source metadata changed before Laravel bootstraps. If it changed, it tries to rebuild Laravel's cached config or cached routes before the request continues.

When shell functions such as `exec()` are disabled, the package can still remove stale cache files. A protected repair endpoint can then rebuild Laravel caches through `Artisan::call()` without using `exec()`.

## What it does

On normal requests, the guard performs small metadata checks against:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is provided as a real server environment variable
- `config/**/*.php`
- `routes/**/*.php` when a route cache file already exists
- `bootstrap/app.php` when it exists, because modern Laravel apps often register routes there
- `app/Providers/RouteServiceProvider.php` when it exists
- this package's repair route file

It only checks file metadata such as timestamps, file size and inode metadata. It does not read or store secret values.

When the config signature changed, the guard takes a file lock and runs:

```bash
php artisan config:cache
```

When the route signature changed and a route cache file already exists, the guard takes a file lock and runs:

```bash
php artisan route:cache
```

The request then continues with refreshed deployment cache files.

## What it does not do

- It does not read, log or store `.env` values.
- It does not use Redis, queues, workers, cron or a database.
- It does not add middleware to the request lifecycle.
- It does not rebuild config cache or route cache on every request.
- It does not automatically start route caching when your app is not already using route cache.
- It does not run `cache:clear`, `optimize:clear`, `view:clear` or `event:clear`.
- It does not replace a proper deployment process.

The package registers a small service provider for Artisan commands and the optional repair endpoint. The runtime guard itself is loaded directly from `public/index.php` before Laravel bootstraps.

## When to use this package

Use it when:

- you deploy Laravel through FTP or shared hosting
- your deploy process sometimes forgets `php artisan config:cache` or `php artisan route:cache`
- your hosting panel has limited deployment hooks
- your hosting disables `exec()`, but you still want a protected web repair option
- you want a small safety net against stale config cache or stale route cache
- you want to avoid queues, Redis, cron or background workers

Do not use it as a replacement for a correct deployment pipeline.

## How it works

```text
HTTP request
  -> public/index.php
  -> bootstrap guard runs before vendor/autoload.php
  -> build metadata signature from .env and config/**/*.php
  -> compare signature with bootstrap/cache/config-source.signature
  -> changed: lock, run php artisan config:cache, continue
  -> build metadata signature from routes/**/*.php and route registration files
  -> compare signature with bootstrap/cache/route-source.signature
  -> changed and route cache exists: lock, run php artisan route:cache, continue
  -> failed rebuild: remove stale cache file and write a safe diagnostic marker
```

This order is important. A Laravel middleware or service provider is too late for this job, because Laravel may already have loaded the old cached config or old cached routes by then.

## Installation

```bash
composer require codegenie-be/laravel-config-cache-guard
php artisan config-cache-guard:install
```

The installer adds this line to `public/index.php`:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

It must be placed before:

```php
require __DIR__ . '/../vendor/autoload.php';
```

A typical `public/index.php` should look like this:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';

require __DIR__ . '/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Request::capture());
```

## Status check

```bash
php artisan config-cache-guard:status
```

This checks:

- whether the guard is enabled
- whether the config guard is enabled
- whether the route guard is enabled
- which failure cooldown is configured
- whether fail-hard mode is enabled
- whether the guard is installed in `public/index.php`
- whether `bootstrap/cache` is writable
- whether cached config exists
- whether cached routes exist
- whether the config and route signature files exist
- whether failed-rebuild markers exist and why
- whether `exec()` is available
- which PHP CLI binary will be used
- whether the protected repair endpoint is enabled

Clear old failure markers after fixing a hosting issue:

```bash
php artisan config-cache-guard:status --clear-failures
```

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- A writable `bootstrap/cache` directory
- `exec()` and a working PHP CLI binary for automatic pre-bootstrap cache rebuilding
- Optional: a repair token when you want to rebuild through a protected web endpoint without `exec()`

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

Pre-bootstrap options should preferably be real server environment variables because the guard runs before Laravel bootstraps. The repair endpoint can also read Laravel's normal environment after the stale config cache has been removed.

| Variable | Default | Description |
| --- | --- | --- |
| `CONFIG_CACHE_GUARD_ENABLED` | `true` | Set to `false`, `0`, `off` or `no` to disable the entire guard. |
| `CONFIG_CACHE_GUARD_CONFIG` | `true` | Set to `false`, `0`, `off` or `no` to disable config cache guarding only. |
| `CONFIG_CACHE_GUARD_ROUTES` | `true` | Set to `false`, `0`, `off` or `no` to disable route cache guarding only. |
| `CONFIG_CACHE_GUARD_FAILURE_COOLDOWN` | `60` | Number of seconds to wait after a failed rebuild before trying again. |
| `CONFIG_CACHE_GUARD_FAIL_HARD` | `false` | Show a safe 503 error page when automatic refresh fails instead of silently continuing uncached. |
| `CONFIG_CACHE_GUARD_PHP_BINARY` | auto-detect | Optional full path to the PHP CLI binary. |
| `PHP_CLI_BINARY` | auto-detect | Secondary PHP CLI binary override. |
| `CONFIG_CACHE_GUARD_REPAIR_ENABLED` | `true` | Enables the protected repair endpoint when a repair token is configured. |
| `CONFIG_CACHE_GUARD_REPAIR_TOKEN` | none | Required token for the protected repair endpoint. Keep this secret. |
| `CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET` | `false` | Allows browser-based GET repair requests with `?token=...`. POST with a header is safer. |
| `APP_ENV` | optional | When provided externally, `.env.{APP_ENV}` is included in metadata signatures. |

Example:

```env
CONFIG_CACHE_GUARD_ENABLED=true
CONFIG_CACHE_GUARD_CONFIG=true
CONFIG_CACHE_GUARD_ROUTES=true
CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

## Repair endpoint without exec

Some shared hosts disable `exec()`. In that case, the pre-bootstrap guard cannot start `php artisan config:cache` or `php artisan route:cache` as a CLI command.

You can enable a protected repair endpoint that rebuilds through Laravel itself using `Artisan::call()`:

```env
CONFIG_CACHE_GUARD_REPAIR_TOKEN=use-a-long-random-secret-token
```

Recommended POST request:

```bash
curl -X POST https://example.com/_config-cache-guard/repair \
  -H 'X-Config-Cache-Guard-Token: use-a-long-random-secret-token'
```

For shared hosting panels where you only have a browser, explicitly allow GET:

```env
CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET=true
```

Then open:

```text
https://example.com/_config-cache-guard/repair?token=use-a-long-random-secret-token
```

To force route cache repair when no route cache file currently exists, add:

```text
&routes=1
```

Security notes:

- The endpoint returns `404` when no token is configured or the token is invalid.
- GET repair requests are disabled by default because URLs can end up in browser history or logs.
- The endpoint does not show `.env` values, secrets, tokens or command output.
- The current request is already booted. The refreshed cache files are primarily used by the next request.

## Files written by the guard

The guard may create or update these files inside `bootstrap/cache`:

| File | Purpose |
| --- | --- |
| `config.php` | Laravel's cached configuration, created by `php artisan config:cache`. |
| `config-source.signature` | Metadata signature of `.env` and `config/**/*.php`. |
| `config-cache-refresh.lock` | File lock to avoid concurrent config cache rebuilds. |
| `config-cache-refresh.failed` | Safe diagnostic marker after a failed config rebuild attempt. |
| `routes-*.php` | Laravel's cached routes, created by `php artisan route:cache`. |
| `route-source.signature` | Metadata signature of route source files. |
| `route-cache-refresh.lock` | File lock to avoid concurrent route cache rebuilds. |
| `route-cache-refresh.failed` | Safe diagnostic marker after a failed route rebuild attempt. |

## Failure behavior

| Situation | Behavior |
| --- | --- |
| No relevant source change | Continue immediately. |
| Config changed and rebuild succeeds | Continue with refreshed cached config. |
| Routes changed and rebuild succeeds | Continue with refreshed cached routes. |
| Rebuild needs `exec()` but `exec()` is disabled | Remove stale cached file and write a safe diagnostic marker. |
| PHP CLI is not found | Remove stale cached file and write a safe diagnostic marker. |
| Rebuild fails | Remove stale cached file and wait for the failure cooldown before retrying. |
| Repair endpoint succeeds | Rebuild cache files through Laravel without `exec()` and update signatures. |

Removing stale deployment cache files is intentional. Running without a stale cache file is slower, but safer than continuing with old configuration or old routes.

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

If `exec()` is disabled, enable the repair endpoint and call it once after the stale cache file was removed.

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

- Automatic pre-bootstrap rebuilding from a web request requires `exec()` and a working PHP CLI binary.
- The repair endpoint works without `exec()`, but it runs after Laravel has booted. The refreshed cache files are mainly used by the next request.
- Change detection is metadata-based for performance. It uses file timestamps, size and inode metadata instead of reading file contents or `.env` values.
- Route cache guarding only activates automatically when a `bootstrap/cache/routes-*.php` file already exists.
- The package does not clear application cache, view cache, event cache, OPcache or Redis.
- This package is a fallback safety net. It should not replace a correct deployment pipeline that runs Laravel's deployment cache commands.

## Troubleshooting

### The status command says `exec available: no`

Your hosting disables `exec()`. The guard can still remove stale cached config or stale cached routes, but it cannot rebuild new cached files automatically before Laravel bootstraps.

Use the protected repair endpoint if SSH or terminal commands are not available.

### I see `config-cache-refresh.failed` or `route-cache-refresh.failed`

Open the file. It contains a safe diagnostic reason and suggested action. It does not contain `.env` values, secrets, tokens or command output.

After fixing the issue, clear old markers:

```bash
php artisan config-cache-guard:status --clear-failures
```

Or remove them manually from `bootstrap/cache`.

### The wrong PHP binary is detected

Set the binary manually:

```env
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

Then run:

```bash
php artisan config-cache-guard:status
```

### I do not want route cache guarding

Disable only route cache guarding with a real server environment variable:

```env
CONFIG_CACHE_GUARD_ROUTES=false
```

### The installer cannot update `public/index.php`

Add the require line manually before `vendor/autoload.php`:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

### I want to disable the guard temporarily

Use a real server environment variable:

```env
CONFIG_CACHE_GUARD_ENABLED=false
```

## Uninstall

Remove the require line from `public/index.php`:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

Then remove the package:

```bash
composer remove codegenie-be/laravel-config-cache-guard
```

Optional cleanup:

```bash
rm -f bootstrap/cache/config-source.signature
rm -f bootstrap/cache/config-cache-refresh.lock
rm -f bootstrap/cache/config-cache-refresh.failed
rm -f bootstrap/cache/route-source.signature
rm -f bootstrap/cache/route-cache-refresh.lock
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
- The repair endpoint uses a secret token and does not expose command output.

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## License

The MIT License. See [LICENSE.md](LICENSE.md).

## About Codegenie

Codegenie builds Laravel websites and web applications with a focus on simplicity, reliability and production-friendly deployment.

https://www.codegenie.be
