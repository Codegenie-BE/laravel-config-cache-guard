# Deployment recipes

Laravel Config Cache Guard is a fallback for missed or unavailable deployment cache commands. When your platform can run those commands reliably, keep them in the deployment flow.

## Recommended deployment commands

Run these after installing production dependencies and before switching traffic to the new release:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
```

Only run `route:cache` when every application route is cacheable. The guard does not make an incompatible route definition cacheable.

## cPanel

When Terminal or a deployment hook is available:

1. Point the domain document root at the Laravel `public` directory.
2. Run the recommended deployment commands from the application root.
3. Confirm that Laravel's active bootstrap cache directory is writable by the PHP web process.
4. Run `php artisan config-cache-guard:status --strict` and resolve failures before sending traffic.

When Terminal is unavailable, build the Composer vendor directory in a clean environment with a compatible PHP version, upload the release without overlaying an older vendor tree, and keep the bootstrap cache directory writable. The first web request can reject stale cache and complete in-app repair.

## Plesk

Use the Plesk Composer interface or deployment task to install production dependencies. Add the recommended Artisan commands as post-deployment tasks when the hosting plan allows them.

If Plesk runs a different PHP CLI version than the website, set the full supported PHP binary path as a real server environment variable:

```dotenv
CONFIG_CACHE_GUARD_PHP_BINARY=/opt/plesk/php/8.4/bin/php
```

Run `config-cache-guard:status --strict` with that same binary to verify the result.

## FTP-only deployment

Prefer a clean release directory over extracting or uploading files on top of an old application tree. Overlay deployments cannot remove files deleted by a newer dependency version.

1. Build production dependencies locally with a PHP version compatible with the server.
2. Upload the complete release to a new directory when the host supports it.
3. Preserve the production `.env`; never commit or upload it from source control.
4. Make Laravel's active bootstrap cache directory writable by PHP.
5. Switch the document root or rename directories atomically when possible.
6. Send one normal request, then check the safe `.pending`, `.failed` or `.succeeded` markers in the active bootstrap cache directory.

The guard never exposes a public repair endpoint and never stores `.env` values in its markers.

## GitHub Actions

Build and test an artifact before upload. A minimal cache stage looks like:

```yaml
- name: Install production dependencies
  run: composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

- name: Build Laravel deployment cache
  run: |
    php artisan config:cache
    php artisan route:cache
```

Provide production environment variables through the deployment platform, not the build log. If production secrets are unavailable during artifact creation, run cache commands on the server after the new release is in place instead.

## Hosting without process control or SSH

The defaults already support in-app repair:

```dotenv
CONFIG_CACHE_GUARD_AUTO_REPAIR=true
CONFIG_CACHE_GUARD_FAIL_HARD=false
```

The first request rejects stale cache and writes an internal pending marker. After the response is sent, the service provider rebuilds through Laravel's `Artisan::call()`. A later request uses the rebuilt cache.

Keep these invariants:

- the active bootstrap cache directory is writable
- `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE` stays `false` unless you explicitly want missing config cache to be created
- route guarding starts only after the application has used route cache
- real server environment variables are used for pre-bootstrap overrides

See the main [README](../README.md) for failure behavior and troubleshooting.
