<?php

namespace Resque;

use Resque\Test\TestCase;

/**
 * Resque\Stat tests.
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class StatTest extends TestCase
{
    public function testStatCanBeIncremented(): void
    {
        Stat::incr('test_incr');
        self::assertEquals(1, self::$redis->get('stat:test_incr'));
    }

    public function testStatCanBeIncrementedByX(): void
    {
        self::$redis->set('stat:test_incrX', '10');
        Stat::incr('test_incrX', 11);
        self::assertEquals(21, self::$redis->get('stat:test_incrX'));
    }

    public function testStatCanBeDecremented(): void
    {
        self::$redis->set('stat:test_decr', '22');
        Stat::decr('test_decr');
        self::assertEquals(21, self::$redis->get('stat:test_decr'));
    }

    public function testStatCanBeDecrementedByX(): void
    {
        self::$redis->set('stat:test_decrX', '22');
        Stat::decr('test_decrX', 11);
        self::assertEquals(11, self::$redis->get('stat:test_decrX'));
    }

    public function testGetStatByName(): void
    {
        self::$redis->set('stat:test_get', '100');
        self::assertEquals(100, Stat::get('test_get'));
    }

    public function testGetUnknownStatReturns0(): void
    {
        self::assertEquals(0, Stat::get('test_get_unknown'));
    }
}
