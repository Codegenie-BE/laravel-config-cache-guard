# Verified repair demo transcript

The animated demo is a concise representation of the real Laravel 13 end-to-end scenario in `tests/E2E/LaravelConfigCacheGuardE2e.php`.

The test installs the built package artifact through Composer into a fresh Laravel application, builds config and route cache, changes the application configuration and route source, starts PHP with external process control disabled and sends real HTTP requests.

```text
$ composer require codegenie-be/laravel-config-cache-guard
Package installed through Composer autoload.files.

$ php artisan config:cache && php artisan route:cache
Baseline deployment cache created.

$ # A later deployment changes config and route source files.
$ php -d disable_functions=exec,proc_open,proc_get_status,proc_terminate,proc_close -S 127.0.0.1:8000 ...

$ curl http://127.0.0.1:8000/e2e
config: deferred-refreshed-config-value
route:  deferred-refreshed-route
cache:  stale files rejected; deferred repair scheduled

$ # After the response terminates, Artisan::call() completes repair.
$ curl http://127.0.0.1:8000/e2e
config: deferred-refreshed-config-value
route:  deferred-refreshed-route
cache:  config and routes cached with current signatures
```

The shortened output above is explanatory rather than copied verbatim from a shell recording. Every state transition is asserted by the E2E suite:

- the first request receives the changed config and route values instead of stale values
- stale cache is not loaded while external process control is disabled
- pending markers are processed after the HTTP response
- success markers and current source signatures are written
- the following request uses rebuilt config and route cache

Run the same verification locally with:

```bash
composer test:e2e -- --laravel=13
```

No `.env` values, user paths, tokens or other secrets are included in the demo.
