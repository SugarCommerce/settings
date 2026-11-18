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
use SugarCommerce\Fields\Value;

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

	/**
	 * The states a licence can be in.
	 *
	 * @var string[]
	 */
	public const LICENSE_STATUSES = [ 'inactive', 'active', 'expired', 'invalid' ];

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

	/**
	 * Whether a toggle or a checkbox is on.
	 *
	 * Not a truthiness check: a toggle stores "1" and "0", and "0" is a
	 * string PHP calls false but a form field does not always send.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_on( string $key ): bool {
		return Value::is_on( $this->get( $key, false ) );
	}

	/**
	 * Whether a checkbox group or a multiple select has one choice ticked.
	 *
	 * @param string $key    The setting.
	 * @param string $option The choice.
	 *
	 * @return bool
	 */
	public function includes( string $key, string $option ): bool {
		return Value::includes( $this->get( $key, [] ), $option );
	}

	/**
	 * A tags field or a multiple select as a list of strings.
	 *
	 * @param string $key The setting.
	 *
	 * @return string[]
	 */
	public function list( string $key ): array {
		return Value::list( $this->get( $key, [] ) );
	}

	/**
	 * A relational field as a list of object ids.
	 *
	 * @param string $key The setting.
	 *
	 * @return int[]
	 */
	public function ids( string $key ): array {
		return Value::ids( $this->get( $key, [] ) );
	}

	/**
	 * A whole number, or the fallback when what is stored is not one.
	 *
	 * "eighty" is not 0 here, and neither is "8.5": a value that is not a
	 * whole number has not answered, so the fallback stands in for it
	 * rather than a number nobody chose.
	 *
	 * @param string $key      The setting.
	 * @param int    $fallback Returned when there is no whole number.
	 *
	 * @return int
	 */
	public function int( string $key, int $fallback = 0 ): int {
		$value = $this->get( $key, $fallback );

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) <= PHP_INT_MAX ) {
			return (int) $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^\s*[-+]?\d+\s*$/', $value ) ) {
			return (int) trim( $value );
		}

		return $fallback;
	}

	/**
	 * A line of text, or the fallback when what is stored is not one.
	 *
	 * An array or an object is not a string, and "Array" is not what was
	 * meant; a true or false is not one either.
	 *
	 * @param string $key      The setting.
	 * @param string $fallback Returned when there is no text.
	 *
	 * @return string
	 */
	public function string( string $key, string $fallback = '' ): string {
		$value = $this->get( $key, $fallback );

		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : $fallback;
	}

	/**
	 * The id of the page or post a setting points at.
	 *
	 * @param string $key The setting.
	 *
	 * @return int 0 when none is set.
	 */
	public function page_id( string $key ): int {
		return Value::id( $this->get( $key, 0 ) );
	}

	/**
	 * The address of the page or post a setting points at.
	 *
	 * @param string $key      The setting.
	 * @param string $fallback Returned when none is set.
	 *
	 * @return string The page's address, the fallback, or the home page.
	 */
	public function page_url( string $key, string $fallback = '' ): string {
		return Value::url( $this->get( $key, 0 ), $fallback );
	}

	/**
	 * Whether the request is for the page or post a setting points at.
	 *
	 * Compares the queried object rather than calling is_page(): a setting
	 * can point at any post type -- the `post`, `page` and `ajax` fields all
	 * store an id the same way -- and is_page() answers false for every one
	 * of them that is not a page.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_page( string $key ): bool {
		return Value::is_viewing( $this->get( $key, 0 ) );
	}

	/* ---------------------------------------------------------------------
	 * A licence field
	 * ------------------------------------------------------------------ */

	/**
	 * A `license` field's key, status and expiry.
	 *
	 * @param string $key The setting.
	 *
	 * @return array{key: string, status: string, expiry: string}
	 */
	public function license( string $key ): array {
		$value = $this->get( $key, [] );

		// The field itself stores the key alone; the status and expiry are
		// what a licence check wrote beside it.
		$value = is_array( $value ) ? $value : [ 'key' => is_scalar( $value ) ? (string) $value : '' ];

		return [
			'key'    => (string) ( $value['key'] ?? '' ),
			'status' => (string) ( $value['status'] ?? 'inactive' ),
			'expiry' => (string) ( $value['expiry'] ?? '' ),
		] + $value;
	}

	/**
	 * Whether a licence is active.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_license_active( string $key ): bool {
		return 'active' === $this->license( $key )['status'];
	}

	/**
	 * Change a licence's status, and its expiry if one is given.
	 *
	 * For a cron job, a webhook or a remote check that learns the licence has
	 * changed without anybody opening the settings page. The key is kept.
	 *
	 * @param string $key    The setting.
	 * @param string $status inactive, active, expired or invalid.
	 * @param string $expiry A new expiry, or empty to keep the one stored.
	 *
	 * @return bool False for a status that is not one of the four.
	 */
	public function update_license_status( string $key, string $status, string $expiry = '' ): bool {
		if ( ! in_array( $status, self::LICENSE_STATUSES, true ) ) {
			return false;
		}

		$license = $this->license( $key );

		$license['status'] = $status;

		if ( '' !== $expiry ) {
			$license['expiry'] = sanitize_text_field( $expiry );
		}

		return $this->update( $key, $license );
	}

	/* ---------------------------------------------------------------------
	 * An email_editor field
	 * ------------------------------------------------------------------ */

	/**
	 * What the site owner typed into an `email_editor` field, in the shape
	 * sugarcommerce/emails reads.
	 *
	 * The field stores `recipient`, `subject`, `heading` and `body`, and an
	 * `enabled` toggle if it was given one. `Emails::add()`'s `settings`
	 * callable is read for `enabled`, `to`, `subject`, `content` and
	 * `context`. This is the seam between the two:
	 *
	 *     Emails::add( 'my_plugin', 'purchase_receipt', [
	 *         'settings' => fn() => my_plugin_options()->email( 'email_purchase_receipt' ),
	 *     ] );
	 *
	 * A part the owner left empty is left out, so the email's registered
	 * default stands in for it.
	 *
	 * @param string $key The setting.
	 *
	 * @return array{enabled?: bool, to?: string[], subject?: string, content?: string, context?: array{title: string}}
	 */
	public function email( string $key ): array {
		$value = $this->get( $key );

		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		$result = [];

		if ( isset( $value['enabled'] ) ) {
			$result['enabled'] = (bool) $value['enabled'];
		}

		// Several addresses, separated by commas -- which is what the field
		// says beside its recipient box.
		$to = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $value['recipient'] ?? '' ) ) ) );

		if ( [] !== $to ) {
			$result['to'] = array_values( $to );
		}

		if ( ! empty( $value['subject'] ) ) {
			$result['subject'] = (string) $value['subject'];
		}

		// The editor stores the body without its <p> tags, as wp_editor
		// does; wpautop() puts them back.
		if ( ! empty( $value['body'] ) ) {
			$result['content'] = wpautop( (string) $value['body'] );
		}

		// The heading is the template's title, which sits above the body.
		if ( ! empty( $value['heading'] ) ) {
			$result['context'] = [ 'title' => (string) $value['heading'] ];
		}

		return $result;
	}

	/**
	 * Who an `email_editor` field's email goes to, or the site's admin.
	 *
	 * @param string $key The setting.
	 *
	 * @return string
	 */
	public function email_recipient( string $key ): string {
		$value = $this->get( $key );

		if ( is_array( $value ) && ! empty( $value['recipient'] ) && is_email( $value['recipient'] ) ) {
			return (string) $value['recipient'];
		}

		return (string) get_option( 'admin_email', '' );
	}

	/**
	 * Whether an `email_editor` field's email is on.
	 *
	 * On when nothing is stored: an email nobody has turned off is sent.
	 *
	 * @param string $key The setting.
	 *
	 * @return bool
	 */
	public function is_email_enabled( string $key ): bool {
		$value = $this->get( $key );

		if ( is_array( $value ) && isset( $value['enabled'] ) ) {
			return (bool) $value['enabled'];
		}

		return true;
	}

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
