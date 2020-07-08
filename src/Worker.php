<?php

namespace Resque;

use MonologInit\MonologInit;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Resque\Job\CreatorInterface;
use Resque\Job\DirtyExitException;
use Resque\Job\LegacyCreator;
use Resque\Job\PerformerInterface;
use Resque\Job\Status;
use RuntimeException;
use Throwable;

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
     * @deprecated Set the log level on the logger instance.
     */
    public const LOG_NONE = 0;

    /**
     * @var int Log level for normal logging.
     * @deprecated Set the log level on the logger instance.
     */
    public const LOG_NORMAL = 1;

    /**
     * @var int Log level for verbose logging.
     * @deprecated Set the log level on the logger instance.
     */
    public const LOG_VERBOSE = 2;

    /**
     * @var int Log type for a debug message.
     * @deprecated Use PSR log levels.
     */
    public const LOG_TYPE_DEBUG = 100;

    /**
     * @var int Log type for an informational message.
     * @deprecated Use PSR log levels.
     */
    public const LOG_TYPE_INFO = 200;

    /**
     * @var int Log type for a warning message.
     * @deprecated Use PSR log levels.
     */
    public const LOG_TYPE_WARNING = 300;

    /**
     * @var int Log type for an error message.
     * @deprecated Use PSR log levels.
     */
    public const LOG_TYPE_ERROR = 400;

    /**
     * @var int Log type for a critical error message.
     * @deprecated Use PSR log levels.
     */
    public const LOG_TYPE_CRITICAL = 500;

    /**
     * @var int Log type for an alert message.
     * @deprecated Use PSR log levels.
     */
    public const LOG_TYPE_ALERT = 550;

    /**
     * @var array<int,string> Mapping from legacy log type to PSR log level.
     */
    private const LOG_LEVEL_MAP = [
        self::LOG_TYPE_DEBUG    => LogLevel::DEBUG,
        self::LOG_TYPE_INFO     => LogLevel::INFO,
        self::LOG_TYPE_WARNING  => LogLevel::WARNING,
        self::LOG_TYPE_ERROR    => LogLevel::ERROR,
        self::LOG_TYPE_CRITICAL => LogLevel::CRITICAL,
        self::LOG_TYPE_ALERT    => LogLevel::ALERT,
    ];

    /**
     * @var resource The handle to write logs to.
     * @deprecated Use an appropriately configured logger.
     */
    public $logOutput = STDOUT;

    /**
     * @var int Current log level of this worker.
     * @deprecated Set the log level on the logger instance.
     */
    public $logLevel = self::LOG_NONE;

    /**
     * @var array<string> Array of all associated queues for this worker.
     */
    protected $queues = [];

    /**
     * @var string The hostname of this worker.
     */
    protected $hostname;

    /**
     * @var int The process ID of this worker.
     */
    protected $pid;

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
     * @var int The next kill signal which should be sent to a child.
     */
    protected $childSignal = SIGKILL;

    /**
     * @var LoggerInterface|null A logger to use for this worker.
     */
    protected $logger = null;

    /**
     * @var int Number of seconds to wait for a child in a graceful shutdown.
     */
    public $gracefulDelay = 5;

    /**
     * @var int|null If set, the given signal will be sent to the child worker
     *      after the graceful delay, instead of KILL.
     */
    public $gracefulSignal = null;

    /**
     * @var int Number of seconds to wait for a child after sending it a
     *      non-KILL signal, before sending KILL.
     */
    public $gracefulDelayTwo = 2;

    /**
     * @var bool If true, a Redis error while trying to reserve a job will
     *      cause the worker to shut down.
     */
    public $shutDownOnReserveError = false;

    /**
     * @var CreatorInterface|null A job instance creator.
     */
    private $creator;

    /**
     * Instantiate a new worker, given a list of queues that it should be
     * working on. The list of queues should be supplied in the priority that
     * they should be checked for jobs (first come, first served.)
     *
     * Passing a single `*` allows the worker to work on all queues in a
     * random order. You can easily add new queues dynamically and have them
     * worked on using this method. More complex rules can be added with
     * multiple wildcard and exclusion patterns; see the `queues()` method for
     * more details.
     *
     * @param string|array<string> $queues String with a single queue name,
     *      array with multiple.
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

            if ($pid === false) {
                throw new ResqueException('Unable to determine PID');
            }
        }

        $this->queues = $queues;
        $this->hostname = $hostname;
        $this->pid = $pid;
        $this->id = "$hostname:$pid:" . implode(',', $this->queues);
    }

    /**
     * Set the logger to use.
     *
     * @param LoggerInterface $logger A logger.
     * @return void
     * @internal Instead set the logger on the worker factory.
     * @see WorkerFactory
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Set the job instance creator to use.
     *
     * @param CreatorInterface $creator A job creator.
     * @return void
     * @internal Instead set the creator on the worker factory.
     * @see WorkerFactory
     */
    public function setCreator(CreatorInterface $creator): void
    {
        $this->creator = $creator;
    }

    /**
     * Return the job creator instance to use.
     *
     * @return CreatorInterface A job creator.
     * @deprecated Use `createJob()`
     */
    public function getCreator(): CreatorInterface
    {
        if (!isset($this->creator)) {
            $this->creator = new LegacyCreator();
        }

        return $this->creator;
    }

    /**
     * Create a new job instance.
     *
     * @param Job $job The job to be processed.
     * @return PerformerInterface A job instance.
     */
    public function createJob(Job $job): object
    {
        return $this->getCreator()->createJob($job);
    }

    /**
     * The primary loop for a worker which when called on an instance starts
     * the worker's life cycle.
     *
     * Queues are checked every $interval (seconds) for new jobs.
     *
     * @param int $interval How often to check for new jobs across the queues.
     * @param bool $blocking Whether to use blocking queue pops.
     * @return void
     */
    public function work(
        int $interval = Resque::DEFAULT_INTERVAL,
        bool $blocking = false
    ): void {
        $this->updateProcLine('Starting');
        $this->startup();

        while (true) {
            if ($this->shutdown) {
                break;
            }

            // Attempt to find and reserve a job
            $job = null;

            if (!$this->paused) {
                if ($blocking) {
                    $this->updateProcLine('Blocking on ' . implode(',', $this->queues));
                }

                try {
                    $job = $this->reserve($blocking, $interval);
                } catch (RedisException $e) {
                    $this->log([
                        'message' => 'Redis exception caught: ' . $e->getMessage(),
                        'data' => [
                            'type' => 'fail',
                            'log' => $e->getMessage(),
                            'time' => time(),
                            'exception' => $e,
                        ],
                    ], LogLevel::ALERT);

                    if ($this->shutDownOnReserveError) {
                        break;
                    }
                }
            }

            if (!isset($job)) {
                // For an interval of 0, break now - helps with unit testing etc
                if ($interval == 0) {
                    break;
                } elseif (!$blocking) {
                    // If no job was found, we sleep for $interval before continuing and checking again
                    $this->log([
                        'message' => "Sleeping for $interval",
                        'data' => [
                            'type' => 'sleep',
                            'second' => $interval,
                        ],
                    ], LogLevel::DEBUG);

                    if ($this->paused) {
                        $this->updateProcLine('Paused');
                    } else {
                        $this->updateProcLine('Waiting for ' . implode(',', $this->queues));
                    }

                    usleep($interval * 1000000);
                }

                continue;
            }

            $workerName = "$this->hostname:$this->pid";

            $this->log([
                'message' => "Got ID:{$job->payload['id']} in $job->queue",
                'data' => [
                    'type' => 'got',
                    'worker' => $workerName,
                    'queue' => $job->queue,
                    'job_id' => $job->payload['id'],
                    'job_class' => $job->payload['class'],
                    'args' => $job,
                ],
            ], LogLevel::DEBUG);

            Event::trigger('beforeFork', $job);
            $this->workingOn($job);

            $this->child = $this->fork();

            if ($this->child === 0) {
                // Forked and we're the child. Run the job.
                $status = "Processing ID:{$job->payload['id']} in $job->queue";
                $this->updateProcLine($status);

                $this->log([
                    'message' => $status,
                    'data' => [
                        'type' => 'process',
                        'worker' => $workerName,
                        'queue' => $job->queue,
                        'job_id' => $job->payload['id'],
                        'job_class' => $job->payload['class'],
                    ],
                ], LogLevel::INFO);

                $this->perform($job);

                exit(0);
            } elseif ($this->child > 0) {
                // Parent process, sit and wait
                $status = "Forked $this->child for ID:{$job->payload['id']}";
                $this->updateProcLine($status);

                $this->log([
                    'message' => $status,
                    'data' => [
                        'type' => 'fork',
                        'worker' => $workerName,
                        'queue' => $job->queue,
                        'job_id' => $job->payload['id'],
                        'job_class' => $job->payload['class'],
                    ],
                ], LogLevel::DEBUG);

                // Wait until the child process finishes before continuing.
                // We use a loop to continue waiting after we get a signal.
                do {
                    $pid = pcntl_wait($status);
                } while ($pid <= 0);

                $exitStatus = pcntl_wexitstatus($status);

                if ($exitStatus !== 0) {
                    $job->fail(new DirtyExitException("Job exited with exit code $exitStatus"));
                }
            } else {
                // Fork failed!
                $this->log([
                    'message' => "Fork failed $this->child for ID:{$job->payload['id']}",
                    'data' => [
                        'type' => 'fail',
                        'worker' => $workerName,
                        'queue' => $job->queue,
                        'job_id' => $job->payload['id'],
                        'job_class' => $job->payload['class'],
                    ],
                ], LogLevel::ERROR);
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
    public function perform(Job $job): void
    {
        $workerName = "$this->hostname:$this->pid";
        $startTime = microtime(true);

        try {
            Event::trigger('afterFork', $job);
            $job->perform();

            $this->log([
                'message' => "Completed ID:{$job->payload['id']}",
                'data' => [
                    'type' => 'done',
                    'worker' => $workerName,
                    'queue' => $job->queue,
                    'job_id' => $job->payload['id'],
                    'job_class' => $job->payload['class'],
                    'time' => round(microtime(true) - $startTime, 3) * 1000,
                ],
            ], LogLevel::INFO);
        } catch (Throwable $e) {
            $this->log([
                'message' => "ID:{$job->payload['id']} failed: " . $e->getMessage(),
                'data' => [
                    'type' => 'fail',
                    'log' => $e->getMessage(),
                    'worker' => $workerName,
                    'queue' => $job->queue,
                    'job_id' => $job->payload['id'],
                    'job_class' => $job->payload['class'],
                    'time' => round(microtime(true) - $startTime, 3) * 1000,
                    'exception' => $e,
                ],
            ], LogLevel::ERROR);

            $job->fail($e);

            return;
        }

        $job->updateStatus(Status::STATUS_COMPLETE);
    }

    /**
     * Attempt to find a job from the top of one of the queues for this worker.
     *
     * @param bool $blocking Whether to use blocking queue pops.
     * @param int $timeout Timeout for blocking reads.
     * @return Job Instance of Resque\Job if a job is found, null if not.
     */
    public function reserve(bool $blocking = false, int $timeout = 0): ?Job
    {
        $queues = $this->queues();

        if ($blocking) {
            $this->log([
                'message' => 'Starting blocking check of ' . implode(',', $this->queues),
                'data' => [
                    'type' => 'check',
                    'queue' => $queues,
                    'timeout' => $timeout,
                ],
            ], LogLevel::DEBUG);

            $job = Job::reserveBlocking($queues, $timeout);
        } else {
            foreach ($queues as $queue) {
                $this->log([
                    'message' => "Checking $queue",
                    'data' => [
                        'type' => 'check',
                        'queue' => $queue,
                    ],
                ], LogLevel::DEBUG);

                $job = Job::reserve($queue);

                if (isset($job)) {
                    break;
                }
            }
        }

        if (isset($job)) {
            $this->log([
                'message' => "Found job on $job->queue",
                'data' => [
                    'type' => 'found',
                    'queue' => $job->queue,
                ],
            ], LogLevel::DEBUG);

            return $job;
        }

        return null;
    }

    /**
     * Return an array containing all of the queues that this worker should use
     * when searching for jobs.
     *
     * If any queue name contains on ore more wildcard `*` characters, then a
     * list of all known queue names will be fetched from Redis, and that name
     * will be replaced by all known queues which match that pattern, in a
     * random order.
     *
     * For each queue name given which starts with `!`, the remainder of that
     * name will be used as a pattern to remove entries from the list of all
     * queues, bofore any other matching is performed. Exclusions do *not*
     * affect explicitly named queues, and their position in the input is not
     * important.
     *
     * For example, if the input array of queues passed to the constructor was:
     * ```
     * ['system:high', '*:high', '*', 'system:low', '!*:low']
     * ```
     * Then the queues returned by this method would be:
     * 1. `system:high`
     * 2. All other queues ending in `:high`, in a random order.
     * 3. All other queues *not* ending in `:low`, in a random order.
     * 4. `system:low`
     *
     * @param bool $fetch If set to false (the default is true), then `*` and
     *      `!` characters in queue names will be treated as literals, and the
     *      list of all queues will never be fetched from Redis.
     * @return array<string> The queues to use, in order of priority.
     */
    public function queues(bool $fetch = true): array
    {
        if (!$fetch) {
            return $this->queues;
        }

        $all = null;
        $add = [];
        $remove = null;
        $filtered = [];

        foreach ($this->queues as $queue) {
            if (strlen($queue) === 0) {
                continue;
            } elseif ($queue[0] === '!') {
                $q = str_replace('\\*', '.*', preg_quote(substr($queue, 1)));
                $remove = isset($remove) ? "$remove|$q" : $q;
                continue;
            } elseif (!isset($all) && (strpos($queue, '*') !== false)) {
                $all = Resque::queues();
                shuffle($all);
            }

            $add[] = $queue;
        }

        if (!isset($all)) {
            return $add;
        } elseif (isset($remove)) {
            $all = preg_grep("/^($remove)$/", $all, PREG_GREP_INVERT);
        }

        foreach ($add as $queue) {
            if ($all === false) {
                $all = [];
            }

            if (strpos($queue, '*') === false) {
                $filtered[] = $queue;
                $all = array_filter($all, function ($q) use ($queue): bool {
                    return $q !== $queue;
                });
            } else {
                $pattern = '/^' . str_replace('\\*', '.*', preg_quote($queue))
                    . '$/';
                $matched = preg_grep($pattern, $all);

                if ($matched !== false) {
                    array_push($filtered, ...$matched);
                }

                $all = preg_grep($pattern, $all, PREG_GREP_INVERT);
            }
        }

        return $filtered;
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     * Return values are those of pcntl_fork().
     *
     * @return int -1 if the fork failed, 0 for the forked child, the PID of
     *      the child for the parent.
     */
    protected function fork(): int
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
    protected function startup(): void
    {
        $this->log([
            'message' => "Starting worker $this",
            'data' => [
                'type' => 'start',
                'worker' => $this->id,
            ],
        ], LogLevel::INFO);

        $this->registerSigHandlers();
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
    protected function updateProcLine(string $status): void
    {
        if (PHP_OS != 'Darwin') { // Not suppotted on macOS
            cli_set_process_title("resque: $status");
        }
    }

    /**
     * Register signal handlers that a worker should respond to.
     *
     * TERM: Shutdown gracefully and stop processing jobs.
     * INT: Shutdown immediately and stop processing jobs.
     * QUIT: Shutdown after the current job finishes processing.
     * USR1: Kill the forked child immediately and continue processing jobs.
     *
     * @return void
     */
    protected function registerSigHandlers(): void
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
                'type' => 'signal',
            ],
        ], LogLevel::DEBUG);
    }

    /**
     * Signal handler callback for USR2, pauses processing of new jobs.
     *
     * @return void
     */
    public function pauseProcessing(): void
    {
        $this->log([
            'message' => 'USR2 received; pausing job processing',
            'data' => [
                'type' => 'pause',
            ],
        ], LogLevel::INFO);

        $this->paused = true;
    }

    /**
     * Signal handler callback for CONT, resumes worker allowing it to pick
     * up new jobs.
     *
     * @return void
     */
    public function unPauseProcessing(): void
    {
        $this->log([
            'message' => 'CONT received; resuming job processing',
            'data' => [
                'type' => 'resume',
            ],
        ], LogLevel::INFO);

        $this->paused = false;
    }

    /**
     * Signal handler for SIGPIPE, in the event the redis connection has gone
     * away. Attempts to reconnect to redis, or raises an Exception.
     *
     * @return void
     */
    public function reestablishRedisConnection(): void
    {
        $this->log([
            'message' => 'SIGPIPE received; attempting to reconnect',
            'data' => [
                'type' => 'reconnect',
            ],
        ], LogLevel::INFO);

        $redis = Resque::redis();

        $redis->disconnect();
        $redis->connect();
    }

    /**
     * Schedule a worker for shutdown. Will finish processing the current job
     * and when the timeout interval is reached, the worker will shut down.
     *
     * @return void
     */
    public function shutdown(): void
    {
        $this->shutdown = true;

        $this->log([
            'message' => 'Exiting...',
            'data' => [
                'type' => 'shutdown',
            ],
        ], LogLevel::INFO);
    }

    /**
     * Force an immediate shutdown of the worker, killing any child jobs
     * currently running.
     *
     * @return void
     */
    public function shutdownNow(): void
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
    public function shutdownGraceful(): void
    {
        $this->shutdown();

        if (isset($this->child, $this->gracefulSignal)) {
            $this->childSignal = $this->gracefulSignal;
        }

        pcntl_alarm($this->gracefulDelay);
    }

    /**
     * Kill a forked child job immediately. The job it is processing will not
     * be completed.
     *
     * @return void
     */
    public function killChild(): void
    {
        if (!isset($this->child)) {
            $this->log([
                'message' => 'No child to kill.',
                'data' => [
                    'type' => 'kill',
                    'child' => null,
                ],
            ], LogLevel::DEBUG);

            return;
        }

        exec("ps -p $this->child", $output, $returnCode);

        if ($returnCode === 0) {
            $this->log([
                'message' => "Killing child at $this->child",
                'data' => [
                    'type' => 'kill',
                    'child' => $this->child,
                ],
            ], LogLevel::DEBUG);

            posix_kill($this->child, $this->childSignal);

            if ($this->childSignal === SIGKILL) {
                $this->child = null;
            } else {
                $this->childSignal = SIGKILL;
                pcntl_alarm($this->gracefulDelayTwo);
            }
        } else {
            $this->log([
                'message' => "Child $this->child not found, restarting.",
                'data' => [
                    'type' => 'kill',
                    'child' => $this->child,
                ],
            ], LogLevel::ERROR);

            $this->shutdown();
        }
    }

    /**
     * Register this worker in Redis.
     *
     * @return void
     */
    public function registerWorker(): void
    {
        /** @var string */
        $now = date('D M d H:i:s e Y');

        Resque::redis()->sadd('workers', $this->id);
        Resque::redis()->set("worker:$this:started", $now);
    }

    /**
     * Unregister this worker in Redis. (shutdown etc)
     *
     * @return void
     */
    public function unregisterWorker(): void
    {
        if (is_object($this->currentJob)) {
            $this->currentJob->fail(new DirtyExitException());
        }

        $id = $this->id;
        Resque::redis()->srem('workers', $id);
        Resque::redis()->del("worker:$id");
        Resque::redis()->del("worker:$id:started");
        Stat::clear("processed:$id");
        Stat::clear("failed:$id");
        Resque::redis()->hdel('workerLogger', $id);
    }

    /**
     * Tell Redis which job we're currently working on.
     *
     * @param Job $job Resque\Job instance containing the job we're working on.
     * @return void
     */
    public function workingOn(Job $job): void
    {
        $job->worker = $this;
        $this->currentJob = $job;
        $job->updateStatus(Status::STATUS_RUNNING);
        $json = Util::jsonEncode([
            'queue' => $job->queue,
            'run_at' => date('D M d H:i:s e Y'),
            'payload' => $job->payload,
        ]);

        Resque::redis()->set("worker:$this", $json);
    }

    /**
     * Notify Redis that we've finished working on a job, clearing the working
     * state and incrementing the job stats.
     *
     * @return void
     */
    public function doneWorking(): void
    {
        $this->currentJob = null;
        Stat::incr('processed');
        Stat::incr("processed:$this");
        Resque::redis()->del("worker:$this");
    }

    /**
     * Generate a string representation of this worker.
     *
     * @return string String identifier for this worker instance.
     */
    public function __toString(): string
    {
        return $this->id;
    }

    /**
     * Output a given log message to STDOUT.
     *
     * @param array{message:string,data:array<mixed>} $message Message to output.
     * @param int|string $code A log type code or a PSR log level.
     * @return bool True if the message is logged
     */
    public function log(array $message, $code = LogLevel::INFO): bool
    {
        $level = is_string($code) ? $code : self::LOG_LEVEL_MAP[$code];

        if (
            ($this->logLevel === self::LOG_NONE)
            || (($level === LogLevel::DEBUG)
                && ($this->logLevel === self::LOG_NORMAL))
        ) {
            return false;
        }

        $context = $message['data'];
        $message = $message['message'];

        if (!isset($context['worker'])) {
            $context['worker'] = "$this->hostname:$this->pid";
        }

        if (isset($this->logger)) {
            $this->logger->log($level, $message, $context);
        } else {
            fwrite($this->logOutput, '[' . date('c') . "] $message\n");
        }

        return true;
    }

    /**
     * Register a logger for this worker.
     *
     * @param MonologInit $logger A logger.
     * @return void
     * @deprecated Support for logger configuration stored in Redis will be
     *      removed in v3.0.
     * @see LoggerFactory
     */
    public function registerLogger(MonologInit $logger): void
    {
        $this->logger = $logger->getInstance();

        $json = Util::jsonEncode([
            $logger->handler,
            $logger->target,
        ]);

        Resque::redis()->hset('workerLogger', $this->id, $json);
    }

    /**
     * Fetch the logger for a given worker.
     *
     * @param string $workerId A worker ID.
     * @return LoggerInterface The logger, or null for internal logging.
     * @deprecated Support for logger configuration stored in Redis will be
     *      removed in v3.0.
     * @see LoggerFactory
     */
    public function getLogger(string $workerId): ?LoggerInterface
    {
        $json = Resque::redis()->hget('workerLogger', $workerId);

        if (isset($json)) {
            /** @var array<mixed> */
            $settings = Util::jsonDecode($json);
            $logger = new MonologInit($settings[0], $settings[1]);

            return $logger->getInstance();
        }

        return null;
    }

    /**
     * Return an object describing the job this worker is currently working on.
     *
     * @return array<mixed> Object with details of current job.
     */
    public function job(): array
    {
        $json = Resque::redis()->get("worker:$this");

        if (isset($json)) {
            /** @var array<mixed> */
            $job = Util::jsonDecode($json);

            return $job;
        }

        return [];
    }

    /**
     * Get a statistic belonging to this worker.
     *
     * @param string $stat Statistic to fetch.
     * @return int Statistic value.
     */
    public function getStat(string $stat): int
    {
        return Stat::get("$stat:$this");
    }
}
