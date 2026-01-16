<?php
/**
 * API Client for communicating with the WordPress plugin.
 */

declare(strict_types=1);

namespace CronService\Worker;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Monolog\Logger;

/**
 * API Client class.
 */
class ApiClient
{
    private Config $config;
    private HttpClient $httpClient;
    private string $workerId;
    private Logger $logger;

    /**
     * Constructor.
     *
     * @param Config $config   Configuration.
     * @param string $workerId Worker ID.
     * @param Logger $logger   Logger instance.
     */
    public function __construct(Config $config, string $workerId, Logger $logger)
    {
        $this->config = $config;
        $this->workerId = $workerId;
        $this->logger = $logger;
        $this->httpClient = HttpClientBuilder::buildDefault();
    }

    /**
     * Fetch pending jobs from the API.
     *
     * @param int $limit Maximum jobs to fetch.
     * @return array<array<string, mixed>>
     */
    public function fetchPendingJobs(int $limit = 100): array
    {
        $url = $this->buildUrl('/jobs/pending', ['limit' => $limit]);
        $response = $this->request('GET', $url);

        return $response['jobs'] ?? [];
    }

    /**
     * Mark a job as started.
     *
     * @param int $scheduleId Schedule ID.
     */
    public function markJobStarted(int $scheduleId): void
    {
        $url = $this->buildUrl("/jobs/{$scheduleId}/start");
        $this->request('POST', $url);
    }

    /**
     * Report job completion.
     *
     * @param int                       $scheduleId Schedule ID.
     * @param Models\ExecutionResult $result     Execution result.
     */
    public function reportJobComplete(int $scheduleId, Models\ExecutionResult $result): void
    {
        $url = $this->buildUrl("/jobs/{$scheduleId}/complete");
        $this->request('POST', $url, $result->toArray());
    }

    /**
     * Report job failure.
     *
     * @param int                       $scheduleId Schedule ID.
     * @param Models\ExecutionResult $result     Execution result.
     */
    public function reportJobFailed(int $scheduleId, Models\ExecutionResult $result): void
    {
        $url = $this->buildUrl("/jobs/{$scheduleId}/failed");
        $this->request('POST', $url, $result->toArray());
    }

    /**
     * Send worker heartbeat.
     *
     * @param array<string, mixed> $status Worker status data.
     */
    public function sendHeartbeat(array $status): void
    {
        $url = $this->buildUrl('/status');
        $this->request('POST', $url, $status);
    }

    /**
     * Build API URL.
     *
     * @param string               $endpoint   API endpoint.
     * @param array<string, mixed> $queryParams Query parameters.
     * @return string
     */
    private function buildUrl(string $endpoint, array $queryParams = []): string
    {
        $baseUrl = rtrim($this->config->get('api_url'), '/');
        $url = $baseUrl . '/wp-json/cron-worker/v1' . $endpoint;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /**
     * Make an API request.
     *
     * @param string               $method HTTP method.
     * @param string               $url    URL.
     * @param array<string, mixed> $data   Request data.
     * @return array<string, mixed>
     * @throws \RuntimeException On API error.
     */
    private function request(string $method, string $url, array $data = []): array
    {
        $request = new Request($url, $method);

        // Add authentication headers.
        $request->setHeader('X-Worker-Secret', $this->config->get('worker_secret'));
        $request->setHeader('X-Worker-ID', $this->workerId);
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('User-Agent', 'UltimateMultisite-CronWorker/1.0');

        // Set timeouts.
        $request->setTcpConnectTimeout($this->config->get('connect_timeout') / 1000);
        $request->setTransferTimeout($this->config->get('transfer_timeout') / 1000);

        // Add body if present.
        if (!empty($data)) {
            $request->setBody(json_encode($data));
        }

        try {
            $response = $this->httpClient->request($request);
            $body = $response->getBody()->buffer();
            $statusCode = $response->getStatus();

            if ($statusCode >= 400) {
                throw new \RuntimeException("API error: HTTP {$statusCode} - {$body}");
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
            }

            return $decoded;
        } catch (\Throwable $e) {
            $this->logger->error('API request failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
