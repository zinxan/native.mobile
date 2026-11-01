<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use Symfony\Component\ErrorHandler\Error\FatalError;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Level;

/**
 * Reports fatal errors, such as running out of memory, that Laravel cannot report.
 *
 * Laravel reports a fatal error from a shutdown function. NativePHP mobile keeps one PHP
 * process alive, so that function never runs, and a fatal error can leave the runtime
 * unable to serve another request. PHP itself still writes the error to its error_log,
 * so the SDK points error_log at a file and sends what it finds on the next launch.
 */
final class FatalErrors
{
    private const array FATAL = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

    private const int MAX_LOG_BYTES = 65_536;

    private const string LOG_LINE = '/^\[[^\]]+\] PHP (?:Fatal error|Parse error):\s+(?<message>.+) in (?<file>.+) on line (?<line>\d+)$/m';

    private bool $collected = false;

    public function __construct(private readonly Client $client) {}

    /**
     * Send PHP to the log file of the SDK, unless the app chose a log file itself.
     */
    public static function logTo(string $path): void
    {
        if ((string) ini_get('error_log') !== '') {
            return;
        }

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            return;
        }

        ini_set('log_errors', '1');
        ini_set('error_log', $path);
    }

    /**
     * Report the fatal errors in the log file, then empty it, once per launch.
     * Returns the number of errors.
     */
    public function collectFrom(string $path): int
    {
        if ($this->collected || ! $this->client->isEnabled()) {
            return 0;
        }

        $this->collected = true;

        if (! is_file($path) || ! is_readable($path) || filesize($path) === 0) {
            return 0;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        fseek($handle, -min(self::MAX_LOG_BYTES, (int) filesize($path)), SEEK_END);
        $log = (string) fread($handle, self::MAX_LOG_BYTES);
        fclose($handle);
        @file_put_contents($path, '');

        preg_match_all(self::LOG_LINE, $log, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $this->capture(['type' => E_ERROR, 'message' => $match['message'], 'file' => $match['file'], 'line' => (int) $match['line']]);
        }

        return count($matches);
    }

    /**
     * @param  array{type: int, message: string, file: string, line: int}|null  $error  The result of error_get_last().
     */
    public function report(?array $error): void
    {
        if ($error === null || ! in_array($error['type'], self::FATAL, true)) {
            return;
        }

        error_clear_last();

        $this->capture($error);
    }

    /**
     * @param  array{type: int, message: string, file: string, line: int}  $error
     */
    private function capture(array $error): void
    {
        $this->client->captureException(new FatalError($error['message'], 0, $error, 0), Level::Fatal);
    }
}
