# CI dependency resilience

The test and release workflows intentionally keep dependency resolution reproducible and fail closed. Composer's secure dist-to-source fallback remains disabled; transient upstream HTTP failures are handled with bounded retries instead of silently changing the installation source.

## Policy

Network-facing `composer install` and `composer update` phases use:

```bash
sh tests/Support/composer-retry.sh <install|update> [...arguments]
```

The helper:

- forwards every Composer argument without `eval` or shell re-parsing;
- defaults to three total attempts;
- applies a linear 10-second backoff between attempts;
- rejects invalid retry configuration;
- returns the final Composer failure after the configured bound.

`COMPOSER_RETRY_MAX_ATTEMPTS` and `COMPOSER_RETRY_BASE_DELAY` exist for deterministic tests and exceptional CI tuning. Normal workflows use the defaults.

Retry handling does not turn deterministic dependency, security, platform or test failures into successes. A command only passes when Composer itself exits successfully.