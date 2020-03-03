<?php

use Resque\Event;

class My_Resque_Plugin
{
    public static function afterEnqueue($class, $arguments)
    {
        echo "Job was queued for " . $class . ". Arguments:";
        print_r($arguments);
    }

    public static function beforeFirstFork($worker)
    {
        echo "Worker started. Listening on queues: " . implode(', ', $worker->queues(false)) . "\n";
    }

    public static function beforeFork($job)
    {
        echo "Just about to fork to run " . $job;
    }

    public static function afterFork($job)
    {
        echo "Forked to run " . $job . ". This is the child process.\n";
    }

    public static function beforePerform($job)
    {
        echo "Cancelling " . $job . "\n";
    //    throw new Resque\Job\DontPerform;
    }

    public static function afterPerform($job)
    {
        echo "Just performed " . $job . "\n";
    }

    public static function onFailure($exception, $job)
    {
        echo $job . " threw an exception:\n" . $exception;
    }
}

// Somewhere in our application, we need to register:
Event::listen('afterEnqueue', ['My_Resque_Plugin', 'afterEnqueue']);
Event::listen('beforeFirstFork', ['My_Resque_Plugin', 'beforeFirstFork']);
Event::listen('beforeFork', ['My_Resque_Plugin', 'beforeFork']);
Event::listen('afterFork', ['My_Resque_Plugin', 'afterFork']);
Event::listen('beforePerform', ['My_Resque_Plugin', 'beforePerform']);
Event::listen('afterPerform', ['My_Resque_Plugin', 'afterPerform']);
Event::listen('onFailure', ['My_Resque_Plugin', 'onFailure']);
