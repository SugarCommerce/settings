<?php
/**
 * The sidebar layout.
 *
 * @package SugarCommerce\Settings
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Tests;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Support\PageHeader;
use SugarCommerce\Settings\Page;
use PHPUnit\Framework\TestCase;

/**
 * A modern page that asked for its tabs down the side is an ordinary
 * screen -- core's heading, the tabs as a column of links, the cards beside
 * them -- and a page that did not ask keeps the header it had.
 *
 * The layout is markup. What is stored is SettingFieldsTest's, and one test
 * here saves through the sidebar page all the same.
 */
final class SidebarTest extends TestCase {

	/**
	 * Reset the stubbed globals and the registry.
	 */
	protected function setUp(): void {
		sf_reset_globals();

		$_POST  = [];
		$_GET   = [];
		$_FILES = [];

		foreach ( array_keys( Page::all() ) as $id ) {
			Page::unregister( (string) $id );
		}
	}

	/**
	 * A modern page of three tabs, two with an icon.
	 *
	 * @param array<string, mixed> $config Configuration overrides.
	 *
	 * @return Page
	 */
	private function page( array $config = [] ): Page {
		return new Page(
			'sf_demo',
			array_merge(
				[
					'option_name'  => 'sf_demo',
					'menu_slug'    => 'sf-demo',
					'parent_slug'  => 'sf-parent',
					'page_title'   => 'Demo Settings',
					'header_title' => 'Settings',
					'appearance'   => 'modern',
					'layout'       => 'sidebar',
					'tabs'         => [
						'general'  => [
							'label' => 'General',
							'icon'  => 'dashicons-admin-settings',
						],
						'payments' => [
							'label' => 'Payments',
							'icon'  => 'dashicons-money-alt',
						],
						'extra'    => 'Extra',
					],
					'sections'     => [
						'shop' => [
							'title' => 'Shop',
							'tab'   => 'general',
						],
					],
					'fields'       => [
						'name' => [
							'type'    => 'text',
							'label'   => 'Name',
							'tab'     => 'general',
							'section' => 'shop',
						],
						'key'  => [
							'type'  => 'text',
							'label' => 'Key',
							'tab'   => 'payments',
						],
					],
				],
				$config
			)
		);
	}

