<?php

namespace Resque\Test;

class JobWithTearDown
{
    public static $called = false;
    public $args = false;

    public function perform()
    {
    }

    public function tearDown()
    {
        self::$called = true;
    }
}
