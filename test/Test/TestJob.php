<?php

namespace Resque\Test;

class TestJob
{
    public static $called = false;

    public function perform()
    {
        self::$called = true;
    }
}
