<?php
/**
 * Settings Sidebar
 *
 * @package     SugarCommerce\Settings
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Settings;

/**
 * The furniture of a modern page whose tabs are down the side.
 *
 * `'layout' => 'sidebar'` on a page whose appearance is `modern`. The
 * centred header band and its row of tabs are the privacy screen's, and
 * they suit a page of three tabs that stands alone. A plugin with a menu
 * of its own has list screens and reports one click either side of its
 * settings, and those are ordinary screens: a title at the top left and
 * the content under it. This is the settings page drawn as one of them --
 * core's own heading, where a notice lands under it, and the tabs as a
 * card of links beside the column of cards.
 *
 * The markup and the figures follow wp-register-reports' sidebar, link for
 * link, so the two screens are the same furniture in one plugin. They are
 * restated here because a settings page does not load that package; the
 * styles are the kit's.
 *
 * Nothing here decides what is drawn in the column or what is stored.
 */
final class Sidebar {

	/**
	 * The page's heading: its title and, past the first tab, which tab.
	 *
	 * "Settings › Payments". The first tab is the page's front and reads
	 * better as the bare title, as it does on a report.
	 *
	 * @param string $title   The page's title.
	 * @param string $section The current tab's label, or empty on the first.
	 *
	 * @return string
	 */
	public static function title( string $title, string $section ): string {
		if ( '' === $title ) {
			return '';
		}

		return sprintf(
			'<h1 class="wp-heading-inline field-kit__settings-title">%s%s</h1>',
			esc_html( $title ),
			'' === $section
				? ''
				: sprintf(
					' <span class="field-kit__settings-title-sep" aria-hidden="true">&rsaquo;</span> ' .
					'<span class="field-kit__settings-title-section">%s</span>',
					esc_html( $section )
				)
		);
	}

	/**
	 * The tabs as a column of links.
	 *
	 * A labelled nav of links, as the tab bar is, so it is announced as the
	 * same thing in either layout. The current one carries aria-current;
	 * the class beside it is only how it is drawn. On a narrow screen the
	 * same links become one scrolling row, in CSS.
	 *
	 * @param array<string, array<string, mixed>> $tabs    Tabs keyed by slug: `label`, `url` and an optional
	 *                                                     `icon`, `set:name` or a Dashicon.
	 * @param string                              $current The tab being shown.
	 *
	 * @return string
	 */
	public static function nav( array $tabs, string $current ): string {
		if ( [] === $tabs ) {
			return '';
		}

		// One with an icon and the labels are in a second column, so a tab
		// without one keeps the space for it. None, and there is no column.
		$icons = [] !== array_filter( array_column( $tabs, 'icon' ) );
		$links = '';

		foreach ( $tabs as $slug => $tab ) {
			$links .= self::link( (string) $slug, $tab, (string) $slug === $current, $icons );
		}

		return sprintf(
			'<nav class="field-kit__settings-sidebar" aria-label="%s"><ul class="field-kit__settings-sidebar-list">%s</ul></nav>',
			esc_attr__( 'Settings sections', 'sugarcommerce' ),
			$links
		);
	}

	/**
	 * One tab's link.
	 *
	 * @param string               $slug    The tab's slug.
	 * @param array<string, mixed> $tab     Its label, url and icon.
	 * @param bool                 $current Whether it is the one being shown.
	 * @param bool                 $icons   Whether any tab has an icon.
	 *
	 * @return string
	 */
	private static function link( string $slug, array $tab, bool $current, bool $icons ): string {
		// Decorative either way: the label beside it says the same thing.
		// `heroicons-outline:banknotes` is inline SVG, a Dashicon (`money-alt`
		// or `dashicons-money-alt`) the font's span.
		$mark = Cards::icon( (string) ( $tab['icon'] ?? '' ), [ 'class' => 'field-kit__settings-sidebar-icon' ] );

		// No icon, or one that cannot be drawn: the space for one, where
		// other tabs have theirs.
		if ( '' === $mark && $icons ) {
			$mark = '<span class="field-kit__settings-sidebar-icon field-kit__settings-sidebar-icon--none" aria-hidden="true"></span>';
		}

		return sprintf(
			'<li><a href="%s" class="field-kit__settings-sidebar-link%s" data-tab="%s"%s>%s<span class="field-kit__settings-sidebar-label">%s</span></a></li>',
			esc_url( (string) ( $tab['url'] ?? '' ) ),
			$current ? ' field-kit__settings-sidebar-link--current' : '',
			esc_attr( $slug ),
			$current ? ' aria-current="page"' : '',
			$mark,
			esc_html( (string) ( $tab['label'] ?? $slug ) )
		);
	}
}
