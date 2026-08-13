<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class FileLock
{
    /**
     * @param  resource  $stream
     */
    public static function acquire($stream, int $timeoutMilliseconds = 0): bool
    {
        $deadline = microtime(true) + (max(0, $timeoutMilliseconds) / 1000);

        do {
            if (@flock($stream, LOCK_EX | LOCK_NB)) {
                return true;
            }

            if ($timeoutMilliseconds === 0 || microtime(true) >= $deadline) {
                return false;
            }

            usleep(25_000);
        } while (true);
    }

    /**
     * @param  resource  $stream
     */
    public static function release($stream): void
    {
        @flock($stream, LOCK_UN);
    }
}
