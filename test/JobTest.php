<?php

namespace Resque;

use Resque\Test\FailingJob;
use Resque\Test\JobWithoutPerformMethod;
use Resque\Test\JobWithSetUp;
use Resque\Test\JobWithTearDown;
use Resque\Test\TestCase;
use Resque\Test\TestJob;
use stdClass;
use TypeError;

/**
 * Resque\Job tests.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class JobTest extends TestCase
{
    /** @var Worker */
    private $worker;

    public function setUp(): void
    {
        parent::setUp();

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->registerWorker();
    }

    public function testJobCanBeQueued(): void
    {
        $token = Resque::enqueue('jobs', TestJob::class);
        self::assertIsString($token);
    }

    public function testQeueuedJobCanBeReserved(): void
    {
        Resque::enqueue('jobs', TestJob::class);

        $job = Job::reserve('jobs');
        self::assertNotNull($job);
        self::assertEquals('jobs', $job->queue);
        self::assertEquals(TestJob::class, $job->payload['class']);
    }

    public function testObjectArgumentsCannotBePassedToJob(): void
    {
        $args = new stdClass();
        $args->test = 'somevalue';
        $this->expectException(TypeError::class);
        /** @phpstan-ignore-next-line */
        Resque::enqueue('jobs', TestJob::class, $args);
    }

    public function testQueuedJobReturnsExactSamePassedInArguments(): void
    {
        $args = [
            'int'        => 123,
            'numArray'   => [
                1,
                2,
            ],
            'assocArray' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ];

        Resque::enqueue('jobs', TestJob::class, $args);

        $job = Job::reserve('jobs');
        self::assertNotNull($job);
        self::assertEquals($args, $job->getArguments());
    }

    public function testAfterJobIsReservedItIsRemoved(): void
    {
        Resque::enqueue('jobs', TestJob::class);
        Job::reserve('jobs');
        self::assertNull(Job::reserve('jobs'));
    }

    public function testRecreatedJobMatchesExistingJob(): void
    {
        $args = [
            'int'        => 123,
            'numArray'   => [
                1,
                2,
            ],
            'assocArray' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ];

        Resque::enqueue('jobs', TestJob::class, $args);

        $job = Job::reserve('jobs');
        self::assertNotNull($job);

        // Now recreate it
        $job->recreate();

        $newJob = Job::reserve('jobs');
        self::assertNotNull($newJob);
        self::assertEquals($job->payload['class'], $newJob->payload['class']);
        self::assertEquals($job->payload['args'], $newJob->getArguments());
    }


    public function testFailedJobExceptionsAreCaught(): void
    {
        $payload = [
            'class' => FailingJob::class,
            'id'    => 'randomId',
            'args'  => null,
        ];
        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;

        $this->worker->perform($job);

        self::assertEquals(1, Stat::get('failed'));
        self::assertEquals(1, Stat::get('failed:' . $this->worker));
    }

    public function testJobWithoutPerformMethodThrowsException(): void
    {
        Resque::enqueue('jobs', JobWithoutPerformMethod::class);

        $job = $this->worker->reserve();
        self::assertNotNull($job);

        $job->worker = $this->worker;

        $this->expectException(ResqueException::class);
        $job->perform();
    }

    public function testInvalidJobThrowsException(): void
    {
        Resque::enqueue('jobs', 'InvalidJob');

        $job = $this->worker->reserve();
        self::assertNotNull($job);

        $job->worker = $this->worker;

        $this->expectException(ResqueException::class);
        $job->perform();
    }

    public function testJobWithSetUpCallbackFiresSetUp(): void
    {
        $payload = [
            'class' => JobWithSetUp::class,
            'args'  => [[
                'somevar',
                'somevar2',
            ]],
        ];

        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;
        $job->perform();

        self::assertTrue(JobWithSetUp::$called);
    }

    public function testJobWithTearDownCallbackFiresTearDown(): void
    {
        $payload = [
            'class' => JobWithTearDown::class,
            'args'  => [[
                'somevar',
                'somevar2',
            ]],
        ];

        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;
        $job->perform();

        self::assertTrue(JobWithTearDown::$called);
    }

    public function testJobWithNamespace(): void
    {
        self::connect('testResque2');
        $queue = 'jobs';
        $payload = ['another_value'];
        Resque::enqueue($queue, JobWithTearDown::class, $payload);

        self::assertEquals(Resque::queues(), ['jobs']);
        self::assertEquals(Resque::size($queue), 1);

        self::connect();
        self::assertEquals(Resque::size($queue), 0);
    }

    public function testDequeueAll(): void
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'JobDequeue');
        Resque::enqueue($queue, 'JobDequeue');
        self::assertEquals(Resque::size($queue), 2);
        self::assertEquals(Resque::dequeue($queue), 2);
        self::assertEquals(Resque::size($queue), 0);
    }

    public function testDequeueMakeSureNotDeleteOthers(): void
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'JobDequeue');
        Resque::enqueue($queue, 'JobDequeue');
        $other_queue = 'other_jobs';
        Resque::enqueue($other_queue, 'JobDequeue');
        Resque::enqueue($other_queue, 'JobDequeue');
        self::assertEquals(Resque::size($queue), 2);
        self::assertEquals(Resque::size($other_queue), 2);
        self::assertEquals(Resque::dequeue($queue), 2);
        self::assertEquals(Resque::size($queue), 0);
        self::assertEquals(Resque::size($other_queue), 2);
    }

    public function testDequeueSpecificItem(): void
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'JobDequeue1');
        Resque::enqueue($queue, 'JobDequeue2');
        self::assertEquals(Resque::size($queue), 2);
        $test = ['JobDequeue2'];
        self::assertEquals(Resque::dequeue($queue, $test), 1);
        self::assertEquals(Resque::size($queue), 1);
    }

    public function testDequeueSpecificMultipleItems(): void
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'JobDequeue1');
        Resque::enqueue($queue, 'JobDequeue2');
        Resque::enqueue($queue, 'JobDequeue3');
        self::assertEquals(Resque::size($queue), 3);
        $test = ['JobDequeue2', 'JobDequeue3'];
        self::assertEquals(Resque::dequeue($queue, $test), 2);
        self::assertEquals(Resque::size($queue), 1);
    }

    public function testDequeueNonExistingItem(): void
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'JobDequeue1');
        Resque::enqueue($queue, 'JobDequeue2');
        Resque::enqueue($queue, 'JobDequeue3');
        self::assertEquals(Resque::size($queue), 3);
        $test = ['JobDequeue4'];
        self::assertEquals(Resque::dequeue($queue, $test), 0);
        self::assertEquals(Resque::size($queue), 3);
    }

    public function testDequeueNonExistingItem2(): void
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'JobDequeue1');
        Resque::enqueue($queue, 'JobDequeue2');
        Resque::enqueue($queue, 'JobDequeue3');
        self::assertEquals(Resque::size($queue), 3);
        $test = ['JobDequeue4', 'JobDequeue1'];
        self::assertEquals(Resque::dequeue($queue, $test), 1);
        self::assertEquals(Resque::size($queue), 2);
    }
}
