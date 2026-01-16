<?php
/**
 * Cron Schedule model.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\Models;

/**
 * Cron Schedule model class.
 */
class Cron_Schedule {

	/**
	 * Schedule ID.
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
	 * Next run time.
	 *
	 * @var string
	 */
	public string $next_run;

	/**
	 * Is active.
	 *
	 * @var bool
	 */
	public bool $is_active;

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
			$this->next_run  = $data->next_run;
			$this->is_active = (bool) $data->is_active;
		}
	}

	/**
	 * Check if schedule is due.
	 *
	 * @return bool
	 */
	public function is_due(): bool {
		return strtotime($this->next_run) <= time();
	}
}
