<?php
/**
 * Worker API for handling requests from the AMPHP worker service.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\API;

/**
 * Worker API class.
 */
class Worker_API {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'cron-worker/v1';

	/**
	 * Worker secret option name.
	 *
	 * @var string
	 */
	const WORKER_SECRET_OPTION = 'um_cron_service_worker_secret';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Get pending jobs.
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/pending',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_get_pending_jobs'],
				'permission_callback' => [$this, 'check_worker_permission'],
				'args'                => [
					'limit' => [
						'default'           => 100,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ($param) {
							return $param > 0 && $param <= 500;
						},
					],
				],
			]
		);

		// Mark job as started.
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/start',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_job_start'],
				'permission_callback' => [$this, 'check_worker_permission'],
			]
		);

		// Report job completion.
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/complete',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_job_complete'],
				'permission_callback' => [$this, 'check_worker_permission'],
			]
		);

		// Report job failure.
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/failed',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_job_failed'],
				'permission_callback' => [$this, 'check_worker_permission'],
			]
		);

		// Worker status/heartbeat.
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_worker_status'],
				'permission_callback' => [$this, 'check_worker_permission'],
			]
		);

		// Get all due jobs (batch).
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/due',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_get_due_jobs'],
				'permission_callback' => [$this, 'check_worker_permission'],
				'args'                => [
					'window' => [
						'default'           => 60, // Seconds ahead to look.
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Check worker permission.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error
	 */
	public function check_worker_permission(\WP_REST_Request $request): bool|\WP_Error {
		$worker_secret = $request->get_header('X-Worker-Secret');
		$worker_id     = $request->get_header('X-Worker-ID');

		if (empty($worker_secret)) {
			return new \WP_Error(
				'unauthorized',
				__('Worker secret required.', 'um-cron-service'),
				['status' => 401]
			);
		}

		$stored_secret = get_option(self::WORKER_SECRET_OPTION);

		if (empty($stored_secret)) {
			// Generate a new secret if none exists.
			$stored_secret = wp_generate_password(64, false);
			update_option(self::WORKER_SECRET_OPTION, $stored_secret);
		}

		if (!hash_equals($stored_secret, $worker_secret)) {
			return new \WP_Error(
				'unauthorized',
				__('Invalid worker secret.', 'um-cron-service'),
				['status' => 401]
			);
		}

		// Store worker ID for logging.
		if ($worker_id) {
			$request->set_param('worker_id', sanitize_text_field($worker_id));
		}

		return true;
	}

	/**
	 * Handle get pending jobs.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_get_pending_jobs(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$limit = $request->get_param('limit');

		// Get jobs that are due to run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.id AS schedule_id,
					s.site_id,
					s.hook_name,
					s.next_run,
					s.recurrence,
					s.interval_seconds,
					s.args,
					s.failure_count,
					site.cron_url,
					site.timezone,
					site.status AS site_status
				FROM {$wpdb->prefix}cron_service_schedules s
				INNER JOIN {$wpdb->prefix}cron_service_sites site ON s.site_id = site.id
				WHERE s.is_active = 1
					AND site.status = 'active'
					AND s.next_run <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)
					AND s.last_status != 'running'
				ORDER BY s.next_run ASC
				LIMIT %d",
				$limit
			)
		);

		return new \WP_REST_Response([
			'jobs'      => $jobs,
			'count'     => count($jobs),
			'timestamp' => time(),
		], 200);
	}

	/**
	 * Handle get due jobs (jobs that need to run now).
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_get_due_jobs(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$window = $request->get_param('window');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.id AS schedule_id,
					s.site_id,
					s.hook_name,
					s.next_run,
					s.recurrence,
					s.interval_seconds,
					s.args,
					s.failure_count,
					site.cron_url,
					site.timezone
				FROM {$wpdb->prefix}cron_service_schedules s
				INNER JOIN {$wpdb->prefix}cron_service_sites site ON s.site_id = site.id
				WHERE s.is_active = 1
					AND site.status = 'active'
					AND s.next_run <= DATE_ADD(NOW(), INTERVAL %d SECOND)
					AND s.last_status NOT IN ('running')
				ORDER BY s.next_run ASC",
				$window
			)
		);

		return new \WP_REST_Response([
			'jobs'      => $jobs,
			'count'     => count($jobs),
			'timestamp' => time(),
		], 200);
	}

	/**
	 * Handle job start.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_job_start(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$schedule_id = (int) $request->get_param('id');
		$worker_id   = $request->get_param('worker_id');

		// Mark as running (atomic update to prevent duplicates).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}cron_service_schedules
				SET last_status = 'running', last_execution = NOW(), date_modified = NOW()
				WHERE id = %d AND last_status != 'running'",
				$schedule_id
			)
		);

		if ($updated === 0) {
			return new \WP_Error(
				'already_running',
				__('Job is already running or does not exist.', 'um-cron-service'),
				['status' => 409]
			);
		}

		return new \WP_REST_Response([
			'success'     => true,
			'schedule_id' => $schedule_id,
			'started_at'  => current_time('mysql', true),
		], 200);
	}

	/**
	 * Handle job completion.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_job_complete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$schedule_id = (int) $request->get_param('id');
		$worker_id   = $request->get_param('worker_id');
		$data        = json_decode($request->get_body(), true);

		// Get schedule info.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cron_service_schedules WHERE id = %d",
				$schedule_id
			)
		);

		if (!$schedule) {
			return new \WP_Error('not_found', __('Schedule not found.', 'um-cron-service'), ['status' => 404]);
		}

		// Calculate next run time for recurring jobs.
		$next_run = null;
		if (!empty($schedule->interval_seconds) && $schedule->interval_seconds > 0) {
			$next_run = gmdate('Y-m-d H:i:s', time() + $schedule->interval_seconds);
		}

		// Update schedule.
		$update_data = [
			'last_status'   => 'success',
			'failure_count' => 0,
			'date_modified' => current_time('mysql', true),
		];

		if ($next_run) {
			$update_data['next_run'] = $next_run;
		} else {
			// Non-recurring job, deactivate it.
			$update_data['is_active'] = 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}cron_service_schedules",
			$update_data,
			['id' => $schedule_id]
		);

		// Log execution.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}cron_service_execution_logs",
			[
				'site_id'          => $schedule->site_id,
				'schedule_id'      => $schedule_id,
				'hook_name'        => $schedule->hook_name,
				'execution_time'   => current_time('mysql', true),
				'duration_ms'      => absint($data['duration_ms'] ?? 0),
				'http_status_code' => absint($data['status_code'] ?? 0),
				'response_body'    => isset($data['body']) ? substr($data['body'], 0, 5000) : null,
				'status'           => 'success',
				'worker_id'        => $worker_id,
				'date_created'     => current_time('mysql', true),
			],
			['%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
		);

		// Check if site was in failure state and trigger recovery notification.
		$this->maybe_send_recovery_notification($schedule->site_id, $schedule_id);

		return new \WP_REST_Response([
			'success'  => true,
			'next_run' => $next_run,
		], 200);
	}

	/**
	 * Handle job failure.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_job_failed(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$schedule_id = (int) $request->get_param('id');
		$worker_id   = $request->get_param('worker_id');
		$data        = json_decode($request->get_body(), true);

		// Get schedule info.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cron_service_schedules WHERE id = %d",
				$schedule_id
			)
		);

		if (!$schedule) {
			return new \WP_Error('not_found', __('Schedule not found.', 'um-cron-service'), ['status' => 404]);
		}

		$failure_count = $schedule->failure_count + 1;
		$status        = $data['timeout'] ?? false ? 'timeout' : 'failed';

		// Determine retry delay based on failure count.
		$retry_delays = [5, 30, 120, 300, 600]; // Seconds.
		$delay_index  = min($failure_count - 1, count($retry_delays) - 1);
		$retry_delay  = $retry_delays[$delay_index];
		$next_run     = gmdate('Y-m-d H:i:s', time() + $retry_delay);

		// If too many failures, pause the schedule.
		$max_failures = apply_filters('um_cron_service_max_failures', 10);
		$is_active    = $failure_count < $max_failures ? 1 : 0;

		// Update schedule.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}cron_service_schedules",
			[
				'last_status'   => $status,
				'failure_count' => $failure_count,
				'next_run'      => $next_run,
				'is_active'     => $is_active,
				'date_modified' => current_time('mysql', true),
			],
			['id' => $schedule_id],
			['%s', '%d', '%s', '%d', '%s'],
			['%d']
		);

		// Log execution.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}cron_service_execution_logs",
			[
				'site_id'          => $schedule->site_id,
				'schedule_id'      => $schedule_id,
				'hook_name'        => $schedule->hook_name,
				'execution_time'   => current_time('mysql', true),
				'duration_ms'      => absint($data['duration_ms'] ?? 0),
				'http_status_code' => absint($data['status_code'] ?? 0),
				'response_body'    => isset($data['body']) ? substr($data['body'], 0, 5000) : null,
				'status'           => $status,
				'error_message'    => sanitize_text_field($data['error_message'] ?? ''),
				'worker_id'        => $worker_id,
				'date_created'     => current_time('mysql', true),
			],
			['%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
		);

		// Check if we should send failure notification.
		$this->maybe_send_failure_notification($schedule->site_id, $schedule_id, $failure_count);

		return new \WP_REST_Response([
			'success'       => true,
			'failure_count' => $failure_count,
			'next_retry'    => $next_run,
			'is_active'     => (bool) $is_active,
		], 200);
	}

	/**
	 * Handle worker status update.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_worker_status(\WP_REST_Request $request): \WP_REST_Response {
		$worker_id = $request->get_param('worker_id');
		$data      = json_decode($request->get_body(), true);

		// Store worker status.
		$status = [
			'worker_id'       => $worker_id,
			'last_heartbeat'  => time(),
			'jobs_processed'  => absint($data['jobs_processed'] ?? 0),
			'jobs_in_queue'   => absint($data['jobs_in_queue'] ?? 0),
			'memory_usage'    => absint($data['memory_usage'] ?? 0),
			'uptime_seconds'  => absint($data['uptime_seconds'] ?? 0),
		];

		set_transient('um_cron_worker_status_' . sanitize_key($worker_id), $status, 120);

		return new \WP_REST_Response([
			'success'   => true,
			'timestamp' => time(),
		], 200);
	}

	/**
	 * Maybe send failure notification.
	 *
	 * @param int $site_id       The site ID.
	 * @param int $schedule_id   The schedule ID.
	 * @param int $failure_count The failure count.
	 * @return void
	 */
	private function maybe_send_failure_notification(int $site_id, int $schedule_id, int $failure_count): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$config = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cron_service_notification_configs WHERE site_id = %d",
				$site_id
			)
		);

		if (!$config || !$config->notify_on_failure) {
			return;
		}

		if ($failure_count < $config->failure_threshold) {
			return;
		}

		// Only send notification once per threshold.
		if ($failure_count !== (int) $config->failure_threshold) {
			return;
		}

		// Trigger notification.
		do_action('um_cron_service_failure_notification', $site_id, $schedule_id, $failure_count, $config);
	}

	/**
	 * Maybe send recovery notification.
	 *
	 * @param int $site_id     The site ID.
	 * @param int $schedule_id The schedule ID.
	 * @return void
	 */
	private function maybe_send_recovery_notification(int $site_id, int $schedule_id): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$config = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cron_service_notification_configs WHERE site_id = %d",
				$site_id
			)
		);

		if (!$config || !$config->notify_on_recovery) {
			return;
		}

		// Check if there was a previous failure.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$last_failure = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cron_service_execution_logs
				WHERE site_id = %d AND schedule_id = %d AND status IN ('failed', 'timeout')
				ORDER BY execution_time DESC
				LIMIT 1",
				$site_id,
				$schedule_id
			)
		);

		if (!$last_failure) {
			return;
		}

		// Trigger notification.
		do_action('um_cron_service_recovery_notification', $site_id, $schedule_id, $config);
	}

	/**
	 * Get the worker secret (for display in admin).
	 *
	 * @return string The worker secret.
	 */
	public static function get_worker_secret(): string {
		$secret = get_option(self::WORKER_SECRET_OPTION);

		if (empty($secret)) {
			$secret = wp_generate_password(64, false);
			update_option(self::WORKER_SECRET_OPTION, $secret);
		}

		return $secret;
	}

	/**
	 * Regenerate the worker secret.
	 *
	 * @return string The new worker secret.
	 */
	public static function regenerate_worker_secret(): string {
		$secret = wp_generate_password(64, false);
		update_option(self::WORKER_SECRET_OPTION, $secret);
		return $secret;
	}
}
