<?php
/**
 * Settings Page
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings;

use SugarCommerce\Fields\Context\ConstantContext;
use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Fields\Context\OptionContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Settings\Traits\Page\Instances;
use SugarCommerce\Settings\Traits\Page\Screen;
use SugarCommerce\Settings\Traits\Page\Saving;
use SugarCommerce\Settings\Traits\Page\Rendering;
use SugarCommerce\Settings\Traits\Page\Tools;
use SugarCommerce\Settings\Traits\Page\Values;

/**
 * Registers a tabbed settings page backed by a single option.
 *
 * Rendering, sanitizing, conditional logic, accessibility, the search
 * endpoint and the action endpoint all come from sugarcommerce/fields. What
 * is left here is what is genuinely about a settings *page*: the menu entry,
 * the tabs, WordPress's Settings API wiring, and export, import and reset.
 *
 * Every write funnels through `sanitize()`, because that is what
 * `update_option()` calls. Import and reset therefore do not sanitize
 * anything themselves — they hand `update_option()` the array they want and
 * the registered callback does the rest.
 *
 * The registered pages are kept here as well, by id, so a page can be found
 * from anywhere -- `Page::get( 'my_plugin' )` -- and its values read through
 * `Page::options( 'my_plugin' )` whether it has been registered yet or not.
 */
final class Page {

	use Instances;
	use Screen;
	use Saving;
	use Rendering;
	use Tools;
	use Values;

	/**
	 * Every registered page, by id.
	 *
	 * @var array<string, Page>
	 */
	private static array $pages = [];

	/**
	 * This settings page's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Page configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Field configuration, keyed by field key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Tabs, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $tabs;

	/**
	 * Sections, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sections;

	/**
	 * Where values are read from, for rendering and for get_value().
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The option underneath the decorators.
	 *
	 * @var OptionContext
	 */
	private OptionContext $options;

	/**
	 * Field sets, keyed by tab.
	 *
	 * @var array<string, FieldSet>
	 */
	private array $sets = [];

