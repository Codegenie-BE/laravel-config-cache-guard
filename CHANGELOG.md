# Changelog

All notable changes to `codegenie-be/laravel-config-cache-guard` will be documented in this file.

## v1.2.0 - Unreleased

- Added a protected repair endpoint at `/_config-cache-guard/repair`.
- The repair endpoint can rebuild Laravel config and route cache through `Artisan::call()` without using `exec()`.
- Added `CONFIG_CACHE_GUARD_REPAIR_TOKEN` for protected repair requests.
- Added `CONFIG_CACHE_GUARD_REPAIR_ALLOW_GET` for shared-hosting browser-based repairs when POST is not practical.
- Added `CONFIG_CACHE_GUARD_FAIL_HARD` for visible safe 503 errors when automatic pre-bootstrap refresh fails.
- Added safe diagnostic contents to `.failed` marker files instead of empty marker files.
- Added `php artisan config-cache-guard:status --clear-failures`.
- Updated status diagnostics with repair endpoint visibility.
- Updated documentation for shared hosting without `exec()`.

## v1.1.0

- Added optional route cache freshness guarding.
- Added route cache metadata signatures.
- Added route cache lock and failure marker handling.
- Updated documentation for route cache behavior.

## v1.0.0

- Initial release.
- Added pre-bootstrap config cache guard.
- Added installer command.
- Added status command.
