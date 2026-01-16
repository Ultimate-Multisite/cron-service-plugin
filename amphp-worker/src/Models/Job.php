<?php
/**
 * Job data model.
 */

declare(strict_types=1);

namespace CronService\Worker\Models;

/**
 * Job class representing a scheduled cron job.
 */
class Job
{
    public readonly int $scheduleId;
    public readonly int $siteId;
    public readonly string $hookName;
    public readonly string $nextRun;
    public readonly int $nextRunTimestamp;
    public readonly ?string $recurrence;
    public readonly ?int $intervalSeconds;
    public readonly ?string $args;
    public readonly int $failureCount;
    public readonly string $cronUrl;
    public readonly string $timezone;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $data Job data from API.
     */
    public function __construct(array $data)
    {
        $this->scheduleId = (int) ($data['schedule_id'] ?? 0);
        $this->siteId = (int) ($data['site_id'] ?? 0);
        $this->hookName = $data['hook_name'] ?? '';
        $this->nextRun = $data['next_run'] ?? '';
        $this->nextRunTimestamp = $this->parseTimestamp($this->nextRun, $data['timezone'] ?? 'UTC');
        $this->recurrence = $data['recurrence'] ?? null;
        $this->intervalSeconds = isset($data['interval_seconds']) ? (int) $data['interval_seconds'] : null;
        $this->args = $data['args'] ?? null;
        $this->failureCount = (int) ($data['failure_count'] ?? 0);
        $this->cronUrl = $data['cron_url'] ?? '';
        $this->timezone = $data['timezone'] ?? 'UTC';
    }

    /**
     * Parse timestamp from database datetime string.
     *
     * @param string $datetime Datetime string.
     * @param string $timezone Timezone.
     * @return int Unix timestamp.
     */
    private function parseTimestamp(string $datetime, string $timezone): int
    {
        if (empty($datetime)) {
            return 0;
        }

        try {
            $tz = new \DateTimeZone($timezone);
            $dt = new \DateTime($datetime, new \DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            return strtotime($datetime) ?: 0;
        }
    }

    /**
     * Check if the job is due to run.
     *
     * @param int|null $now Current timestamp.
     * @return bool
     */
    public function isDue(?int $now = null): bool
    {
        $now = $now ?? time();
        return $this->nextRunTimestamp <= $now;
    }

    /**
     * Get seconds until job is due.
     *
     * @param int|null $now Current timestamp.
     * @return int Negative if overdue.
     */
    public function secondsUntilDue(?int $now = null): int
    {
        $now = $now ?? time();
        return $this->nextRunTimestamp - $now;
    }
}
