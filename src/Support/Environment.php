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
        $capturedEnvironment = $GLOBALS['__codegenie_config_cache_guard_external_environment'] ?? null;

        if (is_array($capturedEnvironment) && array_key_exists($name, $capturedEnvironment)) {
            $capturedValue = $capturedEnvironment[$name];

            return is_string($capturedValue) && $capturedValue !== ''
                ? $capturedValue
                : null;
        }

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
