<?php
/**
 * Main Cron Service class.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service;

use UM_Cron_Service\API\Client_API;
use UM_Cron_Service\API\Worker_API;
use UM_Cron_Service\API\OAuth_Handler;
use UM_Cron_Service\Admin\Dashboard_Page;
use UM_Cron_Service\Admin\Sites_List_Page;
use UM_Cron_Service\WooCommerce\Subscription_Handler;
use UM_Cron_Service\Notifications\Notification_Manager;

/**
 * Cron Service main class.
 */
class Cron_Service {

	/**
	 * Single instance of the class.
	 *
	 * @var Cron_Service|null
	 */
	protected static ?Cron_Service $instance = null;

	/**
	 * OAuth handler instance.
	 *
	 * @var OAuth_Handler|null
	 */
	private ?OAuth_Handler $oauth_handler = null;

	/**
	 * Client API instance.
	 *
	 * @var Client_API|null
	 */
	private ?Client_API $client_api = null;

	/**
	 * Worker API instance.
	 *
	 * @var Worker_API|null
	 */
	private ?Worker_API $worker_api = null;

	/**
	 * Subscription handler instance.
	 *
	 * @var Subscription_Handler|null
	 */
	private ?Subscription_Handler $subscription_handler = null;

	/**
	 * Notification manager instance.
	 *
	 * @var Notification_Manager|null
	 */
	private ?Notification_Manager $notification_manager = null;

	/**
	 * Main instance.
	 *
	 * @return Cron_Service
	 */
	public static function get_instance(): Cron_Service {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the service.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->oauth_handler        = new OAuth_Handler();
		$this->client_api           = new Client_API($this->oauth_handler);
		$this->worker_api           = new Worker_API();
		$this->subscription_handler = new Subscription_Handler();
		$this->notification_manager = new Notification_Manager();

		// Register REST API routes.
		add_action('rest_api_init', [$this->client_api, 'register_routes']);
		add_action('rest_api_init', [$this->worker_api, 'register_routes']);

		// Admin pages.
		if (is_admin()) {
			$this->init_admin();
		}

		// Cleanup old logs.
		add_action('um_cron_service_cleanup', [$this, 'cleanup_old_logs']);
	}

	/**
	 * Initialize admin functionality.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_menu_page(
			__('Cron Service', 'um-cron-service'),
			__('Cron Service', 'um-cron-service'),
			'manage_options',
			'um-cron-service',
			[$this, 'render_dashboard_page'],
			'dashicons-update',
			30
		);

		add_submenu_page(
			'um-cron-service',
			__('Dashboard', 'um-cron-service'),
			__('Dashboard', 'um-cron-service'),
			'manage_options',
			'um-cron-service',
			[$this, 'render_dashboard_page']
		);

		add_submenu_page(
			'um-cron-service',
			__('Registered Sites', 'um-cron-service'),
			__('Sites', 'um-cron-service'),
			'manage_options',
			'um-cron-service-sites',
			[$this, 'render_sites_page']
		);

		add_submenu_page(
			'um-cron-service',
			__('Execution Logs', 'um-cron-service'),
			__('Logs', 'um-cron-service'),
			'manage_options',
			'um-cron-service-logs',
			[$this, 'render_logs_page']
		);

		add_submenu_page(
			'um-cron-service',
			__('Settings', 'um-cron-service'),
			__('Settings', 'um-cron-service'),
			'manage_options',
			'um-cron-service-settings',
			[$this, 'render_settings_page']
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets(string $hook): void {
		if (strpos($hook, 'um-cron-service') === false) {
			return;
		}

		wp_enqueue_style(
			'um-cron-service-admin',
			UM_CRON_SERVICE_PLUGIN_URL . 'assets/css/admin.css',
			[],
			UM_CRON_SERVICE_VERSION
		);

		wp_enqueue_script(
			'um-cron-service-admin',
			UM_CRON_SERVICE_PLUGIN_URL . 'assets/js/admin.js',
			['jquery'],
			UM_CRON_SERVICE_VERSION,
			true
		);

		wp_localize_script('um-cron-service-admin', 'umCronService', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('um_cron_service_nonce'),
		]);
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		$dashboard = new Dashboard_Page();
		$dashboard->render();
	}

	/**
	 * Render sites list page.
	 *
	 * @return void
	 */
	public function render_sites_page(): void {
		$sites_page = new Sites_List_Page();
		$sites_page->render();
	}

	/**
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render_logs_page(): void {
		include UM_CRON_SERVICE_PLUGIN_DIR . 'views/admin/logs.php';
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		include UM_CRON_SERVICE_PLUGIN_DIR . 'views/admin/settings.php';
	}

	/**
	 * Cleanup old execution logs.
	 *
	 * @return void
	 */
	public function cleanup_old_logs(): void {
		global $wpdb;

		$days_to_keep = apply_filters('um_cron_service_log_retention_days', 30);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}cron_service_execution_logs WHERE date_created < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_to_keep
			)
		);

		if ($deleted > 0) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log(sprintf('UM Cron Service: Cleaned up %d old log records', $deleted));
		}
	}

	/**
	 * Get OAuth handler.
	 *
	 * @return OAuth_Handler
	 */
	public function get_oauth_handler(): OAuth_Handler {
		return $this->oauth_handler;
	}

	/**
	 * Get subscription handler.
	 *
	 * @return Subscription_Handler
	 */
	public function get_subscription_handler(): Subscription_Handler {
		return $this->subscription_handler;
	}

	/**
	 * Get notification manager.
	 *
	 * @return Notification_Manager
	 */
	public function get_notification_manager(): Notification_Manager {
		return $this->notification_manager;
	}
}
