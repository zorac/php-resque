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
    public function testWorkerRegistersInList() : void
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        // Make sure the worker is in the list
        self::assertTrue((bool)self::$redis->sismember('workers', (string)$worker));
    }

    public function testGetAllWorkers() : void
    {
        $num = 3;

        // Register a few workers
        for ($i = 0; $i < $num; ++$i) {
            $worker = new Worker("queue_$i");
            $worker->registerWorker();
        }

        // Now try to get them
        self::assertEquals($num, count(Worker::all()));
    }

    public function testGetWorkerById() : void
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        $newWorker = Worker::find((string)$worker);
        self::assertEquals((string)$worker, (string)$newWorker);
    }

    public function testInvalidWorkerDoesNotExist() : void
    {
        self::assertFalse(Worker::exists('blah'));
    }

    public function testWorkerCanUnregister() : void
    {
        $worker = new Worker('*');
        $worker->registerWorker();
        $worker->unregisterWorker();

        self::assertFalse(Worker::exists((string)$worker));
        self::assertEquals([], Worker::all());
        self::assertEquals([], self::$redis->smembers('resque:workers'));
    }

    public function testPausedWorkerDoesNotPickUpJobs() : void
    {
        $worker = new Worker('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', TestJob::class);
        $worker->work(0);
        $worker->work(0);
        self::assertEquals(0, Stat::get('processed'));
    }

    public function testResumedWorkerPicksUpJobs() : void
    {
        $worker = new Worker('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', TestJob::class);
        $worker->work(0);
        self::assertEquals(0, Stat::get('processed'));
        $worker->unPauseProcessing();
        $worker->work(0);
        self::assertEquals(1, Stat::get('processed'));
    }

    public function testWorkerCanWorkOverMultipleQueues() : void
    {
        $worker = new Worker([ 'queue1', 'queue2' ]);
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

    public function testWorkerWorksQueuesInSpecifiedOrder() : void
    {
        $worker = new Worker([ 'high', 'medium', 'low' ]);
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

    public function testWildcardQueueWorkerWorksAllQueues() : void
    {
        $worker = new Worker('*');
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

    public function testWorkerDoesNotWorkOnUnknownQueues() : void
    {
        $worker = new Worker('queue1');
        $worker->registerWorker();
        Resque::enqueue('queue2', TestJob::class);

        self::assertNull($worker->reserve());
    }

    public function testWorkerClearsItsStatusWhenNotWorking() : void
    {
        Resque::enqueue('jobs', TestJob::class);
        $worker = new Worker('jobs');

        $job = $worker->reserve();
        self::assertNotNull($job);

        $worker->workingOn($job);
        $worker->doneWorking();
        self::assertEquals([], $worker->job());
    }

    public function testWorkerRecordsWhatItIsWorkingOn() : void
    {
        $worker = new Worker('jobs');
        $worker->registerWorker();

        $payload = [ 'class' => TestJob::class ];
        $job = new Job('jobs', $payload);
        $worker->workingOn($job);

        $job = $worker->job();
        self::assertEquals('jobs', $job['queue']);
        if (!isset($job['run_at'])) {
            self::fail('Job does not have run_at time');
        }
        self::assertEquals($payload, $job['payload']);
    }

    public function testWorkerErasesItsStatsWhenShutdown() : void
    {
        Resque::enqueue('jobs', TestJob::class);
        Resque::enqueue('jobs', 'InvalidJob');

        $worker = new Worker('jobs');
        $worker->work(0);
        $worker->work(0);

        self::assertEquals(0, $worker->getStat('processed'));
        self::assertEquals(0, $worker->getStat('failed'));
    }

    public function testWorkerCleansUpDeadWorkersOnStartup() : void
    {
        // Register a good worker
        $goodWorker = new Worker('jobs');
        $goodWorker->registerWorker();
        $workerId = explode(':', $goodWorker);

        // Register some bad workers
        $worker = new Worker('jobs');
        $worker->setId($workerId[0] . ':1:jobs');
        $worker->registerWorker();

        $worker = new Worker(['high', 'low']);
        $worker->setId($workerId[0] . ':2:high,low');
        $worker->registerWorker();

        self::assertEquals(3, count(Worker::all()));

        $goodWorker->pruneDeadWorkers();

        // There should only be $goodWorker left now
        self::assertEquals(1, count(Worker::all()));
    }

    public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers() : void
    {
        // Register a bad worker on this machine
        $worker = new Worker('jobs');
        $workerId = explode(':', $worker);
        $worker->setId($workerId[0] . ':1:jobs');
        $worker->registerWorker();

        // Register some other false workers
        $worker = new Worker('jobs');
        $worker->setId('my.other.host:1:jobs');
        $worker->registerWorker();

        self::assertEquals(2, count(Worker::all()));

        $worker->pruneDeadWorkers();

        // my.other.host should be left
        $workers = Worker::all();
        self::assertEquals(1, count($workers));
        self::assertEquals((string)$worker, (string)$workers[0]);
    }

    public function testWorkerFailsUncompletedJobsOnExit() : void
    {
        $worker = new Worker('jobs');
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

    public function testWorkerLogAllMessageOnVerbose() : void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = new Worker('jobs');
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

    public function testWorkerLogOnlyInfoMessageOnNonVerbose() : void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = new Worker('jobs');
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

    public function testWorkerLogNothingWhenLogNone() : void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = new Worker('jobs');
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

    public function testWorkerLogWithIsoTime() : void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertNotFalse($memory);

        $worker = new Worker('jobs');
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

    public function testBlockingListPop() : void
    {
        $worker = new Worker(['job1s', 'job2s']);
        $worker->registerWorker();

        Resque::enqueue('job1s', 'TestJob1');
        Resque::enqueue('job2s', 'TestJob2');

        $i = 1;

        while($job = $worker->reserve(true, 1))
        {
            self::assertEquals("TestJob$i", $job->payload['class']);

            if ($i == 2) {
                break;
            }

            $i++;
        }

        self::assertEquals(2, $i);
    }
}
