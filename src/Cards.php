<?php
/**
 * Setting Cards
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Fields\Support\Sections;

/**
 * The markup of a settings page whose appearance is `modern`.
 *
 * The classic page is core's own: a heading, a paragraph and a form table
 * per section, a label in the left column and its control in the right. It
 * is what every settings screen in WordPress looks like and it is the
 * default for that reason.
 *
 * This is the other shape, for a plugin whose panels and reports are already
 * drawn as cards: each section is a white card with its title and one line
 * under it, each field is drawn by the kit with its label above it -- the
 * same call a panel makes, so a toggle here is the toggle there -- and the
 * save button rides in a bar that stays in reach however long the tab is.
 *
 * Nothing here decides what is stored. It is given a field set and asked
 * for markup; the saving, the sanitising and the refusing are Page'
 * and are the same for both appearances.
 */
final class Cards {

	/**
	 * The tones a card's status can be drawn in.
	 *
	 * @var string[]
	 */
	private const TONES = [ 'success', 'warning', 'error', 'info', 'neutral' ];

	/**
	 * One section, as a card.
	 *
	 * A section marked `collapsed` is a <details>: folded until it is asked
	 * for, by the browser rather than by a script, so it still opens with no
	 * JavaScript and find-in-page still reaches what is inside. Its controls
	 * are submitted whether it is open or not. It is drawn open when one of
	 * its fields was refused by the last save -- a message nobody can see is
	 * not a message.
	 *
	 * @param FieldSet              $set     The field set.
	 * @param Field[]               $fields  The section's fields.
	 * @param array<string, mixed>  $section The section's configuration; empty
	 *                                       for fields no section claimed.
	 * @param string                $scope   Something unique to the section, for ids.
	 * @param array<string, string> $errors  Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	public static function card( FieldSet $set, array $fields, array $section, string $scope, array $errors ): string {
		$title       = (string) ( $section['title'] ?? '' );
		$description = (string) ( $section['description'] ?? '' );
		$body        = self::body( $set, $fields, $scope, $errors );

		$description = '' === $description
			? ''
			// A div, not a paragraph: a description can be a short list of
			// steps, and a list inside a <p> is closed by the browser before
			// it starts.
			: sprintf( '<div class="field-kit__settings-card-description">%s</div>', wp_kses_post( $description ) );

		$heading = '' === $title
			? ''
			: sprintf(
				'<h2 class="field-kit__settings-card-title">%s%s%s</h2>',
				esc_html( $title ),
				self::status( $section['status'] ?? null ),
				self::badge( $section['badge'] ?? null )
			);

		$id = sanitize_key( $scope );

		if ( '' !== $title && ! empty( $section['collapsed'] ) ) {
			$open = [] !== array_filter(
				$fields,
				static fn( Field $field ): bool => isset( $errors[ $field->key() ] )
			);

			return sprintf(
				'<details class="field-kit__settings-card field-kit__settings-card--collapsible" id="%s"%s>' .
				'<summary class="field-kit__settings-card-header">%s</summary>%s%s</details>',
				esc_attr( $id ),
				$open ? ' open' : '',
				$heading,
				$description,
				$body
			);
		}

		return sprintf(
			'<section class="field-kit__settings-card" id="%s">%s%s</section>',
			esc_attr( $id ),
			'' === $heading && '' === $description
				? ''
				: sprintf( '<header class="field-kit__settings-card-header">%s%s</header>', $heading, $description ),
			$body
		);
	}

	/**
	 * Fields that are panels in their own right, with no card around them.
	 *
	 * An email editor draws its own box, heading and fold. Inside a card it
	 * is a box in a box; in a column of its own it is a card among cards.
	 *
	 * @param FieldSet              $set    The field set.
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	public static function stack( FieldSet $set, array $fields, array $errors ): string {
		return sprintf( '<div class="field-kit__settings-stack">%s</div>', self::fields( $set, $fields, $errors ) );
	}

	/**
	 * Whether every field is one that draws its own panel.
	 *
	 * @param Field[] $fields The fields.
	 *
	 * @return bool
	 */
	public static function all_span( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( ! $field->type()->spans_row() ) {
				return false;
			}
		}

