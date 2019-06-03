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
 * @method mixed[] blpop(string|string[] $keys, int $timeout)
 * @method void connect()
 * @method int decrby(string $key, int $decrement)
 * @method int del(string|string[] $key)
 * @method int exists(string $key)
 * @method int expire(string $key, int $seconds)
 * @method string flushDb()
 * @method ?string get(string $key)
 * @method int hdel(string $key, string $field)
 * @method ?string hget(string $key, string $field)
 * @method int hset(string $key, string $field, string $value)
 * @method int incrby(string $key, int $increment)
 * @method string[] keys(string $pattern)
 * @method int llen(string $key)
 * @method ?string lpop(string $key)
 * @method int lpush(string $key, string|string[] $value)
 * @method int lrem(string $key, int $count, string $value)
 * @method ?string rpop(string $key)
 * @method ?string rpoplpush(string $source, string $destination)
 * @method int rpush(string $key, string|string[] $value)
 * @method string set(string $key, string $value)
 * @method int sadd($key, string|string[] $member)
 * @method mixed select(int $database)
 * @method int setex(string $key, int $seconds, string $value)
 * @method int sismember(string $key, string $member)
 * @method string[] smembers(string $key)
 * @method int srem(string $key, string|string[] $member)
 * @method int zadd(string $key, array $membersAndScoresDictionary)
 * @method int zcard(string $key)
 * @method int zrem(string $key, string $member)
 * @method string[] zrangebyscore(string $key, string|int $min, string|int $max, mixed[] $options = null)
 */
class Redis
{
    /**
     * @var string Redis namespace.
     */
    private static $defaultNamespace = 'resque:';

    /**
     * @var string A default host to connect to
     */
    const DEFAULT_HOST = 'localhost';

    /**
     * @var int The default Redis port
     */
    const DEFAULT_PORT = 6379;

    /**
     * @var int The default Redis Database number
     */
    const DEFAULT_DATABASE = 0;

    /**
     * @var string[] List of all commands in Redis that supply a key as their
     *    first argument. Used to prefix keys with the Resque namespace.
     */
    private $keyCommands = [
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
     * @var Client The underlying Redis driver.
     */
    private $driver = null;

    /**
     * Set Redis namespace (prefix) default: resque.
     *
     * @param string $namespace The prefix to use.
     * @return void
     */
    public static function prefix(string $namespace) : void
    {
        if (($namespace != '') && (substr($namespace, -1) !== ':')) {
            $namespace .= ':';
        }

        self::$defaultNamespace = $namespace;
    }

    /**
     * @param string|array|callable $server A DSN, parameter array, or callable.
     *      Special case: pass an array with keys 'parameters' and 'options' to
     *      pass those separately to the Predis\Client constructor
     * @param int $database A database number to select.
     */
    public function __construct($server, int $database = null)
    {
        try {
            if (is_callable($server)) {
                $this->driver = call_user_func($server, $database);
            } elseif (is_array($server)
                    && array_key_exists('parameters', $server)
                    && array_key_exists('options', $server)) {
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
     * Magic method to handle all function requests and prefix key based
     * operations with the {self::$defaultNamespace} key prefix.
     *
     * @param string $name The name of the method called.
     * @param mixed[] $args Array of supplied arguments to the method.
     * @return mixed Return value from Client->__call() based on the command.
     */
    public function __call($name, $args)
    {
        if (in_array(strtolower($name), $this->keyCommands, true)) {
            if (is_array($args[0])) {
                foreach ($args[0] as $i => $v) {
                    $args[0][$i] = self::$defaultNamespace . $v;
                }
            } else {
                $args[0] = self::$defaultNamespace . $args[0];
            }
        }

        try {
            return $this->driver->__call($name, $args);
        } catch (PredisException $e) {
            throw new RedisException('Error communicating with Redis: '
                . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Fetch the prefix/namespace.
     *
     * @return string The prefix.
     */
    public static function getPrefix() : string
    {
        return self::$defaultNamespace;
    }

    /**
     * Remove the current prefix from a string.
     *
     * @param string $string A string.
     * @return string The string with the prefix removed.
     */
    public static function removePrefix(string $string) : string
    {
        $prefix = self::getPrefix();

        if (substr($string, 0, strlen($prefix)) == $prefix) {
            $string = substr($string, strlen($prefix), strlen($string));
        }

        return $string;
    }
}
