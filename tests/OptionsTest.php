<?php
/**
 * Reading and writing the option with or without the page.
 *
 * @package SugarCommerce\Settings
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Tests;

use SugarCommerce\Settings\Options;
use SugarCommerce\Settings\Page;
use PHPUnit\Framework\TestCase;

/**
 * One reader, the same answers whether the page is registered or not.
 *
 * The page is registered from one hook; a plugin's settings are read from
 * everywhere -- the front end, cron, activation. Every test that matters
 * here is the same read made both ways.
 */
final class OptionsTest extends TestCase {

	private const OPTION = 'sf_store_settings';

	protected function setUp(): void {
		sf_reset_globals();

		$_POST = [];
		$_GET  = [];

		$this->unregister();

		parent::setUp();
	}

	protected function tearDown(): void {
		$this->unregister();

		sf_reset_globals();

		parent::tearDown();
	}

	private function unregister(): void {
		foreach ( array_keys( Page::all() ) as $id ) {
			Page::unregister( (string) $id );
		}
	}

	/**
	 * The page's fields, deliberately under an id that is not the option's
	 * name -- the case the old helper read the wrong option for.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return [
			'plain'     => [ 'type' => 'text' ],
			'secret'    => [
				'type'      => 'password',
				'encrypted' => true,
			],
			'mode'      => [
				'type'    => 'toggle',
				'default' => true,
			],
			'currency'  => [
				'type'    => 'text',
				'default' => 'EUR',
			],
			'from_code' => [
				'type'         => 'text',
				'use_constant' => true,
			],
		];
	}

	private function page(): Page {
		return new Page(
			'sf_store',
			[
				'option_name' => self::OPTION,
				'menu_slug'   => 'sf-store',
				'fields'      => self::fields(),
			]
		);
	}

	/**
	 * @param array<string, mixed> $args Overrides.
	 */
	private function options( array $args = [] ): Options {
		return new Options(
			self::OPTION,
			$args + [
				'page'        => 'sf_store',
				'hook_prefix' => 'sf_store',
				'fields'      => [ self::class, 'fields' ],
			]
		);
	}

	/**
	 * The reason this class exists: a secret the page wrote is the secret,
	 * read on a request where the page was never registered.
	 */
	public function test_an_encrypted_value_reads_back_without_the_page(): void {
		$this->page()->set_value( 'secret', 'rk_example' );
		$this->unregister();

		$this->assertNotSame( 'rk_example', get_option( self::OPTION )['secret'] );
		$this->assertSame( 'rk_example', $this->options()->get( 'secret' ) );
	}

	/**
	 * Even with nothing describing the field: ciphertext says what it is.
	 */
	public function test_an_encrypted_value_reads_back_with_no_field_configuration(): void {
		$this->page()->set_value( 'secret', 'rk_example' );
		$this->unregister();

		$this->assertSame( 'rk_example', $this->options( [ 'fields' => null ] )->get( 'secret' ) );
	}

	/**
	 * Written without the page, an encrypted field is still stored
	 * encrypted -- and the page reads it once it is registered.
	 */
	public function test_an_encrypted_value_written_without_the_page_is_encrypted(): void {
		$this->assertTrue( $this->options()->update( 'secret', 'rk_example' ) );

		$this->assertNotSame( 'rk_example', get_option( self::OPTION )['secret'] );
		$this->assertSame( 'rk_example', $this->page()->get_value( 'secret' ) );
	}

	/**
	 * Written through a registered page, the same.
	 */
	public function test_an_encrypted_value_written_through_the_page_reads_back(): void {
		$this->page();

		$this->assertTrue( $this->options()->update( 'secret', 'rk_example' ) );
		$this->assertNotSame( 'rk_example', get_option( self::OPTION )['secret'] );
		$this->assertSame( 'rk_example', $this->options()->get( 'secret' ) );
	}

	/**
	 * A field's default is the answer both ways, ahead of the fallback.
	 */
	public function test_a_default_is_the_same_with_or_without_the_page(): void {
		$without = $this->options()->get( 'currency', 'GBP' );

		$this->page();

		$this->assertSame( 'EUR', $without );
		$this->assertSame( 'EUR', $this->options()->get( 'currency', 'GBP' ) );
	}

	/**
	 * A falsy default is still the default.
	 */
	public function test_a_stored_off_beats_a_default_of_on(): void {
		$this->options()->update( 'mode', false );

		$this->assertFalse( $this->options()->get( 'mode', 'unused' ) );
	}

	/**
	 * With no configuration the fallback is what an unset value is.
	 */
	public function test_the_fallback_answers_for_an_unset_value(): void {
		$this->assertSame( 'GBP', $this->options( [ 'fields' => null ] )->get( 'currency', 'GBP' ) );
		$this->assertNull( $this->options()->get( 'missing' ) );
	}

