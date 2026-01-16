<?php
/**
 * Sites list admin page.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Admin;

use UM_Cron_Service\Database\Sites_Table;

/**
 * Sites list page class.
 */
class Sites_List_Page {

	/**
	 * Render the sites list page.
	 *
	 * @return void
	 */
	public function render(): void {
		global $wpdb;

		$per_page = 20;
		$page     = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
		$offset   = ($page - 1) * $per_page;

		// Get sites with pagination.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sites = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, u.user_email, u.display_name,
					(SELECT COUNT(*) FROM {$wpdb->prefix}cron_service_schedules WHERE site_id = s.id AND is_active = 1) as schedule_count
				FROM {$wpdb->prefix}cron_service_sites s
				LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
				ORDER BY s.date_created DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cron_service_sites");
		$pages = ceil($total / $per_page);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Registered Sites', 'um-cron-service'); ?></h1>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Site URL', 'um-cron-service'); ?></th>
						<th><?php esc_html_e('Owner', 'um-cron-service'); ?></th>
						<th><?php esc_html_e('Status', 'um-cron-service'); ?></th>
						<th><?php esc_html_e('Schedules', 'um-cron-service'); ?></th>
						<th><?php esc_html_e('Last Heartbeat', 'um-cron-service'); ?></th>
						<th><?php esc_html_e('Registered', 'um-cron-service'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($sites)) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e('No sites registered yet.', 'um-cron-service'); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ($sites as $site) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html($site->site_url); ?></strong>
									<div class="row-actions">
										<span class="view">
											<a href="<?php echo esc_url($site->site_url); ?>" target="_blank">
												<?php esc_html_e('Visit', 'um-cron-service'); ?>
											</a> |
										</span>
										<span class="edit">
											<a href="<?php echo esc_url(admin_url('admin.php?page=um-cron-service-site&id=' . $site->id)); ?>">
												<?php esc_html_e('View Details', 'um-cron-service'); ?>
											</a>
										</span>
									</div>
								</td>
								<td>
									<?php echo esc_html($site->display_name ?: $site->user_email); ?>
								</td>
								<td>
									<span class="um-cron-status um-cron-status-<?php echo esc_attr($site->status); ?>">
										<?php echo esc_html(ucfirst($site->status)); ?>
									</span>
								</td>
								<td><?php echo esc_html($site->schedule_count); ?></td>
								<td>
									<?php
									if ($site->last_heartbeat) {
										echo esc_html(human_time_diff(strtotime($site->last_heartbeat), time()) . ' ago');
									} else {
										esc_html_e('Never', 'um-cron-service');
									}
									?>
								</td>
								<td><?php echo esc_html($site->date_created); ?></td>
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
			.um-cron-status-active { background: #d4edda; color: #155724; }
			.um-cron-status-paused { background: #fff3cd; color: #856404; }
			.um-cron-status-suspended { background: #f8d7da; color: #721c24; }
			.um-cron-status-pending { background: #e2e3e5; color: #383d41; }
		</style>
		<?php
	}
}
