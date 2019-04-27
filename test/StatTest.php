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
    public function testStatCanBeIncremented()
    {
        Stat::incr('test_incr');
        $this->assertEquals(1, self::$redis->get('stat:test_incr'));
    }

    public function testStatCanBeIncrementedByX()
    {
        self::$redis->set('stat:test_incrX', '10');
        Stat::incr('test_incrX', 11);
        $this->assertEquals(21, self::$redis->get('stat:test_incrX'));
    }

    public function testStatCanBeDecremented()
    {
        self::$redis->set('stat:test_decr', '22');
        Stat::decr('test_decr');
        $this->assertEquals(21, self::$redis->get('stat:test_decr'));
    }

    public function testStatCanBeDecrementedByX()
    {
        self::$redis->set('stat:test_decrX', '22');
        Stat::decr('test_decrX', 11);
        $this->assertEquals(11, self::$redis->get('stat:test_decrX'));
    }

    public function testGetStatByName()
    {
        self::$redis->set('stat:test_get', '100');
        $this->assertEquals(100, Stat::get('test_get'));
    }

    public function testGetUnknownStatReturns0()
    {
        $this->assertEquals(0, Stat::get('test_get_unknown'));
    }
}
