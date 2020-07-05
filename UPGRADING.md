# Upgrading `php-resque`

## Preparing for the Upgrade to Version 3

Version 3 of `zorac/php-resque` and related packages are currently under
development. The goals for the 3.x development cycle include:

* Removing the use of static methods and refactoring the code to be fully
  compatible with dependency injection.
* Removing the explicit dependency on `monolog/monolog`, and allowing users to
  supply their own `psr/log` compatible logger.
* Better support for running workers as individual Docker containers.
* Providing a modern CLI for working with Resque.
* Adding a way to have regularly-scheduled jobs (like cron).

**Important note: `php-resque` 3.x will require PHP 7.4 or later.**

Where possible, changes that will break backwards compatiblity will be built
first for the 2.x branch. with existing methods and properties left in place
but marked as `@deprecated`. The first step in upgrading to 3.x, then, will be
to upgrade to the latest 2.x release, and resolve all deprecation warnings in
your code. Specific changes include:

* `Worker` objects should not be created directly, either via the constructor,
  or the static methods on that class. Instead, create or inject an instance of
  the new, `WorkerFactory` class, and use the methods on that instead.
* If you're using `Resque_Job_Creator`, replace that with an implementation of
  the new `CreatorInterface`, and inject an instance of your creator class into
  the `WorkerFactory`. `Worker`s will always use a creator object in 3.x, but
  the factory will default to an instance of `LegacyCreator`.
* Resque jobs *should* implement `PerformerInterface`, but this will not be a
  requirement, and the `setUp()` and `tearDown()` methods will remain optional.
  See `AbstractPerformer` and `AbstractLegacyPerformer` as possible ways to
  easily implement this.
* All direct configuration of logging for `Worker`s (including storing logging
  configuration in Redis) is being removed. Instead, simply inject an instance
  of the PSR `LoggerInterface` into your `WorkerFactory` and use that to
  control where your messages are logged, and at what level. In 3.x, the
  factory will default to using a `NullLogger`.

Other changes in 3.x:

* Blocking job reservation will be the default behvaiour, and polling will no
  longer be suppported.

## Upgrading to Version 2

This section primarily covers upgrading to the latest version 2.x release of
[`zorac/php-resque`](https://github.com/zorac/php-resque) from
[`kamisama/php-resque-ex`](https://github.com/wa0x6e/php-resque-ex),
but it will also apply to upgrading from the original
[`chrisboulton/php-resque`](https://github.com/chrisboulton/php-resque)
or one of the other forks of that project.

### Backwards-Incompatible Changes

* PHP 7.1 or later is required (PHP 5.5 or later before 2.5.x).
* [Composer](https://getcomposer.org) is the only supported method of including
  php-resque in a project.
* All the code is now fully namespaced, for example the `Resque` is now
  `Resque\Resque`, and `Resque_Worker` becomes `Resque\Worker`. Your code will
  need to be updated accordingly.
* Workers which have `*` in their queue list will now process all queues in a
  random order (per attempt) rather than alphabetically. If you rely on the old
  behviour, then you'll need to instead list your queues explicitly.

### Noteworthy Improvements

* [Predis](https://github.com/nrk/predis) is used for back-end connections to
  Redis. You can pass a callback to `Resque::setBackend` to have complete
  control over the creation of the client, for example to support sentinel
  or cluster connections.
* Improved signal-handling performance and robustness; `SIGTERM` now has a
  brief grace period before forcibly killing child processes (this improves
  compatabilty with [Docker](https://www.docker.com)).
* Support for blocking job reservation to increase performance by setting the
  environment variable `BLOCKING=TRUE` (ported from
  [`resque/php-resque`](https://github.com/resque/php-resque)).
