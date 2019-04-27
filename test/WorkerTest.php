<?php

namespace Resque;

use Resque\Test\TestCase;

/**
 * Resque\Worker tests.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class WorkerTest extends TestCase
{
    public function testWorkerRegistersInList()
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        // Make sure the worker is in the list
        $this->assertTrue((bool)self::$redis->sismember('workers', (string)$worker));
    }

    public function testGetAllWorkers()
    {
        $num = 3;

        // Register a few workers
        for ($i = 0; $i < $num; ++$i) {
            $worker = new Worker('queue_' . $i);
            $worker->registerWorker();
        }

        // Now try to get them
        $this->assertEquals($num, count(Worker::all()));
    }

    public function testGetWorkerById()
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        $newWorker = Worker::find((string)$worker);
        $this->assertEquals((string)$worker, (string)$newWorker);
    }

    public function testInvalidWorkerDoesNotExist()
    {
        $this->assertFalse(Worker::exists('blah'));
    }

    public function testWorkerCanUnregister()
    {
        $worker = new Worker('*');
        $worker->registerWorker();
        $worker->unregisterWorker();

        $this->assertFalse(Worker::exists((string)$worker));
        $this->assertEquals([], Worker::all());
        $this->assertEquals([], self::$redis->smembers('resque:workers'));
    }

    public function testPausedWorkerDoesNotPickUpJobs()
    {
        $worker = new Worker('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob');
        $worker->work(0);
        $worker->work(0);
        $this->assertEquals(0, Stat::get('processed'));
    }

    public function testResumedWorkerPicksUpJobs()
    {
        $worker = new Worker('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob');
        $worker->work(0);
        $this->assertEquals(0, Stat::get('processed'));
        $worker->unPauseProcessing();
        $worker->work(0);
        $this->assertEquals(1, Stat::get('processed'));
    }

    public function testWorkerCanWorkOverMultipleQueues()
    {
        $worker = new Worker(array(
            'queue1',
            'queue2'
        ));
        $worker->registerWorker();
        Resque::enqueue('queue1', '\\Resque\\Test\\TestJob1');
        Resque::enqueue('queue2', '\\Resque\\Test\\TestJob2');

        $job = $worker->reserve();
        $this->assertEquals('queue1', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerWorksQueuesInSpecifiedOrder()
    {
        $worker = new Worker(array(
            'high',
            'medium',
            'low'
        ));
        $worker->registerWorker();

        // Queue the jobs in a different order
        Resque::enqueue('low', '\\Resque\\Test\\TestJob1');
        Resque::enqueue('high', '\\Resque\\Test\\TestJob2');
        Resque::enqueue('medium', '\\Resque\\Test\\TestJob3');

        // Now check we get the jobs back in the right order
        $job = $worker->reserve();
        $this->assertEquals('high', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('medium', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('low', $job->queue);
    }

    public function testWildcardQueueWorkerWorksAllQueues()
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        Resque::enqueue('queue1', '\\Resque\\Test\\TestJob1');
        Resque::enqueue('queue2', '\\Resque\\Test\\TestJob2');

        $job1 = $worker->reserve();
        $job2 = $worker->reserve();
        $queues = [ $job1->queue, $job2->queue ];

        $this->assertContains('queue1', $queues);
        $this->assertContains('queue2', $queues);
    }

    public function testWorkerDoesNotWorkOnUnknownQueues()
    {
        $worker = new Worker('queue1');
        $worker->registerWorker();
        Resque::enqueue('queue2', '\\Resque\\Test\\TestJob');

        $this->assertNull($worker->reserve());
    }

    public function testWorkerClearsItsStatusWhenNotWorking()
    {
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob');
        $worker = new Worker('jobs');
        $job = $worker->reserve();
        $worker->workingOn($job);
        $worker->doneWorking();
        $this->assertEquals([], $worker->job());
    }

    public function testWorkerRecordsWhatItIsWorkingOn()
    {
        $worker = new Worker('jobs');
        $worker->registerWorker();

        $payload = array(
            'class' => '\\Resque\\Test\\TestJob'
        );
        $job = new Job('jobs', $payload);
        $worker->workingOn($job);

        $job = $worker->job();
        $this->assertEquals('jobs', $job['queue']);
        if (!isset($job['run_at'])) {
            $this->fail('Job does not have run_at time');
        }
        $this->assertEquals($payload, $job['payload']);
    }

    public function testWorkerErasesItsStatsWhenShutdown()
    {
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob');
        Resque::enqueue('jobs', '\\Resque\\Test\\InvalidJob');

        $worker = new Worker('jobs');
        $worker->work(0);
        $worker->work(0);

        $this->assertEquals(0, $worker->getStat('processed'));
        $this->assertEquals(0, $worker->getStat('failed'));
    }

    public function testWorkerCleansUpDeadWorkersOnStartup()
    {
        // Register a good worker
        $goodWorker = new Worker('jobs');
        $goodWorker->registerWorker();
        $workerId = explode(':', $goodWorker);

        // Register some bad workers
        $worker = new Worker('jobs');
        $worker->setId($workerId[0].':1:jobs');
        $worker->registerWorker();

        $worker = new Worker(array('high', 'low'));
        $worker->setId($workerId[0].':2:high,low');
        $worker->registerWorker();

        $this->assertEquals(3, count(Worker::all()));

        $goodWorker->pruneDeadWorkers();

        // There should only be $goodWorker left now
        $this->assertEquals(1, count(Worker::all()));
    }

    public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers()
    {
        // Register a bad worker on this machine
        $worker = new Worker('jobs');
        $workerId = explode(':', $worker);
        $worker->setId($workerId[0].':1:jobs');
        $worker->registerWorker();

        // Register some other false workers
        $worker = new Worker('jobs');
        $worker->setId('my.other.host:1:jobs');
        $worker->registerWorker();

        $this->assertEquals(2, count(Worker::all()));

        $worker->pruneDeadWorkers();

        // my.other.host should be left
        $workers = Worker::all();
        $this->assertEquals(1, count($workers));
        $this->assertEquals((string)$worker, (string)$workers[0]);
    }

    public function testWorkerFailsUncompletedJobsOnExit()
    {
        $worker = new Worker('jobs');
        $worker->registerWorker();

        $payload = array(
            'class' => '\\Resque\\Test\\TestJob',
            'id' => 'randomId'
        );
        $job = new Job('jobs', $payload);

        $worker->workingOn($job);
        $worker->unregisterWorker();

        $this->assertEquals(1, Stat::get('failed'));
    }

    public function testWorkerLogAllMessageOnVerbose()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_VERBOSE;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => []];

        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_WARNING));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ERROR));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(6, count($lines) -1);
    }

    public function testWorkerLogOnlyInfoMessageOnNonVerbose()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_NORMAL;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => []];

        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_WARNING));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ERROR));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(5, count($lines) -1);
    }

    public function testWorkerLogNothingWhenLogNone()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_NONE;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => []];

        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_INFO));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_WARNING));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_ERROR));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(0, count($lines) -1);
    }

    public function testWorkerLogWithISOTime()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_NORMAL;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => []];

        $now = date('c');
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(1, count($lines) -1);
        $this->assertEquals('[' . $now . '] x', $lines[0]);
    }
}
