<?php

namespace Resque\Job;

use Exception;

/**
 * Exception to be thrown if a job should not be performed/run.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class DontPerform extends Exception
{
}
