<?php
/**
 * Plugin Name: Ultimate Multisite Cron Service
 * Description: External cron service management for Ultimate Multisite customers. Handles site registration, job scheduling, and execution tracking.
 * Plugin URI: https://ultimatemultisite.com
 * Text Domain: um-cron-service
 * Version: 1.0.0
 * Author: David Stone - Multisite Ultimate
 * Author URI: https://ultimatemultisite.com
 * Requires at least: 6.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 *
 * @package UM_Cron_Service
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants.
define('UM_CRON_SERVICE_VERSION', '1.0.0');
define('UM_CRON_SERVICE_PLUGIN_FILE', __FILE__);
define('UM_CRON_SERVICE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UM_CRON_SERVICE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader.
if (file_exists(UM_CRON_SERVICE_PLUGIN_DIR . 'vendor/autoload.php')) {
	require_once UM_CRON_SERVICE_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main plugin class.
 */
final class UM_Cron_Service_Plugin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public string $version = '1.0.0';

	/**
	 * Single instance of the class.
	 *
	 * @var UM_Cron_Service_Plugin|null
	 */
	protected static ?UM_Cron_Service_Plugin $instance = null;

	/**
	 * Main instance.
	 *
	 * @return UM_Cron_Service_Plugin
	 */
	public static function get_instance(): UM_Cron_Service_Plugin {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('plugins_loaded', [$this, 'init'], 11);
		register_activation_hook(__FILE__, [$this, 'activate']);
		register_deactivation_hook(__FILE__, [$this, 'deactivate']);
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// Check if WooCommerce is active.
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
			return;
		}

		// Load plugin files.
		$this->load_dependencies();

		// Initialize hooks.
		$this->init_hooks();
	}

	/**
	 * Load required dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		// Database tables.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/database/class-sites-table.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/database/class-schedules-table.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/database/class-execution-logs-table.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/database/class-notification-configs-table.php';

		// Models.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/models/class-registered-site.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/models/class-cron-schedule.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/models/class-execution-log.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/models/class-notification-config.php';

		// API.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/api/class-client-api.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/api/class-worker-api.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/api/class-oauth-handler.php';

		// Admin.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/admin/class-dashboard-page.php';
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/admin/class-sites-list-page.php';

		// WooCommerce.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/woocommerce/class-subscription-handler.php';

		// Notifications.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/notifications/class-notification-manager.php';

		// Main plugin class.
		require_once UM_CRON_SERVICE_PLUGIN_DIR . 'inc/class-cron-service.php';
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_action('init', [$this, 'load_textdomain']);

		// Initialize the main service class.
		\UM_Cron_Service\Cron_Service::get_instance()->init();
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'um-cron-service',
			false,
			dirname(plugin_basename(__FILE__)) . '/lang/'
		);
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Create database tables.
		$this->create_tables();

		// Schedule cleanup cron.
		if (!wp_next_scheduled('um_cron_service_cleanup')) {
			wp_schedule_event(time(), 'daily', 'um_cron_service_cleanup');
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook('um_cron_service_cleanup');
		flush_rewrite_rules();
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 */
	private function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = [];

		// Sites table.
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cron_service_sites (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			site_url varchar(255) NOT NULL,
			site_hash varchar(64) NOT NULL,
			api_key varchar(64) NOT NULL,
			api_secret varchar(64) NOT NULL,
			network_id bigint(20) unsigned DEFAULT NULL,
			is_network_registration tinyint(1) DEFAULT 0,
			granularity enum('network','site') DEFAULT 'site',
			status enum('active','paused','suspended','pending') DEFAULT 'pending',
			last_heartbeat datetime DEFAULT NULL,
			cron_url varchar(255) NOT NULL,
			timezone varchar(50) DEFAULT 'UTC',
			date_created datetime NOT NULL,
			date_modified datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY site_hash (site_hash),
			KEY user_id (user_id),
			KEY subscription_id (subscription_id),
			KEY status (status),
			KEY network_id (network_id)
		) $charset_collate;";

		// Schedules table.
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cron_service_schedules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			hook_name varchar(255) NOT NULL,
			next_run datetime NOT NULL,
			recurrence varchar(50) DEFAULT NULL,
			interval_seconds int(11) unsigned DEFAULT NULL,
			args longtext DEFAULT NULL,
			priority tinyint(3) unsigned DEFAULT 10,
			is_active tinyint(1) DEFAULT 1,
			last_execution datetime DEFAULT NULL,
			last_status enum('pending','success','failed','timeout') DEFAULT 'pending',
			failure_count int(11) unsigned DEFAULT 0,
			date_created datetime NOT NULL,
			date_modified datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY site_id (site_id),
			KEY next_run (next_run),
			KEY is_active (is_active),
			KEY hook_name (hook_name(100))
		) $charset_collate;";

		// Execution logs table.
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cron_service_execution_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			schedule_id bigint(20) unsigned DEFAULT NULL,
			hook_name varchar(255) NOT NULL,
			execution_time datetime NOT NULL,
			duration_ms int(11) unsigned DEFAULT NULL,
			http_status_code smallint(5) unsigned DEFAULT NULL,
			response_body text DEFAULT NULL,
			status enum('success','failed','timeout','skipped') NOT NULL,
			error_message text DEFAULT NULL,
			worker_id varchar(50) DEFAULT NULL,
			date_created datetime NOT NULL,
			PRIMARY KEY (id),
			KEY site_id (site_id),
			KEY schedule_id (schedule_id),
			KEY execution_time (execution_time),
			KEY status (status)
		) $charset_collate;";

		// Notification configs table.
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cron_service_notification_configs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			notify_on_failure tinyint(1) DEFAULT 1,
			failure_threshold int(11) unsigned DEFAULT 3,
			notify_on_recovery tinyint(1) DEFAULT 1,
			daily_summary tinyint(1) DEFAULT 0,
			email_addresses text DEFAULT NULL,
			webhook_url varchar(255) DEFAULT NULL,
			slack_webhook varchar(255) DEFAULT NULL,
			date_created datetime NOT NULL,
			date_modified datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY site_id (site_id),
			KEY user_id (user_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ($sql as $query) {
			dbDelta($query);
		}
	}

	/**
	 * Display notice when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice(): void {
		?>
		<div class="notice notice-error is-dismissible">
			<p>
			<?php
			printf(
				/* translators: %1$s: Plugin name, %2$s: WooCommerce */
				esc_html__('%1$s requires %2$s to be installed and active.', 'um-cron-service'),
				'<strong>' . esc_html__('Ultimate Multisite Cron Service', 'um-cron-service') . '</strong>',
				'<strong>' . esc_html__('WooCommerce', 'um-cron-service') . '</strong>'
			);
			?>
			</p>
		</div>
		<?php
	}
}

// Initialize the plugin.
UM_Cron_Service_Plugin::get_instance();
