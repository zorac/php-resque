<?php

namespace Resque\Failure;

use Exception;
use Resque\Resque;
use Resque\Util;
use Resque\Worker;

/**
 * Redis backend for storing failed Resque jobs.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class RedisBackend implements Backend
{
    /**
     * Initialize a failed job class and save it (where appropriate).
     *
     * @param array<mixed> $payload Object containing details of the failed job.
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
        $json = Util::jsonEncode([
            'failed_at' => strftime('%F %T'),
            'payload' => $payload,
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
            'backtrace' => Util::formatStackTrace($exception),
            'worker' => (string)$worker,
            'queue' => $queue,
        ]);

        if ($json !== false) {
            Resque::redis()->setex('failed:' . $payload['id'], 86400, $json);
        }
    }

    /**
     * Return details about a failed job.
     *
     * @param string $jobId A Job ID.
     * @return array<mixed> Array containing details of the failed job, or null
     *      if not found.
     */
    public static function get(string $jobId): ?array
    {
        $json = Resque::redis()->get("failed:$jobId");

        if (isset($json)) {
            $failure = Util::jsonDecode($json);

            if (isset($failure)) {
                return $failure;
            }
        }

        return null;
    }
}
