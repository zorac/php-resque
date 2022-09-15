<?php

namespace Resque;

use JsonException;
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
        | JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR;
    /** @var int Options to pass to json_encode. */
    private const JSON_ENCODE_OPTIONS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /**
     * Decode some JSON into a PHP value.
     *
     * @param string $json Some JSON text.
     * @throws JsonException If the JSON text is not valid.
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
     * @throws JsonException If the value could not be encoded.
     * @return string Its JSON representation.
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
        return self::formatStackTraceRecursive($exception);
    }

    /**
     * Format an exception chain as a string for display.
     *
     * @param Throwable $e An exception.
     * @param array<string> $parent The trace of the parent exception.
     * @return array<string> The stack trace
     */
    private static function formatStackTraceRecursive(
        Throwable $e,
        array $parent = []
    ): array {
        // Format the stack trace for this exception

        $stack = $e->getTrace() ?? [];
        $file = $e->getFile() ?? 'unknown file';
        $line = $e->getLine() ?? 0;
        $output = [];

        do {
            $frame = array_shift($stack);
            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            $output[] = '  in '
                . ($class ?? '')
                . (isset($class) && isset($function) ? '::' : '')
                . ($function ?? '[main]')
                . ' ('
                . (isset($line) ? (basename($file) . ':' . $line) : $file)
                . ')';

            $file = $frame['file'] ?? 'unknown file';
            $line = $frame['line'] ?? 0;
        } while (isset($frame));

        // Fetch the stack trace(s) for any descendants.

        $previous = $e->getPrevious();
        $child = isset($previous)
            ? self::formatStackTraceRecursive($previous, $output) : [];

        // Remove any trailing lines which duplicate the parent trace

        $output_pos = count($output) - 1;
        $parent_pos = count($parent) - 1;
        $seen = 0;

        while (
            ($output_pos >= 0)
            && ($parent_pos >= 0)
            && ($output[$output_pos] === $parent[$parent_pos])
        ) {
            $output_pos--;
            $parent_pos--;
            $seen++;
        }

        if ($seen > 0) {
            $output = array_slice($output, 0, -$seen);
            $output[] = "  ... $seen more";
        }

        // Check for recursion in the trace

        $start = 0;
        $pos = 1;
        $count = count($output);

        while ($pos < $count) {
            for ($i = $start; $i < $pos; $i++) {
                if ($output[$i] !== $output[$pos]) {
                    continue;
                }

                $len = $pos - $i;
                $reps = 1;

                while ($reps < 999) { // Safety valve
                    for ($j = 0; $j < $len; $j++) {
                        $index = $i + $j + ($reps * $len);

                        if (
                            ($index >= $count)
                            || ($output[$index] !== $output[$i + $j])
                        ) {
                            break 2;
                        }
                    }

                    $reps++;
                }

                if ($reps > 1) {
                    array_splice(
                        $output,
                        $i,
                        $len * $reps,
                        array_merge(
                            ["  ... repeat $reps times:"],
                            array_slice($output, $i, $len),
                            ['  ... end repeat']
                        )
                    );

                    $start = $i + $len + 2;
                    $pos = $start;
                    $count = count($output);
                    break;
                }
            }

            $pos++;
        }

        // Add the header line for this trace and return it

        array_unshift($output, ((count($parent) === 0) ? '' : 'Caused by: ')
            . get_class($e) . ': ' . $e->getMessage());

        return array_merge($output, $child);
    }
}
