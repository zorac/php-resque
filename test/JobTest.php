<?php

namespace Resque;

use Resque\Test\JobWithSetUp;
use Resque\Test\JobWithTearDown;
use Resque\Test\TestCase;
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

    public function setUp() : void
    {
        parent::SetUp();

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->registerWorker();
    }

    public function testJobCanBeQueued()
    {
        $this->assertTrue((bool)Resque::enqueue('jobs', '\\Resque\\Test\\TestJob'));
    }

    public function testQeueuedJobCanBeReserved()
    {
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob');

        $job = Job::reserve('jobs');
        if ($job == false) {
            $this->fail('Job could not be reserved.');
        }
        $this->assertEquals('jobs', $job->queue);
        $this->assertEquals('\\Resque\\Test\\TestJob', $job->payload['class']);
    }

    public function testObjectArgumentsCannotBePassedToJob()
    {
        $args = new stdClass;
        $args->test = 'somevalue';
        $this->expectException(TypeError::class);
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob', $args);
    }

    public function testQueuedJobReturnsExactSamePassedInArguments()
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
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob', $args);
        $job = Job::reserve('jobs');

        $this->assertEquals($args, $job->getArguments());
    }

    public function testAfterJobIsReservedItIsRemoved()
    {
        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob');
        Job::reserve('jobs');
        $this->assertNull(Job::reserve('jobs'));
    }

    public function testRecreatedJobMatchesExistingJob()
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

        Resque::enqueue('jobs', '\\Resque\\Test\\TestJob', $args);
        $job = Job::reserve('jobs');

        // Now recreate it
        $job->recreate();

        $newJob = Job::reserve('jobs');
        $this->assertEquals($job->payload['class'], $newJob->payload['class']);
        $this->assertEquals($job->payload['args'], $newJob->getArguments());
    }


    public function testFailedJobExceptionsAreCaught()
    {
        $payload = [
            'class' => '\\Resque\\Test\\FailingJob',
            'id'    => 'randomId',
            'args'  => null,
        ];
        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;

        $this->worker->perform($job);

        $this->assertEquals(1, Stat::get('failed'));
        $this->assertEquals(1, Stat::get('failed:' . $this->worker));
    }

    public function testJobWithoutPerformMethodThrowsException()
    {
        Resque::enqueue('jobs', '\\Resque\\Test\\JobWithoutPerformMethod');
        $job = $this->worker->reserve();
        $job->worker = $this->worker;
        $this->expectException(ResqueException::class);
        $job->perform();
    }

    public function testInvalidJobThrowsException()
    {
        Resque::enqueue('jobs', '\\Resque\\Test\\InvalidJob');
        $job = $this->worker->reserve();
        $job->worker = $this->worker;
        $this->expectException(ResqueException::class);
        $job->perform();
    }

    public function testJobWithSetUpCallbackFiresSetUp()
    {
        $payload = [
            'class' => '\\Resque\\Test\\JobWithSetUp',
            'args'  => [[
                'somevar',
                'somevar2',
            ]],
        ];
        $job = new Job('jobs', $payload);
        $job->perform();

        $this->assertTrue(JobWithSetUp::$called);
    }

    public function testJobWithTearDownCallbackFiresTearDown()
    {
        $payload = [
            'class' => '\\Resque\\Test\\JobWithTearDown',
            'args'  => [[
                'somevar',
                'somevar2',
            ]],
        ];
        $job = new Job('jobs', $payload);
        $job->perform();

        $this->assertTrue(JobWithTearDown::$called);
    }

    public function testJobWithNamespace()
    {
        self::connect('testResque2');
        $queue = 'jobs';
        $payload = ['another_value'];
        Resque::enqueue($queue, '\\Resque\\Test\\JobWithTearDown', $payload);

        $this->assertEquals(Resque::queues(), ['jobs']);
        $this->assertEquals(Resque::size($queue), 1);

        self::connect();
        $this->assertEquals(Resque::size($queue), 0);
    }

    public function testDequeueAll()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue');
        $this->assertEquals(Resque::size($queue), 2);
        $this->assertEquals(Resque::dequeue($queue), 2);
        $this->assertEquals(Resque::size($queue), 0);
    }

    public function testDequeueMakeSureNotDeleteOthers()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue');
        $other_queue = 'other_jobs';
        Resque::enqueue($other_queue, '\\Resque\\Test\\JobDequeue');
        Resque::enqueue($other_queue, '\\Resque\\Test\\JobDequeue');
        $this->assertEquals(Resque::size($queue), 2);
        $this->assertEquals(Resque::size($other_queue), 2);
        $this->assertEquals(Resque::dequeue($queue), 2);
        $this->assertEquals(Resque::size($queue), 0);
        $this->assertEquals(Resque::size($other_queue), 2);
    }

    public function testDequeueSpecificItem()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue1');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue2');
        $this->assertEquals(Resque::size($queue), 2);
        $test = ['\\Resque\\Test\\JobDequeue2'];
        $this->assertEquals(Resque::dequeue($queue, $test), 1);
        $this->assertEquals(Resque::size($queue), 1);
    }

    public function testDequeueSpecificMultipleItems()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue1');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue2');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue3');
        $this->assertEquals(Resque::size($queue), 3);
        $test = ['\\Resque\\Test\\JobDequeue2', '\\Resque\\Test\\JobDequeue3'];
        $this->assertEquals(Resque::dequeue($queue, $test), 2);
        $this->assertEquals(Resque::size($queue), 1);
    }

    public function testDequeueNonExistingItem()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue1');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue2');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue3');
        $this->assertEquals(Resque::size($queue), 3);
        $test = ['\\Resque\\Test\\JobDequeue4'];
        $this->assertEquals(Resque::dequeue($queue, $test), 0);
        $this->assertEquals(Resque::size($queue), 3);
    }

    public function testDequeueNonExistingItem2()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue1');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue2');
        Resque::enqueue($queue, '\\Resque\\Test\\JobDequeue3');
        $this->assertEquals(Resque::size($queue), 3);
        $test = ['\\Resque\\Test\\JobDequeue4', '\\Resque\\Test\\JobDequeue1'];
        $this->assertEquals(Resque::dequeue($queue, $test), 1);
        $this->assertEquals(Resque::size($queue), 2);
    }
}
