<?php

namespace Resque\Scheduler\Job;

use Resque\Job\Status as ResqueJobStatus;

/**
 * Status tracker/information for a job.
 *
 * @author     Wan Qi Chen <kami@kamisama.me>
 * @copyright  2013 Wan Qi Chen
 * @license    http://www.opensource.org/licenses/mit-license.php MIT
 */
class Status extends ResqueJobStatus
{
    public const STATUS_SCHEDULED = 63;
}
