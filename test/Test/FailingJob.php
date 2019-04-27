<?php

namespace Resque\Test;

class FailingJob
{
    public function perform() : void
    {
        throw new FailingJobException('Message!');
    }
}
