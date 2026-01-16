<?php
/**
 * Schedules table class.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Database;

/**
 * Schedules table class.
 */
class Schedules_Table {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'cron_service_schedules';
	}

	/**
	 * Get schedules by site ID.
	 *
	 * @param int $site_id Site ID.
	 * @return array
	 */
	public static function get_by_site(int $site_id): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_table_name() . " WHERE site_id = %d AND is_active = 1 ORDER BY next_run ASC",
				$site_id
			)
		);
	}

	/**
	 * Get pending schedules count.
	 *
	 * @return int
	 */
	public static function get_pending_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE is_active = 1 AND next_run <= NOW()"
		);
	}
}
