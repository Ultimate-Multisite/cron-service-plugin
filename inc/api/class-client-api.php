<?php
/**
 * Client API for handling requests from client sites.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\API;

/**
 * Client API class.
 */
class Client_API {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'cron-service/v1';

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Maximum requests per window.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX = 30;

	/**
	 * OAuth handler instance.
	 *
	 * @var OAuth_Handler
	 */
	private OAuth_Handler $oauth_handler;

	/**
	 * Constructor.
	 *
	 * @param OAuth_Handler $oauth_handler OAuth handler instance.
	 */
	public function __construct(OAuth_Handler $oauth_handler) {
		$this->oauth_handler = $oauth_handler;
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Register a new site.
		register_rest_route(
			self::REST_NAMESPACE,
			'/register',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_register'],
				'permission_callback' => [$this, 'check_oauth_permission'],
			]
		);

		// Unregister a site.
		register_rest_route(
			self::REST_NAMESPACE,
			'/unregister',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_unregister'],
				'permission_callback' => [$this, 'check_api_key_permission'],
			]
		);

		// List user's registered sites.
		register_rest_route(
			self::REST_NAMESPACE,
			'/sites',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_list_sites'],
				'permission_callback' => [$this, 'check_oauth_permission'],
			]
		);

		// Get site details.
		register_rest_route(
			self::REST_NAMESPACE,
			'/sites/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_get_site'],
				'permission_callback' => [$this, 'check_api_key_permission'],
			]
		);

		// Update site schedules.
		register_rest_route(
			self::REST_NAMESPACE,
			'/sites/(?P<id>\d+)/schedules',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_update_schedules'],
				'permission_callback' => [$this, 'check_api_key_permission'],
			]
		);

		// Get site execution logs.
		register_rest_route(
			self::REST_NAMESPACE,
			'/sites/(?P<id>\d+)/logs',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_get_logs'],
				'permission_callback' => [$this, 'check_api_key_permission'],
			]
		);

		// Site heartbeat.
		register_rest_route(
			self::REST_NAMESPACE,
			'/heartbeat',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_heartbeat'],
				'permission_callback' => [$this, 'check_api_key_permission'],
			]
		);

		// Get notification config.
		register_rest_route(
			self::REST_NAMESPACE,
			'/sites/(?P<id>\d+)/notifications',
			[
				[
					'methods'             => 'GET',
					'callback'            => [$this, 'handle_get_notifications'],
					'permission_callback' => [$this, 'check_api_key_permission'],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [$this, 'handle_update_notifications'],
					'permission_callback' => [$this, 'check_api_key_permission'],
				],
			]
		);
	}

	/**
	 * Check OAuth permission.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error
	 */
	public function check_oauth_permission(\WP_REST_Request $request): bool|\WP_Error {
		$user_id = $this->oauth_handler->validate_oauth_token($request);

		if (!$user_id) {
			return new \WP_Error(
				'unauthorized',
				__('Invalid authentication credentials.', 'um-cron-service'),
				['status' => 401]
			);
		}

		// Store user ID for later use.
		$request->set_param('authenticated_user_id', $user_id);

		return true;
	}

	/**
	 * Check API key permission.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error
	 */
	public function check_api_key_permission(\WP_REST_Request $request): bool|\WP_Error {
		$auth_header = $request->get_header('Authorization');

		if (empty($auth_header) || !preg_match('/^Basic\s+(.+)$/i', $auth_header, $matches)) {
			return new \WP_Error(
				'unauthorized',
				__('API key authentication required.', 'um-cron-service'),
				['status' => 401]
			);
		}

		$decoded = base64_decode($matches[1]);
		if ($decoded === false) {
			return new \WP_Error(
				'unauthorized',
				__('Invalid authentication credentials.', 'um-cron-service'),
				['status' => 401]
			);
		}

		$parts = explode(':', $decoded, 2);
		if (count($parts) !== 2) {
			return new \WP_Error(
				'unauthorized',
				__('Invalid authentication credentials.', 'um-cron-service'),
				['status' => 401]
			);
		}

		$site = $this->oauth_handler->get_site_from_credentials($parts[0], $parts[1]);

		if (!$site) {
			return new \WP_Error(
				'unauthorized',
				__('Invalid API credentials.', 'um-cron-service'),
				['status' => 401]
			);
		}

		// Store site for later use.
		$request->set_param('authenticated_site', $site);
		$request->set_param('authenticated_user_id', $site->user_id);

		return true;
	}

	/**
	 * Handle site registration.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_register(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$user_id = $request->get_param('authenticated_user_id');
		$data    = json_decode($request->get_body(), true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new \WP_Error('invalid_json', __('Invalid JSON payload.', 'um-cron-service'), ['status' => 400]);
		}

		// Validate required fields.
		$required = ['site_url', 'cron_url'];
		foreach ($required as $field) {
			if (empty($data[$field])) {
				return new \WP_Error(
					'missing_field',
					/* translators: %s: field name */
					sprintf(__('Missing required field: %s', 'um-cron-service'), $field),
					['status' => 400]
				);
			}
		}

		// Check if user has active subscription.
		if (!$this->oauth_handler->user_has_active_subscription($user_id)) {
			return new \WP_Error(
				'no_subscription',
				__('Active cron service subscription required.', 'um-cron-service'),
				['status' => 403]
			);
		}

		// Check site limit.
		$site_limit    = $this->oauth_handler->get_user_site_limit($user_id);
		$current_count = $this->get_user_site_count($user_id);

		if ($current_count >= $site_limit) {
			return new \WP_Error(
				'site_limit_reached',
				__('Site limit reached for your subscription.', 'um-cron-service'),
				['status' => 403]
			);
		}

		$site_url  = esc_url_raw($data['site_url']);
		$site_hash = $this->oauth_handler->generate_site_hash($site_url);

		// Check if site already registered.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cron_service_sites WHERE site_hash = %s",
				$site_hash
			)
		);

		if ($existing) {
			return new \WP_Error(
				'site_exists',
				__('This site is already registered.', 'um-cron-service'),
				['status' => 409]
			);
		}

		$api_key    = $this->oauth_handler->generate_api_key();
		$api_secret = $this->oauth_handler->generate_api_secret();

		$insert_data = [
			'user_id'                => $user_id,
			'site_url'               => $site_url,
			'site_hash'              => $site_hash,
			'api_key'                => $api_key,
			'api_secret'             => $api_secret,
			'is_network_registration'=> !empty($data['is_network_registration']) ? 1 : 0,
			'granularity'            => in_array($data['granularity'] ?? 'network', ['network', 'site'], true)
				? $data['granularity']
				: 'network',
			'status'                 => 'active',
			'cron_url'               => esc_url_raw($data['cron_url']),
			'timezone'               => sanitize_text_field($data['timezone'] ?? 'UTC'),
			'date_created'           => current_time('mysql', true),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			"{$wpdb->prefix}cron_service_sites",
			$insert_data,
			['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
		);

		if ($result === false) {
			return new \WP_Error(
				'insert_failed',
				__('Failed to register site.', 'um-cron-service'),
				['status' => 500]
			);
		}

		$site_id = $wpdb->insert_id;

		// Create default notification config.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}cron_service_notification_configs",
			[
				'site_id'      => $site_id,
				'user_id'      => $user_id,
				'date_created' => current_time('mysql', true),
			],
			['%d', '%d', '%s']
		);

		return new \WP_REST_Response(
			[
				'success'    => true,
				'site_id'    => $site_id,
				'api_key'    => $api_key,
				'api_secret' => $api_secret,
			],
			201
		);
	}

	/**
	 * Handle site unregistration.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_unregister(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$site = $request->get_param('authenticated_site');

		// Delete associated records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete("{$wpdb->prefix}cron_service_schedules", ['site_id' => $site->id], ['%d']);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete("{$wpdb->prefix}cron_service_notification_configs", ['site_id' => $site->id], ['%d']);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete("{$wpdb->prefix}cron_service_sites", ['id' => $site->id], ['%d']);

		return new \WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Handle list sites.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_list_sites(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$user_id = $request->get_param('authenticated_user_id');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sites = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, site_url, status, granularity, last_heartbeat, date_created
				FROM {$wpdb->prefix}cron_service_sites
				WHERE user_id = %d
				ORDER BY date_created DESC",
				$user_id
			)
		);

		return new \WP_REST_Response(['sites' => $sites], 200);
	}

	/**
	 * Handle get site.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_site(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$site    = $request->get_param('authenticated_site');
		$site_id = (int) $request->get_param('id');

		// Ensure user owns this site.
		if ((int) $site->id !== $site_id) {
			return new \WP_Error('forbidden', __('Access denied.', 'um-cron-service'), ['status' => 403]);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$site_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, site_url, status, granularity, last_heartbeat, cron_url, timezone, date_created
				FROM {$wpdb->prefix}cron_service_sites
				WHERE id = %d",
				$site_id
			)
		);

		if (!$site_data) {
			return new \WP_Error('not_found', __('Site not found.', 'um-cron-service'), ['status' => 404]);
		}

		return new \WP_REST_Response(['site' => $site_data], 200);
	}

	/**
	 * Handle update schedules.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update_schedules(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$site      = $request->get_param('authenticated_site');
		$site_id   = (int) $request->get_param('id');
		$schedules = json_decode($request->get_body(), true);

		// Ensure user owns this site.
		if ((int) $site->id !== $site_id) {
			return new \WP_Error('forbidden', __('Access denied.', 'um-cron-service'), ['status' => 403]);
		}

		if (!is_array($schedules)) {
			return new \WP_Error('invalid_data', __('Schedules must be an array.', 'um-cron-service'), ['status' => 400]);
		}

		// Start transaction.
		$wpdb->query('START TRANSACTION');

		try {
			// Deactivate all existing schedules.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				"{$wpdb->prefix}cron_service_schedules",
				['is_active' => 0, 'date_modified' => current_time('mysql', true)],
				['site_id' => $site_id],
				['%d', '%s'],
				['%d']
			);

			// Insert or update schedules.
			foreach ($schedules as $schedule) {
				if (empty($schedule['hook_name']) || empty($schedule['next_run'])) {
					continue;
				}

				$schedule_data = [
					'site_id'          => $site_id,
					'hook_name'        => sanitize_text_field($schedule['hook_name']),
					'next_run'         => sanitize_text_field($schedule['next_run']),
					'recurrence'       => sanitize_text_field($schedule['recurrence'] ?? ''),
					'interval_seconds' => absint($schedule['interval_seconds'] ?? 0),
					'args'             => $schedule['args'] ?? null,
					'is_active'        => 1,
					'date_modified'    => current_time('mysql', true),
				];

				// Check if schedule exists.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}cron_service_schedules
						WHERE site_id = %d AND hook_name = %s",
						$site_id,
						$schedule_data['hook_name']
					)
				);

				if ($existing) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update(
						"{$wpdb->prefix}cron_service_schedules",
						$schedule_data,
						['id' => $existing],
						['%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s'],
						['%d']
					);
				} else {
					$schedule_data['date_created'] = current_time('mysql', true);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert(
						"{$wpdb->prefix}cron_service_schedules",
						$schedule_data,
						['%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
					);
				}
			}

			$wpdb->query('COMMIT');
		} catch (\Exception $e) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('update_failed', $e->getMessage(), ['status' => 500]);
		}

		return new \WP_REST_Response(['success' => true, 'count' => count($schedules)], 200);
	}

	/**
	 * Handle get logs.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_logs(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$site    = $request->get_param('authenticated_site');
		$site_id = (int) $request->get_param('id');
		$limit   = min(100, absint($request->get_param('limit') ?? 50));
		$offset  = absint($request->get_param('offset') ?? 0);

		// Ensure user owns this site.
		if ((int) $site->id !== $site_id) {
			return new \WP_Error('forbidden', __('Access denied.', 'um-cron-service'), ['status' => 403]);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, hook_name, execution_time, duration_ms, http_status_code, status, error_message
				FROM {$wpdb->prefix}cron_service_execution_logs
				WHERE site_id = %d
				ORDER BY execution_time DESC
				LIMIT %d OFFSET %d",
				$site_id,
				$limit,
				$offset
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cron_service_execution_logs WHERE site_id = %d",
				$site_id
			)
		);

		return new \WP_REST_Response([
			'logs'  => $logs,
			'total' => (int) $total,
			'limit' => $limit,
			'offset'=> $offset,
		], 200);
	}

	/**
	 * Handle heartbeat.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_heartbeat(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$site = $request->get_param('authenticated_site');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}cron_service_sites",
			[
				'last_heartbeat' => current_time('mysql', true),
				'date_modified'  => current_time('mysql', true),
			],
			['id' => $site->id],
			['%s', '%s'],
			['%d']
		);

		return new \WP_REST_Response([
			'success'   => true,
			'timestamp' => time(),
		], 200);
	}

	/**
	 * Handle get notifications.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_notifications(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$site    = $request->get_param('authenticated_site');
		$site_id = (int) $request->get_param('id');

		if ((int) $site->id !== $site_id) {
			return new \WP_Error('forbidden', __('Access denied.', 'um-cron-service'), ['status' => 403]);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$config = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cron_service_notification_configs WHERE site_id = %d",
				$site_id
			)
		);

		return new \WP_REST_Response(['notifications' => $config], 200);
	}

	/**
	 * Handle update notifications.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update_notifications(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$site    = $request->get_param('authenticated_site');
		$site_id = (int) $request->get_param('id');
		$data    = json_decode($request->get_body(), true);

		if ((int) $site->id !== $site_id) {
			return new \WP_Error('forbidden', __('Access denied.', 'um-cron-service'), ['status' => 403]);
		}

		$update_data = [
			'notify_on_failure'  => !empty($data['notify_on_failure']) ? 1 : 0,
			'failure_threshold'  => absint($data['failure_threshold'] ?? 3),
			'notify_on_recovery' => !empty($data['notify_on_recovery']) ? 1 : 0,
			'daily_summary'      => !empty($data['daily_summary']) ? 1 : 0,
			'email_addresses'    => sanitize_textarea_field($data['email_addresses'] ?? ''),
			'webhook_url'        => esc_url_raw($data['webhook_url'] ?? ''),
			'slack_webhook'      => esc_url_raw($data['slack_webhook'] ?? ''),
			'date_modified'      => current_time('mysql', true),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}cron_service_notification_configs",
			$update_data,
			['site_id' => $site_id],
			['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
			['%d']
		);

		return new \WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Get user's registered site count.
	 *
	 * @param int $user_id The user ID.
	 * @return int The site count.
	 */
	private function get_user_site_count(int $user_id): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cron_service_sites WHERE user_id = %d AND status != 'suspended'",
				$user_id
			)
		);
	}
}
