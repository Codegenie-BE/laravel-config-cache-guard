# Changelog

All notable changes to `codegenie-be/laravel-config-cache-guard` will be documented in this file.

## Unreleased

## v1.6.0 - 2026-08-20

- Replaced the large synchronous pre-bootstrap repair script with a minimal Composer loader and a dedicated protection-only guard, removing HTTP-side child processes, Artisan compilation and repair-lock waiting from the visitor-facing boot path.
- Centralized config and route repair in Laravel's after-response lifecycle behind one immediate non-blocking deployment repair lock, so concurrent requests do not wait for or duplicate cache compilation.
- Added a persistent deployment source manifest and one shared source snapshot for config and route signatures, avoiding duplicate recursive discovery on healthy requests while safely detecting added, removed and changed source files.
- Added exact route-cache discovery, signature-versioned route cache handling, atomic route-cache copies, streaming signature hashing and request-local hashing-algorithm reuse to reduce filesystem and allocation overhead.
- Made automatic missing-cache creation and stale-cache recovery safe under zero-configuration defaults while suppressing repeated retries for identical failed source state and preserving uncached Laravel fallback behavior.
- Strengthened source-change race handling so caches built against a deployment that changes during repair are discarded and requeued instead of being published as current.
- Simplified the runtime by removing the obsolete bounded child-process execution path, lock polling/timeouts and internal managed-route environment state while retaining explicit CLI diagnostic compatibility.
- Expanded package regression coverage for deployment manifests, atomic writes, stale-cache protection, source-change races, shared repair locking, runtime path identity and cross-platform portability.
- Reworked real Laravel 12 and 13 production E2E coverage around the non-blocking contract, including automatic cache creation, stale repair, restricted hosts, custom config-cache paths and signature-based routes.
- Added per-scenario E2E performance measurements with monotonic timing, hard request/repair ceilings and healthy cached p50/p95 budgets so future runtime regressions fail CI instead of going unnoticed.

## v1.5.1 - 2026-08-17

- Reduced CI fan-out by keeping the complete PHP/Laravel compatibility matrix on Linux x64 while reusing one PHP 8.5 runner per Windows x64, macOS ARM64, Linux ARM64 and Alpine environment to test both Laravel 12 and 13, with representative minimum-runtime E2E coverage retained on Linux.
- Added change-aware CI planning so documentation-only pull requests skip expensive compatibility, portability, E2E, minimum-dependency and coverage jobs while preserving the stable required `CI gate`.
- Added a focused cross-platform portability smoke suite and E2E dispatcher; portability fixtures use `composer create-project --no-install` and resolve Laravel plus this package in one Composer dependency phase, while full Linux and release-archive E2E coverage remains unchanged.
- Split the fast non-Pest quality gate into `composer check` and added `composer check:all` for the complete local quality plus Pest suite, avoiding an unnecessary duplicate Pest run in CI while keeping coverage independent.
- Limited dependency review to pull requests and prevented ordinary `main` pushes from allocating a release runner unless the changelog diff introduces a newly prepared dated SemVer release.
- Updated the release artifact upload action to the exact `actions/upload-artifact` v7.0.1 commit, removing the deprecated Node.js 20 action runtime from future release runs.
- Refreshed README, contributor, security and GitHub Pages documentation for Laravel 12/13, v1.5.0 runtime-bound config signatures, current CI coverage and the supported FTP-only/shared-hosting fallback.
- Clarified that destination-side Artisan commands are optional on FTP-only shared hosting and documented `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE=true` for hosts that need the in-app fallback to create a missing config cache without SSH or deployment hooks.
- Recorded successful native `config:cache` and `route:cache` commands through Laravel's command-finished event so correctly generated deployment caches receive current guard signatures immediately instead of being rebuilt again on the first HTTP request.
- Seeded the current signature-based route-cache file after a successful native `route:cache` command when versioned route caching is enabled and no custom route cache path is configured.
- Strengthened `config-cache-guard:status --strict` so it compares active cache signatures with the current source/runtime state and fails for stale, missing, unreadable or unavailable signatures and for a missing expected versioned route-cache file.
- Stopped persisting raw cache filesystem paths in success markers; new markers store only a SHA-256 cache identity while legacy markers remain readable without exposing their raw path in status output.
- Surfaced major GitHub Actions upgrades for manual review instead of globally ignoring them in Dependabot, while keeping automatic merge limited to minor/patch updates; also updated `actions/attest-build-provenance` to 3.2.0.
- Removed hardcoded package-version metadata from the GitHub Pages site so release information cannot become stale independently from Packagist and GitHub Releases.
- Reduced transient GitHub/Composer CI dependency failures by limiting Composer HTTP concurrency to four workflow-wide and retrying CI `composer update` operations up to two additional times with bounded 10/20-second backoff, while leaving Composer 2.10's security-oriented dist-to-source fallback disabled.
- Made pending release publication recoverable after transient CI failures by re-detecting the latest changelog tag on `main`, preserving a pending release until it is tagged on another commit, and allowing the release job to evaluate after a successful rerun of the aggregate CI gate.

