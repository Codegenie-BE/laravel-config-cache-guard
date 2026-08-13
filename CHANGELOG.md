# Changelog

All notable changes to `codegenie-be/laravel-config-cache-guard` will be documented in this file.

## Unreleased

- Added a stable aggregate `CI gate`, immutable commit-SHA Action references, minimum-dependency jobs, an enforced coverage baseline and bounded timeouts for every job.
- Added repository Secret Scanning, Push Protection and Dependabot security updates.
- Added optional content-based source signatures for deployment tools that preserve file metadata during same-size rewrites.
- Shared atomic writes and signature calculation between pre-bootstrap and deferred repair paths, and reused the initial route signature within a request.
- Added a real two-process repair-lock regression test that runs across the complete platform matrix.
- Added a signed-tag release workflow that validates releases, publishes ZIP and SHA-256 artifacts and records build provenance.
- Added a Composer distribution gate that builds the release archive, verifies every runtime file and rejects leaked development, documentation or vendor files.
- Disabled Composer's five-minute process timeout only for the real Laravel E2E script, so `composer check:release` can complete reliably on slower machines without weakening other command timeouts.
- Expanded both the package suite and real-application E2E coverage to every supported PHP/Laravel pair across Linux x64, Windows x64, macOS ARM64, Linux ARM64 and Alpine Linux x64.
- Added an official-PHP Alpine Docker test environment for musl-based container portability.
- Strengthened support-policy validation so missing platforms, runners, runtime pairs, Alpine infrastructure or incorrect Testbench and Pest pins fail CI.

## v1.3.1 - 2026-08-11

- Added real application end-to-end tests that install the package through Composer into fresh Laravel 12 and 13 projects.
- Added HTTP coverage for pre-bootstrap CLI repair, the `exec()`-disabled deferred repair fallback, custom `APP_CONFIG_CACHE` paths and `.laravel` bootstrap cache paths.
- Added Linux and Windows E2E jobs and a `composer check:release` pre-release quality gate.
- Added a date-aware support policy gate that rejects EOL PHP or Laravel versions in Composer and CI.
- Expanded the primary CI matrix to all seven currently supported PHP/Laravel combinations.
- Added a dependency-free GitHub Pages package website, verified repair demo and shared-hosting deployment recipes.
- Added contributor, support, adopter and issue-reporting guidance for public package feedback.
- Improved the README positioning and removed the download badge because automated CI installs are not a reliable adoption metric.

## v1.3.0 - 2026-08-11

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
- Removed the public `/_config-cache-guard/repair` route, controller, registration and repair-token environment options.
- Updated status diagnostics to show Composer autoload integration, legacy require detection, pending repair markers and auto repair state.
- Updated documentation for the no-token, no-public-route shared-hosting fallback.
- Removed the unreachable auto-refresh middleware and its unused `CONFIG_CACHE_GUARD_AUTO_REFRESH` option; deferred repair completes after the response and cannot refresh that same request.
- Added support for Laravel's configured `APP_CONFIG_CACHE` path when it is available before Composer loads.
- Added support for Laravel 13 projects that use `.laravel/cache` as the active bootstrap cache directory.
- Made stale-cache removal fail closed: requests stop safely when a known-stale cache file cannot be removed.
- Added explicit handling for unavailable lock files and failed pending-marker writes.
- Fixed failure cooldown behavior so traffic no longer rewrites the marker timestamp and postpones retries indefinitely. Expired failed repairs are retried even when the stale cache file was already removed.
- Updated status diagnostics with active cache paths and config-cache writability.
- Added regression coverage for custom config paths, `.laravel/cache`, lock failures, cooldown expiry and unremovable stale cache.
- Pending repair markers now retain the exact pre-bootstrap source signature, preventing signature drift after Laravel loads `.env` and avoiding repeated repair loops.
- Expanded deployment signatures to include config-dependent route registration, application providers, active bootstrap registration files and Composer dependency metadata.
- Deferred repair now rechecks the pending marker after acquiring its lock to avoid duplicate cache rebuilds during concurrent request termination.
- Fail-hard diagnostics now throw a normal runtime exception in explicit CLI guard mode instead of emitting an HTML response.
- Captured documented guard controls and cache paths before Laravel dotenv bootstrap so status diagnostics and deferred repair cannot drift from pre-bootstrap behavior.
- Source signatures are now written atomically and verified; a rebuilt cache is removed or bypassed when its signature cannot be persisted safely, preventing repeated untracked rebuild loops.
- Added regression coverage for pre-/post-bootstrap environment consistency and failed signature persistence.
- Updated the development lock file to a security-clean dependency set resolved on the lowest supported PHP 8.2 / Laravel 12 platform, while retaining separately validated Laravel 13 compatibility.
- Fixed the remaining Pint and PHPStan findings in the pre-bootstrap guard, deferred repair locking flow and regression tests.
- Added regression coverage proving that route repair cooldown requests preserve the existing failure marker and timestamp instead of extending the cooldown.
- Added a CI quality gate for optimized Composer autoload generation with strict PSR validation.
- Made path assertions platform-independent so the Pest suite treats Windows and Unix separator styles as equivalent.
- Made pre-bootstrap Artisan execution cross-platform by invoking the absolute `artisan` path instead of relying on shell-specific `cd &&` syntax.
- Added a focused Windows CI job to catch filesystem and path-separator regressions on the lowest supported PHP/Laravel combination.
- Added reusable Composer quality scripts, including `composer check`, so local and CI validation use the same commands.

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
