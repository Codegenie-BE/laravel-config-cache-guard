# Security Policy

## Supported versions

Security updates are provided for the latest stable release of `codegenie-be/laravel-config-cache-guard`.

## Reporting a vulnerability

Please do not report security vulnerabilities through public GitHub issues.

Report security issues privately through GitHub Security Advisories or by contacting Codegenie directly through the contact details on:

https://www.codegenie.be

## Security design

This package is intentionally small and avoids external services.

- It does not read `.env` values.
- It does not store secrets.
- It does not log tokens, command output or environment values.
- It does not use Redis, queues, cron, workers or a database.
- Automatic pre-bootstrap rebuild commands are fixed to `config:cache` and `route:cache`.
- Shell paths are escaped when `exec()` is available.
- If automatic rebuilding fails, stale cache files are removed and a safe diagnostic marker is written.
- The optional repair endpoint requires `CONFIG_CACHE_GUARD_REPAIR_TOKEN`.
- Invalid repair tokens return `404` to avoid confirming endpoint availability.
- GET repair requests are disabled by default because URLs can be stored in browser history or logs.
- The repair endpoint does not expose `.env` values, tokens, secrets or command output.

Keep `CONFIG_CACHE_GUARD_REPAIR_TOKEN` long, random and private. Rotate it if it was ever shared, logged or exposed.