		return [] !== $fields;
	}

	/**
	 * The bar the save button rides in.
	 *
	 * Sticky to the foot of the window by the stylesheet, so the button is
	 * in reach from anywhere on a long tab. The status beside it is where a
	 * clean save is acknowledged: one word next to the button that was
	 * pressed, in place of a notice across the top of the page that has to
	 * be read and then dismissed.
	 *
	 * @param bool $saved Whether this load follows a save that stored everything.
	 *
	 * @return string
	 */
	public static function save_bar( bool $saved ): string {
		return sprintf(
			'<div class="field-kit__settings-savebar">' .
			// role="status" so the word is announced on the load that
			// follows the save. The script empties it at the first change:
			// "Saved" beside a form that has been edited since is a lie.
			'<span class="field-kit__settings-status" role="status">%s</span>' .
			'<button type="submit" name="submit" class="button button-primary field-kit__settings-save">%s</button>' .
			'</div>',
			$saved
				? self::icon( 'heroicons-outline:check-circle', [ 'size' => 18 ] ) . esc_html__( 'Saved', 'sugarcommerce' )
				: '',
			esc_html__( 'Save', 'sugarcommerce' )
		);
	}

	/**
	 * An icon, in either form a page may give one, ready to print.
	 *
	 * `heroicons-outline:cog-6-tooth` (any `set:name` the field kit has) is
	 * inline SVG in `currentColor`; a Dashicons name (`admin-generic`, or the
	 * class `dashicons-admin-generic`) is the font's span, as a tab's icon
	 * always was. Either way the element has the `sugarcommerce-icon` class.
	 *
	 * @param string               $value An icon.
	 * @param array<string, mixed> $args  `class`, `size` and the rest of
	 *                                    Icons::render()'s.
	 *
	 * @return string Safe markup; empty for anything that is not an icon.
	 * @internal
	 * @since 1.0.0
	 */
	public static function icon( string $value, array $args = [] ): string {
		return Icons::render( $value, $args );
	}

	/**
	 * Print the last save's notices, leaving out the one the bar replaces.
	 *
	 * Core's "Settings saved." is the only thing withheld. A refused field,
	 * an import, a reset and anything another plugin registered are printed
	 * exactly as settings_errors() prints them -- the same ids and classes,
	 * so core's script still makes them dismissible.
	 *
	 * @return bool Whether the save stored everything it was given.
	 */
	public static function notices(): bool {
		$saved = false;
		$other = false;

		foreach ( (array) get_settings_errors() as $error ) {
			$code = (string) ( $error['code'] ?? '' );
			$type = (string) ( $error['type'] ?? 'error' );

			if ( 'settings_updated' === $code && in_array( $type, [ 'success', 'updated' ], true ) ) {
				$saved = true;

				continue;
			}

			$other = true;

			printf(
				'<div id="setting-error-%1$s" class="notice notice-%2$s settings-error is-dismissible"><p><strong>%3$s</strong></p></div>',
				esc_attr( $code ),
				// core's own two renames, from the days before notice-*.
				esc_attr( 'updated' === $type ? 'success' : $type ),
				wp_kses_post( (string) ( $error['message'] ?? '' ) )
			);
		}

		// "Saved" beside a notice saying what was refused would be two
		// answers to one question.
		return $saved && ! $other;
	}

	/**
	 * A section's fields, with the tabs or accordions a marker asked for.
	 *
	 * @param FieldSet              $set    The field set.
	 * @param Field[]               $fields The fields.
	 * @param string                $scope  Something unique to the section, for ids.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private static function body( FieldSet $set, array $fields, string $scope, array $errors ): string {
		// A card that only explains has a header and nothing under it.
		if ( [] === $fields ) {
			return '';
		}

		$layout = Sections::split( $fields );

		if ( [] === $layout ) {
			return self::grid( $set, $fields, $errors );
		}

		return Sections::render(
			$layout,
			static fn( array $group ): string => [] === $group ? '' : self::grid( $set, $group, $errors ),
			$scope
		);
	}

	/**
	 * A set of fields in the grid a card lays them out on.
	 *
	 * @param FieldSet              $set    The field set.
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private static function grid( FieldSet $set, array $fields, array $errors ): string {
		return sprintf( '<div class="field-kit__settings-card-fields">%s</div>', self::fields( $set, $fields, $errors ) );
	}

	/**
	 * Each field as the kit draws it, label and all.
	 *
	 * The classic table draws the heading itself and tells the kit to draw
	 * none. Here the kit draws it, which is what puts the label above the
	 * control, the help icon beside the label and the badge with them --
	 * and what makes a field on this page the same markup as a field in a
	 * panel.
	 *
	 * @param FieldSet              $set    The field set.
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private static function fields( FieldSet $set, array $fields, array $errors ): string {
		$markup = '';

		foreach ( $fields as $field ) {
			// A marker draws nothing itself; Sections has already read it.
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			$markup .= $set->render_field( $field, $errors[ $field->key() ] ?? '' );
		}

		return $markup;
	}

	/**
	 * A short state beside a card's title: "Connected", "Not set up".
	 *
	 * Not a badge. A badge says a section is locked and locks it; this says
	 * how things stand and changes nothing.
	 *
	 * A closure is asked when the card is drawn. A state is usually read
	 * from the very settings this page stores, and those cannot be read
	 * while the page's own configuration is still being built.
	 *
	 * @param mixed $status A label, an array of `label` and `tone`, or a
	 *                      closure returning either.
	 *
	 * @return string
	 */
	private static function status( mixed $status ): string {
		if ( $status instanceof \Closure ) {
			$status = $status();
		}

		if ( ! is_array( $status ) && ! is_string( $status ) ) {
			return '';
		}

		$label = is_array( $status ) ? (string) ( $status['label'] ?? '' ) : $status;
		$tone  = is_array( $status ) ? (string) ( $status['tone'] ?? '' ) : '';

		if ( '' === $label ) {
			return '';
		}

		return sprintf(
			'<span class="field-kit__settings-card-status field-kit__settings-card-status--%s">%s</span>',
			esc_attr( in_array( $tone, self::TONES, true ) ? $tone : 'neutral' ),
			esc_html( $label )
		);
	}

	/**
	 * A section's badge, when it has one.
	 *
	 * @param mixed $badge The section's badge configuration.
	 *
	 * @return string
	 */
	private static function badge( mixed $badge ): string {
		$resolved = Badge::resolve( $badge );

		return null === $resolved ? '' : Badge::render( $resolved );
	}
}
