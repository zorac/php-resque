<?php

namespace Resque;

/**
 * Resque event/plugin system class
 *
 * @author  Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Event
{
    /**
     * @var array Array containing all registered callbacks, indexked by event
     *      name.
     */
    private static $events = [];

    /**
     * Raise a given event with the supplied data.
     *
     * @param string $event Name of event to be raised.
     * @param mixed $data Optional, any data that should be passed to each
     *      callback.
     * @return bool True
     */
    public static function trigger(string $event, $data = null) : bool
    {
        if (!is_array($data)) {
            $data = [$data];
        }

        if (empty(self::$events[$event])) {
            return true;
        }

        foreach (self::$events[$event] as $callback) {
            if (!is_callable($callback)) {
                continue;
            }

            call_user_func_array($callback, $data);
        }

        return true;
    }

    /**
     * Listen in on a given event to have a specified callback fired.
     *
     * @param string $event Name of event to listen on.
     * @param callable $callback Any callback callable by call_user_func_array.
     * @return bool True
     */
    public static function listen(string $event, callable $callback) : bool
    {
        if (!isset(self::$events[$event])) {
            self::$events[$event] = [];
        }

        self::$events[$event][] = $callback;

        return true;
    }

    /**
     * Stop a given callback from listening on a specific event.
     *
     * @param string $event Name of event.
     * @param callable $callback The callback as defined when listen() was
     *      called.
     * @return bool True
     */
    public static function stopListening(
        string $event,
        callable $callback
    ) : bool {
        if (!isset(self::$events[$event])) {
            return true;
        }

        $key = array_search($callback, self::$events[$event]);

        if ($key !== false) {
            unset(self::$events[$event][$key]);
        }

        return true;
    }

    /**
     * Call all registered listeners.
     */
    public static function clearListeners()
    {
        self::$events = [];
    }
}
