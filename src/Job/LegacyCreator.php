<?php

namespace Resque\Job;

use Resque\Job;
use Resque\ResqueException;

/**
 * A Job creator implementation which maintains the legacy Resque instance API.
 *
 * @author  Mark Rigby-Jones <mark@rigby-jones.net>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class LegacyCreator implements CreatorInterface
{
    /**
     * Create a new job instance.
     *
     * @param Job $job The job to be processed.
     * @throws ResqueException If the class is not found or has no perform().
     * @return PerformerInterface A job instance.
     */
    public function createJob(Job $job): object
    {
        $class = $job->getClass();
        $arguments = $job->getArguments();
        /** @var AbstractLegacyPerformer */
        $instance = null;

        if (class_exists('Resque_Job_Creator')) {
            $instance = \Resque_Job_Creator::createJob($class, $arguments);
        } elseif (!class_exists($class)) {
            throw new ResqueException(
                "Could not find job class $class."
            );
        } elseif (!method_exists($class, 'perform')) {
            throw new ResqueException(
                "Job class $class does not contain a perform method."
            );
        } else {
            $instance = new $class();
        }

        $instance->job = $job;
        $instance->args = $arguments;
        $instance->queue = $job->getQueue();

        return $instance;
    }
}
