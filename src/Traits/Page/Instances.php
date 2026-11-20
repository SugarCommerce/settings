<?php
/**
 * Settings Page: Instances
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings\Traits\Page;

use SugarCommerce\Settings\Options;
use SugarCommerce\Settings\Page;

/**
 * Every registered settings page, and the reader for one page's option.
 *
 * Part of Page: only the class uses it.
 */
trait Instances {

	/**
	 * Register a settings page.
	 *
	 * From `init` or earlier: the option's sanitize callback is registered
	 * here, and it is the gate every write passes through -- a cron job's
	 * and a webhook's as well as the form's.
	 *
	 * @param string               $id     Unique identifier.
	 * @param array<string, mixed> $config Page configuration.
	 *
	 * @return Page
	 */
	public static function register( string $id, array $config ): Page {
		return new self( $id, $config );
	}

	/**
	 * A registered page.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Page|null
	 */
	public static function get( string $id ): ?Page {
		return self::$pages[ $id ] ?? null;
	}

	/**
	 * Whether a page is registered.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return isset( self::$pages[ $id ] );
	}

	/**
	 * Every registered page, by id.
	 *
	 * @return array<string, Page>
	 */
	public static function all(): array {
		return self::$pages;
	}

	/**
	 * Forget a page.
	 *
	 * Its hooks stay attached; this only stops it being found by id.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return bool False when there was no such page.
	 */
	public static function unregister( string $id ): bool {
		if ( ! isset( self::$pages[ $id ] ) ) {
			return false;
		}

		unset( self::$pages[ $id ] );

		return true;
	}

	/**
	 * The reader for a page's values, registered or not.
	 *
	 * When the page is registered, its own option name and constant prefix,
	 * and every read and write goes through the page. Before it is -- a read
	 * at activation, in cron, on the front end -- the option named by the
	 * id, read through the same decorators, so an encrypted value still
	 * comes back decrypted.
	 *
	 * A plugin that wants hooks fired, or the field configuration to hand
	 * before the page exists, makes its own `new Options()` and keeps it.
	 *
	 * @param string $id The page's identifier.
	 *
	 * @return Options
	 */
	public static function options( string $id ): Options {
		$page = self::get( $id );

		if ( null === $page ) {
			return new Options( $id, [ 'page' => $id ] );
		}

		return new Options(
			$page->get_option_name(),
			[
				'page'            => $id,
				'constant_prefix' => (string) $page->get_config( 'constant_prefix' ),
			]
		);
	}
}
