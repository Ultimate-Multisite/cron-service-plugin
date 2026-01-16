<?php
/**
 * Notification Manager.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Notifications;

/**
 * Notification manager class.
 */
class Notification_Manager {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('um_cron_service_failure_notification', [$this, 'send_failure_notification'], 10, 4);
		add_action('um_cron_service_recovery_notification', [$this, 'send_recovery_notification'], 10, 3);
		add_action('um_cron_service_daily_summary', [$this, 'send_daily_summary']);

		// Schedule daily summary.
		if (!wp_next_scheduled('um_cron_service_daily_summary')) {
			wp_schedule_event(strtotime('tomorrow 9:00:00'), 'daily', 'um_cron_service_daily_summary');
		}
	}

	/**
	 * Send failure notification.
	 *
	 * @param int    $site_id       Site ID.
	 * @param int    $schedule_id   Schedule ID.
	 * @param int    $failure_count Failure count.
	 * @param object $config        Notification config.
	 * @return void
	 */
	public function send_failure_notification(int $site_id, int $schedule_id, int $failure_count, object $config): void {
		global $wpdb;

		// Get site and schedule info.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$site = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cron_service_sites WHERE id = %d",
			$site_id
		));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$schedule = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cron_service_schedules WHERE id = %d",
			$schedule_id
		));

		if (!$site || !$schedule) {
			return;
		}

		// Get email addresses.
		$emails = $this->get_notification_emails($config, $site->user_id);

		if (empty($emails)) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site URL */
			__('[Cron Service] Job failures detected on %s', 'um-cron-service'),
			$site->site_url
		);

		$message = sprintf(
			/* translators: %1$s: hook name, %2$d: failure count, %3$s: site URL */
			__("The cron job '%1\$s' has failed %2\$d consecutive times on %3\$s.\n\nPlease check your site to ensure it's accessible and functioning correctly.", 'um-cron-service'),
			$schedule->hook_name,
			$failure_count,
			$site->site_url
		);

		$this->send_email($emails, $subject, $message);

		// Send webhook if configured.
		if (!empty($config->webhook_url)) {
			$this->send_webhook($config->webhook_url, [
				'type'          => 'failure',
				'site_url'      => $site->site_url,
				'hook_name'     => $schedule->hook_name,
				'failure_count' => $failure_count,
				'timestamp'     => time(),
			]);
		}

		// Send Slack notification if configured.
		if (!empty($config->slack_webhook)) {
			$this->send_slack_notification($config->slack_webhook, [
				'text' => sprintf(
					':warning: Cron job `%s` has failed %d times on %s',
					$schedule->hook_name,
					$failure_count,
					$site->site_url
				),
			]);
		}
	}

	/**
	 * Send recovery notification.
	 *
	 * @param int    $site_id     Site ID.
	 * @param int    $schedule_id Schedule ID.
	 * @param object $config      Notification config.
	 * @return void
	 */
	public function send_recovery_notification(int $site_id, int $schedule_id, object $config): void {
		global $wpdb;

		// Get site and schedule info.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$site = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cron_service_sites WHERE id = %d",
			$site_id
		));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$schedule = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cron_service_schedules WHERE id = %d",
			$schedule_id
		));

		if (!$site || !$schedule) {
			return;
		}

		$emails = $this->get_notification_emails($config, $site->user_id);

		if (empty($emails)) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site URL */
			__('[Cron Service] Job recovered on %s', 'um-cron-service'),
			$site->site_url
		);

		$message = sprintf(
			/* translators: %1$s: hook name, %2$s: site URL */
			__("Good news! The cron job '%1\$s' on %2\$s is now running successfully again.", 'um-cron-service'),
			$schedule->hook_name,
			$site->site_url
		);

		$this->send_email($emails, $subject, $message);

		// Send Slack notification if configured.
		if (!empty($config->slack_webhook)) {
			$this->send_slack_notification($config->slack_webhook, [
				'text' => sprintf(
					':white_check_mark: Cron job `%s` has recovered on %s',
					$schedule->hook_name,
					$site->site_url
				),
			]);
		}
	}

	/**
	 * Send daily summary.
	 *
	 * @return void
	 */
	public function send_daily_summary(): void {
		global $wpdb;

		// Get all sites with daily_summary enabled.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$configs = $wpdb->get_results(
			"SELECT nc.*, s.site_url, s.user_id
			FROM {$wpdb->prefix}cron_service_notification_configs nc
			JOIN {$wpdb->prefix}cron_service_sites s ON nc.site_id = s.id
			WHERE nc.daily_summary = 1"
		);

		foreach ($configs as $config) {
			$this->send_site_daily_summary($config);
		}
	}

	/**
	 * Send daily summary for a site.
	 *
	 * @param object $config Config with site info.
	 * @return void
	 */
	private function send_site_daily_summary(object $config): void {
		global $wpdb;

		// Get stats for last 24 hours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stats = $wpdb->get_row($wpdb->prepare(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
				SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as timeout
			FROM {$wpdb->prefix}cron_service_execution_logs
			WHERE site_id = %d AND execution_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
			$config->site_id
		));

		$emails = $this->get_notification_emails($config, $config->user_id);

		if (empty($emails)) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site URL */
			__('[Cron Service] Daily Summary for %s', 'um-cron-service'),
			$config->site_url
		);

		$success_rate = $stats->total > 0 ? round(($stats->success / $stats->total) * 100, 1) : 100;

		$message = sprintf(
			/* translators: %1$s: site URL, %2$d: total, %3$d: success, %4$d: failed, %5$d: timeout, %6$s: success rate */
			__("Daily Cron Summary for %1\$s\n\nTotal Executions: %2\$d\nSuccessful: %3\$d\nFailed: %4\$d\nTimeouts: %5\$d\nSuccess Rate: %6\$s%%", 'um-cron-service'),
			$config->site_url,
			$stats->total,
			$stats->success,
			$stats->failed,
			$stats->timeout,
			$success_rate
		);

		$this->send_email($emails, $subject, $message);
	}

	/**
	 * Get notification email addresses.
	 *
	 * @param object $config  Notification config.
	 * @param int    $user_id User ID.
	 * @return array Email addresses.
	 */
	private function get_notification_emails(object $config, int $user_id): array {
		$emails = [];

		// Custom email addresses.
		if (!empty($config->email_addresses)) {
			$custom_emails = array_map('trim', explode("\n", $config->email_addresses));
			$emails        = array_merge($emails, array_filter($custom_emails, 'is_email'));
		}

		// Fall back to user email.
		if (empty($emails)) {
			$user = get_userdata($user_id);
			if ($user) {
				$emails[] = $user->user_email;
			}
		}

		return array_unique($emails);
	}

	/**
	 * Send email notification.
	 *
	 * @param array  $emails  Email addresses.
	 * @param string $subject Subject.
	 * @param string $message Message.
	 * @return bool
	 */
	private function send_email(array $emails, string $subject, string $message): bool {
		$headers = ['Content-Type: text/plain; charset=UTF-8'];

		return wp_mail($emails, $subject, $message, $headers);
	}

	/**
	 * Send webhook notification.
	 *
	 * @param string $url  Webhook URL.
	 * @param array  $data Data to send.
	 * @return bool
	 */
	private function send_webhook(string $url, array $data): bool {
		$response = wp_remote_post($url, [
			'timeout' => 10,
			'headers' => ['Content-Type' => 'application/json'],
			'body'    => wp_json_encode($data),
		]);

		return !is_wp_error($response) && wp_remote_retrieve_response_code($response) < 400;
	}

	/**
	 * Send Slack notification.
	 *
	 * @param string $webhook_url Slack webhook URL.
	 * @param array  $data        Data to send.
	 * @return bool
	 */
	private function send_slack_notification(string $webhook_url, array $data): bool {
		return $this->send_webhook($webhook_url, $data);
	}
}
