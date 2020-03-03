<?php

namespace Resque;

use Resque\Job\DontPerform;
use Resque\Job\Status;
use Throwable;

/**
 * Resque job.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class Job
{
    /**
     * @var string The name of the queue that this job belongs to.
     */
    public $queue;

    /**
     * @var Worker Instance of the Resque worker running this job.
     */
    public $worker;

    /**
     * @var array<mixed> Array containing details of the job.
     */
    public $payload;

    /**
     * @var object|null Instance of the class performing work for this job.
     */
    private $instance;

    /**
     * Instantiate a new instance of a job.
     *
     * @param string $queue The queue that the job belongs to.
     * @param array<mixed> $payload Array containing details of the job.
     */
    public function __construct(string $queue, array $payload)
    {
        $this->queue = $queue;
        $this->payload = $payload;
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to
     *      execute the job.
     * @param array<mixed> $args Any optional arguments that should be passed
     *      when the job is executed.
     * @param bool $monitor Set to true to be able to monitor the status of the
     *      job.
     * @param string $id Unique identifier for tracking the job. Generated if
     *      not supplied.
     * @return string The job ID.
     */
    public static function create(
        string $queue,
        string $class,
        array $args = null,
        bool $monitor = false,
        string $id = null
    ): string {
        $new = true;

        if (isset($args['id'])) {
            $id = $args['id'];
            unset($args['id']);
            $new = false;
        } elseif (!isset($id)) {
            $id = Resque::generateJobId();
        }

        Resque::push($queue, [
            'class' => $class,
            'args'  => isset($args) ? [$args] : null,
            'id'    => $id,
        ]);

        if ($monitor) {
            if ($new) {
                Status::create($id);
            } else {
                $statusInstance = new Status($id);
                $statusInstance->update(Status::STATUS_WAITING);
            }
        }

        return $id;
    }

    /**
     * Find the next available job from the specified queue and return an
     * instance of Resque\Job for it.
     *
     * @param string $queue The name of the queue to check for a job in.
     * @return Job Null when there aren't any waiting jobs, instance of
     *      Resque\Job when a job was found.
     */
    public static function reserve(string $queue): ?Job
    {
        $payload = Resque::pop($queue);

        if (isset($payload)) {
            return new Job($queue, $payload);
        } else {
            return null;
        }
    }

    /**
     * Wait for the next available job from one of the specified queues and
     * return an instance of Resque\Job for it.
     *
     * @param array<string> $queues The name(s) of the queue(s) to check for a
     *      job in.
     * @param int $timeout How long to wait for a job, in seconds.
     * @return Job An instance of Resque\Job when a job was found, or null if
     *      the timeout was reached.
     */
    public static function reserveBlocking(
        array $queues,
        int $timeout = 0
    ): ?Job {
        [$queue, $payload] = Resque::blpop($queues, $timeout);

        if (isset($queue) && isset($payload)) {
            return new Job($queue, $payload);
        } else {
            return null;
        }
    }

    /**
     * Update the status of the current job.
     *
     * @param int $status Status constant from Resque\Job\Status indicating the
     *      current status of a job.
     * @return void
     */
    public function updateStatus(int $status): void
    {
        if (isset($this->payload['id'])) {
            $statusInstance = new Status($this->payload['id']);
            $statusInstance->update($status);
        }
    }

    /**
     * Return the status of the current job.
     *
     * @return int|bool The status of the job as one of the Resque\Job\Status
     *      constants, or false if the status is not being monitored.
     */
    public function getStatus()
    {
        $status = new Status($this->payload['id']);

        return $status->get();
    }

    /**
     * Get the arguments supplied to this job.
     *
     * @return array<mixed> Array of arguments.
     */
    public function getArguments(): array
    {
        if (!isset($this->payload['args'])) {
            return [];
        } else {
            return $this->payload['args'][0];
        }
    }

    /**
     * Get the instantiated object for this job that will be performing work.
     *
     * @return object Instance of the object that this job belongs to.
     */
    public function getInstance(): object
    {
        if (isset($this->instance)) {
            return $this->instance;
        }

        if (class_exists('Resque_Job_Creator')) {
            $this->instance = \Resque_Job_Creator::createJob(
                $this->payload['class'],
                $this->getArguments()
            );
        } else {
            if (!class_exists($this->payload['class'])) {
                throw new ResqueException(
                    'Could not find job class ' . $this->payload['class'] . '.'
                );
            }

            if (!method_exists($this->payload['class'], 'perform')) {
                throw new ResqueException(
                    'Job class ' . $this->payload['class']
                        . ' does not contain a perform method.'
                );
            }
            $this->instance = new $this->payload['class']();
        }

        $this->instance->job = $this;
        $this->instance->args = $this->getArguments();
        $this->instance->queue = $this->queue;

        return $this->instance;
    }

    /**
     * Actually execute a job by calling the perform method on the class
     * associated with the job with the supplied arguments.
     *
     * @return bool True if the job was performed, false if a DontPerform was
     *      thrown.
     * @throws ResqueException When the job's class could not be found or it
     *      does not contain a perform method.
     */
    public function perform(): bool
    {
        $instance = $this->getInstance();

        try {
            Event::trigger('beforePerform', $this);

            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }

            $instance->perform();

            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }

            Event::trigger('afterPerform', $this);
        } catch (DontPerform $e) {
            // beforePerform/setUp have said don't perform this job. Return.
            return false;
        }

        return true;
    }

    /**
     * Mark the current job as having failed.
     *
     * @param Throwable $exception The exception which occurred.
     * @return void
     */
    public function fail(Throwable $exception): void
    {
        Event::trigger('onFailure', [
            'exception' => $exception,
            'job' => $this,
        ]);

        $this->updateStatus(Status::STATUS_FAILED);

        Failure::create(
            $this->payload,
            $exception,
            $this->worker,
            $this->queue
        );

        Stat::incr('failed');
        Stat::incr('failed:' . $this->worker);
    }

    /**
     * Re-queue the current job.
     *
     * @return string The job ID.
     */
    public function recreate(): string
    {
        $status = new Status($this->payload['id']);
        $monitor = false;

        if ($status->isTracking()) {
            $monitor = true;
        }

        return self::create(
            $this->queue,
            $this->payload['class'],
            $this->payload['args'],
            $monitor
        );
    }

    /**
     * Generate a string representation used to describe the current job.
     *
     * @return string The string representation of the job.
     */
    public function __toString()
    {
        $json = Util::jsonEncode([
            'queue' => $this->queue,
            'id'    => isset($this->payload['id']) ? $this->payload['id'] : '',
            'class' => $this->payload['class'],
            'args'  => isset($this->payload['args']) ? $this->payload['args'] : [[]]
        ]);

        if ($json !== false) {
            return $json;
        } else {
            return ''; // TODO really?
        }
    }
}
