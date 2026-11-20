<?php
/**
 * Options: Emails
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Options;

/**
 * An email_editor field read as the email it configures.
 *
 * Part of Options: only the class uses it.
 */
trait Emails {

	/**
	 * What the site owner typed into an `email_editor` field, in the shape
	 * sugarcommerce/emails reads.
	 *
	 * The field stores `recipient`, `subject`, `heading` and `body`, and an
	 * `enabled` toggle if it was given one. `Emails::add()`'s `settings`
	 * callable is read for `enabled`, `to`, `subject`, `content` and
	 * `context`. This is the seam between the two:
	 *
	 *     Emails::add( 'my_plugin', 'purchase_receipt', [
	 *         'settings' => fn() => my_plugin_options()->email( 'email_purchase_receipt' ),
	 *     ] );
	 *
	 * A part the owner left empty is left out, so the email's registered
	 * default stands in for it.
	 *
	 * @param string $key The setting.
	 *
	 * @return array{enabled?: bool, to?: string[], subject?: string, content?: string, context?: array{title: string}}
	 */
	public function email( string $key ): array {
		$value = $this->get( $key );

		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		$result = [];

		if ( isset( $value['enabled'] ) ) {
			$result['enabled'] = (bool) $value['enabled'];
		}

		// Several addresses, separated by commas -- which is what the field
		// says beside its recipient box.
		$to = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $value['recipient'] ?? '' ) ) ) );

		if ( [] !== $to ) {
			$result['to'] = array_values( $to );
		}

		if ( ! empty( $value['subject'] ) ) {
			$result['subject'] = (string) $value['subject'];
		}

		// The editor stores the body without its <p> tags, as wp_editor
		// does; wpautop() puts them back.
		if ( ! empty( $value['body'] ) ) {
			$result['content'] = wpautop( (string) $value['body'] );
		}

		// The heading is the template's title, which sits above the body.
		if ( ! empty( $value['heading'] ) ) {
			$result['context'] = [ 'title' => (string) $value['heading'] ];
		}

		return $result;
	}

	/**
	 * Who an `email_editor` field's email goes to, or the site's admin.
	 *
	 * @param string $key The setting.
	 *
	 * @return string
	 */
	public function email_recipient( string $key ): string {
		$value = $this->get( $key );

		if ( is_array( $value ) && ! empty( $value['recipient'] ) && is_email( $value['recipient'] ) ) {
			return (string) $value['recipient'];
		}

		return (string) get_option( 'admin_email', '' );
	}

	/**
	 * Whether an `email_editor` field's email is on.
	 *
	 * On when nothing is stored: an email nobody has turned off is sent.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_email_enabled( string $key ): bool {
		$value = $this->get( $key );

		if ( is_array( $value ) && isset( $value['enabled'] ) ) {
			return (bool) $value['enabled'];
		}

		return true;
	}
}
