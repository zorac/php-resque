<?php

namespace Resque\Job;

use Exception;

/**
 * Exception to be thrown if while enqueuing a job it should not be created.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class DontCreate extends Exception
{
}
