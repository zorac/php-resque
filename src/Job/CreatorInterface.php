<?php

namespace Resque\Job;

use Resque\Job;

/**
 * Interface for a Resque job creator
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
interface CreatorInterface
{
    /**
     * Create a new job instance. Note thtat instances are not required to
     * actually implement `PerformerInterface`, but they must at least have a
     * `perform()` method.
     *
     * @param Job $job The job to be processed.
     * @return PerformerInterface A job instance.
     */
    public function createJob(Job $job): object;
}
