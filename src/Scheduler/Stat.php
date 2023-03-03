<?php

namespace Resque\Scheduler;

use Resque\Stat as ResqueStat;

/**
 * Resque statistic management (jobs processed, failed, etc)
 *
 * @author     Chris Boulton <chris@bigcommerce.com>
 * @author     Wan Qi Chen <kami@kamisama.me>
 * @license    http://www.opensource.org/licenses/mit-license.php MIT
 */
class Stat extends ResqueStat
{
    public const KEYNAME = 'schdlr';

    /**
     * Get the value of the supplied statistic counter for the specified
     * statistic.
     *
     * @param string $stat The name of the statistic to get the stats for.
     * @return int Value of the statistic.
     */
    public static function get(string $stat = self::KEYNAME): int
    {
        return parent::get($stat);
    }

    /**
     * Increment the value of the specified statistic by a certain amount
     * (default is 1)
     *
     * @param string $stat The name of the statistic to increment.
     * @param int $by The amount to increment the statistic by.
     * @return bool True if successful, false if not.
     */
    public static function incr(
        string $stat = self::KEYNAME,
        int $by = 1
    ): bool {
        return parent::incr($stat, $by);
    }

    /**
     * Decrement the value of the specified statistic by a certain amount
     * (default is 1)
     *
     * @param string $stat The name of the statistic to decrement.
     * @param int $by The amount to decrement the statistic by.
     * @return bool True if successful, false if not.
     */
    public static function decr(
        string $stat = self::KEYNAME,
        int $by = 1
    ): bool {
        return parent::decr($stat, $by);
    }

    /**
     * Delete a statistic with the given name.
     *
     * @param string $stat The name of the statistic to delete.
     * @return bool True if successful, false if not.
     */
    public static function clear(string $stat = self::KEYNAME): bool
    {
        return parent::clear($stat);
    }
}
