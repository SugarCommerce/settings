<?php
/**
 * Settings Page: Values
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Page;

/**
 * Reading and writing one stored value, and the fields the page holds.
 *
 * Part of Page: only the class uses it.
 */
trait Values {

	/**
	 * Get every stored value, as stored.
	 *
	 * Encrypted values are ciphertext here. Read those through get_value(),
	 * which goes through the decorators.
	 *
	 * @return array<string, mixed>
	 */
	public function get_values(): array {
		return $this->options->values();
	}

	/**
	 * Get one field's value, decrypted, or the constant standing in for it.
	 *
	 * @param string $field_key Field key.
	 * @param mixed  $fallback  Returned when the field has no value and no default.
	 *
	 * @return mixed
	 */
	public function get_value( string $field_key, mixed $fallback = null ): mixed {
		$field = $this->set()->field( $field_key );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}

	/**
	 * Write one value, through the same decorators a form save uses.
	 *
	 * The counterpart to get_value(), and the only way to set a field marked
	 * encrypted from code. Writing the option directly stores plaintext where
	 * ciphertext is expected, and the read side authenticates before
	 * decrypting -- so the value comes back as an empty string rather than as
	 * what was written. An activation routine, a WP-CLI command or a test
	 * that sets an API key that way finds it silently missing.
	 *
	 * Targeted rather than going through FieldSet::save(), which iterates
	 * every field in the set and deletes the ones absent from its input.
	 *
	 * @param string $field_key The field.
	 * @param mixed  $value     What to store.
	 *
	 * @return bool False when there is no such field.
	 */
	public function set_value( string $field_key, mixed $value ): bool {
		$field = $this->set()->field( $field_key );

		if ( null === $field ) {
			return false;
		}

		$this->context->write( 0, $field, $value );
		$this->options->save();

		return true;
	}

	/**
	 * Remove one value.
	 *
	 * @param string $field_key The field.
	 *
	 * @return bool False when there is no such field.
	 */
	public function delete_value( string $field_key ): bool {
		$field = $this->set()->field( $field_key );

		if ( null === $field ) {
			return false;
		}

		$this->context->delete( 0, $field );
		$this->options->save();

		return true;
	}

	/**
	 * Get the field configuration, keyed by field key.
	 *
	 * As configured, with a badged section's badge copied onto each of its
	 * fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get one field's configuration.
	 *
	 * @param string $field_key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_field( string $field_key ): ?array {
		return $this->fields[ $field_key ] ?? null;
	}
}
