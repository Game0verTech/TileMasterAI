<?php

declare(strict_types=1);

namespace TileMasterAI\Server\Support;

use Throwable;

class ErrorLogger
{
    private const DEFAULT_FILENAME = 'error-log.txt';

    /**
     * Write an exception to the server-side log file and return the log path.
     */
    public static function log(Throwable $exception, string $context = ''): string
    {
        $logPath = dirname(__DIR__, 3) . '/data/' . self::DEFAULT_FILENAME;
        $directory = dirname($logPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $lines = [
            sprintf('[%s] %s%s', date('c'), $context !== '' ? "[$context] " : '', $exception->getMessage()),
            $exception->getTraceAsString(),
            '',
        ];

        file_put_contents($logPath, implode(PHP_EOL, $lines), FILE_APPEND);

        return $logPath;
    }
}
