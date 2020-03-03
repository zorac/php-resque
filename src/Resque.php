<?php

namespace Resque;

use Resque\Job\DontCreate;

/**
 * Base Resque class.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class Resque
{
    /**
     * @var string Current version of php-resque.
     */
    public const VERSION = '2.8.0';

    /**
     * @var int Default interval (in seconds) for workers to check for jobs.
     */
    public const DEFAULT_INTERVAL = 5;

    /**
     * @var Redis|null Instance of Resque\Redis that talks to Redis.
     */
    public static $redis = null;

    /**
     * @var string|array<mixed>|callable Host/port combination separated by a
     * colon, or a nested array of server with host/port pairs, or a callable
     * which will return a Redis connection.
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
     * @param string|array<mixed>|callable $server Host/port combination
     *      separated by a colon, or a nested array of servers with host/port
     *      pairs, or a callable which returns a Redis connection.
     * @param int $database The Redis database to use.
     * @param string $namespace A namespace/prefix for Redis keys.
     * @return void
     */
    public static function setBackend(
        $server,
        int $database = 0,
        string $namespace = 'resque'
    ): void {
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

        if (isset(self::$redisDatabase)) {
            self::$redis->select(self::$redisDatabase);
        }

        if (isset(self::$namespace)) {
            Redis::prefix(self::$namespace);
        }

        return self::$redis;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param array<mixed> $item Job description as an array to be JSON encoded.
     * @return void
     */
    public static function push(string $queue, array $item): void
    {
        $json = Util::jsonEncode($item);

        if ($json !== false) { // TODO or throw?
            self::redis()->sadd('queues', $queue);
            self::redis()->rpush('queue:' . $queue, $json);
        }
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @return array<mixed> Decoded item from the queue, or null if none found.
     */
    public static function pop(string $queue): ?array
    {
        $json = self::redis()->lpop('queue:' . $queue);

        if (isset($json)) {
            $item = Util::jsonDecode($json);

            if (isset($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Pop an item off the end of one of the specified queues, using a blocking
     * call, decode it and return it.
     *
     * @param array<string> $queues The name(s) of the queue(s) to fetch an
     *      item from.
     * @param int $timeout The number of seconds to wait.
     * @return array<mixed> An array containing a queue name, and a decoded
     *      item from the queue, or null if none found before the timeout.
     */
    public static function blpop(array $queues, int $timeout = 0): ?array
    {
        $keys = array_map(function (string $queue): string {
            return 'queue:' . $queue;
        }, $queues);

        [$queue, $json] = self::redis()->blpop($keys, $timeout);

        if (isset($queue) && isset($json)) {
            $queue = Redis::removePrefix($queue);
            $queue = substr($queue, 6); // remove queue:
            $item = Util::jsonDecode($json);

            if (isset($item)) {
                return [$queue, $item];
            }
        }

        return null;
    }

    /**
     * Remove items from the specified queue.
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @param array<mixed> $items The items to remove.
     * @return integer The number of deleted items.
     */
    public static function dequeue(string $queue, array $items = []): int
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
     * @param string $queue name of the queue to be checked for pending jobs.
     * @return int The size of the queue.
     */
    public static function size(string $queue): int
    {
        return self::redis()->llen('queue:' . $queue);
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to
     *      execute the job.
     * @param array<mixed> $args Any optional arguments that should be passed
     *      when the job is executed.
     * @param bool $trackStatus Set to true to be able to monitor the status of
     *      a job.
     * @return string The Job ID, or null if job creation was cancelled.
     */
    public static function enqueue(
        string $queue,
        string $class,
        array $args = null,
        bool $trackStatus = false
    ): ?string {
        $id = Resque::generateJobId();
        $event_args = [
            'class' => $class,
            'args'  => $args,
            'queue' => $queue,
            'id'    => $id,
        ];

        try {
            Event::trigger('beforeEnqueue', $event_args);
        } catch (DontCreate $e) {
            return null;
        }

        $id = Job::create($queue, $class, $args, $trackStatus, $id);

        $event_args['id'] = $id;
        Event::trigger('afterEnqueue', $event_args);

        return $id;
    }

    /**
     * Reserve and return the next available job in the specified queue.
     *
     * @param string $queue Queue to fetch next available job from.
     * @return Job Instance of Resque\Job to be processed, null if none or
     *       error.
     */
    public static function reserve(string $queue): ?Job
    {
        return Job::reserve($queue);
    }

    /**
     * Get an array of all known queues.
     *
     * @return array<string> Array of queues.
     */
    public static function queues()
    {
        return self::redis()->smembers('queues');
    }

    /**
     * Remove Items from the queue
     * Safely moving each item to a temporary queue before processing it
     * If the Job matches, counts otherwise puts it in a requeue_queue
     * which at the end eventually be copied back into the original queue
     *
     * @param string $queue The name of the queue.
     * @param array<mixed> $items The items to remove.
     * @return integer The number of deleted items.
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
            $string = self::redis()->rpoplpush(
                $originalQueue,
                Redis::getPrefix() . $tempQueue
            );

            if (isset($string)) {
                if (self::matchItem($string, $items)) {
                    self::redis()->rpop($tempQueue);
                    $counter++;
                } else {
                    self::redis()->rpoplpush(
                        $tempQueue,
                        Redis::getPrefix() . $requeueQueue
                    );
                }
            } else {
                $finished = true;
            }
        }

        // move back from temp queue to original queue
        $finished = false;

        while (!$finished) {
            $string = self::redis()->rpoplpush(
                $requeueQueue,
                Redis::getPrefix() . $originalQueue
            );

            if (!isset($string)) {
                $finished = true;
            }
        }

        // remove temp queue and requeue queue
        self::redis()->del($requeueQueue);
        self::redis()->del($tempQueue);

        return $counter;
    }

    /**
     * Matching item
     * item can be ['class'] or ['class' => 'id'] or ['class' => {:foo => 1, :bar => 2}]
     *
     * @param string $string redis result in json.
     * @param array<mixed> $items the items to match.
     * @return bool
     */
    private static function matchItem(string $string, array $items): bool
    {
        $decoded = Util::jsonDecode($string); // TODO how to handle failure

        foreach ($items as $key => $val) {
            // class name only  ex: item[0] = ['class']
            if (is_numeric($key)) {
                if ($decoded['class'] == $val) {
                    return true;
                }
                // class name with args , example: item[0] = ['class' => {'foo' => 1, 'bar' => 2}]
            } elseif (is_array($val)) {
                $decodedArgs = (array)$decoded['args'][0];
                if (
                    ($decoded['class'] == $key)
                    && (count($decodedArgs) > 0)
                    && (count(array_diff($decodedArgs, $val)) == 0)
                ) {
                    return true;
                }
                // class name with ID, example: item[0] = ['class' => 'id']
            } else {
                if (($decoded['class'] == $key) && ($decoded['id'] == $val)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove List.
     *
     * @param string $queue the name of the queue.
     * @return integer number of deleted items belongs to this list.
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
    public static function generateJobId(): string
    {
        return md5(uniqid('', true));
    }
}
