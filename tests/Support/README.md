# Test support utilities

The files in this directory are repository-internal validation and CI helpers. They are not part of the package runtime or Composer distribution.

## Composer retry policy

`composer-retry.sh` executes one Composer command with bounded retries and linear backoff. CI uses it only around dependency resolution and installation phases that can fail transiently because of upstream HTTP 429 or 5xx responses.

The helper deliberately forwards arguments without `eval`, keeps Composer's secure dist-to-source fallback policy unchanged, and fails after a finite number of attempts. `test-composer-retry.sh` exercises eventual success, permanent failure, argument forwarding and invalid retry configuration.

Defaults:

```text
COMPOSER_RETRY_MAX_ATTEMPTS=3
COMPOSER_RETRY_BASE_DELAY=10
```

Tests may set the base delay to zero; production CI uses the defaults.