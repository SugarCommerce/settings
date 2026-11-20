<?php
/**
 * Options
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Context\ConstantContext;
use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry as Types;
use SugarCommerce\Settings\Traits\Options\Readers;
use SugarCommerce\Settings\Traits\Options\Licenses;
use SugarCommerce\Settings\Traits\Options\Emails;

/**
 * Reads and writes a settings page's option, whether or not the page exists.
 *
 * A plugin's own `get_option()` -- the function the rest of it calls for a
 * setting -- runs everywhere: on the front end, in cron, in a webhook, at
 * activation. The settings page is registered once, from one hook, and a
 * read that happens before that hook (or on a request that never registers
 * the page at all) cannot ask it. Reading the option straight from the
 * database there is the obvious fallback and the wrong one: a field marked
 * encrypted is stored encrypted, and a raw read hands back the ciphertext --
 * which, for an API key, means every request made with it is refused with
 * no indication why.
 *
 * So this is the one reader. When the page is registered it asks the page,
 * which knows every field, its default, its constant and whether it is
 * encrypted. When it is not, it reads the option through the same
 * decorators the page would have used, from the field configuration it was
 * given if any -- and from the value itself if not: ciphertext carries a
 * marker, so it is decrypted whether or not anything says the field is
 * encrypted.
 *
 * Writes go the same way. A field marked encrypted has to be written
 * through the decorator it is read through, or the plaintext lands where
 * ciphertext is expected and reads back as an empty string.
 *
 * Every read and write fires hooks named from `hook_prefix`, so a plugin
 * whose settings function was `myplugin_get_option()` keeps its
 * `myplugin_get_option` and `myplugin_get_option_{$key}` filters. With no
 * prefix nothing is fired.
 *
 * Hold one per option -- a static in the plugin's own wrapper is enough.
 * It caches nothing that changes: the field configuration is read once, the
 * values on every call.
 */
final class Options {

	use Readers;
	use Licenses;
	use Emails;

	/**
	 * The states a licence can be in.
	 *
	 * @var string[]
	 */
	public const array LICENSE_STATUSES = [ 'inactive', 'active', 'expired', 'invalid' ];

	/**
	 * The option every value lives in.
	 *
	 * @var string
	 */
	private string $option_name;

	/**
	 * The registered page to ask, when there is one.
	 *
	 * @var string
	 */
	private string $page;

	/**
	 * What the hooks are named from.
	 *
	 * @var string
	 */
	private string $hook_prefix;

	/**
	 * What a field's constant is named from.
	 *
	 * @var string
	 */
	private string $constant_prefix;

	/**
	 * Field configuration, or what produces it.
	 *
	 * @var array<string, array<string, mixed>>|callable|null
	 */
	private $fields;

	/**
	 * The field configuration, once read.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $resolved = null;

	/**
	 * Whether the field configuration is being read right now.
	 *
	 * @var bool
	 */
	private bool $resolving = false;

	/**
	 * Construct.
	 *
	 * @param string               $option_name The option the values live in.
	 * @param array<string, mixed> $args        {
	 *     Optional.
	 *
	 *     @type string         $page        The id the settings page is
	 *                                       registered under, to ask it when
	 *                                       it is. Defaults to none: the
	 *                                       option is always read directly.
	 *     @type string         $hook_prefix What the hooks are named from.
	 *                                       Empty fires none.
	 *     @type string         $constant_prefix What a field's constant is
	 *                                       named from, as on the page.
	 *                                       Defaults to the option name and
	 *                                       an underscore, as the page's does.
	 *     @type array|callable $fields      The page's field configuration,
	 *                                       or a callable returning it, for a
	 *                                       read before the page is
	 *                                       registered: each field's default
	 *                                       and whether it is encrypted. A
	 *                                       callable is called once, on the
	 *                                       first read that needs it.
	 * }
	 */
	public function __construct( string $option_name, array $args = [] ) {
		$this->option_name = $option_name;
		$this->page        = (string) ( $args['page'] ?? '' );
		$this->hook_prefix = (string) ( $args['hook_prefix'] ?? '' );
		$this->fields      = $args['fields'] ?? null;

		$this->constant_prefix = (string) ( $args['constant_prefix'] ?? '' );

		if ( '' === $this->constant_prefix ) {
			$this->constant_prefix = $option_name . '_';
		}
	}