	/**
	 * The screen's hook suffix, once the menu is registered.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Construct.
	 *
	 * @param string               $id     Settings page identifier.
	 * @param array<string, mixed> $config Page configuration.
	 */
	public function __construct( string $id, array $config ) {
		self::declare_config_keys();

		$this->id       = $id;
		$this->config   = $this->defaults( $config );
		$this->fields   = (array) ( $config['fields'] ?? [] );
		$this->tabs     = $this->parse_tabs( (array) ( $config['tabs'] ?? [] ) );
		$this->sections = (array) ( $config['sections'] ?? [] );
		$this->fields   = $this->apply_section_badges( $this->fields );

		$this->options = new OptionContext( (string) $this->config['option_name'] );
		$this->context = $this->decorate( $this->options );

		self::$pages[ $id ] = $this;

		// Not deferred to admin_init, as core's own convention would have it.
		// The sanitize callback registered here is the gate every write to
		// this option passes through, including one made from cron or from a
		// webhook, and a gate that is only in place on admin screens is not
		// one. Nothing in register_setting() needs the admin to be loaded.
		$this->register_settings();

		// Built now, not when a tab renders. The set's constructor is what
		// registers a field's search source and its action handlers, and the
		// request that searches or presses a button is never the request that
		// drew the control — so a set built lazily registers them only on the
		// renders where nobody needs them. An action button on a settings
		// page came back "Unknown action." every time it was pressed.
		//
		// The whole-page set rather than a tab's: the names are the option
		// name and the field key, with no tab in them, so one set covers
		// every tab. It stores configuration and registers callbacks; the
		// fields themselves are still built on demand.
		$this->set();

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'screen_settings', [ $this, 'render_screen_tools' ], 10, 2 );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		// admin-post, not REST: plain full-page forms -- a download, an upload
		// and a reset -- that work with no script.
		add_action( 'admin_post_' . $this->action_slug( 'export' ), [ $this, 'handle_export' ] );
		add_action( 'admin_post_' . $this->action_slug( 'import' ), [ $this, 'handle_import' ] );
		add_action( 'admin_post_' . $this->action_slug( 'reset' ), [ $this, 'handle_reset' ] );
	}

	/**
	 * Copy a badged section's badge onto each of its fields.
	 *
	 * A section badge locks the whole section. Resolving it here rather than
	 * at render time means every path downstream — rendering, sanitizing,
	 * saving — sees an ordinary badged field and needs no special case. In
	 * particular the save path skips a locked field, and a section that
	 * locked only visually would have had its values cleared by the next save
	 * because a disabled control sends nothing.
	 *
	 * A field's own badge wins: it is the more specific statement.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function apply_section_badges( array $fields ): array {
		foreach ( $this->sections as $slug => $section ) {
			if ( null === Badge::resolve( $section['badge'] ?? null ) ) {
				continue;
			}

			foreach ( $fields as $key => $field ) {
				if ( (string) ( $field['section'] ?? '' ) !== (string) $slug || isset( $field['badge'] ) ) {
					continue;
				}

				$fields[ $key ]['badge'] = $section['badge'];
			}
		}

		return $fields;
	}

	/**
	 * Wrap a store in this page's decorators.
	 *
	 * Order matters: a constant stands in for a value that would otherwise be
	 * decrypted on the way out, so it has to sit above encryption.
	 *
	 * @param Context $store The store to wrap.
	 *
	 * @return Context
	 */
	private function decorate( Context $store ): Context {
		return new ConstantContext(
			new EncryptedContext( $store ),
			(string) $this->config['constant_prefix']
		);
	}

	/**
	 * Merge the page configuration with its defaults.
	 *
	 * @param array<string, mixed> $config Supplied configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults( array $config ): array {
		$config = array_merge(
			[
				'page_title'      => $this->id,
				'menu_title'      => $this->id,
				'menu_slug'       => $this->id,
				'parent_slug'     => '',
				'capability'      => 'manage_options',
				'option_name'     => $this->id,
				'option_group'    => $this->id . '_group',
				'constant_prefix' => '',
				'header_title'    => '',
				'logo'            => '',
				'badge'           => '',
				'icon'            => 'dashicons-admin-generic',
				'position'        => null,
				'submit_button'   => true,
				'reset_button'    => false,
				'export_import'   => false,
				'help_tabs'       => [],
				'help_sidebar'    => '',

				// How the page is drawn. `classic` is core's form table and
				// is the default, so a site that upgrades this library sees
				// the page it had. `modern` draws each section as a card
				// with the labels above the controls -- see Cards.
				'appearance'      => 'classic',

				// Where a modern page lists its tabs. `tabs` is the centred
				// header with a row of them and is the default; `sidebar`
				// is an ordinary screen with its title at the top left and
				// the tabs as a card of links beside the cards -- see
				// Sidebar. Read only when the appearance is `modern`.
				'layout'          => 'tabs',
			],
			$config
		);

		if ( '' === (string) $config['constant_prefix'] ) {
			$config['constant_prefix'] = $config['option_name'] . '_';
		}

		return $config;
	}

	/**
	 * Normalize the tab configuration.
	 *
	 * Accepts both `'slug' => 'Label'` and the full array form, because both
	 * appear in existing configuration.
	 *
	 * @param array<string, mixed> $tabs Raw tabs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function parse_tabs( array $tabs ): array {
		$parsed = [];

		foreach ( $tabs as $slug => $tab ) {
			$parsed[ (string) $slug ] = is_string( $tab )
				? [ 'label' => $tab ]
				: array_merge( [ 'label' => ucfirst( (string) $slug ) ], (array) $tab );
		}

		return $parsed;
	}

	/**
	 * The field set for one tab.
	 *
	 * @param string $tab Restrict to one tab, or empty for every field.
	 *
	 * @return FieldSet
	 */
	private function set( string $tab = '' ): FieldSet {
		return $this->sets[ $tab ] ??= new FieldSet(
			'' === $tab ? $this->fields : $this->fields_for_tab( $tab ),
			$this->context,
			(string) $this->config['option_name']
		);
	}

	/**
	 * The fields belonging to one tab.
	 *
	 * A field with no tab of its own belongs to the first, so a page that
	 * grows tabs later does not lose the fields it already had.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields_for_tab( string $tab ): array {
		if ( [] === $this->tabs ) {
			return $this->fields;
		}

		$first = (string) array_key_first( $this->tabs );

		return array_filter(
			$this->fields,
			static fn( $field ) => (string) ( $field['tab'] ?? $first ) === $tab
		);
	}

	/**
	 * The tab currently being viewed.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// A tab is a view: it changes nothing and carries no nonce, exactly
		// as core's own tabbed screens read theirs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return isset( $this->tabs[ $requested ] ) ? $requested : (string) array_key_first( $this->tabs );
	}

	/**
	 * The name of the hidden input carrying the submitted tab.
	 *
	 * Deliberately outside the option's own array: it is a fact about the
	 * submission, not a value to store.
	 *
	 * @return string
	 */
	private function tab_input(): string {
		return sanitize_key( $this->id . '_tab' );
	}

	/**
	 * This page's URL.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 *
	 * @return string
	 */
	private function page_url( array $args = [] ): string {
		$parent = (string) $this->config['parent_slug'];

		// A parent slug is only a filename when the page hangs off one of
		// core's menus -- options-general.php, edit.php. A plugin with its
		// own top-level menu passes that menu's slug instead, and its
		// children live at admin.php?page=..., exactly as add_submenu_page()
		// registers them a few methods up. Passing the slug to admin_url()
		// builds /wp-admin/my-plugin?page=..., which is not an admin screen
		// at all -- WordPress serves the front end from it.
		$base = ( '' !== $parent && str_ends_with( $parent, '.php' ) )
			? $parent
			: 'admin.php';

		return add_query_arg(
			array_merge( [ 'page' => (string) $this->config['menu_slug'] ], array_filter( $args ) ),
			admin_url( $base )
		);
	}

	/**
	 * Get this page's identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Read a page configuration value.
	 *
	 * @param string $key      Configuration key.
	 * @param mixed  $fallback Returned when absent.
	 *
	 * @return mixed
	 */
	public function get_config( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * Get the screen's hook suffix, once the menu has been registered.
	 *
	 * Empty before `admin_menu` has run. Public because a consumer hooking
	 * this screen — `load-{$hook}`, `admin_footer-{$hook}` — has no other way
	 * to name it, and guessing the form WordPress builds is how a hook ends
	 * up silently attached to nothing.
	 *
	 * @return string
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Get the option name these settings live under.
	 *
	 * @return string
	 */
	public function get_option_name(): string {
		return (string) $this->config['option_name'];
	}

	/**
	 * Tell the kit which configuration this library reads.
	 *
	 * Which tab and which section of this page a field belongs to. Both are
	 * about the page's layout rather than the field, so they are read here.
	 * Without this the kit would report each of them as configuration
	 * nothing reads — which is the warning it exists to give, aimed at the
	 * wrong thing.
	 *
	 * @return void
	 */
	private static function declare_config_keys(): void {
		static $declared = false;

		if ( $declared ) {
			return;
		}

		$declared = true;

		Field::allow_config_keys( [ 'section', 'tab' ] );
	}
}
