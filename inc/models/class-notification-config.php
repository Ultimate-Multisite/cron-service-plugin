<?php
/**
 * Notification Config model.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Models;

/**
 * Notification Config model class.
 */
class Notification_Config {

	/**
	 * Config ID.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * Site ID.
	 *
	 * @var int
	 */
	public int $site_id;

	/**
	 * Notify on failure.
	 *
	 * @var bool
	 */
	public bool $notify_on_failure;

	/**
	 * Failure threshold.
	 *
	 * @var int
	 */
	public int $failure_threshold;

	/**
	 * Email addresses.
	 *
	 * @var string
	 */
	public string $email_addresses;

	/**
	 * Constructor.
	 *
	 * @param object|null $data Database row.
	 */
	public function __construct(?object $data = null) {
		if ($data) {
			$this->id                = (int) $data->id;
			$this->site_id           = (int) $data->site_id;
			$this->notify_on_failure = (bool) $data->notify_on_failure;
			$this->failure_threshold = (int) $data->failure_threshold;
			$this->email_addresses   = $data->email_addresses ?? '';
		}
	}
}