	/**
	 * Render the page and return what it printed.
	 *
	 * @param Page $page The page.
	 *
	 * @return string
	 */
	private function render( Page $page ): string {
		ob_start();

		try {
			$page->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * The default is the header and its row of tabs.
	 *
	 * The library is shared: a modern page that says nothing about layout
	 * is the page it was before the option existed.
	 */
	public function test_a_page_that_did_not_ask_keeps_its_header(): void {
		$page = $this->page( [ 'layout' => 'tabs' ] );
		$html = $this->render( $page );

		$this->assertStringContainsString( 'privacy-settings-header', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-sidebar', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-shell', $html );

		$unsaid = new Page( 'sf_plain', [ 'appearance' => 'modern' ] );

		$this->assertSame( 'tabs', $unsaid->get_config( 'layout' ) );

		// And a layout nobody has heard of is the header too.
		$this->assertStringContainsString( 'privacy-settings-header', $this->render( $this->page( [ 'layout' => 'sidebr' ] ) ) );
	}

	/**
	 * The classic table has no sidebar, whatever it asks for.
	 */
	public function test_the_classic_table_ignores_the_layout(): void {
		$html = $this->render( $this->page( [ 'appearance' => 'classic' ] ) );

		$this->assertStringContainsString( 'form-table', $html );
		$this->assertStringContainsString( 'privacy-settings-header', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-sidebar', $html );
	}

	/**
	 * An ordinary screen: a wrap, core's heading, the notice marker, in that order.
	 */
	public function test_the_page_is_an_ordinary_screen(): void {
		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( 'privacy-settings-header', $html );
		$this->assertStringStartsWith( '<div class="wrap field-kit__settings field-kit__settings--modern field-kit__settings--sidebar">', $html );
		$this->assertStringContainsString( '<h1 class="wp-heading-inline field-kit__settings-title">Settings</h1><hr class="wp-header-end">', $html );

		// Then the tabs, then the form, side by side in the shell.
		$shell = strpos( $html, '<div class="field-kit__settings-shell">' );
		$nav   = strpos( $html, '<nav class="field-kit__settings-sidebar"' );
		$main  = strpos( $html, '<div class="field-kit__settings-main"><form' );

		$this->assertIsInt( $shell );
		$this->assertIsInt( $nav );
		$this->assertIsInt( $main );
		$this->assertLessThan( $shell, strpos( $html, 'wp-header-end' ) );
		$this->assertLessThan( $nav, $shell );
		$this->assertLessThan( $main, $nav );

		// The cards and the bar are the modern page's own.
		$this->assertStringContainsString( '<section class="field-kit__settings-card" id="sf_demo-section-shop">', $html );
		$this->assertStringContainsString( 'field-kit__settings-savebar', $html );
	}

	/**
	 * Past the first tab the heading says which tab, quietly.
	 */
	public function test_the_heading_names_the_tab_past_the_first(): void {
		$_GET['tab'] = 'payments';

		$html = $this->render( $this->page() );

		$this->assertStringContainsString(
			'<h1 class="wp-heading-inline field-kit__settings-title">Settings ' .
			'<span class="field-kit__settings-title-sep" aria-hidden="true">&rsaquo;</span> ' .
			'<span class="field-kit__settings-title-section">Payments</span></h1>',
			$html
		);

		// With no header title, the page's own.
		$_GET['tab'] = 'general';

		$this->assertStringContainsString(
			'field-kit__settings-title">Demo Settings</h1>',
			$this->render( $this->page( [ 'header_title' => '' ] ) )
		);
	}

	/**
	 * Every tab is a link, the current one says so, and an icon is drawn where given.
	 */
	public function test_the_tabs_are_a_column_of_links(): void {
		$_GET['tab'] = 'payments';

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( '<nav class="field-kit__settings-sidebar" aria-label="Settings sections">', $html );
		$this->assertSame( 3, substr_count( $html, 'class="field-kit__settings-sidebar-link' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );

		$this->assertMatchesRegularExpression(
			'#<a href="[^"]*page=sf-demo[^"]*tab=payments" class="field-kit__settings-sidebar-link field-kit__settings-sidebar-link--current" data-tab="payments" aria-current="page">' .
			'<span class="dashicons dashicons-money-alt sugarcommerce-icon field-kit__settings-sidebar-icon" aria-hidden="true"></span>' .
			'<span class="field-kit__settings-sidebar-label">Payments</span></a>#',
			$html
		);

		// A tab with no icon keeps the space for one, since the others have theirs.
		$this->assertStringContainsString(
			'<span class="field-kit__settings-sidebar-icon field-kit__settings-sidebar-icon--none" aria-hidden="true"></span><span class="field-kit__settings-sidebar-label">Extra</span>',
			$html
		);

		// Only the current tab's fields are in the form.
		$this->assertStringContainsString( 'name="sf_demo[key]"', $html );
		$this->assertStringNotContainsString( 'name="sf_demo[name]"', $html );
	}

	/**
	 * A tab's icon in either form: `set:name` is inline SVG, a Dashicon by its
	 * name or its class the font's span; one that cannot be drawn keeps the
	 * space for one.
	 */
	public function test_a_tabs_icon_in_either_form(): void {
		$html = $this->render(
			$this->page(
				[
					'tabs' => [
						'general'  => [
							'label' => 'General',
							'icon'  => 'heroicons-outline:cog-6-tooth',
						],
						'payments' => [
							'label' => 'Payments',
							'icon'  => 'money-alt',
						],
						'emails'   => [
							'label' => 'Emails',
							'icon'  => 'dashicons-email',
						],
						'extra'    => [
							'label' => 'Extra',
							'icon'  => 'heroicons-outline:no-such-icon',
						],
					],
				]
			)
		);

		$svg = Icons::render( 'heroicons-outline:cog-6-tooth', [ 'class' => 'field-kit__settings-sidebar-icon' ] );

		$this->assertStringContainsString( 'class="sugarcommerce-icon field-kit__settings-sidebar-icon"', $svg );
		$this->assertStringContainsString( '>' . $svg . '<span class="field-kit__settings-sidebar-label">General</span>', $html );
		$this->assertStringContainsString( '><span class="dashicons dashicons-money-alt sugarcommerce-icon field-kit__settings-sidebar-icon" aria-hidden="true"></span><span class="field-kit__settings-sidebar-label">Payments</span>', $html );
		$this->assertStringContainsString( '><span class="dashicons dashicons-email sugarcommerce-icon field-kit__settings-sidebar-icon" aria-hidden="true"></span><span class="field-kit__settings-sidebar-label">Emails</span>', $html );
		$this->assertStringContainsString( '><span class="field-kit__settings-sidebar-icon field-kit__settings-sidebar-icon--none" aria-hidden="true"></span><span class="field-kit__settings-sidebar-label">Extra</span>', $html );
	}

	/**
	 * With no icons anywhere there is no column kept for them.
	 */
	public function test_no_icons_means_no_icon_column(): void {
		$html = $this->render(
			$this->page(
				[
					'tabs' => [
						'general'  => 'General',
						'payments' => 'Payments',
					],
				]
			)
		);

		$this->assertStringContainsString( 'field-kit__settings-sidebar-link', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-sidebar-icon', $html );
	}

	/**
	 * One tab, or none, is not a list worth a column.
	 */
	public function test_a_page_with_one_tab_has_no_sidebar(): void {
		$html = $this->render( $this->page( [ 'tabs' => [ 'general' => 'General' ] ] ) );

		$this->assertStringNotContainsString( '<nav', $html );
		$this->assertStringContainsString( '<div class="field-kit__settings-shell"><div class="field-kit__settings-main"><form', $html );
		$this->assertStringContainsString( 'field-kit__settings-title">Settings</h1>', $html );
	}

	/**
	 * The screen is marked as a sidebar one and keeps core's gutters.
	 *
	 * The header's body class removes #wpcontent's padding so the header
	 * can span the screen. There is no header here, and with the class the
	 * wrap would sit against the menu.
	 */
	public function test_the_screen_keeps_cores_gutters(): void {
		$page = $this->page();
		$page->register_menu();

		$GLOBALS['sf_screen'] = new \SF_Screen( $page->get_hook_suffix() );

		$classes = $page->body_class( 'existing-class' );

		$this->assertSame( 'existing-class field-kit__settings-screen--modern field-kit__settings-screen--sidebar', $classes );
		$this->assertStringNotContainsString( PageHeader::body_class(), $classes );

		// The header layout still has the header's class.
		$tabs = $this->page( [ 'layout' => 'tabs' ] );
		$tabs->register_menu();

		$GLOBALS['sf_screen'] = new \SF_Screen( $tabs->get_hook_suffix() );

		$this->assertStringContainsString( PageHeader::body_class(), $tabs->body_class( '' ) );
		$this->assertStringNotContainsString( 'sidebar', $tabs->body_class( '' ) );
	}

	/**
	 * A clean save is said in the bar, and a refusal as a notice under the title.
	 */
	public function test_notices_land_under_the_title(): void {
		$page = $this->page();

		update_option( 'sf_demo', [ 'name' => 'Corner shop' ] );

		add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );

		$html = $this->render( $page );

		$this->assertStringContainsString( 'Saved</span>', $html );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertSame( 'Corner shop', $page->get_value( 'name' ) );

		sf_reset_globals();

		add_settings_error( 'sf_demo', 'imported', 'Settings imported.', 'success' );

		$html = $this->render( $this->page() );

		$notice = strpos( $html, 'id="setting-error-imported"' );

		$this->assertIsInt( $notice );
		$this->assertGreaterThan( strpos( $html, 'wp-header-end' ), $notice );
		$this->assertLessThan( strpos( $html, 'field-kit__settings-shell' ), $notice );
	}
}
