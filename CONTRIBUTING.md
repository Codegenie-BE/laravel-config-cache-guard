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
```

The test suite supports only PHP and Laravel versions that are not end of life. The date-aware support-policy check fails when Composer or CI drifts from that policy.

## Pull requests

- Keep each pull request focused on one problem.
- Add or update tests for behavior changes.
- Update documentation and `CHANGELOG.md` when users are affected.
- Preserve safe behavior when cache files, locks or marker files are not writable.
- Preserve the default that missing config cache is not created automatically.
- Keep the package file-based and free of databases, queues, Redis and public repair endpoints.

By participating, you agree to follow the `CODE_OF_CONDUCT.md`.
