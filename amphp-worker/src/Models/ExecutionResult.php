<?php
/**
 * Execution Result data model.
 */

declare(strict_types=1);

namespace CronService\Worker\Models;

/**
 * Execution Result class.
 */
class ExecutionResult
{
    public readonly bool $success;
    public readonly int $statusCode;
    public readonly ?string $body;
    public readonly int $durationMs;
    public readonly ?string $errorMessage;
    public readonly bool $timeout;

    /**
     * Constructor.
     *
     * @param bool        $success      Whether execution succeeded.
     * @param int         $statusCode   HTTP status code.
     * @param string|null $body         Response body.
     * @param int         $durationMs   Duration in milliseconds.
     * @param string|null $errorMessage Error message if failed.
     * @param bool        $timeout      Whether the request timed out.
     */
    public function __construct(
        bool $success,
        int $statusCode,
        ?string $body,
        int $durationMs,
        ?string $errorMessage = null,
        bool $timeout = false
    ) {
        $this->success = $success;
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->durationMs = $durationMs;
        $this->errorMessage = $errorMessage;
        $this->timeout = $timeout;
    }

    /**
     * Convert to array for API submission.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status_code' => $this->statusCode,
            'body' => $this->body,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
            'timeout' => $this->timeout,
        ];
    }
}
