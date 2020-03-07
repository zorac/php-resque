<?php

namespace Resque\Job;

use Resque\Job;

/**
 * An abstract peformer with the legacy object properties.
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class AbstractLegacyPerformer extends AbstractPerformer
{
    /**
     * @var Job The job to process.
     */
    public $job;

    /**
     * @var array<mixed> The job arguments.
     */
    public $args;

    /**
     * @var string The queue the job is on.
     */
    public $queue;
}
