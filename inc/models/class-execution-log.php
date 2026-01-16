<?php
/**
 * Execution Log model.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Models;

/**
 * Execution Log model class.
 */
class Execution_Log {

	/**
	 * Log ID.
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
	 * Hook name.
	 *
	 * @var string
	 */
	public string $hook_name;

	/**
	 * Status.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Constructor.
	 *
	 * @param object|null $data Database row.
	 */
	public function __construct(?object $data = null) {
		if ($data) {
			$this->id        = (int) $data->id;
			$this->site_id   = (int) $data->site_id;
			$this->hook_name = $data->hook_name;
			$this->status    = $data->status;
		}
	}

	/**
	 * Check if execution was successful.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->status === 'success';
	}
}
