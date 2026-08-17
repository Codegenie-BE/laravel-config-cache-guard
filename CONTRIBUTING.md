# Contributing

Thank you for helping improve Laravel Config Cache Guard.

## Before opening a change

- Use GitHub Discussions for usage questions and hosting feedback.
- Search existing issues before reporting a bug or proposing a feature.
- Report security concerns privately as described in `SECURITY.md`.
- Never include `.env` contents, access tokens, private paths or customer data.

## Development setup

```bash
composer install
composer check:all
```

`composer check` is the fast metadata, distribution, security, autoload, formatting and static-analysis gate. `composer check:all` adds the Pest suite and is the recommended complete local check before opening a code pull request.

Run the real application tests before proposing a release-sensitive change:

```bash
composer check:release
composer test:coverage
```

`composer check:release` runs the fast quality checks, Pest and the exact release-archive Laravel 12/13 E2E suite. The test suite supports only PHP and Laravel versions that are not end of life. The date-aware support-policy check fails when Composer or CI drifts from that policy.

GitHub Actions separates runtime compatibility from platform portability. Linux x64 carries the complete supported PHP/Laravel matrix and full Laravel application E2E coverage on the minimum supported PHP runtime for each Laravel major. Windows x64, macOS ARM64, Linux ARM64 and Alpine Linux each reuse one PHP 8.5 environment to run Laravel 12 and 13 package tests plus the focused portability smoke suite. Documentation-only pull requests use the lightweight CI plan and intentionally skip expensive runtime jobs while preserving the required `CI gate`.

## Releases

User-facing changes belong under `Unreleased` in `CHANGELOG.md`. When those changes are ready, run the **Prepare release PR** workflow from the Actions tab and choose a patch, minor or major increment. The workflow calculates the next strict SemVer version, moves the unreleased notes into a dated section, pushes a release branch, opens its pull request and approves the generated test run for that exact release commit. It enables auto-merge, but the protected branch keeps the merge blocked until the required `CI gate` passes.

After the release PR auto-merges, the preparation workflow dispatches protected `main` explicitly because GitHub suppresses recursive push events created with `GITHUB_TOKEN`. Main runs the complete CI gate again. Only after it succeeds does the release job build and test the exact Composer ZIP, create an annotated tag, publish the ZIP and SHA-256 checksum, record GitHub artifact provenance and wait until Packagist exposes the same version at the same commit. Ordinary main pushes without a newly prepared dated SemVer changelog section do not allocate the release job. Do not create release tags manually.

## Pull requests

- Keep each pull request focused on one problem.
- Add or update tests for behavior changes.
- Update documentation and `CHANGELOG.md` when users are affected.
- Preserve safe behavior when cache files, locks or marker files are not writable.
- Preserve the default that missing config cache is not created automatically.
- Keep the package file-based and free of databases, queues, Redis and public repair endpoints.

By participating, you agree to follow the `CODE_OF_CONDUCT.md`.
