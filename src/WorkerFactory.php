<?php

namespace Resque;

use Psr\Log\LoggerInterface;
use Resque\Job\CreatorInterface;
use Resque\Job\LegacyCreator;

/**
 * Factory class for working with Resque workers.
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class WorkerFactory
{
    /**
     * @var LoggerInterface|null A logger to pass to workers.
     */
    private $logger;

    /**
     * @var CreatorInterface A job instance creator to pass to workers.
     */
    private $creator;

    /**
     * Create a new worker factory.
     *
     * @param LoggerInterface $logger A logger.
     * @param CreatorInterface $creator A job creator.
     */
    public function __construct(
        LoggerInterface $logger = null,
        CreatorInterface $creator = null
    ) {
        $this->logger = $logger;
        $this->creator = $creator ?? new LegacyCreator();
    }

    /**
     * Create a new worker instance.
     *
     * @param string|array<string> $queues String with a single queue name, or
     *      an array with multiple.
     * @param string $hostname A hostname to use for this worker; defaults to
     *      the result of gethostname().
     * @param int $pid A process ID to use for this worker; defaults to the
     *      result of getmypid().
     * @return Worker The newly-created worker instance.
     */
    public function create(
        $queues,
        string $hostname = null,
        int $pid = null
    ) {
        $worker = new Worker($queues, $hostname, $pid);
        $logger = $worker->getLogger((string)$worker);

        if (isset($logger)) {
            $worker->setLogger($logger);
        } elseif (isset($this->logger)) {
            $worker->setLogger($this->logger);
        }

        $worker->setCreator($this->creator);

        return $worker;
    }

    /**
     * Given a worker ID, check if it is registered/valid.
     *
     * @param string $workerId ID of the worker.
     * @return bool True if the worker exists, false if not.
     */
    public function exists(string $workerId): bool
    {
        return (bool)Resque::redis()->sismember('workers', $workerId);
    }

    /**
     * Create a worker instance for an existing worker ID.
     *
     * @param string $workerId The ID of the worker.
     * @return Worker A worker instance, or null if the worker does not exist.
     */
    public function get(string $workerId): ?Worker
    {
        if (!$this->exists($workerId)) {
            return null;
        }

        [$hostname, $pid, $queues] = explode(':', $workerId, 3);

        return $this->create(explode(',', $queues), $hostname, (int)$pid);
    }

    /**
     * Return worker instances for all known Resque workers.
     *
     * @return array<int,Worker> The workers, empty if none found.
     */
    public function getAll(): array
    {
        $workers = Resque::redis()->smembers('workers');
        $instances = [];

        foreach ($workers as $workerId) {
            $worker = $this->get($workerId);

            if (isset($worker)) {
                $instances[] = $worker;
            }
        }

        return $instances;
    }

    /**
     * Look for any workers which should be running on this server and if
     * they're not, remove them from Redis.
     *
     * This is a form of garbage collection to handle cases where the server
     * may have been killed and the Resque workers did not die gracefully and
     * therefore leave state information in Redis.
     *
     * @return void
     */
    public function prune(): void
    {
        $hostname = gethostname();
        $pids = [];
        exec('ps -A -o pid,comm | grep [r]esque', $cmdOutput);

        foreach ($cmdOutput as $line) {
            [$pids[]] = explode(' ', trim($line), 2);
        }

        foreach ($this->getAll() as $worker) {
            [$host, $pid, $queues] = explode(':', (string)$worker, 3);

            if (
                ($host != $hostname)
                || in_array($pid, $pids, true)
                || $pid == getmypid()
            ) {
                continue;
            }

            $worker->log([
                'message' => "Pruning dead worker: $worker",
                'data' => [
                    'type' => 'prune',
                ],
            ], Worker::LOG_TYPE_DEBUG);

            $worker->unregisterWorker();
        }
    }
}
