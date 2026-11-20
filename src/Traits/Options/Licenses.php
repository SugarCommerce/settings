<?php
/**
 * Options: Licenses
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Options;

/**
 * A licence field's key, status and expiry.
 *
 * Part of Options: only the class uses it.
 */
trait Licenses {

	/**
	 * A `license` field's key, status and expiry.
	 *
	 * @param string $key The setting.
	 *
	 * @return array{key: string, status: string, expiry: string}
	 */
	public function license( string $key ): array {
		$value = $this->get( $key, [] );

		// The field itself stores the key alone; the status and expiry are
		// what a licence check wrote beside it.
		$value = is_array( $value ) ? $value : [ 'key' => is_scalar( $value ) ? (string) $value : '' ];

		return [
			'key'    => (string) ( $value['key'] ?? '' ),
			'status' => (string) ( $value['status'] ?? 'inactive' ),
			'expiry' => (string) ( $value['expiry'] ?? '' ),
		] + $value;
	}

	/**
	 * Whether a licence is active.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_license_active( string $key ): bool {
		return 'active' === $this->license( $key )['status'];
	}

	/**
	 * Change a licence's status, and its expiry if one is given.
	 *
	 * For a cron job, a webhook or a remote check that learns the licence has
	 * changed without anybody opening the settings page. The key is kept.
	 *
	 * @param string $key    The setting.
	 * @param string $status inactive, active, expired or invalid.
	 * @param string $expiry A new expiry, or empty to keep the one stored.
	 *
	 * @return bool False for a status that is not one of the four.
	 */
	public function update_license_status( string $key, string $status, string $expiry = '' ): bool {
		if ( ! in_array( $status, self::LICENSE_STATUSES, true ) ) {
			return false;
		}

		$license = $this->license( $key );

		$license['status'] = $status;

		if ( '' !== $expiry ) {
			$license['expiry'] = sanitize_text_field( $expiry );
		}

		return $this->update( $key, $license );
	}
}
