#!/bin/sh
set -eu

attempt=1
max_attempts=3

while :; do
    if composer update "$@"; then
        exit 0
    fi

    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "Composer update failed after ${max_attempts} attempts." >&2
        exit 1
    fi

    delay=$((attempt * 10))
    echo "Composer update failed; retrying in ${delay} seconds (${attempt}/${max_attempts})." >&2
    sleep "$delay"
    attempt=$((attempt + 1))
done
