<?php

namespace Resque\Test;

use Resque\Job\AbstractLegacyPerformer;

class FailingJob extends AbstractLegacyPerformer
{
    public function perform(): void
    {
        throw new FailingJobException('Message!');
    }
}
