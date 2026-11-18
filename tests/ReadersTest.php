<?php
/**
 * The registry of pages, and what a value means.
 *
 * @package SugarCommerce\Settings
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Tests;

use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Settings\Options;
use SugarCommerce\Settings\Page;
use PHPUnit\Framework\TestCase;

/**
 * The helpers that used to be global functions, now on Page and Options.
 *
 * A global function is the one thing Strauss cannot prefix, so two plugins
 * bundling the old library declared the same function and the second one's
 * guard quietly handed it the first one's. What they did is kept; who
 * answers is now the copy that was asked.
 */
final class ReadersTest extends TestCase {

	protected function setUp(): void {
		sf_reset_globals();

		$_POST = [];
		$_GET  = [];

		foreach ( array_keys( Page::all() ) as $id ) {
			Page::unregister( (string) $id );
		}

		parent::setUp();
	}

	protected function tearDown(): void {
		foreach ( array_keys( Page::all() ) as $id ) {
			Page::unregister( (string) $id );
		}

		sf_reset_globals();

		parent::tearDown();
	}

	/**
	 * A page with one field of each kind the readers are about.
	 *
	 * @return Page
	 */
	private function page(): Page {
		return Page::register(
			'sr_demo',
			[
				'option_name' => 'sr_settings',
				'fields'      => [
					'count'    => [ 'type' => 'number' ],
					'name'     => [ 'type' => 'text' ],
					'flag'     => [ 'type' => 'toggle' ],
					'features' => [
						'type'    => 'checkbox_group',
						'options' => [
							'a' => 'A',
							'b' => 'B',
						],
					],
					'tags'     => [ 'type' => 'tags' ],
					'license'  => [ 'type' => 'license' ],
					'receipt'  => [ 'type' => 'email_editor' ],
					'secret'   => [
						'type'      => 'password',
						'encrypted' => true,
					],
				],
			]
		);
	}

	/**
	 * Register, find, list and forget.
	 */
	public function test_a_page_is_found_by_its_id_until_it_is_forgotten(): void {
		$page = $this->page();

		$this->assertSame( $page, Page::get( 'sr_demo' ) );
		$this->assertTrue( Page::has( 'sr_demo' ) );
		$this->assertSame( [ 'sr_demo' => $page ], Page::all() );

		$this->assertTrue( Page::unregister( 'sr_demo' ) );
		$this->assertFalse( Page::unregister( 'sr_demo' ) );
		$this->assertNull( Page::get( 'sr_demo' ) );
		$this->assertFalse( Page::has( 'sr_demo' ) );
	}

	/**
	 * The reader for a registered page reads the page's own option, not
	 * the option named by its id.
	 */
	public function test_the_reader_of_a_registered_page_reads_its_own_option(): void {
		$GLOBALS['fk_options']['sr_settings'] = [ 'name' => 'Stored' ];
		$GLOBALS['fk_options']['sr_demo']     = [ 'name' => 'Wrong one' ];

		$this->page();

		$options = Page::options( 'sr_demo' );

		$this->assertSame( 'sr_settings', $options->option_name() );
		$this->assertSame( 'Stored', $options->get( 'name' ) );
		$this->assertNotNull( $options->page() );
	}

	/**
	 * Before the page is registered, the option under the id, and a secret
	 * still comes back as the secret.
	 */
	public function test_the_reader_of_an_unregistered_page_still_decrypts(): void {
		// Written by a reader that knows the field is encrypted...
		( new Options( 'sr_early', [ 'fields' => [ 'secret' => [ 'type' => 'password', 'encrypted' => true ] ] ] ) )->update( 'secret', 'sk_test_early' );

		$this->assertStringStartsWith( 'fkenc:', (string) $GLOBALS['fk_options']['sr_early']['secret'] );

		// ...and read by one that knows nothing about it.
		$options = Page::options( 'sr_early' );

		$this->assertNull( $options->page() );
		$this->assertSame( 'sk_test_early', $options->get( 'secret' ) );
		$this->assertSame( 'fallback', $options->get( 'missing', 'fallback' ) );
	}

	/**
	 * A write through the reader is sanitized by its field; a key that is
	 * not a field is refused.
	 */
	public function test_a_write_through_the_reader_is_sanitized_and_refuses_strangers(): void {
		$page = $this->page();

		$this->assertTrue( Page::options( 'sr_demo' )->update( 'count', '13' ) );
		$this->assertFalse( Page::options( 'sr_demo' )->update( 'stranger', 'nope' ) );

		$this->assertSame( 13, $page->get_value( 'count' ) );
		$this->assertArrayNotHasKey( 'stranger', $page->get_values() );
	}

