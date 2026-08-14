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
composer check
```

Run the real application tests before proposing a release-sensitive change:

```bash
composer check:release
composer test:coverage
```

The test suite supports only PHP and Laravel versions that are not end of life. The date-aware support-policy check fails when Composer or CI drifts from that policy.

## Releases

User-facing changes belong under `Unreleased` in `CHANGELOG.md`. When those changes are ready, run the **Prepare release PR** workflow from the Actions tab and choose a patch, minor or major increment. The workflow calculates the next strict SemVer version, moves the unreleased notes into a dated section, pushes a release branch, opens its pull request and explicitly starts the complete test workflow. It enables auto-merge, but the protected branch keeps the merge blocked until the required `CI gate` passes.

After the release PR auto-merges, the protected `main` workflow runs the complete CI gate again. Only after it succeeds does the release job build and test the exact Composer ZIP, create an annotated tag, publish the ZIP and SHA-256 checksum, record GitHub artifact provenance and wait until Packagist exposes the same version at the same commit. Do not create release tags manually.

## Pull requests

- Keep each pull request focused on one problem.
- Add or update tests for behavior changes.
- Update documentation and `CHANGELOG.md` when users are affected.
- Preserve safe behavior when cache files, locks or marker files are not writable.
- Preserve the default that missing config cache is not created automatically.
- Keep the package file-based and free of databases, queues, Redis and public repair endpoints.

By participating, you agree to follow the `CODE_OF_CONDUCT.md`.
