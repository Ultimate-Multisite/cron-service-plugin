<?php
/**
 * Dashboard admin page.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Admin;

use UM_Cron_Service\Database\Sites_Table;
use UM_Cron_Service\Database\Schedules_Table;
use UM_Cron_Service\Database\Execution_Logs_Table;
use UM_Cron_Service\API\Worker_API;

/**
 * Dashboard page class.
 */
class Dashboard_Page {

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render(): void {
		$active_sites    = Sites_Table::get_active_count();
		$pending_jobs    = Schedules_Table::get_pending_count();
		$success_rate    = Execution_Logs_Table::get_success_rate();
		$executions_24h  = Execution_Logs_Table::get_24h_count();
		$recent_logs     = Execution_Logs_Table::get_recent(10);
		$worker_secret   = Worker_API::get_worker_secret();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Cron Service Dashboard', 'um-cron-service'); ?></h1>

			<div class="um-cron-dashboard">
				<!-- Stats Cards -->
				<div class="um-cron-stats">
					<div class="um-cron-stat-card">
						<span class="stat-value"><?php echo esc_html($active_sites); ?></span>
						<span class="stat-label"><?php esc_html_e('Active Sites', 'um-cron-service'); ?></span>
					</div>
					<div class="um-cron-stat-card">
						<span class="stat-value"><?php echo esc_html($pending_jobs); ?></span>
						<span class="stat-label"><?php esc_html_e('Pending Jobs', 'um-cron-service'); ?></span>
					</div>
					<div class="um-cron-stat-card">
						<span class="stat-value"><?php echo esc_html($success_rate); ?>%</span>
						<span class="stat-label"><?php esc_html_e('Success Rate (24h)', 'um-cron-service'); ?></span>
					</div>
					<div class="um-cron-stat-card">
						<span class="stat-value"><?php echo esc_html($executions_24h); ?></span>
						<span class="stat-label"><?php esc_html_e('Executions (24h)', 'um-cron-service'); ?></span>
					</div>
				</div>

				<!-- Worker Configuration -->
				<div class="um-cron-section">
					<h2><?php esc_html_e('Worker Configuration', 'um-cron-service'); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e('API URL', 'um-cron-service'); ?></th>
							<td>
								<code><?php echo esc_url(rest_url('cron-worker/v1')); ?></code>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Worker Secret', 'um-cron-service'); ?></th>
							<td>
								<code id="worker-secret"><?php echo esc_html($worker_secret); ?></code>
								<button type="button" class="button" id="regenerate-secret">
									<?php esc_html_e('Regenerate', 'um-cron-service'); ?>
								</button>
								<p class="description">
									<?php esc_html_e('Use this secret in your AMPHP worker configuration.', 'um-cron-service'); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Recent Activity -->
				<div class="um-cron-section">
					<h2><?php esc_html_e('Recent Activity', 'um-cron-service'); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Site', 'um-cron-service'); ?></th>
								<th><?php esc_html_e('Hook', 'um-cron-service'); ?></th>
								<th><?php esc_html_e('Status', 'um-cron-service'); ?></th>
								<th><?php esc_html_e('Duration', 'um-cron-service'); ?></th>
								<th><?php esc_html_e('Time', 'um-cron-service'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($recent_logs)) : ?>
								<tr>
									<td colspan="5"><?php esc_html_e('No recent activity.', 'um-cron-service'); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ($recent_logs as $log) : ?>
									<tr>
										<td><?php echo esc_html($log->site_url ?? 'Unknown'); ?></td>
										<td><code><?php echo esc_html($log->hook_name); ?></code></td>
										<td>
											<span class="um-cron-status um-cron-status-<?php echo esc_attr($log->status); ?>">
												<?php echo esc_html(ucfirst($log->status)); ?>
											</span>
										</td>
										<td><?php echo esc_html($log->duration_ms ? $log->duration_ms . 'ms' : '-'); ?></td>
										<td><?php echo esc_html($log->execution_time); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
					<p>
						<a href="<?php echo esc_url(admin_url('admin.php?page=um-cron-service-logs')); ?>">
							<?php esc_html_e('View all logs', 'um-cron-service'); ?> &rarr;
						</a>
					</p>
				</div>
			</div>
		</div>

		<style>
			.um-cron-dashboard { max-width: 1200px; }
			.um-cron-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
			.um-cron-stat-card { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; text-align: center; }
			.um-cron-stat-card .stat-value { display: block; font-size: 36px; font-weight: 600; color: #1d2327; }
			.um-cron-stat-card .stat-label { display: block; color: #646970; margin-top: 5px; }
			.um-cron-section { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0; }
			.um-cron-section h2 { margin-top: 0; }
			.um-cron-status { padding: 2px 8px; border-radius: 3px; font-size: 12px; }
			.um-cron-status-success { background: #d4edda; color: #155724; }
			.um-cron-status-failed { background: #f8d7da; color: #721c24; }
			.um-cron-status-timeout { background: #fff3cd; color: #856404; }
			.um-cron-status-running { background: #cce5ff; color: #004085; }
		</style>
		<?php
	}
}
