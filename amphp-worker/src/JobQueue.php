<?php
/**
 * Job Queue for managing pending jobs.
 */

declare(strict_types=1);

namespace CronService\Worker;

use Monolog\Logger;

/**
 * Job Queue class.
 */
class JobQueue
{
    /**
     * Queue of jobs indexed by schedule ID.
     *
     * @var array<int, Models\Job>
     */
    private array $jobs = [];

    private Logger $logger;

    /**
     * Constructor.
     *
     * @param Logger $logger Logger instance.
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Add a job to the queue.
     *
     * @param Models\Job $job The job to add.
     */
    public function add(Models\Job $job): void
    {
        // Don't add duplicates.
        if (isset($this->jobs[$job->scheduleId])) {
            return;
        }

        $this->jobs[$job->scheduleId] = $job;
    }

    /**
     * Remove a job from the queue.
     *
     * @param int $scheduleId Schedule ID.
     */
    public function remove(int $scheduleId): void
    {
        unset($this->jobs[$scheduleId]);
    }

    /**
     * Get all jobs that are due to run.
     *
     * @param int|null $timestamp Current timestamp (defaults to now).
     * @return array<Models\Job>
     */
    public function getDueJobs(?int $timestamp = null): array
    {
        $now = $timestamp ?? time();
        $dueJobs = [];

        foreach ($this->jobs as $job) {
            if ($job->isDue($now)) {
                $dueJobs[] = $job;
            }
        }

        // Sort by next_run time.
        usort($dueJobs, fn($a, $b) => $a->nextRunTimestamp <=> $b->nextRunTimestamp);

        return $dueJobs;
    }

    /**
     * Get the count of jobs in the queue.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->jobs);
    }

    /**
     * Check if a job exists in the queue.
     *
     * @param int $scheduleId Schedule ID.
     * @return bool
     */
    public function has(int $scheduleId): bool
    {
        return isset($this->jobs[$scheduleId]);
    }

    /**
     * Clear all jobs from the queue.
     */
    public function clear(): void
    {
        $this->jobs = [];
    }

    /**
     * Get a job by schedule ID.
     *
     * @param int $scheduleId Schedule ID.
     * @return Models\Job|null
     */
    public function get(int $scheduleId): ?Models\Job
    {
        return $this->jobs[$scheduleId] ?? null;
    }
}
