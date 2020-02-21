<?php

namespace Resque\Job;

use Resque\Resque;
use Resque\Util;

/**
 * Status tracker/information for a job.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class Status
{
    /**
     * @var int The status code for a job which is waiting to be started.`
     */
    public const STATUS_WAITING = 1;

    /**
     * @var int The status code for a job which is currently running.
     */
    public const STATUS_RUNNING = 2;

    /**
     * @var int The status code for a job which failed.
     */
    public const STATUS_FAILED = 3;

    /**
     * @var int The status code for a job which successfully completed.
     */
    public const STATUS_COMPLETE = 4;

    /**
     * @var string The ID of the job this status class refers back to.
     */
    private $id;

    /**
     * @var bool|null Cache variable if the status of this job is being
     *      monitored or not. True/false when checked at least once or null if
     *      not checked yet.
     */
    private $isTracking = null;

    /**
     * @var int[] Array of statuses that are considered final/complete.
     */
    private static $completeStatuses = [
        self::STATUS_FAILED,
        self::STATUS_COMPLETE
    ];

    /**
     * Setup a new instance of the job monitor class for the supplied job ID.
     *
     * @param string $id The ID of the job to manage the status for.
     */
    public function __construct(string $id)
    {
        $this->id = $id;
    }

    /**
     * Create a new status monitor item for the supplied job ID. Will create
     * all necessary keys in Redis to monitor the status of a job.
     *
     * @param string $id The ID of the job to monitor the status of.
     * @param int $status The initial status of the job.
     * @return void
     */
    public static function create(
        string $id,
        int $status = self::STATUS_WAITING
    ): void {
        $json = Util::jsonEncode([
            'status' => $status,
            'updated' => time(),
            'started' => time(),
        ]);

        if ($json !== false) {
            Resque::redis()->set('job:' . $id . ':status', $json);
        }
    }

    /**
     * Check if we're actually checking the status of the loaded job status
     * instance.
     *
     * @return bool True if the status is being monitored, false if not.
     */
    public function isTracking(): bool
    {
        if ($this->isTracking === false) {
            return false;
        }

        if (Resque::redis()->exists((string)$this) === 0) {
            $this->isTracking = false;
            return false;
        }

        $this->isTracking = true;
        return true;
    }

    /**
     * Update the status indicator for the current job with a new status.
     *
     * @param int $status The status of the job (see constants in
     *      Resque\Job\Status)
     * @return void
     */
    public function update(int $status): void
    {
        if (!$this->isTracking()) {
            return;
        }

        $json = Util::jsonEncode([
            'status' => $status,
            'updated' => time(),
        ]);

        if ($json !== false) {
            Resque::redis()->set((string)$this, $json);
        }

        // Expire the status for completed jobs after 24 hours
        if (in_array($status, self::$completeStatuses, true)) {
            Resque::redis()->expire((string)$this, 86400);
        }
    }

    /**
     * Fetch the status for the job being monitored.
     *
     * @return int|bool False if the status is not being monitored, otherwise
     *      the status as as an integer, based on the Resque\Job\Status
     *      constants.
     */
    public function get()
    {
        if ($this->isTracking()) {
            $json = Resque::redis()->get((string)$this);

            if (isset($json)) {
                $status = Util::jsonDecode($json);

                if (isset($status)) {
                    return $status['status'];
                }
            }
        }

        return false;
    }

    /**
     * Stop tracking the status of a job.
     *
     * @return void
     */
    public function stop(): void
    {
        Resque::redis()->del((string)$this);
    }

    /**
     * Generate a string representation of this object.
     *
     * @return string String representation of the current job status class.
     */
    public function __toString()
    {
        return 'job:' . $this->id . ':status';
    }
}
