<?php
/**
 * Options: Readers
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Options;

use SugarCommerce\Fields\Value;

/**
 * A setting read as the kind of value it is: on or off, a list, a number, a page.
 *
 * Part of Options: only the class uses it.
 */
trait Readers {

	/**
	 * Whether a toggle or a checkbox is on.
	 *
	 * Not a truthiness check: a toggle stores "1" and "0", and "0" is a
	 * string PHP calls false but a form field does not always send.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_on( string $key ): bool {
		return Value::is_on( $this->get( $key, false ) );
	}

	/**
	 * Whether a checkbox group or a multiple select has one choice ticked.
	 *
	 * @param string $key    The setting.
	 * @param string $option The choice.
	 *
	 * @return bool
	 */
	public function includes( string $key, string $option ): bool {
		return Value::includes( $this->get( $key, [] ), $option );
	}

	/**
	 * A tags field or a multiple select as a list of strings.
	 *
	 * @param string $key The setting.
	 *
	 * @return string[]
	 */
	public function list( string $key ): array {
		return Value::list( $this->get( $key, [] ) );
	}

	/**
	 * A relational field as a list of object ids.
	 *
	 * @param string $key The setting.
	 *
	 * @return int[]
	 */
	public function ids( string $key ): array {
		return Value::ids( $this->get( $key, [] ) );
	}

	/**
	 * A whole number, or the fallback when what is stored is not one.
	 *
	 * "eighty" is not 0 here, and neither is "8.5": a value that is not a
	 * whole number has not answered, so the fallback stands in for it
	 * rather than a number nobody chose.
	 *
	 * @param string $key      The setting.
	 * @param int    $fallback Returned when there is no whole number.
	 *
	 * @return int
	 */
	public function int( string $key, int $fallback = 0 ): int {
		$value = $this->get( $key, $fallback );

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) <= PHP_INT_MAX ) {
			return (int) $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^\s*[-+]?\d+\s*$/', $value ) ) {
			return (int) trim( $value );
		}

		return $fallback;
	}

	/**
	 * A line of text, or the fallback when what is stored is not one.
	 *
	 * An array or an object is not a string, and "Array" is not what was
	 * meant; a true or false is not one either.
	 *
	 * @param string $key      The setting.
	 * @param string $fallback Returned when there is no text.
	 *
	 * @return string
	 */
	public function string( string $key, string $fallback = '' ): string {
		$value = $this->get( $key, $fallback );

		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : $fallback;
	}

	/**
	 * The id of the page or post a setting points at.
	 *
	 * @param string $key The setting.
	 *
	 * @return int 0 when none is set.
	 */
	public function page_id( string $key ): int {
		return Value::id( $this->get( $key, 0 ) );
	}

	/**
	 * The address of the page or post a setting points at.
	 *
	 * @param string $key      The setting.
	 * @param string $fallback Returned when none is set.
	 *
	 * @return string The page's address, the fallback, or the home page.
	 */
	public function page_url( string $key, string $fallback = '' ): string {
		return Value::url( $this->get( $key, 0 ), $fallback );
	}

	/**
	 * Whether the request is for the page or post a setting points at.
	 *
	 * Compares the queried object rather than calling is_page(): a setting
	 * can point at any post type -- the `post`, `page` and `ajax` fields all
	 * store an id the same way -- and is_page() answers false for every one
	 * of them that is not a page.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_page( string $key ): bool {
		return Value::is_viewing( $this->get( $key, 0 ) );
	}
}
