<?php

namespace Resque;

/**
 * Base Resque class.
 *
 * @package Resque
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Resque
{
    const VERSION = '2.0.1';

    /**
     * @var Redis|null Instance of Resque\Redis that talks to redis.
     */
    public static $redis = null;

    /**
     * @var string|array|callable Host/port combination separated by a colon,
     * or a nested array of server with host/port pairs, or a callable which
     * will return a Redis connection.
     */
    protected static $redisServer = null;

    /**
     * @var int ID of Redis database to select.
     */
    protected static $redisDatabase = 0;

    /**
     * @var string namespace of the redis keys
     */
    protected static $namespace = '';

    /**
     * @var int|null PID of current process. Used to detect changes when
     * forking and implement "thread" safety to avoid race conditions.
     */
    protected static $pid = null;

    /**
     * Given a host/port combination separated by a colon, set it as
     * the redis server that Resque will talk to.
     *
     * @param string|array|callable $server Host/port combination separated by
     *      a colon, or a nested array of servers with host/port pairs, or a
     *      callable which returns a redis connection.
     * @param int $database The Redis database to use
     * @param string $namespace A namespace/prefix for Redis keys
     */
    public static function setBackend(
        $server,
        int $database = 0,
        string $namespace = 'resque'
    ) {
        self::$redisServer = $server;
        self::$redisDatabase = $database;
        self::$redis = null;
        self::$namespace = $namespace;
    }

    /**
     * Return an instance of the Resque\Redis class instantiated for Resque.
     *
     * @return Redis Instance of Resque\Redis.
     */
    public static function redis()
    {
        // Detect when the PID of the current process has changed (from a fork,
        // etc) and force a reconnect to Redis.
        $pid = getmypid();

        if (self::$pid !== $pid) {
            self::$redis = null;
            self::$pid = $pid;
        }

        if (isset(self::$redis)) {
            return self::$redis;
        }

        self::$redis = new Redis(self::$redisServer, self::$redisDatabase);

        if (!empty(self::$redisDatabase)) {
            self::$redis->select(self::$redisDatabase);
        }

        if (!empty(self::$namespace)) {
            Redis::prefix(self::$namespace);
        }

        return self::$redis;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param mixed[] $item Job description as an array to be JSON encoded.
     */
    public static function push(string $queue, array $item)
    {
        $json = json_encode($item);

        if ($json !== false ) { // TODO or throw?
            self::redis()->sadd('queues', $queue);
            self::redis()->rpush('queue:' . $queue, $json);
        }
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @return mixed[] Decoded item from the queue, or null if none found.
     */
    public static function pop(string $queue) : ?array
    {
        $item = self::redis()->lpop('queue:' . $queue);

        if (!$item) {
            return null;
        }

        return json_decode($item, true);
    }

    /**
     * Remove items from the specified queue
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @param array $items
     * @return integer number of deleted items
     */
    public static function dequeue(string $queue, array $items = []) : int
    {
        if (count($items) > 0) {
            return self::removeItems($queue, $items);
        } else {
            return self::removeList($queue);
        }
    }

    /**
     * Return the size (number of pending jobs) of the specified queue.
     *
     * @param string $queue name of the queue to be checked for pending jobs
     * @return int The size of the queue.
     */
    public static function size(string $queue) : int
    {
        return self::redis()->llen('queue:' . $queue);
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to
     *      execute the job.
     * @param mixed[] $args Any optional arguments that should be passed when
     *      the job is executed.
     * @param bool $trackStatus Set to true to be able to monitor the status of
     *      a job.
     *
     * @return string
     */
    public static function enqueue(
        string $queue,
        string $class,
        array $args = null,
        bool $trackStatus = false
    ) {
        $result = Job::create($queue, $class, $args, $trackStatus);

        if ($result) {
            Event::trigger('afterEnqueue', [
                'class' => $class,
                'args'  => $args,
                'queue' => $queue,
            ]);
        }

        return $result;
    }

    /**
     * Reserve and return the next available job in the specified queue.
     *
     * @param string $queue Queue to fetch next available job from.
     * @return Job Instance of Resque\Job to be processed, null if none or
     *       error.
     */
    public static function reserve(string $queue) : ?Job
    {
        return Job::reserve($queue);
    }

    /**
     * Get an array of all known queues.
     *
     * @return array Array of queues.
     */
    public static function queues()
    {
        $queues = self::redis()->smembers('queues');
        if (!is_array($queues)) {
            $queues = [];
        }

        return $queues;
    }

    /**
     * Remove Items from the queue
     * Safely moving each item to a temporary queue before processing it
     * If the Job matches, counts otherwise puts it in a requeue_queue
     * which at the end eventually be copied back into the original queue
     *
     * @private
     *
     * @param string $queue The name of the queue
     * @param array $items
     * @return integer number of deleted items
     */
    private static function removeItems($queue, $items = [])
    {
        $counter = 0;
        $originalQueue = 'queue:' . $queue;
        $tempQueue = $originalQueue . ':temp:' . time();
        $requeueQueue = $tempQueue . ':requeue';

        // move each item from original queue to temp queue and process it
        $finished = false;

        while (!$finished) {
            $string = self::redis()->rpoplpush($originalQueue,
                self::redis()->getPrefix() . $tempQueue);

            if (!empty($string)) {
                if (self::matchItem($string, $items)) {
                    self::redis()->rpop($tempQueue);
                    $counter++;
                } else {
                    self::redis()->rpoplpush($tempQueue,
                        self::redis()->getPrefix() . $requeueQueue);
                }
            } else {
                $finished = true;
            }
        }

        // move back from temp queue to original queue
        $finished = false;

        while (!$finished) {
            $string = self::redis()->rpoplpush($requeueQueue,
                self::redis()->getPrefix() . $originalQueue);

            if (empty($string)) {
                $finished = true;
            }
        }

        // remove temp queue and requeue queue
        self::redis()->del($requeueQueue);
        self::redis()->del($tempQueue);

        return $counter;
    }

    /**
     * matching item
     * item can be ['class'] or ['class' => 'id'] or ['class' => {:foo => 1, :bar => 2}]
     *
     * @param string $string redis result in json
     * @param mixed[] $items
     * @return bool
     */
    private static function matchItem(string $string, array $items) : bool
    {
        $decoded = json_decode($string, true);

        foreach ($items as $key => $val) {
            # class name only  ex: item[0] = ['class']
            if (is_numeric($key)) {
                if ($decoded['class'] == $val) {
                    return true;
                }
                # class name with args , example: item[0] = ['class' => {'foo' => 1, 'bar' => 2}]
            } elseif (is_array($val)) {
                $decodedArgs = (array)$decoded['args'][0];
                if ($decoded['class'] == $key &&
                    count($decodedArgs) > 0 && count(array_diff($decodedArgs,
                        $val)) == 0
                ) {
                    return true;
                }
                # class name with ID, example: item[0] = ['class' => 'id']
            } else {
                if ($decoded['class'] == $key && $decoded['id'] == $val) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove List.
     *
     * @param string $queue the name of the queue
     * @return integer number of deleted items belongs to this list
     */
    private static function removeList($queue)
    {
        $counter = self::size($queue);
        $result = self::redis()->del('queue:' . $queue);

        return ($result == 1) ? $counter : 0;
    }

    /**
     * Generate an identifier to attach to a job for status tracking.
     *
     * @return string A job ID.
     */
    public static function generateJobId() : string
    {
        return md5(uniqid('', true));
    }
}
