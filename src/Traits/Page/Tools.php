<?php
/**
 * Settings Page: Tools
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Page;

use SugarCommerce\Notices\Notices;

/**
 * Export, import and reset: the screen's tools and the requests they send.
 *
 * Part of Page: only the class uses it.
 */
trait Tools {

	/**
	 * Render the export, import and reset controls into Screen Options.
	 *
	 * They used to sit in the header, where a file input and three buttons
	 * across a centred title looked exactly as bad as it sounds. Screen
	 * Options is a panel WordPress already provides, it opens next to Help,
	 * and it is where a screen's occasional controls belong — not beside its
	 * title, which is read on every visit.
	 *
	 * Hooked on `screen_settings` rather than printed: that filter is also
	 * what makes the toggle appear at all, since core only shows Screen
	 * Options when something has put content in it.
	 *
	 * @param string    $settings Existing panel markup.
	 * @param \WP_Screen $screen   The current screen.
	 *
	 * @return string
	 */
	public function render_screen_tools( string $settings, $screen ): string {
		if ( '' === $this->hook_suffix || ! is_object( $screen ) || $this->hook_suffix !== ( $screen->id ?? '' ) ) {
			return $settings;
		}

		$tools = '';

		if ( $this->config['export_import'] ) {
			$tools .= $this->tool(
				__( 'Export', 'sugarcommerce' ),
				__( 'Download this page\'s settings as a JSON file. Encrypted values are left out — they cannot be read on another site.', 'sugarcommerce' ),
				$this->action_form( 'export', __( 'Export settings', 'sugarcommerce' ), 'button' )
			);

			$tools .= $this->tool(
				__( 'Import', 'sugarcommerce' ),
				__( 'Read a settings file back. Every value is sanitized by its own field, and anything that is not a field on this page is ignored.', 'sugarcommerce' ),
				$this->import_form()
			);
		}

		if ( $this->config['reset_button'] ) {
			$tools .= $this->tool(
				__( 'Reset', 'sugarcommerce' ),
				__( 'Restore every setting on the tab you are viewing to its default.', 'sugarcommerce' ),
				$this->action_form(
					'reset',
					__( 'Reset this tab', 'sugarcommerce' ),
					'button button-link-delete',
					__( 'Reset every setting on this tab to its default? This cannot be undone.', 'sugarcommerce' )
				)
			);
		}

		return '' === $tools
			? $settings
			: $settings . sprintf( '<div class="field-kit__screen-tools">%s</div>', $tools );
	}

	/**
	 * One labelled group inside the Screen Options panel.
	 *
	 * @param string $title       Group heading.
	 * @param string $description What it does.
	 * @param string $controls    The controls themselves.
	 *
	 * @return string
	 */
	private function tool( string $title, string $description, string $controls ): string {
		// A fieldset with a legend, because these are groups of controls and
		// a legend is the only heading a group gets announced by. The legend
		// has to be the fieldset's first child, so the description goes after
		// it rather than wrapping it.
		return sprintf(
			'<fieldset class="field-kit__screen-tool">' .
			'<legend class="field-kit__screen-tool-title">%s</legend>' .
			'<p class="description">%s</p>' .
			'<div class="field-kit__screen-tool-controls">%s</div>' .
			'</fieldset>',
			esc_html( $title ),
			esc_html( $description ),
			$controls
		);
	}

	/**
	 * A single-button form posting to admin-post.
	 *
	 * A form rather than a link, because these change state and a link that
	 * changes state can be followed by a prefetch or a crawler.
	 *
	 * @param string $action  Action slug.
	 * @param string $label   Button label.
	 * @param string $classes Button classes.
	 * @param string $confirm Optional confirmation prompt.
	 *
	 * @return string
	 */
	private function action_form( string $action, string $label, string $classes, string $confirm = '' ): string {
		return sprintf(
			'<form method="post" action="%s"%s>%s' .
			'<input type="hidden" name="action" value="%s" />' .
			'<input type="hidden" name="tab" value="%s" />' .
			'<button type="submit" class="%s">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			'' === $confirm ? '' : sprintf( ' onsubmit="return confirm(%s)"', esc_attr( (string) wp_json_encode( $confirm ) ) ),
			wp_nonce_field( $this->action_slug( $action ), '_wpnonce', false, false ),
			esc_attr( $this->action_slug( $action ) ),
			esc_attr( $this->current_tab() ),
			esc_attr( $classes ),
			esc_html( $label )
		);
	}

	/**
	 * The import form, which needs a file input.
	 *
	 * @return string
	 */
	private function import_form(): string {
		// core's own import form, from wp_import_upload_form(): a
		// .wp-upload-form, a *visible* label saying what to choose, the size
		// limit beside it, and a plain file input. A file input is what core
		// uses — hiding one behind a styled button is the part that would not
		// be core-like.
		//
		// Numbered throughout: the id appears twice, and mixing numbered with
		// unnumbered placeholders advances two separate counters.
		$bytes = wp_max_upload_size();

		return sprintf(
			'<form method="post" enctype="multipart/form-data" action="%1$s" class="wp-upload-form">%2$s' .
			'<input type="hidden" name="action" value="%3$s" />' .
			'<input type="hidden" name="max_file_size" value="%7$d" />' .
			'<p><label for="%4$s-import">%5$s</label> <span class="description">(%8$s)</span></p>' .
			'<p><input type="file" id="%4$s-import" name="import" accept="application/json,.json" required /></p>' .
			'<p class="submit"><button type="submit" class="button">%6$s</button></p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( $this->action_slug( 'import' ), '_wpnonce', false, false ),
			esc_attr( $this->action_slug( 'import' ) ),
			esc_attr( $this->id ),
			esc_html__( 'Choose a settings file from your computer:', 'sugarcommerce' ),
			esc_html__( 'Import', 'sugarcommerce' ),
			(int) $bytes,
			sprintf(
				/* translators: %s: maximum allowed upload size */
				esc_html__( 'Maximum size: %s', 'sugarcommerce' ),
				esc_html( size_format( $bytes ) )
			)
		);
	}

