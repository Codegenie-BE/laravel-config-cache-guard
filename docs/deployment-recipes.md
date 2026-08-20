# Deployment recipes

Laravel Config Cache Guard is zero-configuration for normal applications. Package-specific `.env` variables are not required.

It protects stale Laravel deployment cache before Laravel boots and automatically queues missing config and route cache creation after an HTTP response.

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

No `CONFIG_CACHE_GUARD_*` variable is required.

When config or route cache is missing, the package queues cache creation after the current HTTP response. The visitor does not need to wait for the missing cache to compile.

When existing config or route cache is stale, the pre-bootstrap guard prevents Laravel from using that known-stale state and uses the existing repair flow.

## cPanel

With Terminal:

```bash
cd /path/to/laravel-app
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Without Terminal, upload production dependencies with the application and let normal HTTP traffic perform the zero-configuration fallback.

## Plesk

Use the Plesk Composer/deployment integration when available and run the same destination-side Artisan commands.

When no command runner is available, use the FTP-only flow. No PHP CLI path has to be configured for automatic missing-cache creation because the normal fallback runs through Laravel's own `Artisan::call()` after the response.

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

For FTP-only hosting, do not assume a config cache built at a different filesystem path is portable. The package binds config signatures to the destination runtime identity and can create missing caches after the application responds.

## Automatic missing-cache optimization

A fresh uncached deployment follows this flow:

```text
request
  -> Laravel boots without missing deployment cache
  -> response is sent
  -> package queues/repairs config cache when missing
  -> package queues/repairs route cache when missing
  -> next request can use the generated caches
```

Concurrent repairs use non-blocking file locks, so multiple requests do not all rebuild the same pending target.

If `route:cache` fails for the current route source signature, the application keeps using normal uncached routing. The failed signature is remembered so the same unsupported route state is not retried on every request. When route sources change, the package can try again.

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

## Health checks

After deployment:

```bash
php artisan config-cache-guard:status
```

For an automated deployment gate:

```bash
php artisan config-cache-guard:status --strict
```

After correcting a previously reported hosting or route-cache problem:

```bash
php artisan config-cache-guard:status --clear-failures
```

The package never needs a public repair URL, repair token, queue worker, Redis instance, database table or cron task.
