# Laravel Config Cache Guard

[![Tests](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/codegenie-be/laravel-config-cache-guard.svg)](https://packagist.org/packages/codegenie-be/laravel-config-cache-guard)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/13.x/releases)

**by [Codegenie](https://www.codegenie.be)**

**Never serve stale Laravel configuration or routes after an FTP or shared-hosting deployment, even when `exec()` is disabled.**

Laravel Config Cache Guard detects relevant deployment changes before Laravel boots, prevents stale cache files from being used and safely repairs them through the PHP CLI or Laravel's own `Artisan::call()` fallback.

It is built for Laravel 12 and 13 applications deployed through FTP, cPanel, Plesk or other environments where deployment hooks, SSH or shell functions may be unavailable.

## Quick start

```bash
composer require codegenie-be/laravel-config-cache-guard
```

That single Composer command completes installation. Optionally verify the integration with:

```bash
php artisan config-cache-guard:status
```

No `public/index.php` change is required. The guard is loaded automatically by Composer when Laravel requires `vendor/autoload.php`, before `bootstrap/app.php` bootstraps the application.

- [Open the package website](https://codegenie-be.github.io/laravel-config-cache-guard/)
- [Watch the verified repair flow](#verified-repair-demo)
- [Read the deployment recipes](docs/deployment-recipes.md)
- [Ask a question](https://github.com/Codegenie-BE/laravel-config-cache-guard/discussions)

## Who this is for

Use this package when your application already uses config or route cache and:

- you deploy through FTP, cPanel, Plesk or shared hosting
- deployment cache commands can occasionally be skipped
- SSH, `exec()` or a reliable deploy hook is unavailable
- you want stale cache to be rejected before Laravel can load it
- you need a small file-based safety net without Redis, queues, cron or a database

Do not use it as a replacement for a correct deployment pipeline. A deployment that can reliably run Laravel's cache commands should keep doing so.

By default, the package does **not** create config cache when none exists and does **not** enable route caching for applications that are not already using it.

## Verified repair demo

![Terminal demonstration of Laravel Config Cache Guard rejecting stale deployment cache and completing deferred repair](https://raw.githubusercontent.com/Codegenie-BE/laravel-config-cache-guard/main/docs/assets/demo.gif)

The animation is a concise transcript of the real Laravel 13, process-control-disabled scenario covered by the full package E2E suite. Read the [accessible transcript and verification notes](docs/demo-transcript.md).

> This package is a safety net. When your host exposes reliable destination-side deployment commands, rebuilding Laravel deployment caches there remains the preferred production flow. FTP-only/shared hosting without command access is explicitly supported through the in-app fallback.

## Why this exists

Laravel normally caches configuration into:

```text
bootstrap/cache/config.php
```

Laravel 13 can use `.laravel/cache` as its active bootstrap cache directory, and Laravel also supports an explicit `APP_CONFIG_CACHE` path. Routes are normally cached in files such as:

```text
bootstrap/cache/routes-v7.php
```

Those caches are good for production performance, but relevant deployment changes are not reflected until the appropriate cache is rebuilt. This includes changes to `.env`, configuration, routes, application providers, bootstrap registration and installed dependency metadata.

Config cache also is not necessarily portable between filesystem locations. Laravel configuration can contain absolute application or storage paths, so a cache generated in CI, on a developer machine, on staging or in a previous release directory can be unsafe after relocation even when the source files themselves are unchanged.

This is easy to miss on shared hosting, FTP deployments or hosting panels where deploy hooks are limited. The package checks source state before Laravel bootstraps and binds config signatures to the current runtime identity. If source state or the config runtime identity changed, it prevents Laravel from using stale deployment cache and tries to rebuild safely.

When bounded process control is unavailable, the package removes stale config cache, points Laravel at a current signature-based route cache path, and queues an internal in-app auto repair. After the current HTTP response is sent, the package can rebuild through Laravel's own `Artisan::call()` without SSH, tokens or public repair URLs.

## What it does

On normal HTTP requests, the guard checks relevant deployment sources against:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is provided as a real server environment variable
- `config/**/*.php` when config cache guarding is active, and also for route signatures because route registration can depend on configuration
- `routes/**/*.php` when a route cache file already exists
- `app/Providers/**/*.php`
- the active bootstrap `app.php` and `providers.php` files (`bootstrap/*` or `.laravel/*`) when they exist
- `composer.json` and `composer.lock` when present, so dependency or package-discovery changes invalidate deployment caches

The default `metadata` signature mode uses fast metadata such as timestamps, file size and inode metadata. Optional `content` mode hashes source-file bytes in memory to catch same-size rewrites that preserve metadata. Neither mode stores source contents or `.env` values.

Config signatures additionally include a one-way runtime identity derived from the normalized application base path, canonical base path and OS family. This means a signed config cache moved to another application path or operating system is rejected even when its source files are otherwise identical. Raw runtime filesystem paths are not persisted in the signature file. Route signatures remain source-based because route cache does not need the same config-path portability protection.

By default, config cache guarding refreshes the existing config cache in Laravel's active bootstrap cache directory. It also supports an explicit `APP_CONFIG_CACHE` path when that path is available as a real process or server environment variable before Composer loads. It does not force config caching on projects that are not using config cache. You can opt into creating config cache when missing with `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true`.

When the config signature changed and config cache exists, the guard takes a file lock and tries:

```bash
php artisan config:cache
```

When the route signature changed and a route cache file already exists, the guard takes a file lock and tries:

```bash
php artisan route:cache
```

If pre-bootstrap rebuilding cannot run because bounded PHP process control or a PHP CLI binary is unavailable, stale config cache is removed and stale route cache is bypassed with a signature-based route cache path. An internal pending marker records the exact pre-bootstrap source signature, then the service provider processes that marker with `Artisan::call()` after the current HTTP response is sent. This prevents the deferred layer from registering a different signature after Laravel has loaded `.env`.

When a deployment itself successfully runs Laravel's native `config:cache` or `route:cache` command, the package records the freshly generated deployment signature after the command finishes. A normal `route:cache` command also seeds the guard's signature-based route cache file when versioned route cache is enabled and no custom `APP_ROUTES_CACHE` is configured. A correct deployment therefore does not need a first HTTP request merely to teach the guard that the cache it just built is current.

## What it does not do

- It does not parse, log or store `.env` values. Content-signature mode may hash `.env` file bytes in memory without persisting the values.
- It does not persist raw runtime filesystem paths in deployment signatures.
- It does not use Redis, queues, workers, cron or a database.
- It does not require you to manually register middleware.
- It does not expose an unauthenticated public repair endpoint.
- It does not require a secret repair token for automatic in-app repair.
- It does not rebuild config cache or route cache on every request.
- It does not automatically start route caching when your app is not already using route cache.
- It does not run `cache:clear`, `optimize:clear`, `view:clear` or `event:clear`.
- It does not replace a proper deployment process.

The pre-bootstrap guard is loaded through Composer `autoload.files`. The package service provider registers the diagnostic Artisan commands, records successful native config/route cache command completion, and schedules internal pending repair markers to run after the current HTTP response is sent.

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
  -> guard checks config and route source signatures
  -> config signature also checks the one-way runtime identity
  -> unchanged: continue immediately
  -> changed or relocated config: remove stale config cache before Laravel can use it
  -> changed routes: point Laravel at a current signature-based route cache path
  -> proc_open/PHP CLI available: run a bounded config:cache or route:cache process before Laravel boots
  -> bounded process control/PHP CLI unavailable: write pending repair marker
  -> Laravel boots without stale deployment cache
  -> current request continues without stale deployment cache
  -> after the response is sent, service provider processes pending marker with Artisan::call()
  -> next request uses the refreshed cache file
```

When the deployment explicitly runs `config:cache` or `route:cache`, Laravel's successful command-finished event takes the shorter path: the package records the current signature immediately, clears resolved pending/failure state and prepares the versioned route-cache copy where applicable.

This order is important. A Laravel middleware or normal service provider is too late to prevent Laravel from loading old cached config or old cached routes. The Composer-loaded guard prevents stale cache from being used. The in-app auto repair fallback only runs after the current response is sent, so Laravel's in-request view, session and routing state is not disturbed by cache rebuild commands.

## Installation

```bash
composer require codegenie-be/laravel-config-cache-guard
```

That single Composer command is enough for normal Laravel projects. No manual require line is needed in `public/index.php`.

Optionally verify the integration with:

```bash
php artisan config-cache-guard:status
```

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

When updating a source checkout or replacing this repository from a ZIP file, replace it in a clean directory instead of extracting over the previous tree. An overlay cannot remove files that were deleted by a newer release. Run `composer install` and regenerate the optimized autoloader after the clean replacement.

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
- which Laravel bootstrap cache directory is active and whether it is writable
- which config cache path is active and whether it is writable
- whether cached config exists
- whether cached routes exist
- whether the config and route signature files exist
- whether each active deployment signature is `current`, `stale`, `missing` or unavailable
- whether the currently expected signature-based route cache file exists
- whether pending repair markers exist and why
- whether failed-rebuild markers exist and why
- when config and route cache repair last succeeded
- which active cache files are currently expected
- how many stale route cache files were cleaned during the last successful route repair
- whether bounded PHP process control is available
- which PHP CLI binary will be used
- which lock and process timeouts are configured

Clear old failure and pending markers after fixing a hosting issue:

```bash
php artisan config-cache-guard:status --clear-failures
```

Deployment scripts can request a non-zero exit code for unsafe or unresolved states:

```bash
php artisan config-cache-guard:status --strict
```

Strict mode validates freshness, not merely the presence of cache/signature files. It fails when an active guarded config or route cache is untracked, stale, unreadable, cannot be recalculated or is missing the currently expected signature-based route-cache file. That makes it suitable as a post-deployment health gate before traffic is switched.

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- A writable active Laravel bootstrap cache directory (`bootstrap/cache` or `.laravel/cache`)
- A writable configured config cache file or parent directory when `APP_CONFIG_CACHE` points elsewhere
- Optional: `proc_open()` and a working PHP CLI binary for bounded pre-bootstrap rebuilding

When bounded process control is unavailable, the in-app auto repair fallback can still rebuild through `Artisan::call()` after the current response is sent.

## Compatibility

| Laravel | Package target | PHP range | Framework status |
| --- | --- | --- | --- |
| 12 | Supported | 8.2 - 8.5 | Security fixes until February 24, 2027 |
| 13 | Supported | 8.3 - 8.5 | Security fixes until March 17, 2028 |

PHP 8.2 is security fixes only until December 31, 2026. For new production projects, prefer PHP 8.4 or PHP 8.5 when your hosting supports it.

As of August 17, 2026, the non-EOL runtime matrix is PHP 8.2, 8.3, 8.4 and 8.5 with Laravel 12, plus PHP 8.3, 8.4 and 8.5 with Laravel 13. Laravel 13/PHP 8.2 is deliberately absent because Laravel 13 officially requires PHP 8.3 or newer. Security-only support still counts as supported; a version is removed when its official security support ends. `composer check:support` validates the Composer constraints, dependency pins, platform definitions and compatibility/E2E matrices against `tests/Support/policy.php`, and deliberately fails once a configured branch reaches EOL or any supported platform/runtime entry disappears.

Useful references:

- PHP supported versions: https://www.php.net/supported-versions.php
- Laravel release support policy: https://laravel.com/docs/13.x/releases

## Environment options

The pre-bootstrap guard runs before Laravel loads `.env`. Therefore every `CONFIG_CACHE_GUARD_*` override, `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` and `APP_ENV` value that must affect the guard **must be configured as a real process or server environment variable**. Putting such an override only in `.env` is too late for pre-bootstrap detection. Default package behavior does not require extra variables.

At Composer load time, the guard keeps an in-memory snapshot of only these named control and cache-path variables. The status command and deferred repair layer use that same pre-bootstrap snapshot, so a value that appears later through Laravel's dotenv bootstrap cannot silently change guard behavior halfway through the request. Values supplied by the process or web server before Composer loads are preserved for the whole request.

| Variable | Default | Description |
| --- | --- | --- |
| `CONFIG_CACHE_GUARD_ENABLED` | `true` | Set to `false`, `0`, `off` or `no` to disable the entire guard. |
| `CONFIG_CACHE_GUARD_CONFIG` | `true` | Set to `false`, `0`, `off` or `no` to disable config cache guarding only. |
| `CONFIG_CACHE_GUARD_ROUTES` | `true` | Set to `false`, `0`, `off` or `no` to disable route cache guarding only. |
| `CONFIG_CACHE_GUARD_SIGNATURE_MODE` | `metadata` | Use `metadata` for fast timestamp/size/inode signatures, or `content` to hash source-file contents and detect same-size rewrites that preserve metadata. |
| `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE` | `false` | Set to `true` to let the guard create Laravel's configured config cache even when no config cache exists yet. |
| `CONFIG_CACHE_GUARD_AUTO_REPAIR` | `true` | Allows the service provider to process pending repair markers through `Artisan::call()` after the current HTTP response is sent. |
| `CONFIG_CACHE_GUARD_VERSIONED_ROUTE_CACHE` | `true` | Stores refreshed route caches in a signature-based `routes-*.php` file and sets `APP_ROUTES_CACHE` before Laravel boots. This avoids stale opcache reads of `routes-v7.php` on shared hosting. |
| `CONFIG_CACHE_GUARD_FAILURE_COOLDOWN` | `60` | Number of seconds to wait after a failed rebuild before trying again. |
| `CONFIG_CACHE_GUARD_LOCK_TIMEOUT` | `2000` | Maximum milliseconds a pre-bootstrap request waits for another rebuild lock. Use `0` for a single non-blocking attempt; values above `30000` fall back to the default. |
| `CONFIG_CACHE_GUARD_PROCESS_TIMEOUT` | `30` | Maximum seconds for the pre-bootstrap PHP CLI cache command. Valid values are `1` through `300`. |
| `CONFIG_CACHE_GUARD_FAIL_HARD` | `false` | Show a safe 503 error page when pre-bootstrap refresh cannot continue. Leave this `false` when you want in-app auto repair to run automatically. |
| `CONFIG_CACHE_GUARD_PHP_BINARY` | auto-detect | Optional full path to the PHP CLI binary. |
| `PHP_CLI_BINARY` | auto-detect | Secondary PHP CLI binary override. |
| `APP_CONFIG_CACHE` | Laravel default | Optional config cache path override. Relative paths are resolved from the application base path. It must be externally available before Composer loads for pre-bootstrap protection. |
| `APP_ROUTES_CACHE` | Laravel default | Optional Laravel route cache path override. Explicit custom paths are respected; guard-managed signature paths are only used when no custom path is configured. It must be externally available before Composer loads. |
| `APP_ENV` | optional | When provided externally, `.env.{APP_ENV}` is included in metadata signatures. |

Example process/server environment configuration:

```dotenv
CONFIG_CACHE_GUARD_ENABLED=true
CONFIG_CACHE_GUARD_CONFIG=true
CONFIG_CACHE_GUARD_ROUTES=true
CONFIG_CACHE_GUARD_SIGNATURE_MODE=metadata
CONFIG_CACHE_GUARD_AUTO_REPAIR=true
CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60
CONFIG_CACHE_GUARD_LOCK_TIMEOUT=2000
CONFIG_CACHE_GUARD_PROCESS_TIMEOUT=30
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

## Shared hosting without process control

Some shared hosts disable process functions such as `proc_open()`. In that case, the pre-bootstrap guard does not start `php artisan config:cache` or `php artisan route:cache` before Laravel boots.

This package handles that without a public endpoint:

```text
1. stale config cache is removed immediately
2. stale route cache is bypassed by pointing Laravel at a signature-based route cache path
3. a safe pending marker is written
4. Laravel boots without using the stale route cache
5. the current request continues without stale deployment cache
6. after the response is sent, the service provider rebuilds through Artisan::call()
7. the next request uses the refreshed cache file
```

If no config cache exists at all and you intentionally want the package to create one without SSH or a deployment hook, set `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true` as a real server environment value. Otherwise the application continues without config cache until another valid deployment mechanism creates it.

If the in-app repair fails, a safe `.failed` marker is written in the active Laravel bootstrap cache directory. It contains a reason and suggested action, but no `.env` values, raw runtime paths, secrets, tokens or command output.

## Files written by the guard

The guard may create or update these files inside Laravel's active bootstrap cache directory (`bootstrap/cache` or `.laravel/cache`). The actual config cache file can live elsewhere when `APP_CONFIG_CACHE` is configured:

| File | Purpose |
| --- | --- |
| `config.php` | Laravel's cached configuration, created by `php artisan config:cache`. |
| `config-source.signature` | One-way deployment signature of environment, config, provider, bootstrap and dependency sources plus the config runtime identity. Raw runtime paths are not stored. |
| `config-cache-refresh.lock` | File lock to avoid concurrent config cache rebuilds. |
| `config-cache-refresh.pending` | Internal marker used by the in-app auto repair fallback, including the exact pre-bootstrap config signature. |
| `config-cache-refresh.failed` | Safe diagnostic marker after a failed config rebuild attempt. |
| `config-cache-refresh.succeeded` | Safe diagnostic marker after a successful config rebuild. |
| `routes-*.php` | Laravel's cached routes, created by `php artisan route:cache`; when versioned route cache is enabled, a successful native command also seeds the current signature-based copy. |
| `route-source.signature` | Source signature of route, config, provider, bootstrap, environment and dependency files. |
| `route-cache-refresh.lock` | File lock to avoid concurrent route cache rebuilds. |
| `route-cache-refresh.pending` | Internal marker used by the in-app auto repair fallback, including the exact pre-bootstrap source signature. |
| `route-cache-refresh.failed` | Safe diagnostic marker after a failed route rebuild attempt. |
| `route-cache-refresh.succeeded` | Safe diagnostic marker after a successful route rebuild, including stale route cleanup count. |

## Failure behavior

| Situation | Behavior |
| --- | --- |
| No relevant source or config-runtime change | Continue immediately. |
| No config cache exists and `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=false` | Do nothing for config cache. |
| Successful native `config:cache` | Record the current runtime-bound config signature and clear resolved config repair state. |
| Successful native `route:cache` | Record the current route signature, seed the signature-based route cache when applicable and clear resolved route repair state. |
| Config changed or its runtime identity changed and pre-bootstrap rebuild succeeds | Continue with refreshed cached config. |
| Routes changed and pre-bootstrap rebuild succeeds | Continue with refreshed cached routes in the current signature-based route cache file. |
| Config rebuild needs bounded process control but it is unavailable | Remove stale cached config and write a pending auto repair marker. |
| Route rebuild needs bounded process control but it is unavailable | Point Laravel at the current signature-based route cache path, keep older bypassed route cache files for cleanup, and write a pending auto repair marker. If a signature-based bypass is not possible, remove the stale route cache file so the current request loads route source files. |
| PHP CLI is not found | Use the same pending auto repair fallback behavior for the affected cache target. |
| Pre-bootstrap rebuild fails | Use the same pending auto repair fallback behavior for the affected cache target. |
| In-app auto repair succeeds | Rebuild through Laravel without external process control after the current response is sent, atomically persist and verify the exact pre-bootstrap signature, then remove pending markers. |
| In-app auto repair fails | Remove stale cache file and write a safe failed marker. |
| A rebuilt cache signature cannot be stored | Do not retain an untracked cache file. Remove or bypass it safely, write a pending or failed marker, and retry after the configured recovery path. |
| A stale cache file cannot be removed | Stop the request with a safe 503 response instead of allowing Laravel to load known-stale cache. This safety stop applies even when normal fail-hard mode is disabled. |
| A previous failure is still inside the cooldown | Keep the original failure marker unchanged, bypass stale cache, and retry after the configured cooldown actually expires. |

Removing stale config cache files is intentional. For routes, the guard avoids stale reads by switching Laravel to a route-cache filename derived from the current route source signature. Explicit custom `APP_ROUTES_CACHE` paths are respected; if a custom route cache is stale and cannot be rebuilt before boot, the stale file is removed and rebuilt at the same custom path after the response. Running uncached for one request is slower, but safer than continuing with old configuration or old routes.

## Testing manually

After installation, you can test the config guard like this:

```bash
php artisan config:cache
php artisan config-cache-guard:status --strict
```

A successful native `config:cache` command now records the guard signature immediately, so the status output should report `config signature state` as `current` without requiring a browser request first.

Then change a value in a file such as `config/app.php` or update its modified time:

```bash
touch config/app.php
```

`php artisan config-cache-guard:status --strict` should now report the config signature as stale. Load the application once in the browser. If `proc_open()` and PHP CLI are available, the guard should rebuild the active config cache and update `config-source.signature` in the active Laravel bootstrap cache directory.

If bounded process control is disabled, the first request removes the stale config cache and queues in-app auto repair after the response. A following request should use the refreshed config cache if the repair succeeded.

To test the route guard, first make sure your app already uses route cache:

```bash
php artisan route:cache
php artisan config-cache-guard:status --strict
```

A successful native `route:cache` command records the route signature and, with default versioned route caching, prepares the current signature-based route-cache copy. Strict status should therefore be current immediately after the command.

Then change a route file or update its modified time:

```bash
touch routes/web.php
```

Load the application once in the browser. If `proc_open()` and PHP CLI are available, the guard should rebuild the active `routes-*.php` cache and update `route-source.signature` in the active Laravel bootstrap cache directory.

If bounded process control is disabled, the first request points Laravel at a signature-based route cache path and queues in-app auto repair after the response. A following request should use the refreshed route cache if the repair succeeded.

## Recommended production flow

Use this package as a fallback, not as your primary deployment strategy when destination deployment commands are available.

A deployment target that exposes a reliable command runner should still include:

```bash
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Only run `php artisan route:cache` in deployments when your application supports Laravel route caching. Successful native config/route cache commands synchronize the guard's deployment signatures before they return, so the strict status command can validate the resulting cache state before traffic is switched.

On FTP-only/shared hosting where SSH, Terminal or deployment hooks are not available, destination-side Artisan is not a package requirement. Keep the active Laravel cache directory writable and use the documented in-app fallback. See [Deployment recipes](docs/deployment-recipes.md).

## Known limitations

- Pre-bootstrap rebuilding requires `proc_open()` and a working PHP CLI binary. Commands are invoked without a shell and stopped after `CONFIG_CACHE_GUARD_PROCESS_TIMEOUT` seconds.
- Lock acquisition is bounded by `CONFIG_CACHE_GUARD_LOCK_TIMEOUT`, so concurrent or abandoned rebuilds cannot hold a request indefinitely.
- In-app auto repair works without external process control, but it runs after the current HTTP response is sent. The current request runs without stale deployment cache first.
- `CONFIG_CACHE_GUARD_FAIL_HARD=true` intentionally stops the request with a safe 503 page, so in-app auto repair cannot run during that same request.
- Change detection uses fast metadata signatures by default. Filesystems or deployment tools that preserve timestamps, size and inode metadata for a same-size in-place rewrite can evade that mode. Set `CONFIG_CACHE_GUARD_SIGNATURE_MODE=content` at process or web-server level to hash source contents, including `.env` files, without storing their values. Content mode performs more file I/O on every guarded request; a normal deployment cache rebuild remains the primary release mechanism when deployment commands are available.
- Config cache creation when missing is opt-in through `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true`.
- Route cache guarding only activates automatically when a `routes-*.php` file already exists in Laravel's active bootstrap cache directory.
- Pre-bootstrap cache path detection supports Laravel's standard `bootstrap` directory and Laravel 13's `.laravel` directory. Arbitrary bootstrap paths selected later through application code cannot be discovered before `bootstrap/app.php` runs.
- A custom `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` or guard override placed only in `.env` is unavailable to the Composer-loaded pre-bootstrap guard. Configure these overrides at process or web-server level. The deferred layer intentionally keeps using the earlier external-environment snapshot for consistency.
- The package does not clear application cache, view cache, event cache, OPcache or Redis.
- This package is a fallback safety net. It should not replace a correct deployment pipeline when one is available.

## Troubleshooting

### The status command says `Bounded process control available: no`

Your hosting disables one or more required process-control functions. The guard can still remove stale cached config and bypass stale cached routes. With `CONFIG_CACHE_GUARD_AUTO_REPAIR=true`, it can then rebuild through `Artisan::call()` after the current HTTP response is sent.

### The status command reports a stale, missing or unavailable signature

An active cache exists, but the guard cannot prove that cache matches the current source/runtime state. `--strict` intentionally returns a failure in that situation. Run the relevant native cache command (`config:cache` or `route:cache`) on the destination when available, or let one guarded HTTP request perform the configured repair path. Then rerun `php artisan config-cache-guard:status --strict`.

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

Or remove them manually from Laravel's active bootstrap cache directory.

### public/index.php still contains the old require line

Current versions do not need this line anymore:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

Remove it manually, or run:

```bash
php artisan config-cache-guard:install --remove-legacy
```

### PHPStan still reports `RefreshAfterRouteCacheRepair` after upgrading

`src/Http/Middleware/RefreshAfterRouteCacheRepair.php` is not part of the current package. If PHPStan still scans it, an older source file remained because a ZIP was extracted over an existing checkout. Remove that exact stale file or replace the checkout in a clean directory, then regenerate Composer autoload metadata:

```bash
composer install
composer dump-autoload --optimize
```

On Windows PowerShell, the obsolete file can be removed explicitly before regenerating autoload metadata:

```powershell
Remove-Item .\src\Http\Middleware\RefreshAfterRouteCacheRepair.php -Force -ErrorAction SilentlyContinue
composer dump-autoload --optimize
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

## Development and quality checks

Install the development dependencies and run the complete local gate with:

```bash
composer install
composer check:all
```

`composer check` is the fast non-Pest quality gate. It validates the date-aware PHP/Laravel support policy, runs strict Composer validation, builds and inspects the package distribution archive, runs the security audit, generates an optimized strict-PSR autoloader, checks Pint formatting and runs PHPStan. `composer check:all` adds Pest once. The focused commands remain available as `composer check:support`, `composer check:composer`, `composer check:distribution`, `composer check:security`, `composer check:autoload`, `composer format:test`, `composer analyse`, `composer test` and `composer test:coverage`. The coverage command requires Xdebug and enforces the non-decreasing 80% baseline.

Run the real application end-to-end suite before a release:

```bash
composer test:e2e
composer check:release
```

`composer test:e2e` runs the full suite by default. It creates temporary fresh Laravel 12 and 13 applications, installs this checkout through a copied Composer path repository, builds real config and route caches, and sends HTTP requests through PHP's built-in server. It verifies bounded pre-bootstrap CLI repair, the process-control-disabled in-app fallback, a custom `APP_CONFIG_CACHE` path and Laravel 13 with `.laravel` as its active bootstrap path. `composer check:release` runs the fast quality gate, Pest, builds a Composer ZIP and installs that exact artifact before running the full E2E suite. The temporary applications are removed automatically. Composer's default process timeout is disabled only for these network-heavy E2E commands.

Use `composer test:e2e -- --laravel=12` to run one framework version or add `--keep` to retain a failing full-E2E fixture for inspection. The CI portability path uses `composer test:e2e -- --laravel=12 --suite=smoke`: that focused suite fetches the Laravel skeleton with `create-project --no-install`, resolves Laravel plus this package in one dependency-install phase and validates the platform-sensitive pre-bootstrap stale-config repair path without repeating the full HTTP scenarios.

GitHub Actions deliberately separates runtime compatibility from platform portability:

- Linux x64 runs package tests for every supported PHP/Laravel pair: PHP 8.2–8.5 with Laravel 12 and PHP 8.3–8.5 with Laravel 13.
- Linux x64 runs the full real-application E2E suite on the lowest supported PHP runtime for each Laravel major.
- Windows x64, macOS ARM64, Ubuntu Linux ARM64 and Alpine Linux x64 each start one PHP 8.5 environment, then run Laravel 12 and Laravel 13 package tests plus the focused portability smoke suite in that same environment.
- Separate Linux jobs verify the minimum supported Composer dependency set for Laravel 12 and 13.
- Coverage includes the real pre-bootstrap guard and maintains the 80% minimum.
- Pull requests receive dependency review.
- A stable `CI gate` combines required results. Intentionally skipped jobs are accepted only when the CI planner classified them as unnecessary for that change.
- Documentation-only pull requests keep the required `CI gate` but skip the expensive runtime, E2E, portability, minimum-dependency and coverage jobs.
- Runtime, Composer, test, workflow, release and scheduled weekly checks run the full matrix.
- Dependabot automatically merges only safe minor/patch GitHub Actions or direct development-dependency updates; major GitHub Actions upgrades remain visible for manual compatibility review instead of being silently ignored.

Stable releases use a release pull request. A maintainer starts **Prepare release PR** from GitHub Actions and selects a patch, minor or major increment. The workflow prepares the versioned changelog, opens the release PR, approves the generated test run for that exact release commit and enables auto-merge. The protected branch keeps that merge blocked until the required `CI gate` passes. After auto-merge, the workflow explicitly starts protected `main`. The release job only allocates on a normal main push when the changelog diff introduces a newly prepared dated SemVer release, or when main is explicitly dispatched. It then creates the annotated tag, tests the exact release ZIP, uploads its checksum and provenance, creates the GitHub Release and verifies that Packagist exposes the same version and commit. No Packagist token or local signing key is required.

## Uninstall

Remove the package:

```bash
composer remove codegenie-be/laravel-config-cache-guard
```

If you installed an older version that added a manual require line to `public/index.php`, remove it too:

```php
require __DIR__ . '/../vendor/codegenie-be/laravel-config-cache-guard/bootstrap/guard.php';
```

Optional cleanup for the default Laravel bootstrap cache directory (use `.laravel/cache` instead when that is the active directory):

```bash
rm -f bootstrap/cache/config-source.signature
rm -f bootstrap/cache/config-cache-refresh.lock
rm -f bootstrap/cache/config-cache-refresh.pending
rm -f bootstrap/cache/config-cache-refresh.failed
rm -f bootstrap/cache/config-cache-refresh.succeeded
rm -f bootstrap/cache/route-source.signature
rm -f bootstrap/cache/route-cache-refresh.lock
rm -f bootstrap/cache/route-cache-refresh.pending
rm -f bootstrap/cache/route-cache-refresh.failed
rm -f bootstrap/cache/route-cache-refresh.succeeded
```

## Security and privacy

This package is intentionally small and file-based.

- It does not parse, log or store `.env` values; content-signature mode may hash source bytes in memory.
- It does not persist raw application or canonical runtime paths; config runtime identity is stored only through one-way signature material.
- It does not store secrets.
- It does not send data to external services.
- It does not use a database.
- It does not require Redis, queues, workers or cron.
- It uses file locks to avoid concurrent rebuilds.
- It atomically replaces and verifies source-signature files before a rebuilt or explicitly generated cache is accepted as tracked deployment state.
- It keeps only a request-local snapshot of the documented non-secret guard controls and cache paths; it does not snapshot arbitrary environment variables.
- The automatic rebuild commands are fixed argument arrays for `php artisan config:cache` and `php artisan route:cache`; no shell is invoked and no user input becomes a command argument.
- The in-app auto repair fallback uses Laravel's own `Artisan::call()` and does not expose command output.

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## License

The MIT License. See [LICENSE.md](LICENSE.md).

## About Codegenie

Codegenie builds Laravel websites and web applications with a focus on simplicity, reliability and production-friendly deployment.

https://www.codegenie.be