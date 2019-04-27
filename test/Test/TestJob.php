<?php

namespace Resque\Test;

class TestJob
{
    /** @var bool */
    public static $called = false;

    public function perform() : void
    {
        self::$called = true;
    }
}
