<?php
/**
 * Notification configs table class.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Database;

/**
 * Notification configs table class.
 */
class Notification_Configs_Table {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'cron_service_notification_configs';
	}

	/**
	 * Get config by site ID.
	 *
	 * @param int $site_id Site ID.
	 * @return object|null
	 */
	public static function get_by_site(int $site_id): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::get_table_name() . " WHERE site_id = %d",
				$site_id
			)
		);
	}
}
