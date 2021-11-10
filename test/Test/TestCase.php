<?php

namespace Resque\Test;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Resque\Resque;
use Resque\Redis;

class TestCase extends PHPUnitTestCase
{
    /** @var Redis */
    protected static $redis;
    /** @var bool */
    private static $lastNamespaceWasCustom = false;

    public static function connect(string $namespace = null): void
    {
        if (self::$lastNamespaceWasCustom && !isset($namespace)) {
            self::$lastNamespaceWasCustom = false;
            unset(self::$redis);
        }

        if (!isset(self::$redis)) {
            $backend = getenv('REDIS_BACKEND');
            $database = getenv('REDIS_DATABASE');

            if ($backend === false) {
                $backend = 'localhost:6379';
            }

            if ($database === false) {
                $database = 7;
            } else {
                $database = (int)$database;
            }

            if (!isset($namespace)) {
                $namespace = getenv('REDIS_NAMESPACE');

                if ($namespace === false) {
                    $namespace = 'testResque';
                }
            } else {
                self::$lastNamespaceWasCustom = true;
            }

            Resque::setBackend($backend, $database, $namespace);
            self::$redis = Resque::redis();
        }

        self::$redis->flushDb();
    }

    public function setUp(): void
    {
        self::connect();
    }
}
