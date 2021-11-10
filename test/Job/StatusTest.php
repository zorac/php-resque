<?php

namespace Resque\Job;

use Resque\Job;
use Resque\Resque;
use Resque\Test\FailingJob;
use Resque\Test\TestCase;
use Resque\Test\TestJob;
use Resque\Worker;

/**
 * Resque\Job\Status tests.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class StatusTest extends TestCase
{
    /** @var Worker */
    private $worker;

    public function setUp(): void
    {
        parent::setUp();
        $this->worker = new Worker('jobs');
    }

    public function testJobStatusCanBeTracked(): void
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        self::assertNotNull($token);

        $status = new Status($token);
        self::assertTrue($status->isTracking());
    }

    public function testJobStatusIsReturnedViaJobInstance(): void
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        self::assertNotNull($token);

        $job = Job::reserve('jobs');
        self::assertNotNull($job);
        self::assertEquals(Status::STATUS_WAITING, $job->getStatus());
    }

    public function testQueuedJobReturnsQueuedStatus(): void
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        self::assertNotNull($token);

        $status = new Status($token);
        self::assertEquals(Status::STATUS_WAITING, $status->get());
    }
    public function testRunningJobReturnsRunningStatus(): void
    {
        $token = Resque::enqueue('jobs', FailingJob::class, null, true);
        self::assertNotNull($token);

        $job = $this->worker->reserve();
        self::assertNotNull($job);

        $this->worker->workingOn($job);
        $status = new Status($token);
        self::assertEquals(Status::STATUS_RUNNING, $status->get());
    }

    public function testFailedJobReturnsFailedStatus(): void
    {
        $token = Resque::enqueue('jobs', FailingJob::class, null, true);
        self::assertNotNull($token);

        $this->worker->work(0);
        $status = new Status($token);
        self::assertEquals(Status::STATUS_FAILED, $status->get());
    }

    public function testCompletedJobReturnsCompletedStatus(): void
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        self::assertNotNull($token);

        $this->worker->work(0);
        $status = new Status($token);
        self::assertEquals(Status::STATUS_COMPLETE, $status->get());
    }

    public function testStatusIsNotTrackedWhenToldNotTo(): void
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, false);
        self::assertNotNull($token);

        $status = new Status($token);
        self::assertFalse($status->isTracking());
    }

    public function testStatusTrackingCanBeStopped(): void
    {
        Status::create('test');
        $status = new Status('test');
        self::assertEquals(Status::STATUS_WAITING, $status->get());

        $status->stop();
        self::assertFalse($status->get());
    }

    public function testRecreatedJobWithTrackingStillTracksStatus(): void
    {
        $originalToken = Resque::enqueue('jobs', TestJob::class, null, true);
        self::assertNotNull($originalToken);

        $job = $this->worker->reserve();
        self::assertNotNull($job);

        // Mark this job as being worked on to ensure that the new status is still
        // waiting.
        $this->worker->workingOn($job);

        // Now recreate it
        $newToken = $job->recreate();

        // Make sure we've got a new job returned
        self::assertNotEquals($originalToken, $newToken);

        // Now check the status of the new job
        $newJob = Job::reserve('jobs');
        self::assertNotNull($newJob);
        self::assertEquals(Status::STATUS_WAITING, $newJob->getStatus());
    }
}
