<?php

namespace Resque\Job;

use RuntimeException;

/**
 * Runtime exception class for a job that does not exit cleanly.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class DirtyExitException extends RuntimeException
{
}
