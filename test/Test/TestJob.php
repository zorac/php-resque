<?php

namespace Resque\Test;

class TestJob
{
    /** @var bool */
    public static bool $called = false;

    public function perform() : void
    {
        self::$called = true;
    }
}
