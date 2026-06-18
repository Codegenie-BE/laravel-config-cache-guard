# Changelog

All notable changes to `codegenie-be/laravel-config-cache-guard` will be documented in this file.

## v1.2.0 - Unreleased

- Load the pre-bootstrap guard automatically through Composer `autoload.files`.
- Removed the need to add a manual require line to `public/index.php` for new installations.
- Kept `config-cache-guard:install` as a compatibility command that can remove the old manual require line with `--remove-legacy`.
- Added idempotent guard loading so legacy `public/index.php` requires and Composer autoload can safely coexist during upgrades.
- Skip guard execution on CLI/phpdbg by default to avoid recursive Artisan or Composer behavior.
- Replaced the protected repair endpoint approach with an internal in-app auto repair fallback.
- Added `CONFIG_CACHE_GUARD_AUTO_REPAIR`, enabled by default.
- Added `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE`, disabled by default, so the package does not force config caching on projects that are not already using it.
- Added pending repair markers for shared hosting environments where `exec()` or a PHP CLI binary is unavailable.
- The service provider now processes pending config and route repairs through Laravel's own `Artisan::call()` after Laravel boots uncached.
- Removed the public `/_config-cache-guard/repair` route, repair controller and repair token environment options.
- Updated status diagnostics to show Composer autoload integration, legacy require detection, pending repair markers and auto repair state.
- Updated documentation for the no-token, no-public-route shared-hosting fallback.

## v1.1.0

- Added optional route cache freshness guarding.
- Added route cache metadata signatures.
- Added route cache lock and failure marker handling.
- Added safe diagnostic contents to `.failed` marker files instead of empty marker files.
- Added `CONFIG_CACHE_GUARD_FAIL_HARD` for visible safe 503 errors when automatic pre-bootstrap refresh fails.
- Added `php artisan config-cache-guard:status --clear-failures`.
- Updated documentation for route cache behavior.

## v1.0.0

- Initial release.
- Added pre-bootstrap config cache guard.
- Added installer command.
- Added status command.
