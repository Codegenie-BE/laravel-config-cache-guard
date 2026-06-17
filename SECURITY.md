# Security Policy

## Supported versions

The latest stable version receives security fixes.

## Reporting a vulnerability

Please report security issues privately before opening any public issue.

Use GitHub private vulnerability reporting when it is enabled for this repository, or contact Codegenie through the official contact details on the Codegenie website.

Do not include secrets, tokens, passwords, cookies, authorization headers, `.env` values or customer data in reports.

## Security design

This package is intentionally small and file-based:

- it does not read `.env` values;
- it does not store secrets;
- it does not send data to external services;
- it does not use a database, Redis, queues, workers or cron;
- it runs a fixed `php artisan config:cache` command only when a metadata signature changed;
- shell paths are escaped and no user input is passed to the shell.
