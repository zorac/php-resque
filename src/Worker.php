<?php

namespace Resque;

use Exception;
use MonologInit\MonologInit;
use Monolog\Logger;
use Resque\Job\DirtyExitException;
use Resque\Job\Status;
use RuntimeException;

/**
 * Resque worker that handles checking queues for jobs, fetching them
 * off the queues, running them and handling the result.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class Worker
{
    /**
     * @var int Log level for no logging.
     */
    const LOG_NONE = 0;

    /**
     * @var int Log level for normal logging.
     */
    const LOG_NORMAL = 1;

    /**
     * @var int Log level for verbose logging.
     */
    const LOG_VERBOSE = 2;

    /**
     * @var int Log type for a debug message.
     */
    const LOG_TYPE_DEBUG = 100;

    /**
     * @var int Log type for an informational message.
     */
    const LOG_TYPE_INFO = 200;

    /**
     * @var int Log type for a warning message.
     */
    const LOG_TYPE_WARNING = 300;

    /**
     * @var int Log type for an error message.
     */
    const LOG_TYPE_ERROR = 400;

    /**
     * @var int Log type for a critical error message.
     */
    const LOG_TYPE_CRITICAL = 500;

    /**
     * @var int Log type for an alert message.
     */
    const LOG_TYPE_ALERT = 550;

    /**
     * @var resource The handle to write logs to.
     */
    public $logOutput = STDOUT;

    /**
     * @var int Current log level of this worker.
     */
    public $logLevel = self::LOG_NONE;

    /**
     * @var string[] Array of all associated queues for this worker.
     */
    protected $queues = [];

    /**
     * @var string The hostname of this worker.
     */
    protected $hostname;

    /**
     * @var bool True if on the next iteration, the worker should shutdown.
     */
    protected $shutdown = false;

    /**
     * @var bool True if this worker is paused.
     */
    protected $paused = false;

    /**
     * @var string String identifying this worker.
     */
    protected $id;

    /**
     * @var Job|null Current job, if any, being processed by this worker.
     */
    protected $currentJob = null;

    /**
     * @var int|null Process ID of child worker processes.
     */
    protected $child = null;

    /**
     * @var Logger|null A logger to use for this worker.
     */
    protected $logger = null;

    /**
     * @var int Number of seconds to wait for a child in a graceful shutdown.
     */
    public $gracefulDelay = 5;

    /**
     * @var bool Whether dead workers should be pruned on startup.
     */
    public $pruneDeadWorkersOnStartup = true;

    /**
     * Return all workers known to Resque as instantiated instances.
     *
     * @return Worker[] The workers.
     */
    public static function all() : array
    {
        $workers = Resque::redis()->smembers('workers');
        $instances = [];

        foreach ($workers as $workerId) {
            $worker = self::find($workerId);

            if (isset($worker)) {
                $instances[] = $worker;
            }
        }

        return $instances;
    }

    /**
     * Given a worker ID, check if it is registered/valid.
     *
     * @param string $workerId ID of the worker.
     * @return bool True if the worker exists, false if not.
     */
    public static function exists(string $workerId) : bool
    {
        return (bool)Resque::redis()->sismember('workers', $workerId);
    }

    /**
     * Given a worker ID, find it and return an instantiated worker class for
     * it.
     *
     * @param string $workerId The ID of the worker.
     * @return Worker Instance of the worker. Null if the worker does not exist.
     */
    public static function find(string $workerId) : ?Worker
    {
        if (!self::exists($workerId) || false === strpos($workerId, ":")) {
            return null;
        }

        list($hostname, $pid, $queues) = explode(':', $workerId, 3);
        $queues = explode(',', $queues);
        $worker = new self($queues, $hostname, (int)$pid);
        $worker->logger = $worker->getLogger($workerId);
        return $worker;
    }

    /**
     * Set the ID of this worker to a given ID string.
     *
     * @param string $workerId ID for the worker.
     * @deprecated Specify the hotsname and PID in the constructor.
     * @return void
     */
    public function setId(string $workerId) : void
    {
        $this->id = $workerId;
    }

    /**
     * Instantiate a new worker, given a list of queues that it should be
     * working on. The list of queues should be supplied in the priority that
     * they should be checked for jobs (first come, first served.)
     *
     * Passing a single '*' allows the worker to work on all queues in
     * a random order. You can easily add new queues dynamically and have
     * them worked on using this method.
     *
     * @param string|array $queues String with a single queue name, array with
     *      multiple.
     * @param string $hostname A hostname to use for this worker; defaults to
     *      the result of gethostname().
     * @param int $pid A process ID to use for this worker; defaults to the
     *      result of getmypid(). Setting this will automatically disable
     *      dead worker pruning on startup.
     */
    public function __construct(
        $queues,
        string $hostname = null,
        int $pid = null
    ) {
        if (!is_array($queues)) {
            $queues = [$queues];
        }

        if (!isset($hostname)) {
            $hostname = gethostname();

            if ($hostname === false) {
                $hostname = 'localhost';
            }
        }

        if (!isset($pid)) {
            $pid = getmypid();
        } else {
            $this->pruneDeadWorkersOnStartup = false;
        }

        $this->queues = $queues;
        $this->hostname = $hostname;
        $this->id = $hostname . ':' . $pid . ':' . implode(',', $this->queues);
    }

    /**
     * The primary loop for a worker which when called on an instance starts
     * the worker's life cycle.
     *
     * Queues are checked every $interval (seconds) for new jobs.
     *
     * @param int $interval How often to check for new jobs across the queues.
     * @return void
     */
    public function work(int $interval = Resque::DEFAULT_INTERVAL) : void
    {
        $this->updateProcLine('Starting');
        $this->startup();

        while (true) {
            if ($this->shutdown) {
                break;
            }

            // Attempt to find and reserve a job
            $job = null;

            if (!$this->paused) {
                try {
                    $job = $this->reserve();
                } catch (RedisException $e) {
                    $this->log([
                        'message' => 'Redis exception caught: ' . $e->getMessage(),
                        'data' => [
                            'type' => 'fail',
                            'log' => $e->getMessage(),
                            'time' => time()
                        ]
                    ], self::LOG_TYPE_ALERT);
                }
            }

            if (!isset($job)) {
                // For an interval of 0, break now - helps with unit testing etc
                if ($interval == 0) {
                    break;
                }

                // If no job was found, we sleep for $interval before continuing and checking again
                $this->log([
                    'message' => 'Sleeping for ' . $interval,
                    'data' => [
                        'type' => 'sleep',
                        'second' => $interval
                    ]
                ], self::LOG_TYPE_DEBUG);

                if ($this->paused) {
                    $this->updateProcLine('Paused');
                } else {
                    $this->updateProcLine('Waiting for ' . implode(',', $this->queues));
                }

                usleep($interval * 1000000);
                continue;
            }

            $this->log([
                'message' => 'got ' . $job,
                'data' => [
                    'type' => 'got',
                    'args' => $job
                ]
            ], self::LOG_TYPE_INFO);

            Event::trigger('beforeFork', $job);
            $this->workingOn($job);

            $workerName = $this->hostname . ':' . getmypid();

            $this->child = $this->fork();

            // Forked and we're the child. Run the job.
            if ($this->child === 0) {
                $status = 'Processing ID:' . $job->payload['id'] .  ' in ' . $job->queue;
                $this->updateProcLine($status);

                $this->log([
                    'message' => $status,
                    'data' => [
                        'type' => 'process',
                        'worker' => $workerName,
                        'job_id' => $job->payload['id']
                    ]
                ], self::LOG_TYPE_INFO);

                $this->perform($job);

                exit(0);
            }

            if ($this->child > 0) {
                // Parent process, sit and wait
                $status = 'Forked ' . $this->child . ' for ID:' . $job->payload['id'];
                $this->updateProcLine($status);

                $this->log([
                    'message' => $status,
                    'data' => [
                        'type' => 'fork',
                        'worker' => $workerName,
                        'job_id' => $job->payload['id']
                    ]
                ], self::LOG_TYPE_DEBUG);

                // Wait until the child process finishes before continuing.
                // We use a loop to continue waiting after we get a signal.
                do {
                    $pid = pcntl_wait($status);
                } while ($pid <= 0);

                $exitStatus = pcntl_wexitstatus($status);

                if ($exitStatus !== 0) {
                    $job->fail(new DirtyExitException('Job exited with exit code ' . $exitStatus));
                }
            }

            $this->child = null;
            $this->doneWorking();
        }

        $this->unregisterWorker();
    }

    /**
     * Process a single job.
     *
     * @param Job $job The job to be processed.
     * @return void
     */
    public function perform(Job $job) : void
    {
        $startTime = microtime(true);

        try {
            Event::trigger('afterFork', $job);
            $job->perform();

            $this->log([
                'message' => 'done ID:' . $job->payload['id'],
                'data' => [
                    'type' => 'done',
                    'job_id' => $job->payload['id'],
                    'time' => round(microtime(true) - $startTime, 3) * 1000
                ]
            ], self::LOG_TYPE_INFO);
        } catch (Exception $e) {
            $this->log([
                'message' => $job . ' failed: ' . $e->getMessage(),
                'data' => [
                    'type' => 'fail',
                    'log' => $e->getMessage(),
                    'job_id' => $job->payload['id'],
                    'time' => round(microtime(true) - $startTime, 3) * 1000
                ]
            ], self::LOG_TYPE_ERROR);

            $job->fail($e);

            return;
        }

        $job->updateStatus(Status::STATUS_COMPLETE);
    }

    /**
     * Attempt to find a job from the top of one of the queues for this worker.
     *
     * @return Job Instance of Resque\Job if a job is found, null if not.
     */
    public function reserve() : ?Job
    {
        $queues = $this->queues();

        foreach ($queues as $queue) {
            $this->log([
                'message' => 'Checking ' . $queue,
                'data' => [
                    'type' => 'check',
                    'queue' => $queue
                ]
            ], self::LOG_TYPE_DEBUG);

            $job = Job::reserve($queue);

            if (isset($job)) {
                $this->log([
                    'message' => 'Found job on ' . $queue,
                    'data' => [
                        'type' => 'found',
                        'queue' => $queue
                    ]
                ], self::LOG_TYPE_DEBUG);

                return $job;
            }
        }

        return null;
    }

    /**
     * Return an array containing all of the queues that this worker should use
     * when searching for jobs.
     *
     * If * is found in the list of queues, every queue will be searched in
     * a random order. (@see $fetch)
     *
     * @param bool $fetch If true (the default), and the queue is set to *,
     *      will fetch all queue names from Redis.
     * @return string[] Array of associated queues.
     */
    public function queues(bool $fetch = true) : array
    {
        if ($fetch && in_array('*', $this->queues, true)) {
            $queues = Resque::queues();
            shuffle($queues);
            return $queues;
        } else {
            return $this->queues;
        }
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     * Return values are those of pcntl_fork().
     *
     * @return int -1 if the fork failed, 0 for the forked child, the PID of
     *      the child for the parent.
     */
    protected function fork() : int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork child worker.');
        }

        return $pid;
    }

    /**
     * Perform necessary actions to start a worker.
     *
     * @return void
     */
    protected function startup() : void
    {
        $this->log([
            'message' => 'Starting worker ' . $this,
            'data' => [
                'type' => 'start',
                'worker' => (string)$this
            ]
        ], self::LOG_TYPE_INFO);

        $this->registerSigHandlers();

        if ($this->pruneDeadWorkersOnStartup) {
            $this->pruneDeadWorkers();
        }

        Event::trigger('beforeFirstFork', $this);
        $this->registerWorker();
    }

    /**
     * On supported systems (with the PECL proctitle module installed), update
     * the name of the currently running process to indicate the current state
     * of a worker.
     *
     * @param string $status The updated process title.
     * @return void
     */
    protected function updateProcLine(string $status) : void
    {
        if (PHP_OS != 'Darwin') { // Not suppotted on macOS
            cli_set_process_title('resque-' . Resque::VERSION . ': ' . $status);
        }
    }

    /**
     * Register signal handlers that a worker should respond to.
     *
     * TERM: Shutdown immediately and stop processing jobs.
     * INT: Shutdown immediately and stop processing jobs.
     * QUIT: Shutdown after the current job finishes processing.
     * USR1: Kill the forked child immediately and continue processing jobs.
     *
     * @return void
     */
    protected function registerSigHandlers() : void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'shutdownGraceful'], false);
        pcntl_signal(SIGALRM, [$this, 'killChild'], false);
        pcntl_signal(SIGINT, [$this, 'shutDownNow'], false);
        pcntl_signal(SIGQUIT, [$this, 'shutdown']);
        pcntl_signal(SIGUSR1, [$this, 'killChild'], false);
        pcntl_signal(SIGUSR2, [$this, 'pauseProcessing']);
        pcntl_signal(SIGCONT, [$this, 'unPauseProcessing']);
        pcntl_signal(SIGPIPE, [$this, 'reestablishRedisConnection']);

        $this->log([
            'message' => 'Registered signals',
            'data' => [
                'type' => 'signal'
            ]
        ], self::LOG_TYPE_DEBUG);
    }

    /**
     * Signal handler callback for USR2, pauses processing of new jobs.
     *
     * @return void
     */
    public function pauseProcessing() : void
    {
        $this->log([
            'message' => 'USR2 received; pausing job processing',
            'data' => [
                'type' => 'pause'
            ]
        ], self::LOG_TYPE_INFO);

        $this->paused = true;
    }

    /**
     * Signal handler callback for CONT, resumes worker allowing it to pick
     * up new jobs.
     *
     * @return void
     */
    public function unPauseProcessing() : void
    {
        $this->log([
            'message' => 'CONT received; resuming job processing',
            'data' => [
                'type' => 'resume'
            ]
        ], self::LOG_TYPE_INFO);

        $this->paused = false;
    }

    /**
     * Signal handler for SIGPIPE, in the event the redis connection has gone away.
     * Attempts to reconnect to redis, or raises an Exception.
     *
     * @return void
     */
    public function reestablishRedisConnection() : void
    {
        $this->log([
            'message' => 'SIGPIPE received; attempting to reconnect',
            'data' => [
                'type' => 'reconnect'
            ]
        ], self::LOG_TYPE_INFO);

        Resque::redis()->connect();
    }

    /**
     * Schedule a worker for shutdown. Will finish processing the current job
     * and when the timeout interval is reached, the worker will shut down.
     *
     * @return void
     */
    public function shutdown() : void
    {
        $this->shutdown = true;

        $this->log([
            'message' => 'Exiting...',
            'data' => [
                'type' => 'shutdown'
            ]
        ], self::LOG_TYPE_INFO);
    }

    /**
     * Force an immediate shutdown of the worker, killing any child jobs
     * currently running.
     *
     * @return void
     */
    public function shutdownNow() : void
    {
        $this->shutdown();
        $this->killChild();
    }

    /**
     * Force an graceful shutdown of the worker, killing any child jobs
     * after a brief grace period.
     *
     * @return void
     */
    public function shutdownGraceful() : void
    {
        $this->shutdown();
        pcntl_alarm($this->gracefulDelay);
    }

    /**
     * Kill a forked child job immediately. The job it is processing will not
     * be completed.
     *
     * @return void
     */
    public function killChild() : void
    {
        if (!isset($this->child)) {
            $this->log([
                'message' => 'No child to kill.',
                'data' => [
                    'type' => 'kill',
                    'child' => null
                ]
            ], self::LOG_TYPE_DEBUG);

            return;
        }

        $this->log([
            'message' => 'Killing child at ' . $this->child,
            'data' => [
                'type' => 'kill',
                'child' => $this->child
            ]
        ], self::LOG_TYPE_DEBUG);

        exec('ps -p ' . $this->child, $output, $returnCode);

        if ($returnCode === 0) {
            $this->log([
                'message' => 'Killing child at ' . $this->child,
                'data' => [
                    'type' => 'kill',
                    'child' => $this->child
                ]
            ], self::LOG_TYPE_DEBUG);

            posix_kill($this->child, SIGKILL);
            $this->child = null;
        } else {
            $this->log([
                'message' => 'Child ' . $this->child . ' not found, restarting.',
                'data' => [
                    'type' => 'kill',
                    'child' => $this->child
                ]
            ], self::LOG_TYPE_ERROR);

            $this->shutdown();
        }
    }

    /**
     * Look for any workers which should be running on this server and if
     * they're not, remove them from Redis.
     *
     * This is a form of garbage collection to handle cases where the
     * server may have been killed and the Resque workers did not die gracefully
     * and therefore leave state information in Redis.
     *
     * @return void
     */
    public function pruneDeadWorkers() : void
    {
        $workerPids = $this->workerPids();
        $workers = self::all();

        foreach ($workers as $worker) {
            list($host, $pid, $queues) = explode(':', (string)$worker, 3);

            if (($host != $this->hostname)
                    || in_array($pid, $workerPids, true)
                    || $pid == getmypid()) {
                continue;
            }

            $this->log([
                'message' => 'Pruning dead worker: ' . (string)$worker,
                'data' => [
                    'type' => 'prune'
                ]
            ], self::LOG_TYPE_DEBUG);

            $worker->unregisterWorker();
        }
    }

    /**
     * Return an array of process IDs for all of the Resque workers currently
     * running on this machine.
     *
     * @return string[] Array of Resque worker process IDs.
     */
    public function workerPids()
    {
        $pids = [];
        exec('ps -A -o pid,comm | grep [r]esque', $cmdOutput);

        foreach ($cmdOutput as $line) {
            list($pids[]) = explode(' ', trim($line), 2);
        }

        return $pids;
    }

    /**
     * Register this worker in Redis.
     *
     * @return void
     */
    public function registerWorker() : void
    {
        Resque::redis()->sadd('workers', (string)$this);
        Resque::redis()->set('worker:' . (string)$this . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
    }

    /**
     * Unregister this worker in Redis. (shutdown etc)
     *
     * @return void
     */
    public function unregisterWorker() : void
    {
        if (is_object($this->currentJob)) {
            $this->currentJob->fail(new DirtyExitException());
        }

        $id = (string)$this;
        Resque::redis()->srem('workers', $id);
        Resque::redis()->del('worker:' . $id);
        Resque::redis()->del('worker:' . $id . ':started');
        Stat::clear('processed:' . $id);
        Stat::clear('failed:' . $id);
        Resque::redis()->hdel('workerLogger', $id);
    }

    /**
     * Tell Redis which job we're currently working on.
     *
     * @param Job $job Resque\Job instance containing the job we're working on.
     * @return void
     */
    public function workingOn(Job $job) : void
    {
        $job->worker = $this;
        $this->currentJob = $job;
        $job->updateStatus(Status::STATUS_RUNNING);
        $json = Util::jsonEncode([
            'queue' => $job->queue,
            'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
            'payload' => $job->payload
        ]);

        if ($json !== false) {
            Resque::redis()->set('worker:' . $job->worker, $json);
        }
    }

    /**
     * Notify Redis that we've finished working on a job, clearing the working
     * state and incrementing the job stats.
     *
     * @return void
     */
    public function doneWorking() : void
    {
        $this->currentJob = null;
        Stat::incr('processed');
        Stat::incr('processed:' . (string)$this);
        Resque::redis()->del('worker:' . (string)$this);
    }

    /**
     * Generate a string representation of this worker.
     *
     * @return string String identifier for this worker instance.
     */
    public function __toString() : string
    {
        return $this->id;
    }

    /**
     * Output a given log message to STDOUT.
     *
     * @param mixed[] $message Message to output.
     * @param int $code A log type code.
     * @return bool True if the message is logged
     */
    public function log(array $message, int $code = self::LOG_TYPE_INFO) : bool
    {
        if ($this->logLevel === self::LOG_NONE) {
            return false;
        }

        /*if ($this->logger === null) {
            if (($this->logLevel === self::LOG_NORMAL)
                    && ($code !== self::LOG_TYPE_DEBUG)) {
                fwrite($this->logOutput, "*** " . $message['message'] . "\n");
            } else if ($this->logLevel === self::LOG_VERBOSE) {
                fwrite($this->logOutput, "** [" . strftime('%T %Y-%m-%d') . "] " . $message['message'] . "\n");
            } else {
                return false;
            }
            return true;
        } else {*/
        $extra = $message['data'];
        $message = $message['message'];

        if (!isset($extra['worker'])) {
            if ($this->child > 0) {
                $extra['worker'] = $this->hostname . ':' . getmypid();
            } else {
                list($host, $pid, $queues) = explode(':', (string)$this, 3);
                $extra['worker'] = $host . ':' . $pid;
            }
        }

        if (($code !== self::LOG_TYPE_DEBUG)
                && (($this->logLevel === self::LOG_NORMAL)
                || ($this->logLevel === self::LOG_VERBOSE))) {
            if ($this->logger === null) {
                fwrite($this->logOutput, "[" . date('c') . "] " . $message . "\n");
            } else {
                switch ($code) {
                    case self::LOG_TYPE_INFO:
                        $this->logger->addInfo($message, $extra);
                        break;
                    case self::LOG_TYPE_WARNING:
                        $this->logger->addWarning($message, $extra);
                        break;
                    case self::LOG_TYPE_ERROR:
                        $this->logger->addError($message, $extra);
                        break;
                    case self::LOG_TYPE_CRITICAL:
                        $this->logger->addCritical($message, $extra);
                        break;
                    case self::LOG_TYPE_ALERT:
                        $this->logger->addAlert($message, $extra);
                }
            }
        } elseif (($code === self::LOG_TYPE_DEBUG)
                && ($this->logLevel === self::LOG_VERBOSE)) {
            if ($this->logger === null) {
                fwrite($this->logOutput, "[" . date('c') . "] " . $message . "\n");
            } else {
                $this->logger->addDebug($message, $extra);
            }
        } else {
            return false;
        }

        return true;
        //}
    }

    /**
     * Register a logger for this worker.
     *
     * @param MonologInit $logger A logger.
     * @return void
     */
    public function registerLogger(MonologInit $logger) : void
    {
        $this->logger = $logger->getInstance();

        $json = Util::jsonEncode([
            $logger->handler,
            $logger->target
        ]);

        if ($json !== false) {
            Resque::redis()->hset('workerLogger', (string)$this, $json);
        }
    }

    /**
     * Fetch the logger for a given worker.
     *
     * @param string $workerId A worker ID.
     * @return Logger The logger, or null for internal logging.
     */
    public function getLogger(string $workerId) : ?Logger
    {
        $json = Resque::redis()->hget('workerLogger', $workerId);

        if (isset($json)) {
            $settings = Util::jsonDecode($json);

            if (isset($settings)) {
                $logger = new MonologInit($settings[0], $settings[1]);

                return $logger->getInstance();
            }
        }

        return null;
    }

    /**
     * Return an object describing the job this worker is currently working on.
     *
     * @return mixed[] Object with details of current job.
     */
    public function job() : array
    {
        $json = Resque::redis()->get('worker:' . $this);

        if (isset($json)) {
            $job = Util::jsonDecode($json);

            if (isset($job)) {
                return $job;
            }
        }

        return [];
    }

    /**
     * Get a statistic belonging to this worker.
     *
     * @param string $stat Statistic to fetch.
     * @return int Statistic value.
     */
    public function getStat(string $stat) : int
    {
        return Stat::get($stat . ':' . $this);
    }
}
