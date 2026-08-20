<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class FileLock
{
    /**
     * @param  resource  $stream
     */
    public static function acquire($stream): bool
    {
        return @flock($stream, LOCK_EX | LOCK_NB);
    }

    /**
     * @param  resource  $stream
     */
    public static function release($stream): void
    {
        @flock($stream, LOCK_UN);
    }
}
