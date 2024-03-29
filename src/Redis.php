<?php

namespace Resque;

use Predis\Client;
use Predis\PredisException;

/**
 * Wrap Predis to add namespace support and various helper methods.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @method array<mixed> blpop(string|array<string> $keys, int $timeout)
 * @method int decrby(string $key, int $decrement)
 * @method int del(string|array<string> $key)
 * @method int exists(string $key)
 * @method int expire(string $key, int $seconds)
 * @method string flushDb()
 * @method ?string get(string $key)
 * @method int hdel(string $key, string $field)
 * @method ?string hget(string $key, string $field)
 * @method int hset(string $key, string $field, string $value)
 * @method int incrby(string $key, int $increment)
 * @method array<string> keys(string $pattern)
 * @method int llen(string $key)
 * @method ?string lpop(string $key)
 * @method int lpush(string $key, string|array<string> $value)
 * @method int lrem(string $key, int $count, string $value)
 * @method ?string rpop(string $key)
 * @method ?string rpoplpush(string $source, string $destination)
 * @method int rpush(string $key, string|array<string> $value)
 * @method string set(string $key, string $value)
 * @method int sadd($key, string|array<string> $member)
 * @method mixed select(int $database)
 * @method int setex(string $key, int $seconds, string $value)
 * @method int sismember(string $key, string $member)
 * @method array<string> smembers(string $key)
 * @method int srem(string $key, string|array<string> $member)
 * @method int zadd(string $key, array $membersAndScoresDictionary)
 * @method int zcard(string $key)
 * @method int zrem(string $key, string $member)
 * @method array<string> zrangebyscore(string $key, string|int $min, string|int $max, array<mixed> $options = null)
 */
class Redis
{
    /**
     * @var string A default host to connect to
     */
    public const DEFAULT_HOST = 'localhost';

    /**
     * @var int The default Redis port
     */
    public const DEFAULT_PORT = 6379;

    /**
     * @var int The default Redis Database number
     */
    public const DEFAULT_DATABASE = 0;

    /**
     * @var array<string> List of all commands in Redis that supply a key as
     *      their first argument. Used to prefix keys with the Resque namespace.
     */
    private const KEY_COMMANDS = [
        'exists',
        'del',
        'type',
        'keys',
        'expire',
        'ttl',
        'move',
        'set',
        'setex',
        'get',
        'getset',
        'setnx',
        'incr',
        'incrby',
        'decr',
        'decrby',
        'rpush',
        'lpush',
        'llen',
        'lrange',
        'ltrim',
        'lindex',
        'lset',
        'lrem',
        'lpop',
        'blpop',
        'rpop',
        'sadd',
        'srem',
        'spop',
        'scard',
        'sismember',
        'smembers',
        'srandmember',
        'zadd',
        'zrem',
        'zrange',
        'zrevrange',
        'zrangebyscore',
        'zcard',
        'zscore',
        'zremrangebyscore',
        'sort',
        'rename',
        'rpoplpush',
        'hget',
        'hset',
        'hdel',
    ];
    // sinterstore
    // sunion
    // sunionstore
    // sdiff
    // sdiffstore
    // sinter
    // smove
    // mget
    // msetnx
    // mset
    // renamenx

    /**
     * @var string Redis namespace.
     */
    private static $defaultNamespace = 'resque:';

    /**
     * @var Client The underlying Redis driver.
     */
    private $driver = null;

    /**
     * Set Redis namespace (prefix) default: resque.
     *
     * @param string $namespace The prefix to use.
     * @return void
     */
    public static function prefix(string $namespace): void
    {
        if (($namespace != '') && (substr($namespace, -1) !== ':')) {
            $namespace .= ':';
        }

        self::$defaultNamespace = $namespace;
    }

    /**
     * Create a new Redis client instance.
     *
     * @param string|array<mixed>|callable $server A DSN, parameter array, or
     *      callable. Special case: pass an array with keys 'parameters' and
     *      'options' to pass those separately to the Predis\Client constructor.
     * @param int $database A database number to select.
     */
    public function __construct($server, int $database = null)
    {
        try {
            if (is_callable($server)) {
                $this->driver = call_user_func($server, $database);
            } elseif (
                is_array($server)
                && array_key_exists('parameters', $server)
                && array_key_exists('options', $server)
            ) {
                $this->driver = new Client(
                    $server['parameters'],
                    $server['options']
                );
            } elseif (is_string($server) && (strpos($server, ',') > 0)) {
                $this->driver = new Client(explode(',', $server));
            } else {
                $this->driver = new Client($server);
            }

            if (isset($database)) {
                $this->driver->select($database);
            }
        } catch (PredisException $e) {
            throw new RedisException('Error communicating with Redis: '
                . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Connect to Redis. Normally, there should be no need to call this
     * directly.
     *
     * @return void
     */
    public function connect()
    {
        try {
            $this->driver->connect();
        } catch (PredisException $e) {
            throw new RedisException('Connection to Redis failed: '
                . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Disconnect from Redis.
     *
     * @return void
     */
    public function disconnect()
    {
        try {
            $this->driver->disconnect();
        } catch (PredisException $e) {
            throw new RedisException('Disconnection from Redis failed: '
                . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Magic method to handle all function requests and prefix key based
     * operations with the {self::$defaultNamespace} key prefix.
     *
     * @param string $name The name of the method called.
     * @param array<mixed> $args Array of supplied arguments to the method.
     * @return mixed Return value from Client->__call() based on the command.
     */
    public function __call($name, $args)
    {
        if (in_array(strtolower($name), self::KEY_COMMANDS, true)) {
            if (is_array($args[0])) {
                foreach ($args[0] as $i => $v) {
                    $args[0][$i] = self::$defaultNamespace . $v;
                }
            } else {
                $args[0] = self::$defaultNamespace . $args[0];
            }
        }

        $loading_exception = null;

        for ($i = 1; $i < 20; $i++) {
            try {
                return $this->driver->__call($name, $args);
            } catch (PredisException $e) {
                $message = $e->getMessage();

                if (str_starts_with($message, 'LOADING')) {
                    $loading_exception = $e;
                } else {
                    throw new RedisException(
                        "Error communicating with Redis: $message",
                        0,
                        $e
                    );
                }
            }

            sleep($i);
        }

        throw new RedisException(
            'Error communicating with Redis: Still loading dataset after multiple attempts',
            0,
            $loading_exception
        );
    }

    /**
     * Fetch the prefix/namespace.
     *
     * @return string The prefix.
     */
    public static function getPrefix(): string
    {
        return self::$defaultNamespace;
    }

    /**
     * Remove the current prefix from a string.
     *
     * @param string $string A string.
     * @return string The string with the prefix removed.
     */
    public static function removePrefix(string $string): string
    {
        $prefix = self::getPrefix();

        if (substr($string, 0, strlen($prefix)) == $prefix) {
            $string = substr($string, strlen($prefix), strlen($string));
        }

        return $string;
    }
}
