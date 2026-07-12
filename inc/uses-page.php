<?php
/**
 * Signal & Noise Tools — /uses page content editor (data layer).
 *
 * Owner direction 2026-07-01: /uses gets the same plugin-managed content
 * behavior as /now. /about/uses is a real CMS child Page whose post_content is
 * regenerated from this editor on every save (sn_uses_page_save() drives the
 * builder in inc/content-migrations.php via sn_uses_sync_page()), mirroring
 * inc/now-page.php:
 *
 *   - durable autoload=no OPTION (sn_uses_page) holding the raw document +
 *     a site-timezone save-stamp (wp_date — the v7.5.1 lesson, from day one);
 *   - the SECTION grammar is shared with /now (sn_now_parse_sections);
 *     /uses items are {name, note} pairs, split on the FIRST ` | `;
 *   - a serializer so the admin editor can PREFILL from existing groups
 *     instead of making the owner retype eleven items. Fallback discipline:
 *     zero-group content is refused at save time, never blanking /about/uses.
 *
 * The theme's former `sn_uses_groups` filter seam (theme v10.10.0) was retired
 * when /about/uses became a real Page; this module no longer registers it.
 *
 * Admin surface: Content → Uses Page (inc/admin-forms/uses-page.php);
 * POST action `uses_save` (inc/admin-post-actions.php).
 *
 * Load order: requires inc/now-page.php first (shared parser) — both are
 * required from signal-and-noise-tools.php in that order.
 *
 * @package SignalNoiseTools
 * @since 7.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_USES_PAGE_OPTION', 'sn_uses_page' );

/**
 * Parse the raw /uses document into the theme's group shape. Rides the /now
 * section grammar, then maps each string item to a {name, note} pair split
 * on the FIRST ` | ` (pipes after that stay in the note).
 *
 * @param string $raw Owner-edited document.
 * @return array<int,array{label:string,items:array<int,array{name:string,note:string}>}>
 */
function sn_uses_parse_groups( $raw ) {
	$groups = array();
	foreach ( sn_now_parse_sections( $raw ) as $section ) {
		$items = array();
		foreach ( $section['items'] as $line ) {
			$parts = explode( '|', $line, 2 );
			$name  = trim( $parts[0] );
			if ( '' === $name ) {
				continue;
			}
			$items[] = array(
				'name' => $name,
				'note' => isset( $parts[1] ) ? trim( $parts[1] ) : '',
			);
		}
		if ( ! empty( $items ) ) {
			$groups[] = array( 'label' => $section['label'], 'items' => $items );
		}
	}
	return $groups;
}

/**
 * Serialize theme-shaped groups back into the editor's text format —
 * parse(serialize(x)) === x for normalized groups. Used to prefill the
 * editor with the theme's CURRENT file content on first open.
 *
 * @param array $groups Theme-shaped groups (sn_uses_groups() output).
 * @return string
 */
function sn_uses_serialize_groups( $groups ) {
	if ( ! is_array( $groups ) ) {
		return '';
	}
	$out = array();
	foreach ( $groups as $group ) {
		if ( ! is_array( $group ) || '' === trim( (string) ( $group['label'] ?? '' ) ) || empty( $group['items'] ) ) {
			continue;
		}
		$out[] = '## ' . trim( (string) $group['label'] );
		foreach ( (array) $group['items'] as $item ) {
			$name = trim( (string) ( is_array( $item ) ? ( $item['name'] ?? '' ) : $item ) );
			if ( '' === $name ) {
				continue;
			}
			$note  = is_array( $item ) ? trim( (string) ( $item['note'] ?? '' ) ) : '';
			$out[] = '- ' . $name . ( '' !== $note ? ' | ' . $note : '' );
		}
		$out[] = '';
	}
	return empty( $out ) ? '' : implode( "\n", $out );
}

/**
 * The stored /uses page, shape-validated. Null when nothing is saved (the
 * theme's file content is live) or the stored value is hostile.
 *
 * @return array{raw:string,updated:string}|null
 */
function sn_uses_page_get() {
	$stored = get_option( SN_USES_PAGE_OPTION );
	if ( ! is_array( $stored ) || ! isset( $stored['raw'], $stored['updated'] ) ) {
		return null;
	}
	return array(
		'raw'     => (string) $stored['raw'],
		'updated' => (string) $stored['updated'],
	);
}

/**
 * Save (or clear) the /uses document. Whitespace-only input deletes the
 * option — /uses reverts to the theme's file content.
 *
 * @param string $raw Owner-edited document.
 * @return bool True on a real change; false when re-saving identical content.
 */
function sn_uses_page_save( $raw ) {
	$raw = (string) $raw;
	if ( '' === trim( $raw ) ) {
		return delete_option( SN_USES_PAGE_OPTION );
	}
	$result = update_option(
		SN_USES_PAGE_OPTION,
		array(
			'raw'     => $raw,
			// Site-timezone stamp (wp_date), never UTC — the v7.5.1 lesson.
			'updated' => function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
		),
		false // autoload=no: read by the editor + the page regenerator below.
	);

	// v9.20.0: this plain-text box is the canonical /uses editor, and
	// /about/uses is now a real CMS child Page. Regenerate the Page body on
	// every save. Guarded so the editor's own unit tests exercise the option
	// layer in isolation (the page builder in inc/content-migrations.php loads
	// after this file; it is present at save time on a live request).
	if ( function_exists( 'sn_uses_sync_page' ) ) {
		sn_uses_sync_page();
	}

	return $result;
}
