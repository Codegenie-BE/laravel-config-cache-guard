# Security Policy

Please report security issues privately through GitHub Security Advisories or by contacting Codegenie directly.

Do not report vulnerabilities through public GitHub issues.

## Supported versions

Security fixes are provided for the latest stable `1.x` release.

## Security model

Codegenie Laravel Config Cache Guard is intentionally small and file-based.

It is designed to prevent Laravel from using stale deployment cache files without adding queues, cron, Redis, a database, middleware or a public repair endpoint.

## What the package may do

The package may:

- compare file metadata for `.env`, `.env.{APP_ENV}`, `config/**/*.php`, route files and route registration files
- remove stale `bootstrap/cache/config.php`
- remove stale `bootstrap/cache/routes-*.php`
- run fixed deployment-cache commands through PHP CLI when `exec()` is available
- queue an internal pending marker when pre-bootstrap rebuilding is unavailable
- rebuild through Laravel's own `Artisan::call()` after Laravel boots uncached
- write safe diagnostic markers in `bootstrap/cache`

## What the package does not do

The package does not:

- read, log or store `.env` values
- store secrets, tokens, cookies or authorization headers
- send data to external services
- expose a public repair endpoint
- require a secret repair URL or token
- require manual code changes in `public/index.php` for new installations
- execute user-controlled shell commands
- run the guard during normal CLI/phpdbg execution unless explicitly allowed for testing
- call `cache:clear`, `optimize:clear`, `view:clear`, `event:clear`, migrations or Composer commands
- use Redis, queues, workers, cron or a database

## Shell command safety

The guard is loaded by Composer `autoload.files` during HTTP requests and is idempotent when older manual require lines still exist.

When pre-bootstrap rebuilding is possible, the only shell commands executed are fixed Laravel Artisan commands:

```bash
php artisan config:cache
php artisan route:cache
```

The PHP binary path and application path are escaped before shell execution. No user input is passed into the command.

When `exec()` is unavailable, the package falls back to internal in-app repair through `Artisan::call()` after Laravel has booted without the stale cache file.

## Diagnostic markers

Failure and pending marker files may be written to `bootstrap/cache`.

They contain:

- generated timestamp
- target name
- reason
- safe human-readable message
- suggested action

They do not contain `.env` values, command output, exception traces, secrets or tokens.

## Recommended production setup

- Keep `APP_DEBUG=false` in production.
- Keep `bootstrap/cache` writable by PHP but not publicly browsable.
- Do not place backups or logs under the public webroot.
- Use this package as a safety net, not as a replacement for deployment checks.
- Prefer running `php artisan config:cache` and `php artisan route:cache` during deployment when your hosting supports it.
