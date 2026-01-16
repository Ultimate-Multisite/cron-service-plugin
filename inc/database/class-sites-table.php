<?php
/**
 * Sites table class.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Database;

/**
 * Sites table class.
 *
 * Note: Table creation is handled in main plugin class.
 * This class provides helper methods for querying the sites table.
 */
class Sites_Table {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'cron_service_sites';
	}

	/**
	 * Get site by ID.
	 *
	 * @param int $id Site ID.
	 * @return object|null
	 */
	public static function get(int $id): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Get sites by user ID.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_by_user(int $user_id): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_table_name() . " WHERE user_id = %d ORDER BY date_created DESC",
				$user_id
			)
		);
	}

	/**
	 * Get active sites count.
	 *
	 * @return int
	 */
	public static function get_active_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE status = 'active'"
		);
	}

	/**
	 * Update site status.
	 *
	 * @param int    $id     Site ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function update_status(int $id, string $status): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->update(
			self::get_table_name(),
			['status' => $status, 'date_modified' => current_time('mysql', true)],
			['id' => $id],
			['%s', '%s'],
			['%d']
		) !== false;
	}
}
