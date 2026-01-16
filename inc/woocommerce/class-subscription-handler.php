<?php
/**
 * WooCommerce Subscription Handler.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\WooCommerce;

/**
 * Subscription handler class.
 */
class Subscription_Handler {

	/**
	 * Cron service product SKU prefix.
	 *
	 * @var string
	 */
	const PRODUCT_SKU_PREFIX = 'external-cron-service';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Subscription status changes.
		add_action('woocommerce_subscription_status_active', [$this, 'activate_service'], 10, 1);
		add_action('woocommerce_subscription_status_on-hold', [$this, 'pause_service'], 10, 1);
		add_action('woocommerce_subscription_status_cancelled', [$this, 'cancel_service'], 10, 1);
		add_action('woocommerce_subscription_status_expired', [$this, 'cancel_service'], 10, 1);

		// Add custom product fields.
		add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_fields']);
		add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);
		add_action('woocommerce_variation_options', [$this, 'add_variation_fields'], 10, 3);
		add_action('woocommerce_save_product_variation', [$this, 'save_variation_fields'], 10, 2);
	}

	/**
	 * Check if subscription includes cron service.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return bool
	 */
	public function is_cron_service_subscription(\WC_Subscription $subscription): bool {
		foreach ($subscription->get_items() as $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}

			$sku = $product->get_sku();
			if (strpos($sku, self::PRODUCT_SKU_PREFIX) !== false) {
				return true;
			}

			$is_cron = get_post_meta($product->get_id(), '_is_cron_service_product', true);
			if ($is_cron === 'yes') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Activate cron service when subscription becomes active.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return void
	 */
	public function activate_service(\WC_Subscription $subscription): void {
		if (!$this->is_cron_service_subscription($subscription)) {
			return;
		}

		$user_id = $subscription->get_user_id();

		$this->update_user_sites_status($user_id, $subscription->get_id(), 'active');

		do_action('um_cron_service_subscription_activated', $subscription);
	}

	/**
	 * Pause cron service when subscription is on hold.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return void
	 */
	public function pause_service(\WC_Subscription $subscription): void {
		if (!$this->is_cron_service_subscription($subscription)) {
			return;
		}

		$user_id = $subscription->get_user_id();

		$this->update_user_sites_status($user_id, $subscription->get_id(), 'paused');

		do_action('um_cron_service_subscription_paused', $subscription);
	}

	/**
	 * Cancel cron service when subscription is cancelled or expired.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return void
	 */
	public function cancel_service(\WC_Subscription $subscription): void {
		if (!$this->is_cron_service_subscription($subscription)) {
			return;
		}

		$user_id = $subscription->get_user_id();

		$this->update_user_sites_status($user_id, $subscription->get_id(), 'suspended');

		do_action('um_cron_service_subscription_cancelled', $subscription);
	}

	/**
	 * Update sites status for a user.
	 *
	 * @param int    $user_id         User ID.
	 * @param int    $subscription_id Subscription ID.
	 * @param string $status          New status.
	 * @return void
	 */
	private function update_user_sites_status(int $user_id, int $subscription_id, string $status): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			"{$wpdb->prefix}cron_service_sites",
			[
				'status'        => $status,
				'date_modified' => current_time('mysql', true),
			],
			[
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
			],
			['%s', '%s'],
			['%d', '%d']
		);
	}

	/**
	 * Get site limit for a subscription.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return int Site limit (PHP_INT_MAX for unlimited).
	 */
	public function get_site_limit(\WC_Subscription $subscription): int {
		foreach ($subscription->get_items() as $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}

			$product_id = $product->get_id();
			$tier       = get_post_meta($product_id, '_cron_service_tier', true);

			if ($tier) {
				return match ($tier) {
					'standard'  => 1000,
					'unlimited' => PHP_INT_MAX,
					default     => 1000,
				};
			}
		}

		return 1000; // Default to standard.
	}

	/**
	 * Add custom product fields for cron service products.
	 *
	 * @return void
	 */
	public function add_product_fields(): void {
		global $post;

		echo '<div class="options_group">';

		woocommerce_wp_checkbox([
			'id'          => '_is_cron_service_product',
			'label'       => __('Cron Service Product', 'um-cron-service'),
			'description' => __('Enable if this product grants access to the External Cron Service.', 'um-cron-service'),
		]);

		woocommerce_wp_select([
			'id'          => '_cron_service_tier',
			'label'       => __('Service Tier', 'um-cron-service'),
			'description' => __('Select the service tier for this product.', 'um-cron-service'),
			'options'     => [
				''          => __('Select tier', 'um-cron-service'),
				'standard'  => __('Standard ($10/mo - 1,000 sites)', 'um-cron-service'),
				'unlimited' => __('Unlimited ($25/mo - Unlimited sites)', 'um-cron-service'),
			],
		]);

		echo '</div>';
	}

	/**
	 * Save custom product fields.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public function save_product_fields(int $post_id): void {
		$is_cron = isset($_POST['_is_cron_service_product']) ? 'yes' : 'no';
		update_post_meta($post_id, '_is_cron_service_product', $is_cron);

		if (isset($_POST['_cron_service_tier'])) {
			update_post_meta($post_id, '_cron_service_tier', sanitize_text_field($_POST['_cron_service_tier']));
		}
	}

	/**
	 * Add variation fields.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 * @return void
	 */
	public function add_variation_fields(int $loop, array $variation_data, $variation): void {
		$tier = get_post_meta($variation->ID, '_cron_service_tier', true);
		?>
		<label class="tips" data-tip="<?php esc_attr_e('Cron Service Tier for this variation', 'um-cron-service'); ?>">
			<?php esc_html_e('Cron Service Tier', 'um-cron-service'); ?>
			<select name="variable_cron_service_tier[<?php echo esc_attr($loop); ?>]">
				<option value=""><?php esc_html_e('Not a cron service', 'um-cron-service'); ?></option>
				<option value="standard" <?php selected($tier, 'standard'); ?>><?php esc_html_e('Standard (1,000 sites)', 'um-cron-service'); ?></option>
				<option value="unlimited" <?php selected($tier, 'unlimited'); ?>><?php esc_html_e('Unlimited', 'um-cron-service'); ?></option>
			</select>
		</label>
		<?php
	}

	/**
	 * Save variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 * @return void
	 */
	public function save_variation_fields(int $variation_id, int $loop): void {
		if (isset($_POST['variable_cron_service_tier'][$loop])) {
			update_post_meta(
				$variation_id,
				'_cron_service_tier',
				sanitize_text_field($_POST['variable_cron_service_tier'][$loop])
			);
		}
	}
}
