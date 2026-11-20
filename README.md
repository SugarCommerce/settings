# SugarCommerce Settings

A settings page from a list of fields — the menu, the tabs, the saving and
the sanitising included — and one reader for the settings it stores.

## What it does

A settings screen in WordPress is `add_menu_page`, `register_setting`,
`add_settings_section`, `add_settings_field` and a sanitise callback per
field, repeated until it works. Most of it is the same every time, and the
part that varies is just the list of fields.

This takes the list. The menu, the tabs, the form, the nonce, the saving and
the per-type sanitising follow from it. And because a plugin's settings are
read from everywhere — the front end, cron, a webhook, activation — the
reader works whether the page has been registered yet or not.

## Features

* Get a settings page from a list of fields, with no `add_settings_field` calls
* Group fields into tabs and sections, saving one tab without touching others
* Draw it as WordPress does, or as cards with the tabs down the side
* Read and write a value by name, without knowing how the option is shaped
* Read settings on the front end, in cron or at activation, before the page exists
* Sanitise per field type, rather than writing a callback for each
* Refuse a value that is missing or malformed, and say which field
* Encrypt a field, so an API key is not stored in the clear
* Export, import and reset from Screen Options
* Show a field only when another is set

## Installation

```bash
composer require sugarcommerce/settings
```

## Quick start

```php
use SugarCommerce\Settings\Page;

add_action( 'init', function () {
	Page::register( 'my_plugin', [
		'page_title'  => __( 'My Plugin Settings', 'my-plugin' ),
		'menu_title'  => __( 'My Plugin', 'my-plugin' ),
		'parent_slug' => 'options-general.php',
		'fields'      => [
			'site_name'      => [ 'type' => 'text', 'label' => __( 'Site name', 'my-plugin' ) ],
			'enable_feature' => [ 'type' => 'toggle', 'label' => __( 'Enable feature', 'my-plugin' ) ],
			'items_per_page' => [
				'type'  => 'number',
				'label' => __( 'Items per page', 'my-plugin' ),
				'min'   => 1,
				'max'   => 100,
			],
		],
	] );
} );
```

Then reading one, anywhere:

```php
$name = Page::options( 'my_plugin' )->get( 'site_name' );
```

