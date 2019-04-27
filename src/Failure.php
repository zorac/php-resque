<?php

namespace Resque;

use Exception;

/**
 * Failed Resque job.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Failure
{
    /**
     * @var string Class name representing the backend to pass failed jobs off
     *      to.
     */
    private static $backend;

    /**
     * Create a new failed job on the backend.
     *
     * @param mixed[] $payload The contents of the job that has just failed.
     * @param Exception $exception The exception generated when the job failed
     *      to run.
     * @param Worker $worker Instance of Resque\Worker that was running this
     *      job when it failed.
     * @param string $queue The name of the queue that this job was fetched
     *      from.
     */
    public static function create(
        array $payload,
        Exception $exception,
        Worker $worker,
        string $queue
    ) : void {
        $backend = self::getBackend();
        new $backend($payload, $exception, $worker, $queue);
    }

    /**
     * Return the class name of the backend for saving job failures.
     *
     * @return string Class name of backend object.
     */
    public static function getBackend() : string
    {
        if (self::$backend === null) {
            self::$backend = '\\Resque\\Failure\\RedisBackend';
        }

        return self::$backend;
    }

    /**
     * Set the backend to use for raised job failures. The supplied backend
     * should be the name of a class to be instantiated when a job fails. It is
     * your responsibility to have the backend class loaded (or autoloaded.)
     *
     * @param string $backend The class name of the backend to pipe failures to.
     */
    public static function setBackend(string $backend) : void
    {
        self::$backend = $backend;
    }
}
