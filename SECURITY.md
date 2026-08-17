# Security Policy

Please report security issues privately through GitHub Security Advisories or by contacting Codegenie directly.

Do not report vulnerabilities through public GitHub issues.

## Supported versions

Security fixes are provided for the latest stable `1.x` release.

## Security model

Codegenie Laravel Config Cache Guard is intentionally small and file-based.

It is designed to prevent Laravel from using stale deployment cache files without adding queues, cron, Redis, a database, middleware or a public repair endpoint.

Config cache signatures are also bound to a one-way runtime identity derived from the normalized application base path, canonical base path and OS family. This prevents a signed config cache generated at another application path or operating system from being trusted after relocation. Raw runtime filesystem paths are not written to the signature file.

## What the package may do

The package may:

- compare file metadata for `.env`, `.env.{APP_ENV}`, config files, route files, application providers, active bootstrap registration files and Composer dependency metadata
- optionally hash those source-file contents in memory when `CONFIG_CACHE_GUARD_SIGNATURE_MODE=content`; only the aggregate one-way signature is persisted
- bind config source signatures to a one-way runtime identity so config cache relocated from another application path or operating system is rejected before Laravel boots
- remove stale config cache from Laravel's active cache path, including an externally configured `APP_CONFIG_CACHE` path
- remove or bypass stale route cache in Laravel's active bootstrap cache directory
- run fixed deployment-cache argument arrays through a bounded PHP CLI process when `proc_open()` is available
- queue an internal pending marker when pre-bootstrap rebuilding is unavailable
- rebuild through Laravel's own `Artisan::call()` after Laravel boots uncached
- observe Laravel's successful `config:cache` and `route:cache` command-finished events and persist the corresponding current deployment signature
- atomically copy a successfully generated default route cache to its current signature-based route-cache filename when versioned route cache is enabled and no custom `APP_ROUTES_CACHE` is configured
- write safe diagnostic markers in Laravel's active bootstrap cache directory (`bootstrap/cache` or `.laravel/cache`)
- keep a request-local snapshot of the documented guard controls and cache-path variables so deferred repair uses the same pre-bootstrap inputs
- atomically persist and verify source signatures before accepting rebuilt or explicitly generated deployment cache as tracked

## What the package does not do

The package does not:

- log or store `.env` values; content-signature mode reads file bytes only to calculate a one-way hash
- persist raw application or canonical runtime filesystem paths in deployment signatures
- snapshot arbitrary environment variables or persist the documented control-variable snapshot to disk
- store secrets, tokens, cookies or authorization headers
- send data to external services
- expose a public repair endpoint
- require a secret repair URL or token
- require manual code changes in `public/index.php` for new installations
- execute shell commands or user-controlled process arguments
- run the pre-bootstrap guard during normal CLI/phpdbg execution unless explicitly allowed for testing; console integration is limited to package commands and successful native cache-command completion tracking
- call `cache:clear`, `optimize:clear`, `view:clear`, `event:clear`, migrations or Composer commands
- use Redis, queues, workers, cron or a database

## Process execution safety

The guard is loaded by Composer `autoload.files` during HTTP requests and is idempotent when older manual require lines still exist.

When pre-bootstrap rebuilding is possible, the only child-process argument arrays are fixed Laravel Artisan commands:

```bash
php artisan config:cache
php artisan route:cache
```

The PHP binary and Artisan paths are passed directly to `proc_open()` without invoking a shell. No user input is passed into the command. The process is terminated after `CONFIG_CACHE_GUARD_PROCESS_TIMEOUT` seconds, and filesystem-lock acquisition is bounded by `CONFIG_CACHE_GUARD_LOCK_TIMEOUT`.

When bounded process control is unavailable, the package falls back to internal in-app repair through `Artisan::call()` after Laravel has booted without the stale cache file. If a known-stale cache file cannot be removed or bypassed, the guard stops the request with a safe 503 response instead of allowing Laravel to load it.

Successful native cache-command tracking does not execute another command. It reacts only after Laravel reports a zero exit code, calculates the same one-way deployment signature used by the guard, and updates files inside the configured Laravel cache locations.

## Diagnostic markers

Failure and pending marker files may be written to Laravel's active bootstrap cache directory. Pending markers may include a one-way source metadata signature so deferred repair records the exact deployment state detected before Laravel bootstraps.

They contain:

- generated timestamp
- target name
- reason
- safe human-readable message
- suggested action

They do not contain `.env` values, raw runtime paths, command output, exception traces, secrets or tokens.

## Recommended production setup

- Keep `APP_DEBUG=false` in production.
- Keep Laravel's active bootstrap cache directory writable by PHP but not publicly browsable.
- When `APP_CONFIG_CACHE` points elsewhere, keep that file or its parent directory writable by PHP.
- Configure pre-bootstrap overrides such as `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` and `CONFIG_CACHE_GUARD_*` at process or web-server level; values placed only in `.env` load too late for the Composer guard.
- Do not place backups or logs under the public webroot.
- Use this package as a safety net, not as a replacement for deployment checks.
- Prefer running `php artisan config:cache` and `php artisan route:cache` during deployment when your hosting supports it, followed by `php artisan config-cache-guard:status --strict`. On FTP-only/shared hosting without destination command access, keep the active cache directory writable and use the documented in-app fallback instead.
