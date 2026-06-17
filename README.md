# Codegenie Laravel Config Cache Guard

[![Tests](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/Codegenie-BE/laravel-config-cache-guard/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4.svg)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com/docs/13.x/releases)

A lightweight pre-bootstrap Laravel package that helps prevent production apps from running with stale cached configuration.

It is built for Laravel apps where `php artisan config:cache` is used, but where config changes can sometimes be deployed without refreshing the cached config. This is especially useful for FTP deployments, shared hosting and small production apps where deploy steps are intentionally simple.

> This package is a safety net. The best production flow is still to run `php artisan config:cache` during deployment.

## Quick start

```bash
composer require codegenie/laravel-config-cache-guard
php artisan config-cache-guard:install
php artisan config-cache-guard:status
```

The installer adds the guard to `public/index.php` before Laravel bootstraps.

## The problem

Laravel can cache all configuration into:

```text
bootstrap/cache/config.php
```

That is good for production performance, but it also means that changes in `.env` or `config/*.php` are not automatically reflected until the config cache is rebuilt.

This package checks whether the source configuration changed before Laravel bootstraps. If it changed, it tries to rebuild Laravel's cached config before the request continues.

## What it does

On normal requests, the guard performs a small metadata check against:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is provided as a real server environment variable
- `config/**/*.php`

It only checks file metadata such as timestamps, file size and inode metadata. It does not read or store secret values.

When the signature changed, the guard takes a file lock and runs:

```bash
php artisan config:cache
```

The request then continues with the refreshed cached config.

## What it does not do

- It does not read, log or store `.env` values.
- It does not use Redis, queues, workers, cron or a database.
- It does not add middleware to the request lifecycle.
- It does not rebuild config cache on every request.
- It does not replace a proper deployment process.

The package does register a small service provider, but only to expose Artisan commands. The runtime guard itself is loaded directly from `public/index.php` before Laravel bootstraps.

## How it works

```text
HTTP request
  -> public/index.php
  -> bootstrap guard runs before vendor/autoload.php
  -> build metadata signature from .env and config/**/*.php
  -> compare signature with bootstrap/cache/config-source.signature
  -> unchanged: continue immediately
  -> changed: lock, run php artisan config:cache, continue
  -> failed rebuild: remove stale config cache, then continue without cached config
```

This order is important. A Laravel middleware or service provider is too late for this job, because Laravel may already have loaded the old cached config by then.

## Installation

```bash
composer require codegenie/laravel-config-cache-guard
php artisan config-cache-guard:install
```

The installer adds this line to `public/index.php`:

```php
require __DIR__ . '/../vendor/codegenie/laravel-config-cache-guard/bootstrap/guard.php';
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

require __DIR__ . '/../vendor/codegenie/laravel-config-cache-guard/bootstrap/guard.php';

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
- which failure cooldown is configured
- whether the guard is installed in `public/index.php`
- whether `bootstrap/cache` is writable
- whether cached config exists
- whether the signature file exists
- whether a failed-rebuild marker exists
- whether `exec()` is available
- which PHP CLI binary will be used

The command also prints a short result line such as `ready`, `not installed`, `disabled` or `automatic rebuild unavailable`.

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- A writable `bootstrap/cache` directory
- `exec()` and a working PHP CLI binary for automatic cache rebuilding from web requests

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

These values must be real server environment variables. They are not read from Laravel config because the guard runs before Laravel bootstraps.

| Variable | Default | Description |
| --- | --- | --- |
| `CONFIG_CACHE_GUARD_ENABLED` | `true` | Set to `false`, `0`, `off` or `no` to disable the guard. |
| `CONFIG_CACHE_GUARD_FAILURE_COOLDOWN` | `60` | Number of seconds to wait after a failed rebuild before trying again. |
| `CONFIG_CACHE_GUARD_PHP_BINARY` | auto-detect | Optional full path to the PHP CLI binary. |
| `PHP_CLI_BINARY` | auto-detect | Secondary PHP CLI binary override. |
| `APP_ENV` | optional | When provided externally, `.env.{APP_ENV}` is included in the metadata signature. |

Example:

```env
CONFIG_CACHE_GUARD_ENABLED=true
CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

