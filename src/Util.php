<?php

namespace Resque;

use Throwable;

/**
 * Resque utility class.
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class Util
{
    /** @var int Depth to pass to json_decode. */
    private const JSON_DECODE_DEPTH = 512;
    /** @var int Options to pass to json_decode. */
    private const JSON_DECODE_OPTIONS = JSON_BIGINT_AS_STRING
        | JSON_OBJECT_AS_ARRAY; // TODO PHP 7.3 | JSON_THROW_ON_ERROR
    /** @var int Options to pass to json_encode. */
    private const JSON_ENCODE_OPTIONS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        // TODO PHP 7.3 | JSON_THROW_ON_ERROR

    /**
     * Decode some JSON into a PHP value.
     *
     * @param string $json Some JSON text.
     * @return mixed The corresponding PHP value.
     */
    public static function jsonDecode(string $json)
    {
        return json_decode(
            $json,
            true,
            self::JSON_DECODE_DEPTH,
            self::JSON_DECODE_OPTIONS
        );
    }

    /**
     * Decode some JSON into a PHP value.
     *
     * @param mixed $value A PHP value.
     * @return string|false Its JSON representation.
     */
    public static function jsonEncode($value)
    {
        return json_encode($value, self::JSON_ENCODE_OPTIONS);
    }

    /**
     * Format an exception chain as a string for display.
     *
     * @param Throwable $exception An exception.
     * @return array<string> The exception rendered as text lines.
     */
    public static function formatStackTrace(Throwable $exception): array
    {
        $seen = [];
        $output = [];

        self::formatStackTraceRecursive($exception, $seen, $output);

        return $output;
    }

    /**
     * Format an exception chain as a string for display.
     *
     * @param Throwable $e An exception.
     * @param array<string,bool> $seen Stack elements which have already been
     *      seen.
     * @param array<string> $output The output lines.
     * @return void
     */
    private static function formatStackTraceRecursive(
        Throwable $e,
        array &$seen,
        array &$output
    ): void {
        $stack = $e->getTrace() ?? [];
        $cause = $e->getPrevious();
        $file = $e->getFile() ?? 'unknown file';
        $line = $e->getLine() ?? 0;

        array_push(
            $output,
            ((count($seen) > 0) ? 'Caused by: ' : '')
            . get_class($e) . ': ' . $e->getMessage()
        );

        while (true) {
            $current = "$file:$line";

            if ($seen[$current] ?? false) {
                array_push($output, '  ... ' . (count($stack) + 1) . ' more');
                break;
            } else {
                $seen[$current] = true;
            }

            $frame = array_shift($stack) ?? [];
            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            array_push(
                $output,
                '  at '
                . ($class ?? '')
                . (isset($class) && isset($function) ? '::' : '')
                . ($function ?? '[main]')
                . ' ('
                . (isset($line) ? (basename($file) . ':' . $line) : $file)
                . ')'
            );

            if (count($frame) === 0) {
                break;
            } elseif (isset($frame['file'])) {
                $file = $frame['file'];
                $line = $frame['line'] ?? 0;
            } else {
                $file = 'unknown file';
                $line = 0;
            }
        }

        if (isset($cause)) {
            self::formatStackTraceRecursive($cause, $seen, $output);
        }
    }
}