## v1.5.0 - 2026-08-17

- Bound config deployment signatures to a hashed runtime identity derived from the normalized application base path, canonical base path and OS family, so config cache built or signed at another runtime path is rejected before Laravel can load absolute paths from the wrong environment.
- Reused the existing pre-bootstrap invalidation, locking, CLI rebuild and deferred in-app repair flow instead of adding a second cache-state mechanism or new configuration surface.
- Added regression coverage for identical content signatures across different runtime paths and for a signed config cache whose application directory is moved before boot.
- Updated deployment guidance to prefer destination-side cache generation and document the safe fallback behavior for CI, FTP, cPanel and other relocated release artifacts.

## v1.4.3 - 2026-08-14

- Delete the exact generated release branch after protected auto-merge instead of leaving completed release branches on the remote.

## v1.4.2 - 2026-08-14

- Completed unattended releases by approving only the generated pull-request CI run for the exact release commit and explicitly dispatching protected `main` after the bot auto-merges.

## v1.4.1 - 2026-08-14

- Automated release pull-request creation, explicit CI dispatch and protected auto-merge before GitHub and Packagist publication.

## v1.4.0 - 2026-08-14

- Added a stable aggregate `CI gate`, immutable commit-SHA Action references, minimum-dependency jobs, an enforced coverage baseline and bounded timeouts for every job.
- Added repository Secret Scanning, Push Protection and Dependabot security updates.
- Added optional content-based source signatures for deployment tools that preserve file metadata during same-size rewrites.
- Shared atomic writes and signature calculation between pre-bootstrap and deferred repair paths, and reused the initial route signature within a request.
- Added a real two-process repair-lock regression test that runs across the complete platform matrix.
- Added a hardened release workflow that validates releases, publishes ZIP and SHA-256 artifacts and records build provenance.
- Added a Composer distribution gate that builds the release archive, verifies every runtime file and rejects leaked development, documentation or vendor files.
- Disabled Composer's five-minute process timeout only for the real Laravel E2E script, so `composer check:release` can complete reliably on slower machines without weakening other command timeouts.
- Expanded both package and real-application E2E coverage to every supported PHP/Laravel pair on Linux x64 and Windows x64, with representative portability jobs on macOS ARM64, Linux ARM64 and Alpine Linux x64.
- Added an official-PHP Alpine Docker test environment for musl-based container portability.
- Strengthened support-policy validation so missing platforms, runners, runtime pairs, Alpine infrastructure or incorrect Testbench and Pest pins fail CI.
- Added bounded pre-bootstrap process execution and lock acquisition so a stuck Artisan command or concurrent rebuild cannot block a request indefinitely.
- Added `config-cache-guard:status --strict`, verified marker cleanup and timeout diagnostics for deployment health checks.
- Expanded coverage to the real pre-bootstrap guard and added focused process, lock and failure-state regressions.
- Changed release validation to install and test the exact Composer ZIP artifact in fresh Laravel 12 and 13 applications.
- Added strict SemVer and changelog validation for release tags, scheduled weekly compatibility runs and pull-request dependency review.
- Added a release-branch workflow that calculates patch, minor or major versions, prepares the dated changelog automatically and provides the direct PR link.
- Changed stable publication to run only after protected `main` CI succeeds, then create the annotated tag, GitHub Release, checksum and provenance automatically.
- Added post-release Packagist verification that requires the published version to resolve to the exact released commit.

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
- Added `CONFIG_CACHE_GUARD_CREATE_CONFIG_CACHE`, disabled by default, so the package does not force config caching on projects that are not already using config cache.
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
