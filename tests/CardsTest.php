<?php
/**
 * The modern appearance.
 *
 * @package SugarCommerce\Settings
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Tests;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Settings\Page;
use PHPUnit\Framework\TestCase;

/**
 * A page asked for as cards is drawn as cards, and a page that did not ask
 * is drawn exactly as it was.
 *
 * What is stored is not tested here: the appearance changes markup and
 * nothing else, and SettingFieldsTest already holds the saving to account.
 * One test below saves through a modern page anyway, because "changes
 * nothing else" is the claim most worth being wrong about.
 */
final class CardsTest extends TestCase {

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
	 * A page with two sections, one folded away, and a field in neither.
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
					'option_name' => 'sf_demo',
					'menu_slug'   => 'sf-demo',
					'parent_slug' => 'sf-parent',
					'appearance'  => 'modern',
					'sections'    => [
						'shop'     => [
							'title'       => 'Shop',
							'description' => 'What buyers see.',
							'status'      => [
								'label' => 'Connected',
								'tone'  => 'success',
							],
						],
						'advanced' => [
							'title'     => 'Advanced',
							'collapsed' => true,
						],
					],
					'fields'      => [
						'name'    => [
							'type'    => 'text',
							'label'   => 'Name',
							'section' => 'shop',
							'tooltip' => 'Shown on receipts.',
						],
						'open'    => [
							'type'    => 'toggle',
							'label'   => 'Open for orders',
							'section' => 'shop',
						],
						'contact' => [
							'type'     => 'text',
							'label'    => 'Contact',
							'section'  => 'advanced',
							'validate' => 'email',
						],
						'stray'   => [
							'type'  => 'text',
							'label' => 'Stray',
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
	 * What options.php does once the option is updated, then the next load.
	 *
	 * @return void
	 */
	private function redirect_back(): void {
		if ( ! count( get_settings_errors() ) ) {
			add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );

		$GLOBALS['sf_errors']     = [];
		$_GET['settings-updated'] = 'true';
	}

	/**
	 * The default is the page every consumer already has.
	 *
	 * The library is shared. A site that updates it and has said nothing
	 * about appearance must not find its settings screen redrawn.
	 */
	public function test_a_page_that_did_not_ask_is_the_classic_table(): void {
		$html = $this->render( $this->page( [ 'appearance' => 'classic' ] ) );

		$this->assertStringContainsString( 'form-table', $html );
		$this->assertStringContainsString( '<h2 class="title">Shop', $html );
		$this->assertStringContainsString( 'Save Changes', $html );
		$this->assertStringNotContainsString( 'field-kit__settings--modern', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-card', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-savebar', $html );

		// And no appearance at all is the same thing as `classic`.
		$page = new Page(
			'sf_plain',
			[
				'fields' => [
					'a' => [
						'type'  => 'text',
						'label' => 'A',
					],
				],
			]
		);

		$this->assertSame( 'classic', $page->get_config( 'appearance' ) );
		$this->assertStringContainsString( 'form-table', $this->render( $page ) );
	}

	/**
	 * An appearance nobody has heard of is the classic table too.
	 */
	public function test_an_unknown_appearance_is_classic(): void {
		$html = $this->render( $this->page( [ 'appearance' => 'modren' ] ) );

		$this->assertStringContainsString( 'form-table', $html );
		$this->assertStringNotContainsString( 'field-kit__settings-card', $html );
	}

	/**
	 * Each section is a card with its title, its line and its fields.
	 */
	public function test_a_section_is_a_card(): void {
		$html = $this->render( $this->page() );

		$this->assertStringContainsString( 'class="wrap field-kit__settings field-kit__settings--modern"', $html );
		$this->assertStringContainsString( '<section class="field-kit__settings-card" id="sf_demo-section-shop">', $html );
		$this->assertStringContainsString( '<h2 class="field-kit__settings-card-title">Shop', $html );
		$this->assertStringContainsString( '<div class="field-kit__settings-card-description">What buyers see.</div>', $html );
		$this->assertStringContainsString( 'field-kit__settings-card-status--success">Connected</span>', $html );

		// No table anywhere: the kit draws the label, above the control.
		$this->assertStringNotContainsString( 'form-table', $html );
		$this->assertStringNotContainsString( '<th', $html );
		$this->assertMatchesRegularExpression( '/<label for="[^"]+">Name<\/label>/', $html );

		// The help is the icon beside the label, as it is in a panel.
		$this->assertStringContainsString( 'field-kit__tooltip-toggle', $html );
		$this->assertStringContainsString( 'Shown on receipts.', $html );
	}

	/**
	 * A status given as a closure is asked when the card is drawn.
	 *
	 * Whether an account is connected is read from the settings this page
	 * stores, which do not exist to be read while its configuration is
	 * being built.
	 */
	public function test_a_status_can_be_decided_when_the_card_is_drawn(): void {
		$asked = 0;

		$page = $this->page(
			[
				'sections' => [
					'shop' => [
						'title'  => 'Shop',
						'status' => static function () use ( &$asked ): array {
							++$asked;

							return [
								'label' => 'Not connected',
								'tone'  => 'warning',
							];
						},
					],
				],
			]
		);

		$this->assertSame( 0, $asked );

		$html = $this->render( $page );

		$this->assertSame( 1, $asked );
		$this->assertStringContainsString( 'field-kit__settings-card-status--warning">Not connected</span>', $html );

		// A tone nobody defined is drawn as the quiet one, and a status
		// that is not words is not drawn.
		$this->assertStringContainsString(
			'field-kit__settings-card-status--neutral">Odd</span>',
			$this->render( $this->page( [ 'sections' => [ 'shop' => [ 'title' => 'Shop', 'status' => [ 'label' => 'Odd', 'tone' => 'loud' ] ] ] ] ) )
		);
		$this->assertStringNotContainsString(
			'field-kit__settings-card-status',
			$this->render( $this->page( [ 'sections' => [ 'shop' => [ 'title' => 'Shop', 'status' => 42 ] ] ] ) )
		);
	}

	/**
	 * A toggle's name is said once.
	 *
	 * The classic table prints it in the header cell and the toggle prints
	 * it again beside itself. A card has no header cell.
	 */
	public function test_a_self_labelling_field_is_named_once(): void {
		$html = $this->render( $this->page() );

		$this->assertSame( 1, substr_count( $html, 'Open for orders' ) );
	}

	/**
	 * A collapsed section is folded by the browser, and still in the form.
	 */
	public function test_a_collapsed_section_is_a_closed_details(): void {
		$html = $this->render( $this->page() );

		$this->assertStringContainsString(
			'<details class="field-kit__settings-card field-kit__settings-card--collapsible" id="sf_demo-section-advanced">',
			$html
		);
		$this->assertStringContainsString( '<summary class="field-kit__settings-card-header"><h2', $html );

		// Its control is rendered, so it is submitted with the rest.
		$this->assertStringContainsString( 'name="sf_demo[contact]"', $html );
	}

	/**
	 * A collapsed section opens when the save refused something inside it.
	 *
	 * Otherwise the notice at the top names a field the page appears not to
	 * have.
	 */
	public function test_a_collapsed_section_opens_on_a_refused_field(): void {
		$page = $this->page();

		$_POST['sf_demo_tab'] = '';
		update_option( 'sf_demo', [ 'contact' => 'not-an-address' ] );
		$this->redirect_back();

		$html = $this->render( $page );

		$this->assertStringContainsString( 'id="sf_demo-section-advanced" open>', $html );
		$this->assertStringContainsString( 'field-kit__error', $html );

		// Said at the top as well, with core's own markup, and not
		// contradicted by the bar.
		$this->assertStringContainsString( 'id="setting-error-contact" class="notice notice-error settings-error is-dismissible"', $html );
		$this->assertStringNotContainsString( '>Saved<', $html );
		$this->assertStringNotContainsString( 'Saved</span>', $html );
		$this->assertStringNotContainsString( self::saved_mark(), $html );
	}

	/**
	 * A field no section claimed gets a card of its own, not the bare page.
	 */
	public function test_a_loose_field_is_still_in_a_card(): void {
		$html = $this->render( $this->page() );

		$this->assertStringContainsString( '<section class="field-kit__settings-card" id="sf_demo-section-">', $html );
		$this->assertStringContainsString( 'name="sf_demo[stray]"', $html );
	}

	/**
	 * A clean save is acknowledged in the bar, in place of core's notice.
	 */
	public function test_a_clean_save_says_saved_beside_the_button(): void {
		$page = $this->page();

		// Before any save: a bar, a button, and nothing claimed.
		$html = $this->render( $page );

		$this->assertStringContainsString( 'field-kit__settings-savebar', $html );
		$this->assertStringContainsString( '<button type="submit" name="submit" class="button button-primary field-kit__settings-save">Save</button>', $html );
		$this->assertStringContainsString( '<span class="field-kit__settings-status" role="status"></span>', $html );

		update_option( 'sf_demo', [ 'name' => 'Corner shop' ] );
		$this->redirect_back();

		$html = $this->render( $page );

		$this->assertStringContainsString( '<span class="field-kit__settings-status" role="status">' . self::saved_mark() . 'Saved</span>', $html );
		$this->assertStringContainsString( '<svg width="18" height="18" class="sugarcommerce-icon"', self::saved_mark() );
		$this->assertStringNotContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertStringNotContainsString( 'class="notice', $html );

		// And it was saved: the appearance is markup, not behaviour.
		$this->assertSame( 'Corner shop', $page->get_value( 'name' ) );
	}

	/**
	 * A notice that is not "Settings saved." is still printed.
	 *
	 * An import and a reset report through the same API.
	 */
	public function test_other_notices_are_still_printed(): void {
		$page = $this->page();

		add_settings_error( 'sf_demo', 'imported', 'Settings imported.', 'success' );

		$html = $this->render( $page );

		$this->assertStringContainsString( 'id="setting-error-imported" class="notice notice-success settings-error is-dismissible"', $html );
		$this->assertStringContainsString( 'Settings imported.', $html );
	}

	/**
	 * Under the Settings menu core prints the notices, modern or not.
	 */
	public function test_notices_are_left_to_core_under_the_settings_menu(): void {
		$page = $this->page( [ 'parent_slug' => 'options-general.php' ] );

		add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );

		$html = $this->render( $page );

		$this->assertStringNotContainsString( 'setting-error-', $html );
		$this->assertStringNotContainsString( self::saved_mark(), $html );
	}

	/**
	 * The tick beside "Saved": a Heroicon at the size the Dashicon was.
	 *
	 * @return string
	 */
	private static function saved_mark(): string {
		return Icons::render( 'heroicons-outline:check-circle', [ 'size' => 18 ] );
	}

	/**
	 * The screen is marked, so what sits outside the wrap can follow.
	 */
	public function test_the_screen_carries_the_modern_body_class(): void {
		$page = $this->page();
		$page->register_menu();

		$GLOBALS['sf_screen'] = new \SF_Screen( $page->get_hook_suffix() );

		$this->assertStringContainsString( 'field-kit__settings-screen--modern', $page->body_class( '' ) );

		$classic = $this->page( [ 'appearance' => 'classic' ] );
		$classic->register_menu();

		$GLOBALS['sf_screen'] = new \SF_Screen( $classic->get_hook_suffix() );

		$this->assertStringNotContainsString( 'modern', $classic->body_class( '' ) );
	}

	/**
	 * A section that only explains is drawn: a card with its title and its
	 * words, and no empty grid of fields under them.
	 */
	public function test_a_section_with_only_a_description_is_drawn(): void {
		$page = $this->page(
			[
				'sections' => [
					'shop' => [ 'title' => 'Shop' ],
					'note' => [
						'title'       => 'Before you start',
						'description' => 'Your keys are in the dashboard.',
					],
				],
				'fields'   => [
					'name' => [
						'type'    => 'text',
						'label'   => 'Name',
						'section' => 'shop',
					],
				],
			]
		);

		$html = $this->render( $page );

		$this->assertStringContainsString( 'id="sf_demo-section-note"', $html );
		$this->assertStringContainsString( 'Before you start', $html );
		$this->assertStringContainsString( 'Your keys are in the dashboard.', $html );
		$this->assertSame( 1, substr_count( $html, 'field-kit__settings-card-fields' ) );
	}

	/**
	 * A title over nothing is still an empty card, and is not drawn.
	 */
	public function test_a_section_with_neither_fields_nor_a_description_is_not_drawn(): void {
		$page = $this->page(
			[
				'sections' => [
					'shop'  => [ 'title' => 'Shop' ],
					'empty' => [ 'title' => 'Nothing here' ],
					'blank' => [
						'title'       => 'Blank',
						'description' => '   ',
					],
				],
			]
		);

		$html = $this->render( $page );

		$this->assertStringNotContainsString( 'Nothing here', $html );
		$this->assertStringNotContainsString( 'sf_demo-section-blank', $html );
	}

	/**
	 * With no fields to place it, a note goes on the tab it names, or on
	 * the first when it names none or one that does not exist.
	 */
	public function test_a_section_with_only_a_description_is_on_its_own_tab(): void {
		$page = $this->page(
			[
				'tabs'     => [
					'one' => 'One',
					'two' => 'Two',
				],
				'sections' => [
					'later'   => [
						'title'       => 'Later',
						'description' => 'On the second tab.',
						'tab'         => 'two',
					],
					'first'   => [
						'title'       => 'First',
						'description' => 'On the first tab.',
					],
					'nowhere' => [
						'title'       => 'Nowhere',
						'description' => 'On a tab that does not exist.',
						'tab'         => 'gone',
					],
				],
			]
		);

		$one = $this->render( $page );

		$this->assertStringContainsString( 'On the first tab.', $one );
		$this->assertStringContainsString( 'On a tab that does not exist.', $one );
		$this->assertStringNotContainsString( 'On the second tab.', $one );

		$_GET['tab'] = 'two';

		$two = $this->render( $page );

		$this->assertStringContainsString( 'On the second tab.', $two );
		$this->assertStringNotContainsString( 'On the first tab.', $two );
	}

	/**
	 * The classic table draws one too: its heading and paragraph, and no
	 * empty table.
	 */
	public function test_a_classic_section_with_only_a_description_is_drawn(): void {
		$page = $this->page(
			[
				'appearance' => 'classic',
				'sections'   => [
					'note' => [
						'title'       => 'Before you start',
						'description' => 'Your keys are in the dashboard.',
					],
				],
				'fields'     => [],
			]
		);

		$html = $this->render( $page );

		$this->assertStringContainsString( '<h2 class="title">Before you start</h2>', $html );
		$this->assertStringContainsString( '<p class="description">Your keys are in the dashboard.</p>', $html );
		$this->assertStringNotContainsString( 'form-table', $html );
	}
}