	/**
	 * The option's name.
	 *
	 * @return string
	 */
	public function option_name(): string {
		return $this->option_name;
	}

	/**
	 * The registered settings page, when there is one.
	 *
	 * @return Page|null
	 */
	public function page(): ?Page {
		return '' === $this->page ? null : Page::get( $this->page );
	}

	/**
	 * Every stored value, as stored.
	 *
	 * Encrypted values are ciphertext here. Read those through get().
	 *
	 * Filtered by `{prefix}_get_settings`.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$values = get_option( $this->option_name, [] );

		if ( ! is_array( $values ) ) {
			$values = [];
		}

		if ( '' === $this->hook_prefix ) {
			return $values;
		}

		/**
		 * Filter every stored setting.
		 *
		 * @param array<string, mixed> $values The settings, as stored.
		 */
		return (array) apply_filters( $this->hook_prefix . '_get_settings', $values );
	}

	/**
	 * One value: decrypted, or the constant standing in for it, or the
	 * field's default.
	 *
	 * Filtered by `{prefix}_get_option` and `{prefix}_get_option_{$key}`.
	 *
	 * @param string $key      The setting.
	 * @param mixed  $fallback Returned when it has no value and no default.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$page  = $this->page();
		$value = $page ? $page->get_value( $key, $fallback ) : $this->read( $key, $fallback );

		if ( '' === $this->hook_prefix ) {
			return $value;
		}

		/**
		 * Filter one setting as it is read.
		 *
		 * @param mixed  $value    The value.
		 * @param string $key      The setting.
		 * @param mixed  $fallback The fallback that was offered.
		 */
		$value = apply_filters( $this->hook_prefix . '_get_option', $value, $key, $fallback );

		/**
		 * Filter one named setting as it is read.
		 *
		 * @param mixed $value    The value.
		 * @param mixed $fallback The fallback that was offered.
		 */
		return apply_filters( $this->hook_prefix . '_get_option_' . $key, $value, $fallback );
	}

	/**
	 * Change one value.
	 *
	 * Through the page when it is registered: a key that is not one of its
	 * fields is refused, as the page's own save would drop it. Otherwise
	 * through the decorators, encrypted if the field configuration says so.
	 *
	 * Fires `{prefix}_update_option` with the key, the new value and the
	 * previous one when something was written.
	 *
	 * @param string $key   The setting.
	 * @param mixed  $value What to store.
	 *
	 * @return bool Whether anything was written.
	 */
	public function update( string $key, mixed $value ): bool {
		if ( '' === $key ) {
			return false;
		}

		$previous = $this->get( $key );
		$page     = $this->page();

		if ( $page ) {
			$updated = $page->set_value( $key, $value );
		} else {
			$values = new ArrayContext( $this->stored() );

			$this->decorate( $values )->write( 0, $this->field( $key, false ), $value );

			$updated = update_option( $this->option_name, $values->values() );
		}

		if ( $updated && '' !== $this->hook_prefix ) {
			/**
			 * Fires when a setting changes.
			 *
			 * @param string $key      The setting.
			 * @param mixed  $value    The new value.
			 * @param mixed  $previous What it was.
			 */
			do_action( $this->hook_prefix . '_update_option', $key, $value, $previous );
		}

		return $updated;
	}

	/**
	 * Remove one value, so the next read is the field's default.
	 *
	 * Through the page when it is registered, so the write passes the same
	 * gate as every other. A secret that will not decrypt is not removed:
	 * the decorator keeps the only copy until something readable replaces
	 * it.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool False when nothing was stored under it.
	 */
	public function delete( string $key ): bool {
		$stored = $this->stored();

		if ( ! array_key_exists( $key, $stored ) ) {
			return false;
		}

		$page = $this->page();

		if ( $page ) {
			return $page->delete_value( $key );
		}

		$values = new ArrayContext( $stored );

		$this->decorate( $values )->delete( 0, $this->field( $key, false ) );

		return update_option( $this->option_name, $values->values() );
	}

	/* ---------------------------------------------------------------------
	 * What a value means
	 *
	 * Each reads through get(), so a constant, a decrypted secret, a default
	 * and the `{prefix}_get_option` filters are all seen, and hands the value
	 * to the field engine's Value, which draws the line for a term's meta and
	 * a post's meta the same way it is drawn here.
	 * ------------------------------------------------------------------ */

	/* ---------------------------------------------------------------------
	 * A licence field
	 * ------------------------------------------------------------------ */

	/* ---------------------------------------------------------------------
	 * An email_editor field
	 * ------------------------------------------------------------------ */

	/**
	 * Read one value with no page to ask.
	 *
	 * The same order the page reads in: the constant, then the stored value
	 * decrypted, then the field's default, then the fallback. Read from
	 * all(), so a `{prefix}_get_settings` filter is seen here too.
	 *
	 * @param string $key      The setting.
	 * @param mixed  $fallback Returned when it has no value and no default.
	 *
	 * @return mixed
	 */
	private function read( string $key, mixed $fallback ): mixed {
		$field = $this->field( $key, true );
		$value = $this->decorate( new ArrayContext( $this->all() ) )->read( 0, $field );

		if ( null === $value || '' === $value ) {
			$value = $field->get( 'default' );
		}

		return null === $value || '' === $value ? $fallback : $value;
	}

	/**
	 * The option as stored, unfiltered.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$values = get_option( $this->option_name, [] );

		return is_array( $values ) ? $values : [];
	}

	/**
	 * Wrap a store in the decorators the page uses, in the page's order.
	 *
	 * A constant stands in for a value that would otherwise be decrypted on
	 * the way out, so it sits above encryption.
	 *
	 * @param Context $store The store.
	 *
	 * @return Context
	 */
	private function decorate( Context $store ): Context {
		return new ConstantContext( new EncryptedContext( $store ), $this->constant_prefix );
	}

	/**
	 * A field to read or write one key through.
	 *
	 * The configured field when there is one. Otherwise a plain text field,
	 * marked encrypted for a read only: a read of ciphertext decrypts it,
	 * because the value carries its own marker, and anything else comes
	 * back as it is. A write of a field nothing describes is stored as given.
	 *
	 * @param string $key     The setting.
	 * @param bool   $reading Whether the field is for a read.
	 *
	 * @return Field
	 */
	private function field( string $key, bool $reading ): Field {
		$config = $this->config()[ $key ] ?? [
			'type'      => 'text',
			'encrypted' => $reading,
		];

		$types = new Types();
		$type  = (string) ( $config['type'] ?? 'text' );

		// The page and the field kit both read `section` and `tab`; nothing
		// here does, and the kit would otherwise report them as unread.
		Field::allow_config_keys( [ 'section', 'tab' ] );

		return new Field(
			$key,
			$types->get( $types->has( $type ) ? $type : 'text' ),
			$config
		);
	}

	/**
	 * The field configuration, read once.
	 *
	 * A callable that reads a setting while it builds the configuration --
	 * which is how a section learns whether to start folded -- comes back
	 * here. That read is answered from the value alone rather than by
	 * calling the callable again.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function config(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		if ( $this->resolving || null === $this->fields ) {
			return [];
		}

		$this->resolving = true;

		try {
			$fields = is_callable( $this->fields ) ? call_user_func( $this->fields ) : $this->fields;
		} finally {
			$this->resolving = false;
		}

		$this->resolved = is_array( $fields ) ? $fields : [];

		return $this->resolved;
	}
}
