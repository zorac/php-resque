<?php

namespace Resque\Job;

use \Resque\Resque;

/**
 * Status tracker/information for a job.
 *
 * @package Resque/Job
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Status
{
    const STATUS_WAITING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_FAILED = 3;
    const STATUS_COMPLETE = 4;

    /**
     * @var string The ID of the job this status class refers back to.
     */
    private $id;

    /**
     * @var mixed Cache variable if the status of this job is being monitored
     *      or not. True/false when checked at least once or null if not
     *      checked yet.
     */
    private $isTracking = null;

    /**
     * @var array Array of statuses that are considered final/complete.
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
     */
    public static function create(
        string $id,
        int $status = self::STATUS_WAITING
    ) {
        $json = json_encode([
            'status' => $status,
            'updated' => time(),
            'started' => time(),
        ], Resque::JSON_ENCODE_OPTIONS);

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
    public function isTracking() : bool
    {
        if ($this->isTracking === false) {
            return false;
        }

        if (!Resque::redis()->exists((string)$this)) {
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
     */
    public function update(int $status)
    {
        if (!$this->isTracking()) {
            return;
        }

        $json = json_encode([
            'status' => $status,
            'updated' => time(),
        ], Resque::JSON_ENCODE_OPTIONS);

        if ($json !== false) {
            Resque::redis()->set((string)$this, $json);
        }

        // Expire the status for completed jobs after 24 hours
        if (in_array($status, self::$completeStatuses)) {
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

            if (!empty($json)) {
                $status = json_decode($json, true, Resque::JSON_DECODE_DEPTH,
                    Resque::JSON_DECODE_OPTIONS);

                if (!empty($status)) {
                    return $status['status'];
                }
            }
        }

        return false;
    }

    /**
     * Stop tracking the status of a job.
     */
    public function stop()
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