## Files written by the guard

The guard may create or update these files inside `bootstrap/cache`:

| File | Purpose |
| --- | --- |
| `config.php` | Laravel's cached configuration, created by `php artisan config:cache`. |
| `config-source.signature` | Metadata signature of `.env` and `config/**/*.php`. |
| `config-cache-refresh.lock` | File lock to avoid concurrent cache rebuilds. |
| `config-cache-refresh.failed` | Short cooldown marker after a failed rebuild attempt. |

## Failure behavior

| Situation | Behavior |
| --- | --- |
| No config change | Continue immediately. |
| Config changed and rebuild succeeds | Continue with refreshed cached config. |
| Config changed and `exec()` is disabled | Remove stale cached config and continue without cached config. |
| Config changed and PHP CLI is not found | Remove stale cached config and continue without cached config. |
| Rebuild fails | Remove stale cached config and wait for the failure cooldown before retrying. |

Removing stale config is intentional. Running uncached config is slower, but safer than continuing with old configuration.

## Testing manually

After installation, you can test the guard like this:

```bash
php artisan config:cache
php artisan config-cache-guard:status
```

Then change a value in a file such as `config/app.php` or update its modified time:

```bash
touch config/app.php
```

Load the application once in the browser. If `exec()` and PHP CLI are available, the guard should rebuild `bootstrap/cache/config.php` and update `bootstrap/cache/config-source.signature`.

## Recommended production flow

Use this package as a fallback, not as your primary deployment strategy.

A solid deployment should still include:

```bash
php artisan config:cache
```

This package protects you when that step is forgotten, skipped or not available on shared hosting.

## Known limitations

- Automatic rebuilding from a web request requires `exec()` and a working PHP CLI binary.
- Change detection is metadata-based for performance. It uses file timestamps, size and inode metadata instead of reading `.env` values.
- This package is a fallback safety net. It should not replace a correct deployment pipeline that runs `php artisan config:cache`.

## Troubleshooting

### The status command says `exec available: no`

Your hosting disables `exec()`. The guard can still remove stale cached config, but it cannot rebuild a new cached config automatically from a web request.

Recommended fix:

```bash
php artisan config:cache
```

Run this during deployment or from your hosting control panel if available.

### The wrong PHP binary is detected

Set the binary manually:

```env
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

Then run:

```bash
php artisan config-cache-guard:status
```

### The installer cannot update `public/index.php`

Add the require line manually before `vendor/autoload.php`:

```php
require __DIR__ . '/../vendor/codegenie/laravel-config-cache-guard/bootstrap/guard.php';
```

### I want to disable the guard temporarily

Use a real server environment variable:

```env
CONFIG_CACHE_GUARD_ENABLED=false
```

## Uninstall

Remove the require line from `public/index.php`:

```php
require __DIR__ . '/../vendor/codegenie/laravel-config-cache-guard/bootstrap/guard.php';
```

Then remove the package:

```bash
composer remove codegenie/laravel-config-cache-guard
```

Optional cleanup:

```bash
rm -f bootstrap/cache/config-source.signature
rm -f bootstrap/cache/config-cache-refresh.lock
rm -f bootstrap/cache/config-cache-refresh.failed
```

## Security and privacy

This package is intentionally small and file-based.

- It does not read `.env` values.
- It does not store secrets.
- It does not send data to external services.
- It does not use a database.
- It does not require Redis, queues, workers or cron.
- It uses a file lock to avoid concurrent rebuilds.
- The rebuild command is fixed to `php artisan config:cache`; paths are escaped and no user input is passed to the shell.

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## When to use this package

Use it when:

- you deploy Laravel through FTP or shared hosting
- your deploy process sometimes forgets `php artisan config:cache`
- you want a small safety net against stale config cache
- you want to avoid queues, Redis, cron or background workers

Do not use it as a replacement for a correct deployment pipeline.

## License

The MIT License. See [LICENSE.md](LICENSE.md).

## About Codegenie

Codegenie builds Laravel websites and web applications with a focus on simplicity, reliability and production-friendly deployment.

https://www.codegenie.be
