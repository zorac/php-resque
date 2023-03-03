<?php

namespace Resque\Scheduler;

use Resque\ResqueException;

/**
* Exception thrown whenever an invalid timestamp has been passed to a job.
*
* @author    Chris Boulton <chris@bigcommerce.com>
* @copyright 2012 Chris Boulton
* @license   http://www.opensource.org/licenses/mit-license.php MIT
*/
class InvalidTimestampException extends ResqueException
{
}
