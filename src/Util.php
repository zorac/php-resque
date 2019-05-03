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
}
