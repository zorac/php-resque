<?php

namespace Resque\Test;

use Resque\Job\AbstractLegacyPerformer;

class TestJob extends AbstractLegacyPerformer
{
    /** @var bool */
    public static $called = false;

    public function perform(): void
    {
        self::$called = true;
    }
}
