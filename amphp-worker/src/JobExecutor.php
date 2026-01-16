<?php
/**
 * Job Executor for making HTTP requests to cron endpoints.
 */

declare(strict_types=1);

namespace CronService\Worker;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Monolog\Logger;

/**
 * Job Executor class.
 */
class JobExecutor
{
    private Config $config;
    private HttpClient $httpClient;
    private Logger $logger;

    /**
     * Constructor.
     *
     * @param Config $config Configuration.
     * @param Logger $logger Logger instance.
     */
    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = HttpClientBuilder::buildDefault();
    }

    /**
     * Execute a cron job.
     *
     * @param Models\Job $job The job to execute.
     * @return Models\ExecutionResult
     */
    public function execute(Models\Job $job): Models\ExecutionResult
    {
        $startTime = hrtime(true);

        $request = new Request($job->cronUrl, 'GET');
        $request->setHeader('User-Agent', 'UltimateMultisite-CronWorker/1.0');
        $request->setHeader('X-WP-Cron-Hook', $job->hookName);

        // Set timeouts.
        $connectTimeout = $this->config->get('connect_timeout') / 1000;
        $transferTimeout = $this->config->get('transfer_timeout') / 1000;

        $request->setTcpConnectTimeout($connectTimeout);
        $request->setTransferTimeout($transferTimeout);

        try {
            $response = $this->httpClient->request($request);
            $body = $response->getBody()->buffer();
            $statusCode = $response->getStatus();

            $endTime = hrtime(true);
            $durationMs = (int) (($endTime - $startTime) / 1_000_000);

            // Consider 2xx and 3xx as success.
            $success = $statusCode >= 200 && $statusCode < 400;

            return new Models\ExecutionResult(
                success: $success,
                statusCode: $statusCode,
                body: $this->truncateBody($body),
                durationMs: $durationMs,
                errorMessage: $success ? null : "HTTP {$statusCode}"
            );
        } catch (\Amp\TimeoutCancellation $e) {
            $endTime = hrtime(true);
            $durationMs = (int) (($endTime - $startTime) / 1_000_000);

            return new Models\ExecutionResult(
                success: false,
                statusCode: 0,
                body: null,
                durationMs: $durationMs,
                errorMessage: 'Request timeout',
                timeout: true
            );
        } catch (\Throwable $e) {
            $endTime = hrtime(true);
            $durationMs = (int) (($endTime - $startTime) / 1_000_000);

            return new Models\ExecutionResult(
                success: false,
                statusCode: 0,
                body: null,
                durationMs: $durationMs,
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Truncate response body to a reasonable size.
     *
     * @param string $body Response body.
     * @return string
     */
    private function truncateBody(string $body): string
    {
        $maxLength = 5000;

        if (strlen($body) <= $maxLength) {
            return $body;
        }

        return substr($body, 0, $maxLength) . '... (truncated)';
    }
}
