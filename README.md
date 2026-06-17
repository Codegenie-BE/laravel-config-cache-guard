# Codegenie Laravel Config Cache Guard

A lightweight pre-bootstrap Laravel config cache guard that refreshes stale cached config when config files or environment metadata change.

It is designed for production Laravel apps, FTP deployments and shared-hosting environments where `php artisan config:cache` can accidentally be forgotten after changing `.env` or `config/*.php`.

## What it does

On normal requests, the guard performs a small metadata check against:

- `.env`
- `.env.{APP_ENV}` when `APP_ENV` is provided as an external environment variable
- `config/**/*.php`

If the signature changed, the guard takes a file lock and runs:

```bash
php artisan config:cache
```

The request then continues with the refreshed `bootstrap/cache/config.php`.

If rebuilding the config cache fails, the stale cached config is removed so Laravel does not continue with old configuration.

## What it does not do

- It does not read or store secret values.
- It does not use Redis, queues, workers, cron or a database.
- It does not add middleware or a service provider to the request lifecycle.
- It does not run on local development unless you install it there yourself.

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- A writable `bootstrap/cache` directory
- `exec()` and a PHP CLI binary when you want automatic cache rebuilding from web requests

Compatibility target:

| Laravel | Package compatibility target | Upstream framework status |
| --- | --- | --- |
| 12 | PHP 8.2 - 8.5 | Laravel security support until February 24, 2027 |
| 13 | PHP 8.3 - 8.5 | Laravel security support until March 17, 2028 |

PHP 8.2 is supported for security fixes only until December 31, 2026. For new production projects, prefer PHP 8.4 or PHP 8.5 when your hosting supports it.

If `exec()` is disabled, the guard can still remove stale config cache, but it cannot rebuild a new cached config automatically from a web request.

## Installation

```bash
composer require codegenie/laravel-config-cache-guard
php artisan config-cache-guard:install
```

The installer adds this line to `public/index.php` before Laravel bootstraps:

```php
require __DIR__ . '/../vendor/codegenie/laravel-config-cache-guard/bootstrap/guard.php';
```

This must happen before `vendor/autoload.php` and before `bootstrap/app.php`.

## Status check

```bash
php artisan config-cache-guard:status
```

This shows whether the guard is installed, whether `bootstrap/cache` is writable, whether `exec()` is available and which PHP CLI binary will be used.

## Environment options

These options must be provided as real server environment variables. They are intentionally not read from Laravel config, because the guard runs before Laravel bootstraps.

```env
CONFIG_CACHE_GUARD_ENABLED=true
CONFIG_CACHE_GUARD_FAILURE_COOLDOWN=60
CONFIG_CACHE_GUARD_PHP_BINARY=/usr/bin/php
```

## Recommended production flow

The best solution is still to run this during deployment:

```bash
php artisan config:cache
```

This package is a safety net for stale config cache, especially on shared hosting and FTP-style deployments.

## License

MIT
