<?php
/**
 * Settings admin page view.
 *
 * @package UM_Cron_Service
 */

use UM_Cron_Service\API\Worker_API;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

// Handle form submission.
if (isset($_POST['um_cron_service_settings_nonce']) && wp_verify_nonce($_POST['um_cron_service_settings_nonce'], 'um_cron_service_settings')) {
	if (isset($_POST['regenerate_secret'])) {
		Worker_API::regenerate_worker_secret();
		echo '<div class="notice notice-success"><p>' . esc_html__('Worker secret regenerated successfully.', 'um-cron-service') . '</p></div>';
	}

	if (isset($_POST['log_retention_days'])) {
		update_option('um_cron_service_log_retention_days', absint($_POST['log_retention_days']));
	}
}

$worker_secret      = Worker_API::get_worker_secret();
$log_retention_days = get_option('um_cron_service_log_retention_days', 30);
?>
<div class="wrap">
	<h1><?php esc_html_e('Cron Service Settings', 'um-cron-service'); ?></h1>

	<form method="post">
		<?php wp_nonce_field('um_cron_service_settings', 'um_cron_service_settings_nonce'); ?>

		<h2><?php esc_html_e('Worker Configuration', 'um-cron-service'); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e('API Endpoint', 'um-cron-service'); ?></th>
				<td>
					<code><?php echo esc_url(rest_url('cron-worker/v1')); ?></code>
					<p class="description"><?php esc_html_e('Base URL for the worker API.', 'um-cron-service'); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('Worker Secret', 'um-cron-service'); ?></th>
				<td>
					<input type="text" class="large-text code" value="<?php echo esc_attr($worker_secret); ?>" readonly>
					<p class="description"><?php esc_html_e('Use this secret in the X-Worker-Secret header for worker authentication.', 'um-cron-service'); ?></p>
					<p>
						<button type="submit" name="regenerate_secret" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure? This will invalidate the current worker secret.', 'um-cron-service'); ?>');">
							<?php esc_html_e('Regenerate Secret', 'um-cron-service'); ?>
						</button>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e('Client API Configuration', 'um-cron-service'); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e('Client API Endpoint', 'um-cron-service'); ?></th>
				<td>
					<code><?php echo esc_url(rest_url('cron-service/v1')); ?></code>
					<p class="description"><?php esc_html_e('Base URL for client site registration and schedule updates.', 'um-cron-service'); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e('General Settings', 'um-cron-service'); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="log_retention_days"><?php esc_html_e('Log Retention (Days)', 'um-cron-service'); ?></label></th>
				<td>
					<input type="number" name="log_retention_days" id="log_retention_days" value="<?php echo esc_attr($log_retention_days); ?>" min="1" max="365" class="small-text">
					<p class="description"><?php esc_html_e('Number of days to keep execution logs. Older logs are automatically deleted.', 'um-cron-service'); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr>

	<h2><?php esc_html_e('AMPHP Worker Setup', 'um-cron-service'); ?></h2>
	<p><?php esc_html_e('Copy this configuration to your AMPHP worker config file:', 'um-cron-service'); ?></p>
	<pre style="background: #f1f1f1; padding: 15px; overflow-x: auto;">&lt;?php
return [
    'api_url'       => '<?php echo esc_url(home_url()); ?>',
    'worker_secret' => '<?php echo esc_html($worker_secret); ?>',
    'connect_timeout' => 10000, // milliseconds
    'transfer_timeout' => 30000, // milliseconds
    'poll_interval' => 5, // seconds
    'log_file' => __DIR__ . '/../logs/worker.log',
];</pre>

	<h2><?php esc_html_e('Systemd Service', 'um-cron-service'); ?></h2>
	<p><?php esc_html_e('Example systemd service file for running the worker:', 'um-cron-service'); ?></p>
	<pre style="background: #f1f1f1; padding: 15px; overflow-x: auto;">[Unit]
Description=Ultimate Multisite Cron Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/cron-service/amphp-worker
ExecStart=/usr/bin/php bin/worker
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target</pre>
</div>
