<?php
/**
 * Wrap Predis to add namespace support and various helper methods.
 *
 * @package Resque/Redis
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Redis
{
    /**
     * Redis namespace
     * @var string
     */
    private static $defaultNamespace = 'resque:';

    /**
     * A default host to connect to
     */
    const DEFAULT_HOST = 'localhost';

    /**
     * The default Redis port
     */
    const DEFAULT_PORT = 6379;

    /**
     * The default Redis Database number
     */
    const DEFAULT_DATABASE = 0;

    /**
     * @var array List of all commands in Redis that supply a key as their
     *    first argument. Used to prefix keys with the Resque namespace.
     */
    private $keyCommands = array(
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
        'rpoplpush'
    );
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
     * @var object The underlying Redis driver.
     */
    private $driver = null;

    /**
     * Set Redis namespace (prefix) default: resque
     * @param string $namespace
     */
    public static function prefix($namespace)
    {
        if (substr($namespace, -1) !== ':' && $namespace != '') {
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
    public function __construct($server, $database = null)
    {
        try {
            if (is_callable($server)) {
                $this->driver = call_user_func($server, $database);
            } else if (isset($server) && is_array($server)
                    && array_key_exists('parameters', $server)
                    && array_key_exists('options', $server)) {
                $this->driver = new Predis\Client($server['parameters'],
                    $server['options']);
            } else if (isset($server) && is_string($server)
                    && (strpos($server, ',') > 0)) {
                $this->driver = new Predis\Client(explode(',', $server));
            } else {
                $this->driver = new Predis\Client($server);
            }

            if (isset($database)) {
                $this->driver->select($database);
            }
        }
        catch (Predis\PredisException $e) {
            throw new Resque_RedisException('Error communicating with Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Magic method to handle all function requests and prefix key based
     * operations with the {self::$defaultNamespace} key prefix.
     *
     * @param string $name The name of the method called.
     * @param array $args Array of supplied arguments to the method.
     * @return mixed Return value from Resident::call() based on the command.
     */
    public function __call($name, $args)
    {
        if (in_array(strtolower($name), $this->keyCommands)) {
            if (is_array($args[0])) {
                foreach ($args[0] AS $i => $v) {
                    $args[0][$i] = self::$defaultNamespace . $v;
                }
            }
            else {
                $args[0] = self::$defaultNamespace . $args[0];
            }
        }
        try {
            return $this->driver->__call($name, $args);
        }
        catch (Predis\PredisException $e) {
            throw new Resque_RedisException('Error communicating with Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function getPrefix()
    {
        return self::$defaultNamespace;
    }

    public static function removePrefix($string)
    {
        $prefix=self::getPrefix();

        if (substr($string, 0, strlen($prefix)) == $prefix) {
            $string = substr($string, strlen($prefix), strlen($string) );
        }
        return $string;
    }
}