	/**
	 * The admin-post action name for one operation.
	 *
	 * @param string $action Operation name.
	 *
	 * @return string
	 */
	private function action_slug( string $action ): string {
		return sanitize_key( $this->id . '_' . $action );
	}

	/**
	 * Send the settings as a JSON download.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		$this->authorize( 'export' );

		$payload = (string) wp_json_encode( $this->export_payload(), JSON_PRETTY_PRINT );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $this->id . '-' . gmdate( 'Y-m-d' ) . '.json' ) . '"' );
		header( 'Content-Length: ' . strlen( $payload ) );

		echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, which escaping would corrupt.

		exit;
	}

	/**
	 * What an export writes.
	 *
	 * Separate from sending it because a method that ends in exit() cannot
	 * be asserted on, and what is left out of this is the part worth
	 * asserting on.
	 *
	 * @return array<string, mixed>
	 */
	public function export_payload(): array {
		$values = $this->options->values();

		// Encrypted values are left out rather than exported. The key comes
		// from this site's salts, so they cannot be read anywhere else — and
		// exporting them would put a credential in a file for no benefit.
		foreach ( $this->fields as $key => $field ) {
			if ( ! empty( $field['encrypted'] ) ) {
				unset( $values[ (string) $key ] );
			}
		}

		return [
			'id'      => $this->id,
			'version' => 1,
			'values'  => $values,
		];
	}

	/**
	 * Read settings back from an uploaded file.
	 *
	 * @return void
	 */
	public function handle_import(): void {
		$this->authorize( 'import' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$file = isset( $_FILES['import'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_FILES['import'] ) ) : [];

		// is_uploaded_file() as well as the error code: it is what makes the
		// path PHP wrote the only one that can be read here.
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) ) {
			$this->redirect_with_notice( __( 'No file was uploaded.', 'sugarcommerce' ), 'error' );
		}

		$json = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload, not a remote request.

		// Checked before decoding: an upload is untrusted, and a file that is
		// not JSON is refused as "not a settings export" without building it.
		$this->apply_import( json_validate( $json ) ? json_decode( $json, true ) : null );
	}

	/**
	 * Apply a decoded import and redirect.
	 *
	 * Separate from reading the upload so the decisions can be exercised
	 * without a real one — `is_uploaded_file()` is true of nothing a test can
	 * create, and weakening that check to make the test easier would trade a
	 * security property for a convenience.
	 *
	 * @param mixed $decoded The decoded file contents.
	 *
	 * @return void
	 */
	public function apply_import( mixed $decoded ): void {
		if ( ! is_array( $decoded ) || ! isset( $decoded['values'] ) || ! is_array( $decoded['values'] ) ) {
			$this->redirect_with_notice( __( 'That file is not a settings export.', 'sugarcommerce' ), 'error' );
		}

		if ( (string) ( $decoded['id'] ?? '' ) !== $this->id ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: 1: the page the file was exported from, 2: this page */
					__( 'That file is for "%1$s", not "%2$s".', 'sugarcommerce' ),
					// From the file: text here, or a file naming a script
					// would put its markup on the page.
					esc_html( (string) ( $decoded['id'] ?? '' ) ),
					esc_html( $this->id )
				),
				'error'
			);
		}

		// Not written straight to the option: an uploaded file is untrusted,
		// and the registered sanitize callback is what makes every value pass
		// through its own field type. A key that is not a field is dropped
		// there rather than becoming part of the option.
		update_option( (string) $this->config['option_name'], $decoded['values'] );

		$this->redirect_with_notice( __( 'Settings imported.', 'sugarcommerce' ), 'success' );
	}

	/**
	 * Reset a tab's settings to their defaults.
	 *
	 * @return void
	 */
	public function handle_reset(): void {
		$this->authorize( 'reset' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$tab = isset( $this->tabs[ $tab ] ) ? $tab : '';

		$values = $this->options->values();

		foreach ( array_keys( '' === $tab ? $this->fields : $this->fields_for_tab( $tab ) ) as $key ) {
			unset( $values[ (string) $key ] );
		}

		// A field with no stored value renders its configured default, so
		// removing the keys is the reset. This runs through sanitize() like
		// every other write, and survives it because an already-encrypted
		// value is not encrypted again.
		update_option( (string) $this->config['option_name'], $values );

		$this->redirect_with_notice( __( 'Settings reset.', 'sugarcommerce' ), 'success' );
	}

	/**
	 * Verify the nonce and capability for an admin-post action.
	 *
	 * @param string $action Operation name.
	 *
	 * @return void
	 */
	private function authorize( string $action ): void {
		check_admin_referer( $this->action_slug( $action ) );

		if ( ! current_user_can( (string) $this->config['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sugarcommerce' ) );
		}
	}

	/**
	 * Leave a message for this user and go back to the settings page.
	 *
	 * Through sugarcommerce/notices' flash(): kept for the user who did it,
	 * a minute at most, and shown once on the screen the redirect lands on
	 * -- not core's site-wide `settings_errors` transient, which any admin
	 * loading a settings page in the next thirty seconds would read.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 *
	 * @return void
	 */
	private function redirect_with_notice( string $message, string $type ): void {
		Notices::flash( (string) $this->config['option_name'], $message, $type );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

		wp_safe_redirect(
			$this->page_url(
				[ 'tab' => $tab ]
			)
		);

		exit;
	}
}
