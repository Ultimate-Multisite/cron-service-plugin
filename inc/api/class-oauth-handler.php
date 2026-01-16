<?php
/**
 * OAuth Handler for authenticating client sites.
 *
 * @package UM_Cron_Service
 */

namespace UM_Cron_Service\API;

/**
 * OAuth Handler class.
 */
class OAuth_Handler {

	/**
	 * Validate OAuth token from WooCommerce Store API.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|int User ID if valid, false otherwise.
	 */
	public function validate_oauth_token(\WP_REST_Request $request): bool|int {
		$auth_header = $request->get_header('Authorization');

		if (empty($auth_header)) {
			return false;
		}

		// Check for Bearer token.
		if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
			$token = $matches[1];
			return $this->validate_bearer_token($token);
		}

		// Check for Basic auth.
		if (preg_match('/^Basic\s+(.+)$/i', $auth_header, $matches)) {
			$decoded = base64_decode($matches[1]);
			if ($decoded === false) {
				return false;
			}

			$parts = explode(':', $decoded, 2);
			if (count($parts) !== 2) {
				return false;
			}

			return $this->validate_api_credentials($parts[0], $parts[1]);
		}

		return false;
	}

	/**
	 * Validate a Bearer token against WooCommerce Store API.
	 *
	 * @param string $token The bearer token.
	 * @return bool|int User ID if valid, false otherwise.
	 */
	private function validate_bearer_token(string $token): bool|int {
		// Validate against WooCommerce's application passwords or custom tokens.
		$user_id = $this->get_user_from_application_password($token);

		if ($user_id) {
			return $user_id;
		}

		// Try to validate against stored access tokens.
		return $this->validate_access_token($token);
	}

	/**
	 * Get user from application password.
	 *
	 * @param string $password The application password.
	 * @return int|false User ID or false.
	 */
	private function get_user_from_application_password(string $password): int|false {
		if (!function_exists('wp_authenticate_application_password')) {
			return false;
		}

		// Try to find user with this application password.
		$users = get_users([
			'meta_key'   => '_application_passwords',
			'meta_compare' => 'EXISTS',
		]);

		foreach ($users as $user) {
			$authenticated = wp_authenticate_application_password($user, $user->user_login, $password);
			if ($authenticated && !is_wp_error($authenticated)) {
				return $user->ID;
			}
		}

		return false;
	}

	/**
	 * Validate access token stored in database.
	 *
	 * @param string $token The access token.
	 * @return int|false User ID or false.
	 */
	private function validate_access_token(string $token): int|false {
		global $wpdb;

		$token_hash = hash('sha256', $token);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, expires_at FROM {$wpdb->prefix}cron_service_access_tokens WHERE token_hash = %s",
				$token_hash
			)
		);

		if (!$result) {
			return false;
		}

		// Check expiration.
		if ($result->expires_at && strtotime($result->expires_at) < time()) {
			return false;
		}

		return (int) $result->user_id;
	}

	/**
	 * Validate API key and secret credentials.
	 *
	 * @param string $api_key    The API key.
	 * @param string $api_secret The API secret.
	 * @return int|false User ID or false.
	 */
	public function validate_api_credentials(string $api_key, string $api_secret): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$site = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, status FROM {$wpdb->prefix}cron_service_sites WHERE api_key = %s AND api_secret = %s",
				$api_key,
				$api_secret
			)
		);

		if (!$site) {
			return false;
		}

		// Check if site is active or pending.
		if (!in_array($site->status, ['active', 'pending'], true)) {
			return false;
		}

		return (int) $site->user_id;
	}

	/**
	 * Get site from API credentials.
	 *
	 * @param string $api_key    The API key.
	 * @param string $api_secret The API secret.
	 * @return object|null Site object or null.
	 */
	public function get_site_from_credentials(string $api_key, string $api_secret): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cron_service_sites WHERE api_key = %s AND api_secret = %s",
				$api_key,
				$api_secret
			)
		);
	}

	/**
	 * Generate a new API key.
	 *
	 * @return string The API key.
	 */
	public function generate_api_key(): string {
		return 'cs_' . wp_generate_password(32, false);
	}

	/**
	 * Generate a new API secret.
	 *
	 * @return string The API secret.
	 */
	public function generate_api_secret(): string {
		return 'cs_secret_' . wp_generate_password(48, false);
	}

	/**
	 * Generate a site hash.
	 *
	 * @param string $site_url The site URL.
	 * @return string The site hash.
	 */
	public function generate_site_hash(string $site_url): string {
		return hash('sha256', $site_url . wp_salt('auth'));
	}

	/**
	 * Check if user has an active cron service subscription.
	 *
	 * @param int $user_id The user ID.
	 * @return bool Whether the user has an active subscription.
	 */
	public function user_has_active_subscription(int $user_id): bool {
		if (!function_exists('wcs_get_users_subscriptions')) {
			return false;
		}

		$subscriptions = wcs_get_users_subscriptions($user_id);

		foreach ($subscriptions as $subscription) {
			if (!$subscription->has_status('active')) {
				continue;
			}

			// Check if subscription includes cron service product.
			foreach ($subscription->get_items() as $item) {
				$product = $item->get_product();
				if ($product && $this->is_cron_service_product($product)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if a product is a cron service product.
	 *
	 * @param \WC_Product $product The product.
	 * @return bool Whether it's a cron service product.
	 */
	public function is_cron_service_product(\WC_Product $product): bool {
		$product_id = $product->get_id();

		// Check by SKU.
		$sku = $product->get_sku();
		if (strpos($sku, 'external-cron-service') !== false) {
			return true;
		}

		// Check by meta.
		$is_cron_service = get_post_meta($product_id, '_is_cron_service_product', true);
		if ($is_cron_service === 'yes') {
			return true;
		}

		// Check parent product for variations.
		if ($product->is_type('variation')) {
			$parent_id = $product->get_parent_id();
			$parent_sku = get_post_meta($parent_id, '_sku', true);
			if (strpos($parent_sku, 'external-cron-service') !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get user's site limit based on subscription.
	 *
	 * @param int $user_id The user ID.
	 * @return int The site limit (PHP_INT_MAX for unlimited).
	 */
	public function get_user_site_limit(int $user_id): int {
		if (!function_exists('wcs_get_users_subscriptions')) {
			return 0;
		}

		$subscriptions = wcs_get_users_subscriptions($user_id);
		$max_limit     = 0;

		foreach ($subscriptions as $subscription) {
			if (!$subscription->has_status('active')) {
				continue;
			}

			foreach ($subscription->get_items() as $item) {
				$product = $item->get_product();
				if (!$product || !$this->is_cron_service_product($product)) {
					continue;
				}

				$product_id = $product->get_id();
				$tier       = get_post_meta($product_id, '_cron_service_tier', true);

				$limit = match ($tier) {
					'standard'  => 1000,
					'unlimited' => PHP_INT_MAX,
					default     => 1000,
				};

				$max_limit = max($max_limit, $limit);
			}
		}

		return $max_limit;
	}
}