Field types come from [sugarcommerce/fields](https://github.com/SugarCommerce/fields),
so anything it renders can go in a settings page.

## The page

`Page::register( $id, $config )` takes the page's configuration and its
fields, and the id is what everything else is named from: the option (unless
`option_name` says otherwise), the option group, the admin-post actions and
the hidden input that says which tab was submitted.

| Key | Default | What it is |
|---|---|---|
| `page_title`, `menu_title` | the id | The browser title and the menu entry |
| `menu_slug` | the id | The `?page=` the screen is reached by |
| `parent_slug` | none | A parent menu, or a top-level page with `icon` and `position` |
| `capability` | `manage_options` | Who may see it, save it, export, import and reset |
| `option_name` | the id | The one option every value lives in |
| `option_group` | `{id}_group` | The Settings API group |
| `constant_prefix` | `{option_name}_` | What a field's constant is named from |
| `header_title`, `logo`, `badge` | none | The header's own title, image and badge |
| `submit_button` | true | Draw the save button (on a tab with something to save: a field that stores a value and is not read-only, disabled or locked) |
| `export_import`, `reset_button` | false | The tools in Screen Options |
| `help_tabs`, `help_sidebar` | none | The Help panel |
| `appearance` | `classic` | `classic` or `modern` -- see [Appearance](#appearance) |
| `layout` | `tabs` | `tabs` or `sidebar`, on a modern page |
| `tabs`, `sections`, `fields` | none | What is on it |

`Page::get( $id )`, `Page::has()`, `Page::all()` and `Page::unregister()`
find it again, and `get_hook_suffix()` names the screen for a
`load-{$hook}` of your own.

A tab is a label, or an array with its `label` and `icon`; a field names its
`tab` and its `section`, and a field with no tab is on the first.

## Reading settings anywhere

The page is registered from one hook, and a plugin's settings are read from
everywhere: the front end, cron, a webhook, activation. `Options` is one
reader for all of them. When the page is registered it asks the page; when
it is not, it reads the option through the same decorators the page uses, so
an encrypted API key comes back as the key rather than as its ciphertext.

`Page::options( 'my_plugin' )` is that reader for a page, registered or not.
A plugin that wants hooks fired, or its defaults before the page exists,
keeps one of its own:

```php
use SugarCommerce\Settings\Options;

function my_plugin_options(): Options {
	static $options = null;

	return $options ??= new Options( 'my_plugin_settings', [
		'page'        => 'my_plugin',                    // The id the page is registered under.
		'hook_prefix' => 'my_plugin',                    // Fires my_plugin_get_option and friends.
		'fields'      => 'my_plugin_settings_fields',    // Callable or array: defaults and encryption before the page exists.
	] );
}

$key = my_plugin_options()->get( 'api_key', '' );   // Decrypted, constant, default, then the fallback.
my_plugin_options()->update( 'api_key', $new_key ); // Sanitised by its field, encrypted if the field is.
my_plugin_options()->delete( 'api_key' );           // The next read is the field's default.
my_plugin_options()->all();                         // Everything, as stored.
```

* `get()` answers in the page's order: a constant the field opted into, the
  stored value decrypted, the field's `default`, then the fallback. With no
  `fields` to go on it still decrypts, because ciphertext carries a marker.
* `update()` goes through the page when it is registered, which refuses a
  key that is not one of its fields and sanitises the rest by type. Without
  the page an encrypted field is still written encrypted.
* `delete()` is false when nothing is stored under the key. A secret that
  will not decrypt is kept until something readable replaces it.
* A `fields` callable is called once, on the first read that needs it. One
  that reads a setting while it builds the configuration gets an answer from
  the stored value rather than calling itself again.

With a `hook_prefix`, `all()` is filtered by `{prefix}_get_settings`,
`get()` by `{prefix}_get_option` and `{prefix}_get_option_{$key}`, and a
change fires `{prefix}_update_option` with the key, the new value and the
previous one. With none, nothing is fired. `constant_prefix` names a field's
constant as the page's own setting does, and defaults the same way: the
option name and an underscore.

### What a value means

A toggle stores `"1"` and `"0"`, a relational field stores ids in more than
one shape, and a value nobody chose should not turn into one. The reader
says what a value means, through the field engine's own `Value`, so a term's
meta and a setting draw the line in the same place:

```php
$options = Page::options( 'my_plugin' );

$options->is_on( 'enable_feature' );        // A toggle or checkbox.
$options->includes( 'features', 'export' ); // One choice in a checkbox group.
$options->list( 'notify' );                 // Tags or a multiple select, as strings.
$options->ids( 'featured' );                // A relational field, as ids.
$options->int( 'items_per_page', 20 );      // A whole number, or 20 -- never 0 for "twenty".
$options->string( 'site_name' );            // Text, or the fallback for an array or a boolean.
$options->page_id( 'checkout_page' );       // The page a setting points at,
$options->page_url( 'checkout_page' );      // its address,
$options->is_page( 'checkout_page' );       // and whether this request is for it.
```

A `license` field and an `email_editor` field have readers of their own:

```php
$options->license( 'license' );                                // key, status, expiry.
$options->is_license_active( 'license' );
$options->update_license_status( 'license', 'expired', '2027-01-31' ); // From a cron check.

$options->email( 'email_receipt' );           // What sugarcommerce/emails' settings callable reads.
$options->email_recipient( 'email_receipt' ); // Who it goes to, or the site's admin.
$options->is_email_enabled( 'email_receipt' );
```

`email()` is the seam between the field and the email it edits: hand it to
`Emails::add()`'s `settings`, and a part the owner left empty is left out so
the email's own default stands in.

## Validation

Sanitising coerces; it does not refuse. A field that must hold something says
`'required' => true`, and one that must hold a particular shape names a rule
with `validate` — `email`, `url`, `numeric`, `integer`, `slug`, `alphanumeric`,
or a callable returning `true`, a message or a `WP_Error`:

```php
'contact' => [ 'type' => 'text', 'label' => __( 'Contact', 'my-plugin' ), 'required' => true, 'validate' => 'email' ],
```

A value that fails is not stored. The field keeps what it held, every other
field on the tab is saved, and after the redirect the message appears as an
error notice at the top of the page — in place of "Settings saved." — and
again under the field itself.

## Appearance

A page is drawn as WordPress draws its own: a heading and a form table per
section, the label on the left. That is the default.

A plugin whose other screens are cards can ask for the page to match:

```php
Page::register( 'my_plugin', [
	'page_title' => __( 'My Plugin Settings', 'my-plugin' ),
	'appearance' => 'modern',
	'sections'   => [
		'account'  => [
			'title'       => __( 'Account', 'my-plugin' ),
			'description' => __( 'Who this site signs in as.', 'my-plugin' ),
			'status'      => [ 'label' => __( 'Connected', 'my-plugin' ), 'tone' => 'success' ],
		],
		'advanced' => [
			'title'     => __( 'Advanced', 'my-plugin' ),
			'collapsed' => true,
		],
	],
	'fields'     => [
		'api_key' => [
			'type'      => 'password',
			'label'     => __( 'API key', 'my-plugin' ),
			'section'   => 'account',
			'encrypted' => true,
			'tooltip'   => __( 'From your account page, under Developers.', 'my-plugin' ),
		],
		'timeout' => [
			'type'    => 'number',
			'label'   => __( 'Timeout', 'my-plugin' ),
			'section' => 'advanced',
			'width'   => 'half',
		],
	],
] );
```

Each section is then a white card with its title and description, each
field has its label above it and its `tooltip` as a help icon beside the
label, and the save button rides in a bar that stays at the foot of the
window and says "Saved" after a clean save in place of the notice. Three
things a section can say only apply here:

* `collapsed` folds the card away until it is opened. It opens by itself
  when a field inside it was refused.
* `status` is a short state beside the title — a label, or a label and a
  `tone` of `success`, `warning`, `error`, `info` or `neutral`. A closure is
  called when the card is drawn, which is when the page's own settings can
  be read.
* A `description` may hold a short list as well as a sentence.

A section's `badge` locks every field in it, on either appearance.

A section with a description and no fields is drawn too -- a card that only
explains, such as where a key is found. Nothing places it on a tab, so it
names one with `tab` and is on the first otherwise. A title over nothing is
not drawn. The classic appearance draws such a section as its heading and
paragraph.

A field's `width` of `half` puts two on a line. Nothing about what is
stored, sanitised or refused changes with the appearance.

### Tabs down the side

A modern page keeps the centred header and its row of tabs unless it asks
otherwise. A plugin with a menu of its own, whose settings sit between its
list screens and its reports, can have the page laid out as those are:

```php
Page::register( 'my_plugin', [
	'header_title' => __( 'Settings', 'my-plugin' ),
	'appearance'   => 'modern',
	'layout'       => 'sidebar',
	'tabs'         => [
		'general'  => [ 'label' => __( 'General', 'my-plugin' ), 'icon' => 'heroicons-outline:cog-6-tooth' ],
		'payments' => [ 'label' => __( 'Payments', 'my-plugin' ), 'icon' => 'dashicons-money-alt' ],
	],
	'fields'       => [
		'name' => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ), 'tab' => 'general' ],
	],
] );
```

The title is then core's own heading at the top left ("Settings", and
"Settings › Payments" past the first tab) with notices under it, the tabs
are a card of links beside the cards, and on a narrow screen they are one
row of pills that scrolls. A tab's `icon` is drawn only in this layout, and
takes either form the field engine's `Icons::render()` reads: a `set:name`
icon (`heroicons-outline:cog-6-tooth`, any set the engine has), drawn as
inline SVG, or a Dashicon by its name or its class (`money-alt`,
`dashicons-money-alt`), drawn as the font's span. A tab without one, or with
one no set has, keeps the space for it. The tick beside "Saved" is
`heroicons-outline:check-circle`. `layout` is read only when the appearance
is `modern`; the `logo` and `badge` of the header are not drawn in the
sidebar layout.

## Export, import and reset

`'export_import' => true` and `'reset_button' => true` put the tools in
Screen Options, beside Help, rather than across the title. Export downloads
the page's option as JSON, leaving encrypted values out — they are sealed to
this site's salts and would only put a credential in a file. Import reads the
file back through the same sanitise callback as the form, so every value is
coerced by its field and anything that is not a field is ignored; a file
exported from another page is refused. Reset restores the tab being viewed
to its defaults. Each is a nonce'd form posting to `admin-post.php`, checked
against the page's `capability`. What it says afterwards ("Settings
imported.") is flashed through [sugarcommerce/notices](https://github.com/SugarCommerce/notices)
under the page's `option_name`: kept for the user who did it, shown once on
the screen the redirect lands on.

## Security

* Saving, importing or resetting without the right: every form checks its
  nonce and the page's `capability`, and options.php is held to the same
  capability rather than core's `manage_options`.
* Writing what is not a field: a save, an import and a reset all go through
  the page's sanitize callback, which coerces each value by its own type and
  drops any key the page does not declare.
* Saving another tab's fields: only the fields of the tab named in the
  submission, and only a registered tab, are read.
* Script in an import: a file that is not JSON is refused before it is
  decoded, and the page a file names is shown as text.
* Credentials in a file: an export leaves encrypted values out.
* Another admin's message: what an import or reset says waits for the user
  who did it (notices' flash), not in core's site-wide `settings_errors`.
* Redirects elsewhere: every redirect is `wp_safe_redirect()` to the page's
  own address.

## Layout

* `src/Page.php`: one settings page, slim; its jobs are traits in
  `src/Traits/Page/` (the registered pages, the screen, saving, drawing,
  export/import/reset, single values).
* `src/Options.php`: the one reader and writer of a page's option, with
  `src/Traits/Options/` (typed readers, licences, emails).
* `src/Cards.php` and `src/Sidebar.php`: the card and side-tab appearances.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/fields` and `sugarcommerce/notices`

## License

GPL-2.0-or-later
