<?php
/**
 * Settings Page: Rendering
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Page;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Fields\Support\Markup;
use SugarCommerce\Fields\Support\PageHeader;
use SugarCommerce\Fields\Support\Sections;
use SugarCommerce\Fields\Support\Tooltip;
use SugarCommerce\Fields\Utils\Runtime as FieldsRuntime;
use SugarCommerce\Settings\Cards;
use SugarCommerce\Settings\Sidebar;

/**
 * Drawing the page: tabs, the form, sections and cards, rows and their refusals.
 *
 * Part of Page: only the class uses it.
 */
trait Rendering {

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( (string) $this->config['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'sugarcommerce' ) );
		}

		$tab = $this->current_tab();

		if ( $this->has_sidebar() ) {
			$this->render_sidebar_page( $tab );

			return;
		}

		// Outside .wrap, exactly as options-privacy.php renders it. Inside,
		// .wrap's own margins inset the header and it stops looking like
		// core's — which is the whole point of using core's markup.
		//
		// It ends in the <hr class="wp-header-end"> that common.js moves
		// admin notices to.
		$header = PageHeader::render(
			[
				'title'   => (string) ( '' !== (string) $this->config['header_title'] ? $this->config['header_title'] : $this->config['page_title'] ),
				'logo'    => (string) $this->config['logo'],
				'badge'   => $this->config['badge'],
				'tabs'    => $this->tab_links(),
				'current' => $tab,
			]
		);

		echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.

		$modern = $this->is_modern();

		// Stamped for the field kit's build that draws this page: its save
		// bar and sidebar are written here, by hand, and a copy of the kit
		// drives only markup stamped for it.
		printf( '<div class="wrap field-kit__settings%s"%s>', $modern ? ' field-kit__settings--modern' : '', FieldsRuntime::stamp() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stamp() escapes.

		// As cards, a clean save is acknowledged beside the button that was
		// pressed, so the notices are printed without core's "Settings
		// saved." and the answer is carried down to the bar.
		// Not under the Settings menu, where core has printed them already
		// (see below) and says "Settings saved." itself.
		$saved = $modern
			&& 'options-general.php' !== (string) $this->config['parent_slug']
			&& Cards::notices();

		// No argument: core adds its own "Settings saved." under the `general`
		// slug, and asking for this page's slug alone would hide it. A field
		// the last save refused is printed here as well, and again under the
		// field itself. Not under the Settings menu, though: admin.php pulls
		// in options-head.php for every child of options-general.php, and
		// that prints them already -- so a page there said "Settings saved."
		// twice.
		if ( ! $modern && 'options-general.php' !== (string) $this->config['parent_slug'] ) {
			settings_errors();
		}

		$this->render_form( $tab, $saved );

		echo '</div>';
	}

	/**
	 * The page with its tabs down the side.
	 *
	 * An ordinary .wrap: core's heading at the top left, the rule core
	 * moves admin notices to, this page's own notices, and then the tabs
	 * and the form side by side.
	 *
	 * @param string $tab The tab being shown.
	 *
	 * @return void
	 */
	private function render_sidebar_page( string $tab ): void {
		$title = (string) ( '' !== (string) $this->config['header_title'] ? $this->config['header_title'] : $this->config['page_title'] );

		echo '<div class="wrap field-kit__settings field-kit__settings--modern field-kit__settings--sidebar"' . FieldsRuntime::stamp() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stamp() escapes.

		// The first tab is the page's front and is not named after the title.
		echo Sidebar::title( $title, array_key_first( $this->tabs ) === $tab ? '' : (string) ( $this->tabs[ $tab ]['label'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.

		echo '<hr class="wp-header-end">';

		// As in the other modern layout: "Settings saved." is said beside
		// the button, and core has printed the notices already under the
		// Settings menu.
		$saved = 'options-general.php' !== (string) $this->config['parent_slug'] && Cards::notices();

		echo '<div class="field-kit__settings-shell">';

		// One tab is not a list worth a column.
		if ( count( $this->tabs ) > 1 ) {
			echo Sidebar::nav( $this->tab_links(), $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		}

		echo '<div class="field-kit__settings-main">';

		$this->render_form( $tab, $saved );

		echo '</div></div></div>';
	}

	/**
	 * The form: one tab's sections and the button that saves them.
	 *
	 * @param string $tab   The tab being shown.
	 * @param bool   $saved Whether this load follows a save that stored
	 *                      everything, for the bar on a modern page.
	 *
	 * @return void
	 */
	private function render_form( string $tab, bool $saved ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'options.php' ) ) );

		// Emits option_page, action and the nonce — and, through
		// wp_nonce_field(), the _wp_http_referer that sends the redirect back
		// to this tab rather than to the first one.
		settings_fields( (string) $this->config['option_group'] );

		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( $this->tab_input() ),
			esc_attr( $tab )
		);

		$this->render_sections( $tab, $this->field_errors() );

		// A button over a tab with nothing it could save -- previews, a log,
		// figures to read -- says there is something to save, and on a
		// modern page it is a bar floating over the content as well.
		if ( $this->config['submit_button'] && self::saves( $this->set( $tab ) ) ) {
			if ( $this->is_modern() ) {
				echo Cards::save_bar( $saved ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			} else {
				submit_button();
			}
		}

		echo '</form>';
	}

	/**
	 * Whether a set holds a field that a save would write.
	 *
	 * The save's own reading (FieldSet's): a type that stores a value, and
	 * not disabled or locked, which send nothing. A read-only control is
	 * shown and not changed, so a tab of them has nothing to save either.
	 *
	 * @param FieldSet $set A tab's fields.
	 *
	 * @return bool
	 */
	private static function saves( FieldSet $set ): bool {
		foreach ( $set->fields() as $field ) {
			if ( $field->type()->stores_value() && ! $field->get( 'disabled' ) && ! $field->get( 'readonly' ) && ! Badge::locks( $field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The messages the last save registered against this page's fields.
	 *
	 * Read back from the Settings API rather than from the set that saved,
	 * because that set belonged to the previous request: options.php saves,
	 * stores what was registered in a transient and redirects, and
	 * get_settings_errors() is what reads that transient on the load that
	 * follows. Only codes that name a field are kept — the import and reset
	 * notices are registered under the same slug and are not about a field.
	 *
	 * @return array<string, string> Keyed by field.
	 */
	private function field_errors(): array {
		$errors = [];

		foreach ( (array) get_settings_errors( (string) $this->config['option_name'] ) as $error ) {
			$code = (string) ( $error['code'] ?? '' );

			if ( 'error' === (string) ( $error['type'] ?? '' ) && isset( $this->fields[ $code ] ) ) {
				$errors[ $code ] = (string) ( $error['message'] ?? '' );
			}
		}

		return $errors;
	}

	/**
	 * Render one tab's sections and fields.
	 *
	 * @param string                $tab    Tab slug.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_sections( string $tab, array $errors ): void {
		$set    = $this->set( $tab );
		$fields = $set->fields();
		$shown  = [];
		$modern = $this->is_modern();

		foreach ( $this->sections as $slug => $section ) {
			$in_section = array_filter(
				$fields,
				static fn( Field $field ) => (string) $field->get( 'section', '' ) === (string) $slug
			);

			// A section with nothing in it is not drawn -- unless it has
			// something to say. A card that only explains (where a key is
			// found, what happens next) is a section with a description and
			// no fields, and it is drawn on the tab it names.
			if ( [] === $in_section && ! $this->is_note_on( (string) $slug, (array) $section, $tab ) ) {
				continue;
			}

			$shown = array_merge( $shown, array_keys( $in_section ) );

			if ( $modern ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				echo Cards::card( $set, $in_section, (array) $section, $this->id . '-section-' . $slug, $errors );

				continue;
			}

			$badge = Badge::resolve( $section['badge'] ?? null );

			printf(
				'<h2 class="title">%s%s</h2>',
				esc_html( (string) ( $section['title'] ?? $slug ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				null === $badge ? '' : Badge::render( $badge )
			);

			if ( '' !== (string) ( $section['description'] ?? '' ) ) {
				printf( '<p class="description">%s</p>', wp_kses_post( (string) $section['description'] ) );
			}

			if ( [] !== $in_section ) {
				$this->render_table( $set, $in_section, (string) $slug, $errors );
			}
		}

		// A field no section claimed still has to appear, or a typo in a
		// section name silently removes it from the page.
		$loose = array_diff_key( $fields, array_flip( $shown ) );

		if ( [] === $loose ) {
			return;
		}

		if ( ! $modern ) {
			$this->render_table( $set, $loose, '', $errors );

			return;
		}

		// A run of panels -- email editors, say -- is a column of cards
		// already. Anything else gets a card with no title, so a field is
		// never drawn straight onto the page's background.
		$markup = Cards::all_span( $loose )
			? Cards::stack( $set, $loose, $errors )
			: Cards::card( $set, $loose, [], $this->id . '-section-', $errors );

		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
	}

	/**
	 * Whether a section with no fields is drawn on a tab.
	 *
	 * Only one with a description: a title over nothing is an empty card.
	 * A section is otherwise placed by its fields, so one without any says
	 * where it goes with `tab`, and is on the first tab when it does not --
	 * where a field with no tab is.
	 *
	 * A section with fields on some other tab is not a note at all. It is
	 * drawn there, with them; read as a note it was drawn a second time,
	 * empty, on the first tab.
	 *
	 * @param string               $slug    The section's slug.
	 * @param array<string, mixed> $section The section's configuration.
	 * @param string               $tab     The tab being drawn.
	 *
	 * @return bool
	 */
	private function is_note_on( string $slug, array $section, string $tab ): bool {
		if ( '' === trim( (string) ( $section['description'] ?? '' ) ) ) {
			return false;
		}

		foreach ( $this->fields as $field ) {
			if ( (string) ( $field['section'] ?? '' ) === $slug ) {
				return false;
			}
		}

		if ( [] === $this->tabs ) {
			return true;
		}

		$on = (string) ( $section['tab'] ?? '' );

		return ( isset( $this->tabs[ $on ] ) ? $on : (string) array_key_first( $this->tabs ) ) === $tab;
	}

	/**
	 * Render a set of fields as a settings table.
	 *
	 * @param FieldSet $set    The field set.
	 * @param Field[]  $fields The fields to render.
	 * @param string   $scope  The section these belong to, or empty for the
	 *                         fields no section claimed.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_table( FieldSet $set, array $fields, string $scope, array $errors ): void {
		$layout = Sections::split( $fields );

		if ( [] !== $layout ) {
			// Scoped by the section rather than by how many fields it has.
			// Two sections on one tab with the same number of fields got the
			// same ids for their tab strips, and the second strip drove the
			// first one's panels.
			$sections = Sections::render(
				$layout,
				function ( array $group ) use ( $set, $errors ): string {
					if ( [] === $group ) {
						return '';
					}

					ob_start();
					$this->render_rows( $set, $group, $errors );

					return (string) ob_get_clean();
				},
				$this->id . '-' . $scope
			);

			echo $sections; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.

			return;
		}

		$this->render_rows( $set, $fields, $errors );
	}

	/**
	 * A set of fields as one settings table.
	 *
	 * @param FieldSet              $set    The field set.
	 * @param Field[]               $fields The fields to render.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_rows( FieldSet $set, array $fields, array $errors ): void {
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $field ) {
			// A marker draws nothing, so one left in emits an empty row.
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			$type = $field->type();

			// A heading, a notice or a separator is not a control, and an
			// email editor is a panel — neither belongs in a cell built for
			// one control beside one label.
			if ( $type->spans_row() ) {
				printf(
					'<tr class="field-kit__settings-row field-kit__spans-row"><td colspan="2">%s</td></tr>',
					$set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
				);

				continue;
			}

			// A self-labelling control already carries its own <label for>,
			// and a group of controls has no single element to point at, so
			// both get plain text rather than a second label.
			// The badge belongs beside the heading, and the heading is here —
			// the renderer is told to draw none, so it draws no badge either.
			$badge = Badge::for_field( $field );

			// Beside the heading, which this class draws rather than the kit.
			$tooltip = Tooltip::for_field( $field );

			// The kit marks a required field in the label it draws; this class
			// draws the label, so it marks it here.
			$required = Markup::required_marker( $field->is_required() );

			$header = $type->is_self_labelling() || $type->is_grouped()
				? sprintf(
					'<span class="field-kit__row-label">%s%s%s</span>',
					esc_html( $field->label() ),
					$required, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
					$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				)
				: sprintf(
					'<label for="%s">%s%s</label>%s',
					esc_attr( $field->input_id() ),
					esc_html( $field->label() ),
					$required, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
					$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				);

			printf(
				'<tr class="field-kit__settings-row"><th scope="row">%s</th><td>%s</td></tr>',
				$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
				// The header cell is the visible heading here, so the kit
				// draws none: a group keeps its legend, hidden, so the
				// grouping is still announced without appearing twice.
				$set->render_field( $field, $errors[ $field->key() ] ?? '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The tab links for the header.
	 *
	 * The icon is `set:name` (`heroicons-outline:banknotes`) or a Dashicon
	 * (`money-alt`, `dashicons-money-alt`), and is drawn only where the tabs
	 * are a column: core draws no icon on a tab, and does on every entry of
	 * a vertical menu.
	 *
	 * @return array<string, array{label: string, url: string, icon: string}>
	 */
	private function tab_links(): array {
		$links = [];

		foreach ( $this->tabs as $slug => $tab ) {
			$links[ $slug ] = [
				'label' => (string) $tab['label'],
				'url'   => $this->page_url( [ 'tab' => $slug ] ),
				'icon'  => (string) ( $tab['icon'] ?? '' ),
			];
		}

		return $links;
	}
}
