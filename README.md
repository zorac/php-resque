# Resque for PHP

[![Software license][ico-license]](LICENSE)
[![PHP version][ico-php]][link-php]
[![Latest release][ico-packagist]][link-packagist]
[![Build status][ico-travis]][link-travis]

Resque is a Redis-backed library for creating background jobs, placing those
jobs on multiple queues, and processing them later.

See also the [change log](CHANGELOG.md) and [upgrade guide](UPGRADING.md).

## Version 3 is currently in Alpha

This is version 3.x-alpha of php-resque, and is in early development.
Do **not** use this in production, as major breaking changes are expected.

## Background

This version of php-resque is a fork of
[php-resque-ex](https://github.com/wa0x6e/php-resque-ex),
which is in turn a fork of the original
[php-resque](https://github.com/chrisboulton/php-resque)
by chrisboulton. See the
[original README](https://github.com/chrisboulton/php-resque/blob/master/README.md)
for more information.

This fork additionally switches to Predis for the backend, updates to use
namespaced code and backports some other features from the lastest
[php-resque](https://github.com/resque/php-resque).

NB. Maintaining complete backwards compatability -- other than in respect to the
data format in Redis -- is **not** a goal of this fork. Major version upgrades
*will* include breaking changes. Support for discontinued version of PHP may be
dropped in minor version upgrades.

## Additional features

This fork provides some additional features:

### Support of Predis

This port uses [Predis](https://github.com/nrk/predis) for its backend
connection to Redis.

### Powerful logging

Instead of piping STDOUT output to a file, you can log directly to a database,
or send them elsewhere via a socket. We use
[Monolog](https://github.com/Seldaek/monolog)
to manage all the logging. See their documentation to see all the available
handlers.

Log entries are augmented with more informations, and associated with a worker,
a queue, and a job ID if any.

### Job creation delegation

#### Modern method

Create a class which implements `Resque\Job\CreatorInterface`, and inject it
into the worker, e.g.:

```php
$worker = new Resque\Worker($queues);
$creator = new My\Creator();
$worker->setCreator($creator);
```

#### Legacy method

If the `Resque_Job_Creator` class exists and is found by Resque, all jobs
creation will be delegated to this class.

The best way to inject this class is to include it in you `APP_INCLUDE` file.

Class content is:

```php
class Resque_Job_Creator
{
    /**
     * Create a new Resque Job.
     *
     * @param string $className Your job class name, the second argument when
     *      enqueuing a job.
     * @param array<mixed> $args The arguments passed to your job.
     */
    public static function createJob($className, $args) {
        return new $className();
    }
}
```

This is pretty useful when your autoloader can not load the class, like when
classname doesn't match its filename. Some framework, like CakePHP, uses
`PluginName.ClassName` convention for classname, and require special handling
before loading.

### Failed jobs logs

You can easily retrieve logs for a failed jobs in the redis database, their
keys are named after their job ID. Each failed log will expire after 2 weeks to
save space.

### Advanced queue filtering

Queue names can include the wildcard character `*`, which will cause the worker
to fetch the lsit of all queues, and process all of those which match in a
random order. Each `*` matches zero or more characters; you can have multiple
wildcards, and specifically a queue name of just `*` whill match all queues.
Additionally you can exclude queues by prefixing the name with a `!`; these
exclusion patterns may also contain wildcards, and can appear anywhere in the
queue list. Exclusions only affect wildcard patterns.

As an example, if the queues are set as follows:
```
QUEUES="system:high,*:high,*,system:low,!*:low"
```
Then the queues will be processed by the worker will be (in order of priority):
1. `system:high`
2. All other queues ending in `:high`, in a random order.
3. All other queues *not* ending in `:low`, in a random order.
4. `system:low`

### Enhanced graceful shutdown

The default graceful shutdown process (triggered by sending a `SIGTERM` to the
worker process) waits for `$worker->gracefulDelay` seconds (default five)
before forcibly killing the child process with a `SIGKILL`. This will allow
short-running jobs to complete whilst still allowing the worker to exit in a
reasonable amount of time.

If you want your jobs to be able to gracefully shut themselves down, say to
ensure that logging or cleanup is pefrormed even if the job has to be
premeturely terminated, then you can set `$worker->gracefulSignal` to the
signal you would like to be sent instead of `SIGKILL`. After a further delay of
`$worker->gracefulDelayTwo` seconds (default two) a final kill signal will be
sent to the child and the worker will exit.

## Installation

The easiest way is using composer, by adding the following to your
`composer.json`:

```json
    "require": {
        "zorac/php-resque": "^3.0"
    }
```

#### Warning

php-resque requires the pcntl php extension, not available on Windows platform.
Composer installation will fail if you're trying to install this package on
Windows machine. If you still want to continue with the installation at your
own risk, execute the composer install command with the `--ignore-platform-reqs`
option.

## Usage

### Logging

Use the same way as the original port, with additional ENV :

* `LOGHANDLER`: Specify the handler to use for logging (File, MongoDB,
    Socket, etc...). See [Monolog](https://github.com/Seldaek/monolog#handlers)
    doc for all available handlers. `LOGHANDLER` is the name of the handler,
    without the "Handler" part. To use CubeHandler, just type "Cube".
* `LOGHANDLERTARGET`: Information used by the handler to connect to the
    database. Depends on the type of loghandler. If it's the
    *RotatingFileHandler*, the target will be the filename. If it's CubeHandler,
    target will be a udp address. Refer to each Handler to see what type of
    argument their `__construct()` method requires.
* `LOGGING`: This environment variable must be set in order to enable logging
    via Monolog. i.e `LOGGING=1`

If one of these two environement variable is missing, it will default to
*RotatingFile* Handler.

### Redis backend

* `REDIS_BACKEND`: hostname of your Redis database, or Predis DSN
* `REDIS_DATABASE`: To select another redis database (default 0)
* `REDIS_NAMESPACE`: To set a different namespace for the keys (default to
    *resque*)

## Requirements

* PHP 7.2+
* Redis 2.2+

## Contributors

* [chrisboulton](https://github.com/chrisboulton/php-resque) for the original
    port
* [wa0x6e](https://github.com/wa0x6e/php-resque-ex) for php-resque-ex
* zorac

[ico-license]: https://img.shields.io/github/license/zorac/php-resque.svg?style=flat-square
[ico-php]: https://img.shields.io/packagist/php-v/zorac/php-resque.svg?style=flat-square
[ico-packagist]: https://img.shields.io/packagist/v/zorac/php-resque.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/com/zorac/php-resque.svg?style=flat-square
[link-php]: https://www.php.net/
[link-packagist]: https://packagist.org/packages/zorac/php-resque
[link-travis]: https://travis-ci.com/zorac/php-resque
