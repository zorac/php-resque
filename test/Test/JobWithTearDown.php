<?php

namespace Resque\Test;

use Resque\Job\AbstractLegacyPerformer;

class JobWithTearDown extends AbstractLegacyPerformer
{
    /** @var bool */
    public static $called = false;

    public function perform(): void
    {
    }

    public function tearDown(): void
    {
        self::$called = true;
    }
}
