<?php

namespace Resque\Test;

class JobWithTearDown
{
    /** @var bool */
    public static $called = false;

    public function perform() : void
    {
    }

    public function tearDown() : void
    {
        self::$called = true;
    }
}
