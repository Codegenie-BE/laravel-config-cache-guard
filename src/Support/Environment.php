<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

use Illuminate\Support\Env;

final class Environment
{
    private const SNAPSHOT_KEY = '__codegenie_config_cache_guard_external_environment';

    /**
     * Capture process/server values before Laravel loads dotenv so the
     * pre-bootstrap guard and deferred repair layer observe the same inputs.
     *
     * @param  list<string>  $names
     */
    public static function capture(array $names): void
    {
        if (array_key_exists(self::SNAPSHOT_KEY, $GLOBALS)) {
            return;
        }

        $captured = [];

        foreach ($names as $name) {
            $captured[$name] = self::rawString($name);
        }

        $GLOBALS[self::SNAPSHOT_KEY] = $captured;
    }

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
        $capturedEnvironment = $GLOBALS[self::SNAPSHOT_KEY] ?? null;

        if (is_array($capturedEnvironment) && array_key_exists($name, $capturedEnvironment)) {
            $capturedValue = $capturedEnvironment[$name];

            return is_string($capturedValue) && $capturedValue !== ''
                ? $capturedValue
                : null;
        }

        $value = self::rawString($name);

        if ($value !== null) {
            return $value;
        }

        $envValue = Env::get($name);

        return is_string($envValue) && $envValue !== '' ? $envValue : null;
    }

    public static function set(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $capturedEnvironment = $GLOBALS[self::SNAPSHOT_KEY] ?? null;

        if (! is_array($capturedEnvironment)) {
            return;
        }

        $capturedEnvironment[$name] = $value;
        $GLOBALS[self::SNAPSHOT_KEY] = $capturedEnvironment;
    }

    private static function rawString(string $name): ?string
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

        return null;
    }
}
