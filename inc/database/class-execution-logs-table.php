<?php
/**
 * Execution logs table class.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Database;

/**
 * Execution logs table class.
 */
class Execution_Logs_Table {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'cron_service_execution_logs';
	}

	/**
	 * Get recent logs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_recent(int $limit = 100): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, s.site_url
				FROM " . self::get_table_name() . " l
				LEFT JOIN {$wpdb->prefix}cron_service_sites s ON l.site_id = s.id
				ORDER BY l.execution_time DESC
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Get success rate for last 24 hours.
	 *
	 * @return float
	 */
	public static function get_success_rate(): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stats = $wpdb->get_row(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success
			FROM " . self::get_table_name() . "
			WHERE execution_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);

		if (!$stats || $stats->total == 0) {
			return 100.0;
		}

		return round(($stats->success / $stats->total) * 100, 2);
	}

	/**
	 * Get execution count for last 24 hours.
	 *
	 * @return int
	 */
	public static function get_24h_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE execution_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);
	}
}