	/**
	 * Toggles, choices, tags and ids.
	 */
	public function test_what_a_choice_means(): void {
		$this->page();

		$GLOBALS['fk_options']['sr_settings'] = [
			'flag'     => '1',
			'features' => [ 'a' ],
			'tags'     => [ 'one', 'two' ],
		];

		$options = Page::options( 'sr_demo' );

		$this->assertTrue( $options->is_on( 'flag' ) );
		$this->assertTrue( $options->includes( 'features', 'a' ) );
		$this->assertFalse( $options->includes( 'features', 'b' ) );
		$this->assertSame( [ 'one', 'two' ], $options->list( 'tags' ) );

		$GLOBALS['fk_options']['sr_settings']['flag'] = '0';

		$this->assertFalse( $options->is_on( 'flag' ) );
	}

	/**
	 * A whole number, or the fallback -- never a number nobody chose.
	 */
	public function test_int_refuses_what_is_not_a_whole_number(): void {
		$options = new Options( 'sr_plain' );

		foreach ( [ 42 => 42, '42' => 42, ' -7 ' => -7, '8.5' => 9, 'eighty' => 9, '' => 9 ] as $stored => $expected ) {
			$GLOBALS['fk_options']['sr_plain'] = [ 'n' => (string) $stored ];

			$this->assertSame( $expected, $options->int( 'n', 9 ), 'Stored: ' . var_export( $stored, true ) );
		}

		$GLOBALS['fk_options']['sr_plain'] = [ 'n' => 3.0 ];
		$this->assertSame( 3, $options->int( 'n' ) );

		$GLOBALS['fk_options']['sr_plain'] = [ 'n' => true ];
		$this->assertSame( 5, $options->int( 'n', 5 ) );

		$GLOBALS['fk_options']['sr_plain'] = [ 'n' => [ 1 ] ];
		$this->assertSame( 5, $options->int( 'n', 5 ) );
	}

	/**
	 * A line of text, or the fallback.
	 */
	public function test_string_refuses_what_is_not_text(): void {
		$options = new Options( 'sr_plain' );

		$GLOBALS['fk_options']['sr_plain'] = [
			's' => 'hello',
			'n' => 12,
			'a' => [ 'x' ],
			'b' => true,
		];

		$this->assertSame( 'hello', $options->string( 's' ) );
		$this->assertSame( '12', $options->string( 'n' ) );
		$this->assertSame( 'none', $options->string( 'a', 'none' ) );
		$this->assertSame( 'none', $options->string( 'b', 'none' ) );
		$this->assertSame( 'none', $options->string( 'missing', 'none' ) );
	}

	/**
	 * A licence: its parts with their defaults, and a status change that
	 * keeps the key.
	 */
	public function test_a_licence_is_read_and_its_status_changed(): void {
		$this->page();

		// The field stores the key alone.
		$GLOBALS['fk_options']['sr_settings'] = [ 'license' => 'KEY-ONLY' ];

		$this->assertSame( 'KEY-ONLY', Page::options( 'sr_demo' )->license( 'license' )['key'] );

		// A status beside it is written where no field sanitizes it away.
		$options = new Options( 'sr_licence' );

		$this->assertSame(
			[
				'key'    => '',
				'status' => 'inactive',
				'expiry' => '',
			],
			$options->license( 'license' )
		);

		$GLOBALS['fk_options']['sr_licence'] = [
			'license' => [
				'key'    => 'ABC-123',
				'status' => 'inactive',
			],
		];

		$this->assertFalse( $options->update_license_status( 'license', 'stolen' ) );
		$this->assertTrue( $options->update_license_status( 'license', 'active', '2027-01-01' ) );

		$license = $options->license( 'license' );

		$this->assertSame( 'ABC-123', $license['key'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( '2027-01-01', $license['expiry'] );
		$this->assertTrue( $options->is_license_active( 'license' ) );

		// No expiry given keeps the one stored.
		$options->update_license_status( 'license', 'expired' );

		$this->assertSame( '2027-01-01', $options->license( 'license' )['expiry'] );
		$this->assertFalse( $options->is_license_active( 'license' ) );
	}

	/**
	 * An email's recipient falls back to the site's admin, and an email
	 * nobody turned off is on.
	 */
	public function test_an_email_is_on_until_it_is_turned_off(): void {
		$this->page();

		$GLOBALS['fk_options']['admin_email'] = 'admin@example.test';

		$options = Page::options( 'sr_demo' );

		$this->assertTrue( $options->is_email_enabled( 'receipt' ) );
		$this->assertSame( 'admin@example.test', $options->email_recipient( 'receipt' ) );

		$GLOBALS['fk_options']['sr_settings'] = [
			'receipt' => [
				'enabled'   => '0',
				'recipient' => 'shop@example.test',
			],
		];

		$this->assertFalse( $options->is_email_enabled( 'receipt' ) );
		$this->assertSame( 'shop@example.test', $options->email_recipient( 'receipt' ) );

		$GLOBALS['fk_options']['sr_settings']['receipt']['recipient'] = 'not an address';

		$this->assertSame( 'admin@example.test', $options->email_recipient( 'receipt' ) );
	}
}
