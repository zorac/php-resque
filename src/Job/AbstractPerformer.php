<?php

namespace Resque\Job;

/**
 * An abstract peformer which simply provides NOOP `setUp()` and `tearDown()`.
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class AbstractPerformer implements PerformerInterface
{
    /**
     * Set up the job.
     *
     * @throws DontPerform If this job shuld not be performed.
     * @return void
     */
    public function setUp(): void
    {
        // Do nothing.
    }

    /**
     * Tear down the job.
     *
     * @return void
     */
    public function tearDown(): void
    {
        // Do nothing.
    }
}
