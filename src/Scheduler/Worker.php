<?php

namespace Resque\Scheduler;

use DateTime;
use Resque\Event;
use Resque\Resque;
use Resque\Scheduler;
use Resque\Worker as ResqueWorker;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 *
 * @author    Chris Boulton <chris@bigcommerce.com>
 * @author    Wan Qi Chen <kami@kamisama.me>
 * @copyright 2012 Chris Boulton
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 */
class Worker extends ResqueWorker
{
    /**
     * @var int Interval to sleep for between checking schedules.
     */
    protected $interval;

    /**
     * @var bool Whether blocking operation was requested. Not currently
     *      supported by the way we use Redis.
     */
    protected $blocking;

    /**
     * The primary loop for a worker.
     *
     * Every $interval (seconds), the scheduled queue will be checked for jobs
     * that should be pushed to Resque.
     *
     * @param int $interval How often to check schedules.
     * @param bool $blocking Ignored. Resque Scheduler cannot support blocking.
     * @return void
     */
    public function work(
        int $interval = Scheduler::DEFAULT_INTERVAL,
        bool $blocking = false
    ): void {
        $this->interval = $interval;
        $this->blocking = $blocking;

        $this->updateProcLine('Starting');
        $this->startup();

        while (true) {
            if ($this->shutdown) {
                break;
            }

            $this->handleDelayedItems();
            $this->sleep();
        }

        $this->unregisterWorker();
    }

    /**
     * Handle delayed items for the next scheduled timestamp.
     *
     * Searches for any items that are due to be scheduled in Resque
     * and adds them to the appropriate job queue in Resque.
     *
     * @return void
     */
    public function handleDelayedItems(): void
    {
        while ($timestamp = Scheduler::nextDelayedTimestamp()) {
            $this->updateProcLine('Processing Delayed Items');
            $this->enqueueDelayedItemsForTimestamp($timestamp);
        }
    }

    /**
     * Schedule all of the delayed jobs for a given timestamp.
     *
     * Searches for all items for a given timestamp, pulls them off the list of
     * delayed jobs and pushes them across to Resque.
     *
     * @param DateTime|int $timestamp Search for any items up to this timestamp
     *      to schedule.
     * @return void
     */
    public function enqueueDelayedItemsForTimestamp($timestamp): void
    {
        $item = null;

        while ($item = Scheduler::nextItemForTimestamp($timestamp)) {
            $class = strval($item['class']);
            $queue = strval($item['queue']);
            /** @var array<int,array<string,mixed>> */
            $args = $item['args'];

            if ($timestamp instanceof DateTime) {
                $timestamp = $timestamp->getTimestamp();
            }

            $this->log([
                'message' => "Moving scheduled job $class to $queue",
                'data' => [
                    'type' => 'movescheduled',
                    'args' => [
                        'timestamp' => $timestamp,
                        'class' => $class,
                        'queue' => $queue,
                        'job_id' => $args[0]['id'],
                        'wait' => round(microtime(true) - (isset($item['s_time']) ? $item['s_time'] : 0), 3),
                        's_wait' => $timestamp - floor(isset($item['s_time']) ? $item['s_time'] : 0)
                    ]
                ]
            ], self::LOG_TYPE_INFO);

            Event::trigger('beforeDelayedEnqueue', [
                'queue' => $queue,
                'class' => $class,
                'args'  => $args[0],
                'id'    => $args[0]['id'],
            ]);

            Resque::enqueue(
                $queue,
                $class,
                $args[0],
                $item['track']
            );
        }
    }

    /**
     * Sleep for the defined interval.
     *
     * @return void
     */
    protected function sleep(): void
    {
        $this->log([
            'message' => "Sleeping for $this->interval",
            'data' => [
                'type' => 'sleep',
                'second' => $this->interval
            ]
        ], self::LOG_TYPE_DEBUG);

        sleep($this->interval);
    }

    /**
     * Update the status of the current worker process.
     *
     * @param string $status The updated process title.
     * @return void
     */
    protected function updateProcLine(string $status): void
    {
        if (PHP_OS != 'Darwin') { // Not suppotted on macOS
            cli_set_process_title('resque-scheduler-' . Scheduler::VERSION
                . ": $status");
        }
    }
}
