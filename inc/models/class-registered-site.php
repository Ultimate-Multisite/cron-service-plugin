<?php
/**
 * Registered Site model.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Models;

/**
 * Registered Site model class.
 */
class Registered_Site {

	/**
	 * Site ID.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Site URL.
	 *
	 * @var string
	 */
	public string $site_url;

	/**
	 * Site hash.
	 *
	 * @var string
	 */
	public string $site_hash;

	/**
	 * Status.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Cron URL.
	 *
	 * @var string
	 */
	public string $cron_url;

	/**
	 * Constructor.
	 *
	 * @param object|null $data Database row.
	 */
	public function __construct(?object $data = null) {
		if ($data) {
			$this->id        = (int) $data->id;
			$this->user_id   = (int) $data->user_id;
			$this->site_url  = $data->site_url;
			$this->site_hash = $data->site_hash;
			$this->status    = $data->status;
			$this->cron_url  = $data->cron_url;
		}
	}

	/**
	 * Check if site is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->status === 'active';
	}
}
