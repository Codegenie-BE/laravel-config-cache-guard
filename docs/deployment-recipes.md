# Deployment recipes

Laravel Config Cache Guard is zero-configuration for normal applications. Package-specific `.env` variables are not required.

The pre-bootstrap layer only detects and protects. It never waits for a lock or runs Artisan before Laravel boots. Cache creation and repair happen after the HTTP response under one non-blocking deployment repair lock.

## Preferred deployment with command access

When the production host can run destination-side commands, keep the normal Laravel deployment flow:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Only run `route:cache` manually when the application's routes support Laravel route caching.

Successful native cache commands are tracked by the package, so correctly generated caches receive current deployment signatures immediately.

## FTP-only or shared hosting

When SSH, Terminal or deploy hooks are unavailable:

1. build or install production Composer dependencies before upload
2. upload a clean release when possible instead of overlaying an old vendor tree
3. preserve the production `.env`
4. keep Laravel's active bootstrap cache directory writable by PHP
5. send normal HTTP traffic

No `CONFIG_CACHE_GUARD_*` variable or PHP CLI binary path is required.

A fresh uncached request follows this flow:

```text
request
  -> pre-bootstrap guard sees no deployment cache and does no source traversal
  -> Laravel boots normally
  -> response is sent
  -> missing config/route cache is queued
  -> one terminating request acquires deployment-cache-repair.lock
  -> config:cache and/or route:cache run through Artisan::call()
  -> deployment signatures and source manifest are persisted
```

When an existing cache is stale, the guard validates the deployment source manifest, removes or bypasses the unsafe cache before Laravel loads it, and queues deferred repair.

## Healthy request fast path

After cache and the deployment source manifest exist, normal requests do not recursively rediscover `config/`, `routes/` and `app/Providers/`.

The guard verifies already-known source files and source directories directly from:

```text
bootstrap/cache/deployment-source.manifest.json
```

When a known file changes, a source directory changes, an optional deployment file appears/disappears, the active bootstrap path changes, or the runtime identity changes, the manifest is rebuilt from one shared traversal.

Both config and route signatures are derived from that same snapshot.

## Concurrency

All deferred config/route mutation is serialized by:

```text
deployment-cache-repair.lock
```

The lock acquisition is non-blocking. A second worker that reaches termination while another worker owns the lock returns immediately instead of waiting.

The repair owner performs config and route cache operations sequentially so two workers cannot mutate deployment cache concurrently.

## cPanel

With Terminal:

```bash
cd /path/to/laravel-app
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Without Terminal, upload production dependencies with the application and use the normal HTTP fallback.

## Plesk

Use the Plesk Composer/deployment integration when available and run the same destination-side Artisan commands.

When no command runner is available, use the FTP-only flow. The package does not depend on `proc_open()`, `exec()` or a discovered PHP CLI binary for HTTP repair.

## GitHub Actions

Use CI to build and test the release artifact. Prefer generating runtime-specific Laravel caches on the destination when the destination supports commands.

Example destination step:

```yaml
- name: Install production dependencies
  run: composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

- name: Build and verify deployment caches
  run: |
    php artisan config:cache
    php artisan route:cache
    php artisan config-cache-guard:status --strict
```

For FTP-only hosting, do not assume config cache built at a different filesystem path is portable. Config signatures include a one-way runtime identity, and the destination can safely create missing cache after a response.

## Route cache cannot be generated

Some applications contain route definitions that Laravel cannot cache.

In that case:

```text
route:cache fails
  -> generated route cache is removed
  -> failed source signature is stored
  -> application continues with normal uncached routing
  -> identical source state is suppressed for the failure cooldown
  -> source changes trigger a new attempt immediately
```

The stable failure state uses the deployment source manifest; the package does not recursively rescan route/config/provider trees on every response just to rediscover the same uncacheable route signature.

## Config-cache requirement

Automatic config caching assumes normal Laravel production code conventions:

- `env()` belongs in `config/*.php`
- application code reads values through `config()`

Direct `env()` usage in application code outside configuration files can change behavior after `config:cache` is enabled and should be corrected before production deployment.

## Custom Laravel cache paths

Laravel's own optional cache-path overrides remain supported when they are available before Composer loads:

```text
APP_CONFIG_CACHE
APP_ROUTES_CACHE
```

They are optional. Standard Laravel cache paths require no configuration.

For the default route cache path, the package uses signature-based route cache files. A stale old route-cache file can remain on disk during the current request because Laravel is pointed at the current signature path instead.

## Health checks

After deployment:

```bash
php artisan config-cache-guard:status
```

For an automated deployment gate:

```bash
php artisan config-cache-guard:status --strict
```

After correcting a previously reported route/config cache problem:

```bash
php artisan config-cache-guard:status --clear-failures
```

The status command reports source-manifest state, active cache/signatures, pending/failed repairs and the shared deferred repair lock.

The package never needs a public repair URL, repair token, queue worker, Redis instance, database table, cron task or pre-bootstrap child process.