	/**
	 * A constant stands in for a value that opted in, as it does on the
	 * page, named from the option unless told otherwise.
	 */
	public function test_a_constant_stands_in_without_the_page(): void {
		$this->options()->update( 'from_code', 'stored' );

		if ( ! defined( 'SF_STORE_SETTINGS_FROM_CODE' ) ) {
			define( 'SF_STORE_SETTINGS_FROM_CODE', 'constant' );
		}

		$this->assertSame( 'constant', $this->options()->get( 'from_code' ) );
		$this->assertSame( 'stored', $this->options( [ 'constant_prefix' => 'OTHER_' ] )->get( 'from_code' ) );
	}

	public function test_the_hooks_are_named_from_the_prefix(): void {
		$this->options()->update( 'plain', 'stored' );

		add_filter( 'sf_store_get_option', static fn( $value, $key ) => 'plain' === $key ? $value . '+all' : $value );
		add_filter( 'sf_store_get_option_plain', static fn( $value ) => $value . '+one' );

		$this->assertSame( 'stored+all+one', $this->options()->get( 'plain' ) );
		$this->assertSame( 'stored', $this->options( [ 'hook_prefix' => '' ] )->get( 'plain' ) );
	}

	/**
	 * The whole-option filter is what a read without the page reads.
	 */
	public function test_the_settings_filter_is_seen_by_a_read(): void {
		add_filter( 'sf_store_get_settings', static fn( array $values ): array => [ 'plain' => 'filtered' ] + $values );

		$this->assertSame( 'filtered', $this->options()->get( 'plain' ) );
		$this->assertSame( [ 'plain' => 'filtered' ], $this->options()->all() );
	}

	/**
	 * Fired with what changed, and only when something did.
	 */
	public function test_an_update_fires_with_the_previous_value(): void {
		$fired = [];

		add_action(
			'sf_store_update_option',
			static function ( ...$args ) use ( &$fired ): void {
				$fired[] = $args;
			}
		);

		$this->options()->update( 'plain', 'one' );
		$this->options()->update( 'plain', 'two' );
		$this->options()->update( 'plain', 'two' );

		$this->assertSame( [ [ 'plain', 'one', null ], [ 'plain', 'two', 'one' ] ], $fired );
	}

	public function test_an_empty_key_is_refused(): void {
		$this->assertFalse( $this->options()->update( '', 'x' ) );
		$this->assertFalse( get_option( self::OPTION ) );
	}

	/**
	 * The page refuses what is not one of its fields, as its save would.
	 */
	public function test_a_key_the_page_does_not_have_is_refused_by_the_page(): void {
		$this->page();

		$this->assertFalse( $this->options()->update( 'nothing', 'x' ) );
	}

	/**
	 * Removed, the next read is the default -- both ways.
	 */
	public function test_a_deleted_value_reads_as_its_default(): void {
		$this->options()->update( 'currency', 'USD' );

		$this->assertTrue( $this->options()->delete( 'currency' ) );
		$this->assertSame( 'EUR', $this->options()->get( 'currency' ) );
		$this->assertArrayNotHasKey( 'currency', get_option( self::OPTION ) );

		$this->options()->update( 'currency', 'USD' );
		$this->page();

		$this->assertTrue( $this->options()->delete( 'currency' ) );
		$this->assertSame( 'EUR', $this->options()->get( 'currency' ) );
		$this->assertArrayNotHasKey( 'currency', get_option( self::OPTION ) );
	}

	/**
	 * Through the page: the write passes the page's gate, and an encrypted
	 * value next to it survives that pass still encrypted.
	 */
	public function test_deleting_through_the_page_keeps_a_secret_beside_it(): void {
		$this->page();

		$this->options()->update( 'secret', 'rk_example' );
		$this->options()->update( 'plain', 'stored' );

		$this->assertTrue( $this->options()->delete( 'plain' ) );
		$this->assertSame( 'rk_example', $this->options()->get( 'secret' ) );
		$this->assertNotSame( 'rk_example', get_option( self::OPTION )['secret'] );
	}

	public function test_deleting_what_is_not_stored_is_false(): void {
		$this->assertFalse( $this->options()->delete( 'plain' ) );

		$this->page();

		$this->assertFalse( $this->options()->delete( 'plain' ) );
	}

	/**
	 * A configuration callable that reads a setting -- which is how a
	 * section decides whether to start folded -- is not called again from
	 * inside itself.
	 */
	public function test_a_configuration_that_reads_a_setting_does_not_recurse(): void {
		$calls   = 0;
		$options = null;

		$options = $this->options(
			[
				'fields' => static function () use ( &$options, &$calls ): array {
					++$calls;

					$options->get( 'currency' );

					return self::fields();
				},
			]
		);

		$this->assertSame( 'EUR', $options->get( 'currency' ) );
		$this->assertSame( 'EUR', $options->get( 'currency' ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * The old helper read the option named after the page's id, which is
	 * not where the page stores anything once it names its own option.
	 */
	public function test_the_option_name_is_the_one_read(): void {
		update_option( 'sf_store', [ 'plain' => 'wrong' ] );
		update_option( self::OPTION, [ 'plain' => 'right' ] );

		$this->assertSame( 'right', $this->options()->get( 'plain' ) );
		$this->assertSame( self::OPTION, $this->options()->option_name() );
	}
}
