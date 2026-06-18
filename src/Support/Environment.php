<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use Illuminate\Support\Env;

final class Environment
{
    public static function flag(string $name, bool $default = true): bool
    {
        $value = self::string($name);

        if ($value === null) {
            return $default;
        }

        return ! in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
    }

    public static function string(string $name): ?string
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $envValue = Env::get($name);

        if (is_string($envValue) && $envValue !== '') {
            return $envValue;
        }

        return null;
    }
}
