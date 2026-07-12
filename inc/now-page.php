<?php
/**
 * Signal & Noise Tools — /now page content editor (data layer).
 *
 * Owner direction 2026-07-01: /now content should be edited in the plugin
 * admin, not hardcoded in a theme file release. /now is a real CMS Page whose
 * post_content is regenerated from this editor on every save — the page builder
 * lives in inc/content-migrations.php and sn_now_page_save() drives it via
 * sn_now_sync_page(). This module is that editor's data layer:
 *
 *   - one durable autoload=no OPTION (sn_now_page) holding the owner's raw
 *     plain-text document + the save-stamp (transients are flush-volatile
 *     under Breeze/Redis, so durable owner content never rides a transient);
 *   - a tolerant parser: `## Label` opens a section, every other non-empty
 *     line is an item (leading `- ` / `* ` stripped). Fallback discipline:
 *     content that parses to zero sections is refused at save time, so a bad
 *     save can never blank the live /now page.
 *
 * The theme's former `sn_now_sections` / `sn_now_updated` filter seams (theme
 * v10.21.x) were retired when /now became a real Page (theme v10.34.0); this
 * module no longer registers them.
 *
 * Admin surface: Content → Now Page (inc/admin-forms/now-page.php);
 * POST action `now_save` (inc/admin-post-actions.php).
 *
 * @package SignalNoiseTools
 * @since 7.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_NOW_PAGE_OPTION', 'sn_now_page' );

/**
 * Parse the raw /now document into the theme's section shape.
 *
 * `## Label` (any 1-6 # run) opens a section; every other non-empty line is
 * an item with a leading `- ` / `* ` bullet stripped. Items before the first
 * header are dropped (the theme prunes label-less sections anyway); sections
 * without items are dropped.
 *
 * @param string $raw Owner-edited document.
 * @return array<int,array{label:string,items:array<int,string>}>
 */
function sn_now_parse_sections( $raw ) {
	$sections = array();
	$label    = '';
	$items    = array();

	$flush = static function () use ( &$sections, &$label, &$items ) {
		if ( '' !== $label && ! empty( $items ) ) {
			$sections[] = array( 'label' => $label, 'items' => $items );
		}
		$items = array();
	};

	foreach ( preg_split( '/\R/u', (string) $raw ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		if ( preg_match( '/^#{1,6}\s+(.+)$/', $line, $m ) ) {
			$flush();
			$label = trim( $m[1] );
			continue;
		}
		if ( '' === $label ) {
			continue; // Item before any header — dropped.
		}
		$items[] = trim( preg_replace( '/^[-*]\s+/', '', $line ) );
	}
	$flush();

	return $sections;
}

/**
 * The stored /now page, shape-validated. Null when nothing is saved (the
 * theme's file content is live) or the stored value is hostile.
 *
 * @return array{raw:string,updated:string}|null
 */
function sn_now_page_get() {
	$stored = get_option( SN_NOW_PAGE_OPTION );
	if ( ! is_array( $stored ) || ! isset( $stored['raw'], $stored['updated'] ) ) {
		return null;
	}
	return array(
		'raw'     => (string) $stored['raw'],
		'updated' => (string) $stored['updated'],
	);
}

/**
 * The stored document parsed into sections ([] when nothing usable is saved).
 *
 * @return array<int,array{label:string,items:array<int,string>}>
 */
function sn_now_page_sections() {
	$page = sn_now_page_get();
	return $page ? sn_now_parse_sections( $page['raw'] ) : array();
}

/**
 * Save (or clear) the /now document. Whitespace-only input deletes the
 * option — the /now page reverts to the theme's file content. The updated
 * stamp is set at save time (the theme renders it, so staleness is honest).
 *
 * @param string $raw Owner-edited document.
 * @return bool True on a real change (update_option/delete_option semantics);
 *              false when re-saving identical content.
 */
function sn_now_page_save( $raw ) {
	$raw = (string) $raw;
	if ( '' === trim( $raw ) ) {
		return delete_option( SN_NOW_PAGE_OPTION );
	}
	$result = update_option(
		SN_NOW_PAGE_OPTION,
		array(
			'raw'     => $raw,
			// v7.5.1: SITE-timezone date, not UTC. gmdate() stamped an owner
			// save at 8pm US-Eastern July 1 as "July 2" on the live /now page.
			// wp_date() uses the WP settings timezone (always defined on live
			// WP 5.3+; the gmdate fallback only serves bare test harnesses).
			'updated' => function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
		),
		false // autoload=no: read by the editor + the page regenerator below.
	);

	// v9.19.0: this plain-text box is the canonical /now editor, and /now is now
	// a real CMS Page. Regenerate the Page body (hero + these sections as blocks)
	// on every save — the Page is the rendered artifact + Excerpt/SEO/URL surface.
	// Guarded so the editor's own unit tests exercise the option layer in
	// isolation (the page builder lives in inc/content-migrations.php, loaded
	// after this file; it is present at save time on a live request).
	if ( function_exists( 'sn_now_sync_page' ) ) {
		sn_now_sync_page();
	}

	return $result;
}
