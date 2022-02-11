## 2.11.1 (2021-11-11)

* Retry Redis commands if the server is loading its dataset into memory. This
  fixes a potential crash if a worker is started immediately after Redis and
  there's a large amount of data for it to load.
* Upgraded `php-cs-fixer` to the latest major version and resolved some linting
  issues.

## 2.11.0 (2021-10-06)

* **PHP 7.2 or later is now required.**
* Fix Redis reconnection in the SIGPIPE handler not having been updated to
  support Predis.
* Fix a failing test on PHP 8.
* Fix some issues flagged by phpstan.

## 2.10.5 (2020-11-06)

* Merged the 'reserved' and 'got' log entries as type: got, level: debug.
* Ensured that all job-execution log entries are decorated with worker name,
  queue, and job class.
* Added an error log entry in case of fork failure.

## 2.10.4 (2020-08-04)

* Prevent live-lock if blocking with no queues
* Remove Resque version from process titles
* Improvements to stack trace formatter

## 2.10.3 (2020-07-28)

* Remove full arguments logging from 'failed' log entries.
* Added PSR-compliant logging of exceptions.

## 2.10.2 (2020-07-27)

* Changed the existing worker 'got' log entry (which includes a JSON dump of
  the complete job arguments, and thus may get *very* large) to be at the debug
  level, and added a new 'reserved' one at the info level.
* Fixed the process title not getting set in blocking mode.

## 2.10.1 (2020-07-12)

* Greatly enhanced functionality of wildcards in the queue list, and added
  support for exclusions
* Marked many methods and properties of `Resque\Worker` as `@deprecated`;
  **all deprecated code will be removed in version 3.0**
* Added `WorkerFactory` as a dependency injection compatible way of creating
  `Worker`s, and migrated in code from `Worker`'s static and pruning methods
* Cleaned up the changelog, and added an upgrading guide

## 2.9.1 (2020-04-21)

* Fixed a bug in `LegacyCreator`
* Internal improvements to `Worker` logging
* Yet more code cleanup

## 2.9.0 (2020-03-09)

* Added `PerformerInterface` and related abstract classes
* Added `CreatorInterface` as a modern alternative to `Resque_Job_Creator`
* More code cleanup

## 2.8.1 (2020-03-03)

* Code and documentation cleanup

## 2.8.0 (2020-02-21)

* `Worker` now catches *all* `Throwable`s thrown by jobs, not just `Exception`s
* Fixed compatability with Monolog 2
* Code cleanup

## 2.7.0 (2019-06-03)

* Backported blocking job reservation from
  [`resque/php-resque`](https://github.com/resque/php-resque)

## 2.6.0 (2019-05-03)

* Migrated JSON handling to a utility class
* `Exception`s will now be logged with a chained stack trace

## 2.5.0 (2019-04-27)

* **PHP 7.1 or later is now required.**
* Refactored the PHPUnit tests to work with the namespaced code
* Got Travis CI working again
* Added [PHP CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer), and
  additional PHPStan rules
* Lots of code cleanup

## 2.4.1 (2019-03-28)

* Added `pruneDeadWorkersOnStartup` option to `Worker`; defaults to true unless
  a custom PID is given when the worker is created

## 2.4.0 (2019-03-11)

* Added `beforeEnqueue`, and pass job ID to `afterEnqueue` event callbacks

## 2.3.0 (2019-03-08)

* Updated signal handling to improve performance and robustness while waiting
  on child processes
* Changed `SIGTERM` behaviour to kill off the child after a brief grace period
  (this improves compatabilty with [Docker](https://www.docker.com), for
  example)

## 2.2.0 (2019-03-01)

* Allowed specifying the hostname and PID for workers
* Failure timestamps are now stored in a more useful format

## 2.1.1 (2019-02-20)

* Minor fixes and cleanup

## 2.1.0 (2019-02-19)

* When the list of queues for a `Worker` includes the `*` wildcard, all queues
  will be checked for jobs in a random order on each attempt, instead of
  alphabetically
* Errors are now stored in JSON format

## 2.0.1 (2019-02-18)

* Started using the [phpstan](https://phpstan.org) static analysis tool
* Cleanup and fixes from phpstan checks

## 2.0.0 (2019-01-31)

* **PHP 5.5 or later is now required.**
* [Predis](https://github.com/nrk/predis) is now used for the back-end Redis
  connections; this allows (with a custom startup script) for the use of
  [sentinel](https://redis.io/topics/sentinel) and other advanced Redis
  features
* Migrated the codebase to fully-namespaced classes
* Assorted cleanup and fixes

## 1.3.0 (2014-01-28)

* Fix #8: Performing a DB select when the DB is set to default '0' is not
  necessary and breaks Twemproxy
* Fix #13: Added PIDFILE writing when child COUNT > 1
* Fix #14: Add bin/resque to composer
* Fix #17: Catch redis connection issue
* Fix #24: Use getmypid to specify a persistent connection unique identifier
* Add redis authentication support

## 1.2.7 (2013-10-15)

* Include the file given by APP_INCLUDE as soon as possible in bin/resque

## 1.2.6 (2013-06-20)

* Update composer dependencies

## 1.2.5 (2013-05-22)

* Drop support of igbinary serializer in failed job trace
* Use ISO-8601 formatted date in log
* Drop .php extension in resque bin filename

> If you're starting your workers manually, use `php bin/resque` instead of
  `php bin/resque.php`

## 1.2.4 (2013-04-141)

* Fix #3 : Logging now honour verbose level

## 1.2.3 (2012-01-31)

* Fix fatal error when updating job status

## 1.2.2 (2012-01-30)

* Add missing autoloader path

## 1.2.1 (2012-01-30)

* Moved top-level resque.php to bin folder
* Detect composer autoloader up to 3 directory level, and fail gracefully if
  not found
* Change some functions scope to allow inheritance

## 1.0.15 (2012-01-23)

* Record job processing time

## 1.0.14 (2012-10-23)

* Add method to get failed jobs details
* Merge v1.2 from parent

## 1.0.13 (2012-10-17)

* Pause and unpause events go into their own log category

## 1.0.12 (2012-10-14)

* Check that `$logger` is not null before using

## 1.0.11 (2012-10-01)

* Update Composer.json

## 1.0.10 (2012-09-27)

* Update Composer.json

## 1.0.9 (2012-09-20)

* Delegate all the MonologHandler creation to MonologInit. (requires a composer
  update).
* Fix stop event that was not logged

## 1.0.8 (2012-09-19)

* In start log, add a new fields for recording queues names

## 1.0.7 (2012-09-10)

* Fix tests

## 1.0.6 (2012-09-10)

* Merge latest commits from php-resque

## 1.0.5 (2012-08-29)

* Add custom redis database and namespace support

## 1.0.4 (2012-08-29)

* Job creation will be delegated to Resque_Job_Creator class if found
* Use persistent connection to Redis

## 1.0.3 (2012-08-26)

* Fix unknown self reference

## 1.0.2 (2012-08-22)

* Don't use persistent connection to redis, because of segfault bug

## 1.0.1 (2012-08-21)

* Output to STDOUT if no log Handler is defined

## 1.0.0 (2012-08-21)

* Initial release
