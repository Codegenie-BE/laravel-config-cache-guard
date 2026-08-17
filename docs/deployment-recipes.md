# Deployment recipes

Laravel Config Cache Guard is a fallback for missed or unavailable deployment cache commands. When your platform can run those commands reliably, keep them in the deployment flow. FTP-only and shared-hosting deployments are explicitly supported even when SSH, Terminal and deployment hooks are unavailable.

## When destination commands are available

Run these after installing production dependencies on the destination runtime and before switching traffic to the new release:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan config-cache-guard:status --strict
```

Only run `route:cache` when every application route is cacheable. The guard does not make an incompatible route definition cacheable.

Successful native `config:cache` and `route:cache` commands are tracked by the package after Laravel reports a successful command exit. The config deployment signature is stored immediately, and the default route flow also prepares the current signature-based route-cache copy. `config-cache-guard:status --strict` therefore validates the cache state produced by the deployment before traffic needs to hit the application.

These commands are the preferred deployment path, not a requirement of the package. On shared hosting where you cannot execute Artisan on the destination, use the shared-hosting flow below instead.

Do not assume a Laravel config cache is portable between filesystem locations. Configuration may contain absolute application or storage paths. The guard therefore binds the config deployment signature to a hashed runtime identity derived from the current application path and OS family. If a signed config cache is moved from a build, staging or previous release path, it is rejected before Laravel can load it and the existing repair flow rebuilds it for the destination runtime. Raw filesystem paths are not stored in the signature file.

Strict status checks compare the stored deployment signatures with freshly calculated signatures. Existing-but-stale, missing or unreadable signature state fails `--strict`; route cache also fails strict validation when the signature-based cache file currently expected by the guard is missing.

## cPanel

When Terminal or a deployment hook is available:

1. Point the domain document root at the Laravel `public` directory.
2. Run the recommended destination commands from the application root.
3. Confirm that Laravel's active bootstrap cache directory is writable by the PHP web process.
4. Run `php artisan config-cache-guard:status --strict` and resolve failures before sending traffic.

When Terminal is unavailable, do not treat a config cache created by CI or your local machine as production-ready. Build the Composer vendor directory in a clean environment with a compatible PHP version, upload the release without overlaying an older vendor tree, preserve the production `.env`, and keep the active bootstrap cache directory writable. The first web request can reject stale or runtime-mismatched config cache and complete in-app repair.

If you intentionally deploy without `bootstrap/cache/config.php` and still want configuration caching on a host where no destination command can be executed, opt into one-time creation:

```dotenv
CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true
CONFIG_CACHE_GUARD_AUTO_REPAIR=true
```

The guard can then queue an internal repair and build the missing config cache through Laravel's own `Artisan::call()` after the HTTP response. Leave `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=false` when you prefer Laravel to run without config cache rather than create it automatically.

## Plesk

Use the Plesk Composer interface or deployment task to install production dependencies. Add the recommended Artisan commands as post-deployment tasks when the hosting plan allows them.

If Plesk runs a different PHP CLI version than the website, set the full supported PHP binary path as a real server environment variable:

```dotenv
CONFIG_CACHE_GUARD_PHP_BINARY=/opt/plesk/php/8.4/bin/php
```

Run `config-cache-guard:status --strict` with that same binary to verify the result. The command now validates deployment-signature freshness as well as filesystem/process health, so a zero exit code can be used as the final cache gate in the Plesk deployment task.

If your Plesk plan does not expose Terminal or post-deployment commands, use the same shared-hosting fallback as cPanel instead of generating config cache on another machine and assuming it is portable.

## FTP-only deployment

Prefer a clean release directory over extracting or uploading files on top of an old application tree. Overlay deployments cannot remove files deleted by a newer dependency version.

1. Build production dependencies locally with a PHP version compatible with the server.
2. Upload the complete release to a new directory when the host supports it.
3. Preserve the production `.env`; never commit or upload it from source control.
4. Do not rely on a config cache generated at the local or CI filesystem path.
5. Make Laravel's active bootstrap cache directory writable by PHP.
6. Switch the document root or rename directories atomically when possible.
7. Send one normal request, then check the safe `.pending`, `.failed` or `.succeeded` markers in the active bootstrap cache directory.

If the uploaded release contains config cache that was produced at another application path, the runtime-bound signature forces a one-time destination rebuild instead of allowing absolute build-machine paths to leak into production runtime behavior.

If you exclude config cache from the upload entirely, enable `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true` only when you want the package to create it through the in-app fallback. Otherwise the application simply remains uncached until a cache is created by another valid deployment mechanism.

The guard never exposes a public repair endpoint and never stores `.env` values or raw runtime paths in its markers.

## GitHub Actions

Use CI to build and test the deployable artifact. When the production host supports deployment commands, generate Laravel runtime caches on the destination after the release is installed. When it does not, do not manufacture a destination-specific config cache in GitHub Actions merely to have one in the artifact.

A destination cache stage is appropriate only when the deployment target actually exposes a command runner:

```yaml
- name: Install production dependencies
  run: composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

- name: Build and verify Laravel deployment cache on destination
  run: |
    php artisan config:cache
    php artisan route:cache
    php artisan config-cache-guard:status --strict
```

The successful native cache commands synchronize guard signatures before the strict health check runs. The strict command fails when the resulting active cache is not proven current, so the deployment can stop before switching traffic.

For FTP-only/shared hosting, build dependencies and assets in CI, then rely on the package's runtime validation and in-app fallback on production. If config cache is absent and should be created automatically, set `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true` as a real server environment value.

Provide production environment variables through the deployment platform, not the build log.

## Hosting without process control or SSH

The defaults already support in-app repair of stale cache:

```dotenv
CONFIG_CACHE_GUARD_AUTO_REPAIR=true
CONFIG_CACHE_GUARD_FAIL_HARD=false
```

The first request rejects stale cache and writes an internal pending marker. After the response is sent, the service provider rebuilds through Laravel's `Artisan::call()`. A later request uses the rebuilt cache.

To also create config cache when no config cache exists at all, opt in explicitly:

```dotenv
CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true
```

Keep these invariants:

- the active bootstrap cache directory is writable
- `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE` stays `false` unless you explicitly want missing config cache to be created
- route guarding starts only after the application has used route cache
- real server environment variables are used for pre-bootstrap overrides

See the main [README](../README.md) for failure behavior and troubleshooting.
