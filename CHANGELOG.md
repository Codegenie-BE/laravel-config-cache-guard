# Changelog

All notable changes to this package will be documented in this file.

## v1.0.0 - 2026-06-17

- Initial release.
- Added pre-bootstrap config cache guard.
- Added automatic stale config cache detection based on safe file metadata.
- Added lock-protected `php artisan config:cache` refresh flow.
- Added fallback behavior that removes stale cached config when refresh is unavailable or fails.
- Added install command.
- Added status command with production-readiness diagnostics.
- Added GitHub Actions test matrix for Laravel 12 and 13.
- Added tests for installation, idempotency, dry-run behavior, status command and runtime fallback behavior.
