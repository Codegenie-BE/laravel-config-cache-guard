<?php

declare(strict_types=1);

namespace Codegenie\ConfigCacheGuard\Support;

final class BoundedProcess
{
    /**
     * @param  non-empty-list<string>  $command
     */
    public static function run(array $command, string $workingDirectory, int $timeoutSeconds): bool
    {
        if (! self::isAvailable() || $timeoutSeconds < 1) {
            return false;
        }

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $nullDevice, 'a'],
        ];
        $pipes = [];
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true]
        );

        if (! is_resource($process)) {
            return false;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $exitCode = null;

        try {
            do {
                $status = @proc_get_status($process);

                if (! $status['running']) {
                    $exitCode = $status['exitcode'];

                    break;
                }

                if (microtime(true) >= $deadline) {
                    @proc_terminate($process);

                    return false;
                }

                usleep(10_000);
            } while (true);
        } finally {
            $closedExitCode = @proc_close($process);
        }

        return $exitCode === 0 || $closedExitCode === 0;
    }

    public static function isAvailable(): bool
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        $disabledFunctions = array_filter(array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions'))
        ));

        return array_intersect(
            ['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'],
            $disabledFunctions
        ) === [];
    }
}
