<?php
/**
 * Main Worker class.
 */

declare(strict_types=1);

namespace CronService\Worker;

use Amp\Future;
use Revolt\EventLoop;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

use function Amp\async;
use function Amp\delay;

/**
 * Main worker class that runs the event loop.
 */
class Worker
{
    private Config $config;
    private ApiClient $apiClient;
    private JobExecutor $executor;
    private JobQueue $queue;
    private Logger $logger;
    private string $workerId;
    private bool $running = false;
    private int $jobsProcessed = 0;
    private int $startTime;

    /**
     * Constructor.
     *
     * @param Config $config Configuration.
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->workerId = $config->get('worker_id') ?? $this->generateWorkerId();
        $this->startTime = time();

        // Initialize logger.
        $this->logger = $this->createLogger();

        // Initialize components.
        $this->apiClient = new ApiClient($config, $this->workerId, $this->logger);
        $this->executor = new JobExecutor($config, $this->logger);
        $this->queue = new JobQueue($this->logger);
    }

    /**
     * Generate a unique worker ID.
     *
     * @return string
     */
    private function generateWorkerId(): string
    {
        return gethostname() . '-' . getmypid() . '-' . substr(md5((string) microtime(true)), 0, 8);
    }

    /**
     * Get the worker ID.
     *
     * @return string
     */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /**
     * Create logger instance.
     *
     * @return Logger
     */
    private function createLogger(): Logger
    {
        $logger = new Logger('cron-worker');

        $logFile = $this->config->get('log_file');
        $logLevel = match (strtoupper($this->config->get('log_level', 'INFO'))) {
            'DEBUG' => Level::Debug,
            'WARNING' => Level::Warning,
            'ERROR' => Level::Error,
            default => Level::Info,
        };

        if ($logFile) {
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $logger->pushHandler(new StreamHandler($logFile, $logLevel));
        }

        // Also log to stdout.
        $logger->pushHandler(new StreamHandler('php://stdout', $logLevel));

        return $logger;
    }

    /**
     * Run the worker.
     */
    public function run(): void
    {
        $this->running = true;
        $this->logger->info('Worker starting', ['worker_id' => $this->workerId]);

        // Schedule polling for new jobs.
        $pollInterval = $this->config->get('poll_interval');
        EventLoop::repeat($pollInterval, function () {
            if (!$this->running) {
                return;
            }
            async(fn() => $this->fetchPendingJobs());
        });

        // Schedule precision job checking (every second).
        $precisionInterval = $this->config->get('precision_interval');
        EventLoop::repeat($precisionInterval, function () {
            if (!$this->running) {
                return;
            }
            async(fn() => $this->processDueJobs());
        });

        // Schedule heartbeat.
        $heartbeatInterval = $this->config->get('heartbeat_interval');
        EventLoop::repeat($heartbeatInterval, function () {
            if (!$this->running) {
                return;
            }
            async(fn() => $this->sendHeartbeat());
        });

        // Initial fetch.
        async(fn() => $this->fetchPendingJobs());

        // Run the event loop.
        EventLoop::run();
    }

    /**
     * Stop the worker.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->logger->info('Worker stopping', [
            'jobs_processed' => $this->jobsProcessed,
            'uptime_seconds' => time() - $this->startTime,
        ]);

        // Give time for pending operations to complete.
        delay(2);

        EventLoop::getDriver()->stop();
    }

    /**
     * Fetch pending jobs from the API.
     */
    private function fetchPendingJobs(): void
    {
        try {
            $jobs = $this->apiClient->fetchPendingJobs();

            foreach ($jobs as $job) {
                $this->queue->add(new Models\Job($job));
            }

            if (count($jobs) > 0) {
                $this->logger->debug('Fetched jobs', ['count' => count($jobs)]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch pending jobs', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process jobs that are due to run.
     */
    private function processDueJobs(): void
    {
        $dueJobs = $this->queue->getDueJobs();
        $maxConcurrent = $this->config->get('max_concurrent_jobs');

        // Limit concurrent executions.
        $jobsToProcess = array_slice($dueJobs, 0, $maxConcurrent);

        foreach ($jobsToProcess as $job) {
            // Remove from queue before processing.
            $this->queue->remove($job->scheduleId);

            // Execute asynchronously.
            async(fn() => $this->executeJob($job));
        }
    }

    /**
     * Execute a single job.
     *
     * @param Models\Job $job The job to execute.
     */
    private function executeJob(Models\Job $job): void
    {
        $this->logger->info('Executing job', [
            'schedule_id' => $job->scheduleId,
            'hook_name' => $job->hookName,
            'cron_url' => $job->cronUrl,
        ]);

        try {
            // Notify API that job is starting.
            $this->apiClient->markJobStarted($job->scheduleId);

            // Execute the cron request.
            $result = $this->executor->execute($job);

            if ($result->success) {
                $this->apiClient->reportJobComplete($job->scheduleId, $result);
                $this->logger->info('Job completed', [
                    'schedule_id' => $job->scheduleId,
                    'duration_ms' => $result->durationMs,
                    'status_code' => $result->statusCode,
                ]);
            } else {
                $this->apiClient->reportJobFailed($job->scheduleId, $result);
                $this->logger->warning('Job failed', [
                    'schedule_id' => $job->scheduleId,
                    'error' => $result->errorMessage,
                ]);
            }

            $this->jobsProcessed++;
        } catch (\Throwable $e) {
            $this->logger->error('Job execution error', [
                'schedule_id' => $job->scheduleId,
                'error' => $e->getMessage(),
            ]);

            // Report failure.
            $result = new Models\ExecutionResult(
                success: false,
                statusCode: 0,
                body: null,
                durationMs: 0,
                errorMessage: $e->getMessage()
            );

            try {
                $this->apiClient->reportJobFailed($job->scheduleId, $result);
            } catch (\Throwable $reportError) {
                $this->logger->error('Failed to report job failure', [
                    'error' => $reportError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send heartbeat to the API.
     */
    private function sendHeartbeat(): void
    {
        try {
            $this->apiClient->sendHeartbeat([
                'jobs_processed' => $this->jobsProcessed,
                'jobs_in_queue' => $this->queue->count(),
                'memory_usage' => memory_get_usage(true),
                'uptime_seconds' => time() - $this->startTime,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send heartbeat', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
