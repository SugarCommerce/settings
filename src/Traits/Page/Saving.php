<?php
/**
 * Settings Page: Saving
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Page;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;

/**
 * Registering the option and sanitizing a save: only the tab sent, refused values kept as typed.
 *
 * Part of Page: only the class uses it.
 */
trait Saving {

	/**
	 * Register the option with WordPress's Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			(string) $this->config['option_group'],
			(string) $this->config['option_name'],
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);

		// options.php runs its own capability check before it saves, and it
		// does not know what the menu was registered with: it asks this
		// filter and falls back to manage_options. Without it a page
		// registered for a lower capability drew for its users and then
		// refused every save with "Sorry, you are not allowed to manage
		// options."
		add_filter( 'option_page_capability_' . (string) $this->config['option_group'], [ $this, 'option_page_capability' ] );
	}

	/**
	 * The capability options.php checks before it saves this page's option.
	 *
	 * The same one that gates the menu, the render and the admin-post
	 * handlers, so the user who can see the page can save it.
	 *
	 * @return string
	 */
	public function option_page_capability(): string {
		return (string) $this->config['capability'];
	}

	/**
	 * Produce the array to store from a submitted or supplied one.
	 *
	 * `update_option()` runs `sanitize_option()` before it compares values, so
	 * this is the single gate every write passes through — the form's, an
	 * import's, a reset's, and any that consuming code makes itself. Two
	 * consequences shape it.
	 *
	 * It must not write anything. A `update_option()` call from in here would
	 * re-enter this method from inside itself, so the values are collected in
	 * an array rather than staged in the option's own context.
	 *
	 * And it must be safe to run twice on its own output, because a caller is
	 * free to hand back what it just read. That is why an already-encrypted
	 * value passes through the encrypting context untouched.
	 *
	 * @param mixed $input Values to sanitize.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		// A submission carries one tab, so the fields it does not carry must
		// be left alone rather than read as cleared. Anything else — an
		// import, a reset, a direct update_option() — is a write of the whole
		// value and is sanitized against every field.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the nonce before the option is sanitized.
		$tab = isset( $_POST[ $this->tab_input() ] ) ? sanitize_key( wp_unslash( $_POST[ $this->tab_input() ] ) ) : '';
		$tab = isset( $this->tabs[ $tab ] ) ? $tab : '';

		$collector = new ArrayContext( $this->seed( $tab ) );

		$set = new FieldSet(
			$this->fields_governed_by( $tab, $input ),
			$this->decorate( $collector ),
			(string) $this->config['option_name']
		);

		// Re-slashed because the Settings API unslashes before it gets here
		// and the field set unslashes at its own boundary. Without this the
		// values are unslashed twice and every backslash a user typed is
		// eaten by the save that stores it.
		$set->save( wp_slash( $input ) );

		return $this->ordered_like_stored( $this->with_refused( $set, $collector->values() ) );
	}

	/**
	 * Report each field the set refused, and keep what it held.
	 *
	 * The messages go to the Settings API under this page's option name,
	 * which is where options.php looks once every option is updated: it adds
	 * "Settings saved." only when nothing has been registered, carries
	 * whatever has into the transient the next load reads, and
	 * settings_errors() prints them at the top of the page. A refused email
	 * address is therefore reported as such rather than announced as saved.
	 * Guarded, because the Settings API is admin-only and this gate also
	 * runs for a write from cron or a webhook, where there is no page to
	 * report to.
	 *
	 * The stored value is put back by hand for the write that would
	 * otherwise lose it. A form save seeds the collector with everything
	 * stored and the set leaves a failing field alone, so it survives on its
	 * own; a whole-value write seeds no field at all, and without this the
	 * value would be dropped — which is the one thing the kit promises a
	 * refused value is not.
	 *
	 * @param FieldSet             $set    The set that just saved.
	 * @param array<string, mixed> $values What it collected.
	 *
	 * @return array<string, mixed>
	 */
	private function with_refused( FieldSet $set, array $values ): array {
		$stored = $this->options->values();

		foreach ( $set->errors() as $key => $message ) {
			if ( array_key_exists( $key, $stored ) ) {
				$values[ $key ] = $stored[ $key ];
			}

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( (string) $this->config['option_name'], (string) $key, $message, 'error' );
			}
		}

		return $values;
	}

	/**
	 * Put a sanitized array back into the order the option is stored in.
	 *
	 * Cosmetic in the database and not cosmetic above it. `update_option()`
	 * compares the new value with the old before deciding to write, and an
	 * array comparison is order-sensitive, so a pass that reordered the keys
	 * reported a change on every save that changed nothing — a write, a cache
	 * invalidation and an `updated_option` firing each time anything touched
	 * the option.
	 *
	 * @param array<string, mixed> $values Sanitized values.
	 *
	 * @return array<string, mixed>
	 */
	private function ordered_like_stored( array $values ): array {
		$ordered = [];

		foreach ( array_keys( $this->options->values() ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$ordered[ $key ] = $values[ $key ];
			}
		}

		// Anything the store did not already hold keeps the order it was
		// collected in, which is the order the fields are configured in.
		return $ordered + $values;
	}

	/**
	 * Which fields a sanitize pass governs.
	 *
	 * A form submission governs every field on the tab it came from, present
	 * or not: an unticked checkbox and an emptied select are both absent from
	 * a submission, and reading absence as "leave it alone" is how unticking
	 * a box never saves.
	 *
	 * A write that is not a submission governs only the keys it carries. The
	 * same reasoning inverted: nothing about `update_option( $option, [ 'a'
	 * => 1 ] )` says the caller has an opinion on the other forty fields, and
	 * treating absence as "off" there wrote a 0 for every checkbox and number
	 * field on the page on any write from a cron job or a helper.
	 *
	 * @param string               $tab   The tab being saved, or empty for a
	 *                                    whole-value write.
	 * @param array<string, mixed> $input The values being sanitized.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields_governed_by( string $tab, array $input ): array {
		if ( '' !== $tab ) {
			return $this->fields_for_tab( $tab );
		}

		return array_intersect_key( $this->fields, $input );
	}

	/**
	 * The values a sanitize pass starts from.
	 *
	 * @param string $tab The tab being saved, or empty for a whole-value write.
	 *
	 * @return array<string, mixed>
	 */
	private function seed( string $tab ): array {
		$stored = $this->options->values();

		if ( '' !== $tab ) {
			return $stored;
		}

		// A whole-value write governs every field, so no field carries over.
		// Keys the option holds that are not fields do: another plugin's
		// filter may have put them there and this is not the place to drop
		// them.
		return array_diff_key( $stored, $this->fields );
	}
}
