<?php

namespace Resque;

use Resque\Job\DontPerform;
use Resque\Test\TestCase;
use Resque\Test\TestJob;

/**
 * Resque\Event tests.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class EventTest extends TestCase
{
    /** @var array<string> */
    private $callbacksHit = [];
    /** @var Worker */
    private $worker;

    public function setUp() : void
    {
        parent::setUp();
        TestJob::$called = false;

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->registerWorker();
    }

    public function tearDown() : void
    {
        Event::clearListeners();
        $this->callbacksHit = [];
    }

    public function getEventTestJob() : Job
    {
        $payload = [
            'class' => TestJob::class,
            'id'    => 'randomId',
            'args'  => [ [ 'somevar' ] ],
        ];
        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;
        return $job;
    }

    /** @return array<int,array<int,string>> */
    public function eventCallbackProvider() : array
    {
        return [
            ['beforePerform', 'beforePerformEventCallback'],
            ['afterPerform', 'afterPerformEventCallback'],
            ['afterFork', 'afterForkEventCallback'],
        ];
    }

    /**
     * @dataProvider eventCallbackProvider
     */
    public function testEventCallbacksFire(
        string $event,
        string $callback
    ) : void {
        /** @phpstan-ignore-next-line */
        Event::listen($event, [$this, $callback]);

        $job = $this->getEventTestJob();
        $this->worker->perform($job);
        $this->worker->work(0);

        self::assertContains($callback, $this->callbacksHit, "$event callback ($callback) was not called");
    }

    public function testBeforeForkEventCallbackFires() : void
    {
        $event = 'beforeFork';
        $callback = 'beforeForkEventCallback';

        Event::listen($event, [$this, $callback]);
        Resque::enqueue('jobs', TestJob::class, ['somevar']);
        $job = $this->getEventTestJob();
        $this->worker->work(0);
        self::assertContains($callback, $this->callbacksHit, "$event callback ($callback) was not called");
    }

    public function testBeforePerformEventCanStopWork() : void
    {
        $callback = 'beforePerformEventDontPerformCallback';
        Event::listen('beforePerform', [$this, $callback]);

        $job = $this->getEventTestJob();

        self::assertFalse($job->perform());
        self::assertContains($callback, $this->callbacksHit, "$callback callback was not called");
        self::assertFalse(TestJob::$called, 'Job was still performed though DontPerform was thrown');
    }

    public function testAfterEnqueueEventCallbackFires() : void
    {
        $callback = 'afterEnqueueEventCallback';
        $event = 'afterEnqueue';

        Event::listen($event, [$this, $callback]);
        Resque::enqueue('jobs', TestJob::class, ['somevar']);
        self::assertContains($callback, $this->callbacksHit, "$event callback ($callback) was not called");
    }

    public function testStopListeningRemovesListener() : void
    {
        $callback = 'beforePerformEventCallback';
        $event = 'beforePerform';

        Event::listen($event, [$this, $callback]);
        Event::stopListening($event, [$this, $callback]);

        $job = $this->getEventTestJob();
        $this->worker->perform($job);
        $this->worker->work(0);

        self::assertNotContains(
            $callback,
            $this->callbacksHit,
            "$event callback ($callback) was called though Event::stopListening was called"
        );
    }


    public function beforePerformEventDontPerformCallback(Job $instance) : void
    {
        $this->callbacksHit[] = __FUNCTION__;
        throw new DontPerform();
    }

    public function assertValidEventCallback(string $function, Job $job) : void
    {
        $this->callbacksHit[] = $function;
        $args = $job->getArguments();
        self::assertEquals($args[0], 'somevar');
    }

    /** @param array<int,string> $args */
    public function afterEnqueueEventCallback(string $class, array $args) : void
    {
        $this->callbacksHit[] = __FUNCTION__;
        self::assertEquals(TestJob::class, $class);
        self::assertEquals(['somevar'], $args);
    }

    public function beforePerformEventCallback(Job $job) : void
    {
        self::assertValidEventCallback(__FUNCTION__, $job);
    }

    public function afterPerformEventCallback(Job $job) : void
    {
        self::assertValidEventCallback(__FUNCTION__, $job);
    }

    public function beforeForkEventCallback(Job $job) : void
    {
        self::assertValidEventCallback(__FUNCTION__, $job);
    }

    public function afterForkEventCallback(Job $job) : void
    {
        self::assertValidEventCallback(__FUNCTION__, $job);
    }
}
