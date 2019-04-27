<?php

namespace Resque\Test;

class JobWithSetUp
{
    public static $called = false;
    public $args = false;

    public function setUp()
    {
        self::$called = true;
    }

    public function perform()
    {
    }
}
