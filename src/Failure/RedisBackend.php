<?php

namespace Resque\Failure;

use \Exception;
use \Resque\Resque;
use \Resque\Worker;

/**
 * Redis backend for storing failed Resque jobs.
 *
 * @package Resque/Failure
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class RedisBackend implements Backend
{
    /**
     * Initialize a failed job class and save it (where appropriate).
     *
     * @param mixed[] $payload Object containing details of the failed job.
     * @param Exception $exception Instance of the exception that was thrown by
     *      the failed job.
     * @param Worker $worker Instance of Resque\Worker that received the job.
     * @param string $queue The name of the queue the job was fetched from.
     */
    public function __construct(
        array $payload,
        Exception $exception,
        Worker $worker,
        string $queue
    ) {
        $data = [
            'failed_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
            'payload' => $payload,
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
            'backtrace' => explode("\n", $exception->getTraceAsString()),
            'worker' => (string)$worker,
            'queue' => $queue,
        ];

        Resque::redis()->setex('failed:' . $payload['id'], 3600 * 14,
            serialize($data));
    }

    /**
     * Return details about a failed job.
     *
     * @param string $jobId A Job ID.
     * @return mixed[] Array containing details of the failed job.
     */
    static public function get(string $jobId) : array
    {
        $data = Resque::redis()->get('failed:' . $jobId);
        return unserialize($data);
    }
}
