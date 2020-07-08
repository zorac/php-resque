<?php

namespace Resque;

use Resque\Test\TestCase;
use Resque\Test\TestJob;

/**
 * Resque\Worker tests.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class WorkerTest extends TestCase
{
    /** @var WorkerFactory */
    private $factory;

    public function setUp() : void
    {
        parent::setUp();

        $this->factory = new WorkerFactory();
    }

    public function testWorkerRegistersInList(): void
    {
        $worker = $this->factory->create('*');
        $worker->registerWorker();

        // Make sure the worker is in the list
        self::assertTrue((bool)self::$redis->sismember('workers', (string)$worker));
    }

    public function testGetAllWorkers(): void
    {
        $num = 3;

        // Register a few workers
        for ($i = 0; $i < $num; ++$i) {
            $worker = $this->factory->create("queue_$i");
            $worker->registerWorker();
        }

        // Now try to get them
        self::assertEquals($num, count($this->factory->getAll()));
    }

    public function testGetWorkerById(): void
    {
        $worker = $this->factory->create('*');
        $worker->registerWorker();

        $newWorker = $this->factory->get((string)$worker);
        self::assertEquals((string)$worker, (string)$newWorker);
    }

    public function testInvalidWorkerDoesNotExist(): void
    {
        self::assertFalse($this->factory->exists('blah'));
    }

    public function testWorkerCanUnregister(): void
    {
        $worker = $this->factory->create('*');
        $worker->registerWorker();
        $worker->unregisterWorker();

        self::assertFalse($this->factory->exists((string)$worker));
        self::assertEquals([], $this->factory->getAll());
        self::assertEquals([], self::$redis->smembers('resque:workers'));
    }

    public function testPausedWorkerDoesNotPickUpJobs(): void
    {
        $worker = $this->factory->create('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', TestJob::class);
        $worker->work(0);
        $worker->work(0);
        self::assertEquals(0, Stat::get('processed'));
    }

    public function testResumedWorkerPicksUpJobs(): void
    {
        $worker = $this->factory->create('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', TestJob::class);
        $worker->work(0);
        self::assertEquals(0, Stat::get('processed'));
        $worker->unPauseProcessing();
        $worker->work(0);
        self::assertEquals(1, Stat::get('processed'));
    }

    public function testWorkerCanWorkOverMultipleQueues(): void
    {
        $worker = $this->factory->create([ 'queue1', 'queue2' ]);
        $worker->registerWorker();
        Resque::enqueue('queue1', 'TestJob1');
        Resque::enqueue('queue2', 'TestJob2');

        $job = $worker->reserve();
        self::assertNotNull($job);
        self::assertEquals('queue1', $job->queue);

        $job = $worker->reserve();
        self::assertNotNull($job);
        self::assertEquals('queue2', $job->queue);
    }

    public function testWorkerWorksQueuesInSpecifiedOrder(): void
    {
        $worker = $this->factory->create([ 'high', 'medium', 'low' ]);
        $worker->registerWorker();

        // Queue the jobs in a different order
        Resque::enqueue('low', 'TestJob1');
        Resque::enqueue('high', 'TestJob2');
        Resque::enqueue('medium', 'TestJob3');

        // Now check we get the jobs back in the right order
        $job = $worker->reserve();
        self::assertNotNull($job);
        self::assertEquals('high', $job->queue);

        $job = $worker->reserve();
        self::assertNotNull($job);
        self::assertEquals('medium', $job->queue);

        $job = $worker->reserve();
        self::assertNotNull($job);
        self::assertEquals('low', $job->queue);
    }

    public function testWildcardQueueWorkerWorksAllQueues(): void
    {
        $worker = $this->factory->create('*');
        $worker->registerWorker();

        Resque::enqueue('queue1', 'TestJob1');
        Resque::enqueue('queue2', 'TestJob2');

        $job1 = $worker->reserve();
        self::assertNotNull($job1);

        $job2 = $worker->reserve();
        self::assertNotNull($job2);

        $queues = [ $job1->queue, $job2->queue ];

        self::assertContains('queue1', $queues);
        self::assertContains('queue2', $queues);
    }

    public function testWorkerDoesNotWorkOnUnknownQueues(): void
    {
        $worker = $this->factory->create('queue1');
        $worker->registerWorker();
        Resque::enqueue('queue2', TestJob::class);

        self::assertNull($worker->reserve());
    }

    public function testWorkerClearsItsStatusWhenNotWorking(): void
    {
        Resque::enqueue('jobs', TestJob::class);
        $worker = $this->factory->create('jobs');

        $job = $worker->reserve();
        self::assertNotNull($job);

        $worker->workingOn($job);
        $worker->doneWorking();
        self::assertEquals([], $worker->job());
    }

    public function testWorkerRecordsWhatItIsWorkingOn(): void
    {
        $worker = $this->factory->create('jobs');
        $worker->registerWorker();

        $payload = [
            'class' => TestJob::class,
            'id'    => 'randomId',
            'args'  => null,
        ];
        $job = new Job('jobs', $payload);
        $worker->workingOn($job);

        $job = $worker->job();
        self::assertEquals('jobs', $job['queue']);
        if (!isset($job['run_at'])) {
            self::fail('Job does not have run_at time');
        }
        self::assertEquals($payload, $job['payload']);
    }

    public function testWorkerErasesItsStatsWhenShutdown(): void
    {
        Resque::enqueue('jobs', TestJob::class);
        Resque::enqueue('jobs', 'InvalidJob');

        $worker = $this->factory->create('jobs');
        $worker->work(0);
        $worker->work(0);

        self::assertEquals(0, $worker->getStat('processed'));
        self::assertEquals(0, $worker->getStat('failed'));
    }

    public function testWorkerCleansUpDeadWorkersOnStartup(): void
    {
        // Register a good worker
        $goodWorker = $this->factory->create('jobs');
        $goodWorker->registerWorker();

        // Register some bad workers
        $worker = $this->factory->create('jobs', null, 1);
        $worker->registerWorker();

        $worker = $this->factory->create(['high', 'low'], null, 2);
        $worker->registerWorker();

        self::assertEquals(3, count($this->factory->getAll()));

        $this->factory->prune();

        // There should only be $goodWorker left now
        self::assertEquals(1, count($this->factory->getAll()));
    }

    public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers(): void
    {
        // Register a bad worker on this machine
        $badWorker = $this->factory->create('jobs', null, 1);
        $badWorker->registerWorker();

        // Register some other false workers
        $worker = $this->factory->create('jobs', 'my.other.host', 1);
        $worker->registerWorker();

        self::assertEquals(2, count($this->factory->getAll()));

        $this->factory->prune();

        // my.other.host should be left
        $workers = $this->factory->getAll();
        self::assertEquals(1, count($workers));
        self::assertEquals((string)$worker, (string)$workers[0]);
    }

    public function testWorkerFailsUncompletedJobsOnExit(): void
    {
        $worker = $this->factory->create('jobs');
        $worker->registerWorker();

        $payload = [
            'class' => TestJob::class,
            'id'    => 'randomId'
        ];
        $job = new Job('jobs', $payload);

        $worker->workingOn($job);
        $worker->unregisterWorker();

        self::assertEquals(1, Stat::get('failed'));
    }

    public function testWorkerLogAllMessageOnVerbose(): void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = $this->factory->create('jobs');
        $worker->logLevel = Worker::LOG_VERBOSE;
        $worker->logOutput = $memory;

        $message = ['message' => 'x', 'data' => []];

        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_WARNING));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ERROR));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($memory);
        $output = stream_get_contents($memory);
        self::assertNotFalse($output);

        $lines = explode("\n", $output);
        self::assertEquals(6, count($lines) - 1);
    }

    public function testWorkerLogOnlyInfoMessageOnNonVerbose(): void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = $this->factory->create('jobs');
        $worker->logLevel = Worker::LOG_NORMAL;
        $worker->logOutput = $memory;

        $message = ['message' => 'x', 'data' => []];

        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_WARNING));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ERROR));
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($memory);
        $output = stream_get_contents($memory);
        self::assertNotFalse($output);

        $lines = explode("\n", $output);
        self::assertEquals(5, count($lines) - 1);
    }

    public function testWorkerLogNothingWhenLogNone(): void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = $this->factory->create('jobs');
        $worker->logLevel = Worker::LOG_NONE;
        $worker->logOutput = $memory;

        $message = ['message' => 'x', 'data' => []];

        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_INFO));
        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_WARNING));
        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_ERROR));
        self::assertEquals(false, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($memory);
        $output = stream_get_contents($memory);
        self::assertNotFalse($output);

        $lines = explode("\n", $output);
        self::assertEquals(0, count($lines) - 1);
    }

    public function testWorkerLogWithIsoTime(): void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = $this->factory->create('jobs');
        $worker->logLevel = Worker::LOG_NORMAL;
        $worker->logOutput = $memory;

        $message = ['message' => 'x', 'data' => []];

        $now = date('c');
        self::assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));

        rewind($memory);
        $output = stream_get_contents($memory);
        self::assertNotFalse($output);

        $lines = explode("\n", $output);
        self::assertEquals(1, count($lines) - 1);
        self::assertEquals("[$now] x", $lines[0]);
    }

    public function testBlockingListPop(): void
    {
        $worker = $this->factory->create(['job1s', 'job2s']);
        $worker->registerWorker();

        Resque::enqueue('job1s', 'TestJob1');
        Resque::enqueue('job2s', 'TestJob2');

        $i = 1;

        while ($job = $worker->reserve(true, 1)) {
            self::assertEquals("TestJob$i", $job->payload['class']);

            if ($i == 2) {
                break;
            }

            $i++;
        }

        self::assertEquals(2, $i);
    }
}
