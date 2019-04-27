<?php

namespace Resque\Test;

class FailingJob
{
    public function perform()
    {
        throw new FailingJobException('Message!');
    }
}
