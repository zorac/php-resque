<?php

namespace Resque\Job;

/**
 * Interface for a job. It is not required that job classes implement this
 * interface, but at a minimum the `perform()` method is required.
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
interface PerformerInterface
{
    /**
     * Set up the job. This method is optional if you're not actually
     * implementing the interface.
     *
     * @throws DontPerform If this job shuld not be performed.
     * @return void
     */
    public function setUp(): void;

    /**
     * Perform the job.
     *
     * @return void
     */
    public function perform(): void;

    /**
     * Tear down the job. This method is optional if you're not actually
     * implementing the interface.
     *
     * @return void
     */
    public function tearDown(): void;
}
