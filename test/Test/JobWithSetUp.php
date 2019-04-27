<?php

namespace Resque\Test;

class JobWithSetUp
{
    /** @var bool */
    public static $called = false;

    public function setUp() : void
    {
        self::$called = true;
    }

    public function perform() : void
    {
    }
}
