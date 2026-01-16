<?php
/**
 * Logs admin page view.
 *
 * @package UM_Cron_Service
 */

use UM_Cron_Service\Database\Execution_Logs_Table;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

global $wpdb;

$per_page = 50;
$page     = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$offset   = ($page - 1) * $per_page;
$status   = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$site_id  = isset($_GET['site_id']) ? absint($_GET['site_id']) : 0;

// Build query.
$where = ['1=1'];
$args  = [];

if ($status) {
	$where[] = 'l.status = %s';
	$args[]  = $status;
}

if ($site_id) {
	$where[] = 'l.site_id = %d';
	$args[]  = $site_id;
}

$where_sql = implode(' AND ', $where);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$logs = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.*, s.site_url
		FROM {$wpdb->prefix}cron_service_execution_logs l
		LEFT JOIN {$wpdb->prefix}cron_service_sites s ON l.site_id = s.id
		WHERE {$where_sql}
		ORDER BY l.execution_time DESC
		LIMIT %d OFFSET %d",
		array_merge($args, [$per_page, $offset])
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$total = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}cron_service_execution_logs l WHERE {$where_sql}",
		$args
	)
);

$pages = ceil($total / $per_page);

// Get sites for filter dropdown.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$sites = $wpdb->get_results("SELECT id, site_url FROM {$wpdb->prefix}cron_service_sites ORDER BY site_url");
?>
<div class="wrap">
	<h1><?php esc_html_e('Execution Logs', 'um-cron-service'); ?></h1>

	<!-- Filters -->
	<div class="tablenav top">
		<form method="get" class="alignleft">
			<input type="hidden" name="page" value="um-cron-service-logs">

			<select name="status">
				<option value=""><?php esc_html_e('All Statuses', 'um-cron-service'); ?></option>
				<option value="success" <?php selected($status, 'success'); ?>><?php esc_html_e('Success', 'um-cron-service'); ?></option>
				<option value="failed" <?php selected($status, 'failed'); ?>><?php esc_html_e('Failed', 'um-cron-service'); ?></option>
				<option value="timeout" <?php selected($status, 'timeout'); ?>><?php esc_html_e('Timeout', 'um-cron-service'); ?></option>
			</select>

			<select name="site_id">
				<option value=""><?php esc_html_e('All Sites', 'um-cron-service'); ?></option>
				<?php foreach ($sites as $s) : ?>
					<option value="<?php echo esc_attr($s->id); ?>" <?php selected($site_id, $s->id); ?>>
						<?php echo esc_html($s->site_url); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button(__('Filter', 'um-cron-service'), 'secondary', 'filter', false); ?>
		</form>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e('Site', 'um-cron-service'); ?></th>
				<th><?php esc_html_e('Hook', 'um-cron-service'); ?></th>
				<th><?php esc_html_e('Status', 'um-cron-service'); ?></th>
				<th><?php esc_html_e('HTTP Code', 'um-cron-service'); ?></th>
				<th><?php esc_html_e('Duration', 'um-cron-service'); ?></th>
				<th><?php esc_html_e('Error', 'um-cron-service'); ?></th>
				<th><?php esc_html_e('Time', 'um-cron-service'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if (empty($logs)) : ?>
				<tr>
					<td colspan="7"><?php esc_html_e('No logs found.', 'um-cron-service'); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ($logs as $log) : ?>
					<tr>
						<td><?php echo esc_html($log->site_url ?? 'Unknown'); ?></td>
						<td><code><?php echo esc_html($log->hook_name); ?></code></td>
						<td>
							<span class="um-cron-status um-cron-status-<?php echo esc_attr($log->status); ?>">
								<?php echo esc_html(ucfirst($log->status)); ?>
							</span>
						</td>
						<td><?php echo esc_html($log->http_status_code ?: '-'); ?></td>
						<td><?php echo esc_html($log->duration_ms ? $log->duration_ms . 'ms' : '-'); ?></td>
						<td>
							<?php if ($log->error_message) : ?>
								<span title="<?php echo esc_attr($log->error_message); ?>">
									<?php echo esc_html(substr($log->error_message, 0, 50)); ?>
									<?php echo strlen($log->error_message) > 50 ? '...' : ''; ?>
								</span>
							<?php else : ?>
								-
							<?php endif; ?>
						</td>
						<td><?php echo esc_html($log->execution_time); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ($pages > 1) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links([
						'base'      => add_query_arg('paged', '%#%'),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $pages,
						'current'   => $page,
					])
				);
				?>
			</div>
		</div>
	<?php endif; ?>
</div>

<style>
	.um-cron-status { padding: 2px 8px; border-radius: 3px; font-size: 12px; }
	.um-cron-status-success { background: #d4edda; color: #155724; }
	.um-cron-status-failed { background: #f8d7da; color: #721c24; }
	.um-cron-status-timeout { background: #fff3cd; color: #856404; }
</style>
