<?php

namespace Resque\Failure;

use Exception;
use Resque\Worker;

/**
 * Interface that all failure backends should implement.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
interface Backend
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
    );

    /**
     * Return details about a failed job.
     *
     * @param string $jobId A Job ID.
     * @return mixed[] Array containing details of the failed job, or null if
     *      not found.
     */
    public static function get(string $jobId) : ?array;
}
