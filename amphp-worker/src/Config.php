<?php
/**
 * Configuration handler.
 */

declare(strict_types=1);

namespace CronService\Worker;

/**
 * Configuration class.
 */
class Config
{
    /**
     * Configuration values.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Default configuration values.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [
        'api_url' => '',
        'worker_secret' => '',
        'connect_timeout' => 10000,
        'transfer_timeout' => 30000,
        'poll_interval' => 5,
        'precision_interval' => 1,
        'heartbeat_interval' => 30,
        'max_concurrent_jobs' => 50,
        'log_file' => null,
        'log_level' => 'INFO',
        'worker_id' => null,
    ];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration array.
     */
    public function __construct(array $config)
    {
        $this->config = array_merge($this->defaults, $config);

        $this->validate();
    }

    /**
     * Validate configuration.
     *
     * @throws \InvalidArgumentException If configuration is invalid.
     */
    private function validate(): void
    {
        if (empty($this->config['api_url'])) {
            throw new \InvalidArgumentException('api_url is required');
        }

        if (empty($this->config['worker_secret'])) {
            throw new \InvalidArgumentException('worker_secret is required');
        }
    }

    /**
     * Get a configuration value.
     *
     * @param string $key     Configuration key.
     * @param mixed  $default Default value if not set.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get all configuration values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }
}
