<?php
/**
 * Settings Page: Screen
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Page;

use SugarCommerce\Fields\Assets;
use SugarCommerce\Fields\Support\PageHeader;

/**
 * The page in wp-admin: its menu item, body classes, help tabs and assets.
 *
 * Part of Page: only the class uses it.
 */
trait Screen {

	/**
	 * Register the menu entry.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) (
			'' !== (string) $this->config['parent_slug']
				? add_submenu_page(
					(string) $this->config['parent_slug'],
					(string) $this->config['page_title'],
					(string) $this->config['menu_title'],
					(string) $this->config['capability'],
					(string) $this->config['menu_slug'],
					[ $this, 'render_page' ]
				)
				: add_menu_page(
					(string) $this->config['page_title'],
					(string) $this->config['menu_title'],
					(string) $this->config['capability'],
					(string) $this->config['menu_slug'],
					[ $this, 'render_page' ],
					(string) $this->config['icon'],
					$this->config['position']
				)
		);

		if ( [] !== (array) $this->config['help_tabs'] || '' !== (string) $this->config['help_sidebar'] ) {
			add_action( 'load-' . $this->hook_suffix, [ $this, 'register_help_tabs' ] );
		}
	}

	/**
	 * Mark the screen as one using the kit's page header.
	 *
	 * The header only spans the screen if `#wpcontent`'s left padding is
	 * removed, and core does that with a body class rather than on the header
	 * itself. The class name comes from the kit so the rule and the class
	 * cannot drift apart.
	 *
	 * @param string $classes Existing body classes.
	 *
	 * @return string
	 */
	public function body_class( string $classes ): string {
		if ( '' === $this->hook_suffix || get_current_screen()?->id !== $this->hook_suffix ) {
			return $classes;
		}

		// With the tabs down the side there is no header to span the
		// screen, so the screen keeps core's own gutters and is not marked
		// as one that gives them up.
		if ( $this->has_sidebar() ) {
			return trim( $classes . ' field-kit__settings-screen--modern field-kit__settings-screen--sidebar' );
		}

		// The second class is for what sits outside the wrap and has to
		// change with it: the surface behind the cards, and the gutters of
		// the notices that land under the header.
		return trim(
			$classes . ' ' . PageHeader::body_class()
			. ( $this->is_modern() ? ' field-kit__settings-screen--modern' : '' )
		);
	}

	/**
	 * Whether the page is drawn as cards.
	 *
	 * Anything but the word `modern` is the classic table, so a typo in
	 * configuration gives the page every WordPress user already knows
	 * rather than a half-styled one.
	 *
	 * @return bool
	 */
	private function is_modern(): bool {
		return 'modern' === (string) $this->config['appearance'];
	}

	/**
	 * Whether the tabs are drawn down the side.
	 *
	 * Only as cards: the classic table is core's settings screen and keeps
	 * core's furniture. Anything but the word `sidebar` is the header with
	 * its row of tabs, for the reason an unknown appearance is classic.
	 *
	 * @return bool
	 */
	private function has_sidebar(): bool {
		return $this->is_modern() && 'sidebar' === (string) $this->config['layout'];
	}

	/**
	 * Register the help tabs.
	 *
	 * @return void
	 */
	public function register_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		foreach ( (array) $this->config['help_tabs'] as $tab_id => $tab ) {
			$screen->add_help_tab(
				[
					'id'      => (string) $tab_id,
					'title'   => (string) ( $tab['title'] ?? $tab_id ),
					'content' => (string) ( $tab['content'] ?? '' ),
				]
			);
		}

		if ( '' !== (string) $this->config['help_sidebar'] ) {
			$screen->set_help_sidebar( (string) $this->config['help_sidebar'] );
		}
	}

	/**
	 * Enqueue the kit, on this settings screen only.
	 *
	 * @param string $hook_suffix The current screen's hook.
	 *
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		( new Assets() )->enqueue( $this->set( $this->current_tab() )->dependencies() );
	}
}
