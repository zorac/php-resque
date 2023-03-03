<?php

namespace Resque\Test;

use Resque\Job\AbstractLegacyPerformer;

class JobWithSetUp extends AbstractLegacyPerformer
{
    /** @var bool */
    public static $called = false;

    public function setUp(): void
    {
        self::$called = true;
    }

    public function perform(): void
    {
    }
}
