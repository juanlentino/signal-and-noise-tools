<?php
/**
 * S&N Dashboard — Content → Now Page, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/now-page.php, `sn_admin_render_now_section()`)
 * paints one form (`sn_action=now_save`, the shared handler table): the intro
 * with the live /now link and the last-saved stamp, the helper text, one card
 * per stored section (a `now[groups][<i>][label]` text input and a
 * `now[groups][<i>][items]` textarea, one item per line), a `<template>` card
 * keyed `__G__` the "+ Add section" script clones, and the save button.
 *
 * Same readers (`sn_now_page_get()`, `sn_now_parse_sections()`), same names,
 * same handler. A window runs no script, so the template card is PAINTED as
 * the one empty trailing card, under the very names the template bakes
 * (`now[groups][__G__][…]`): `sn_now_rows_to_text()` prunes a fully blank row
 * and keeps string keys, so a blank card costs nothing and a filled one is a
 * new section. The ↑ ↓ ✕ row controls were script-only and posted nothing —
 * here a section is removed by clearing its two fields.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The stored page and its sections, read the way the classic leaf reads them.
 *
 * @return array{page:?array{raw:string,updated:string},sections:array<int,array{label:string,items:array<int,string>}>}
 */
function now_state() {
	$page     = function_exists( 'sn_now_page_get' ) ? \sn_now_page_get() : null;
	$sections = $page && function_exists( 'sn_now_parse_sections' ) ? \sn_now_parse_sections( $page['raw'] ) : array();
	return array(
		'page'     => is_array( $page ) ? $page : null,
		'sections' => is_array( $sections ) ? $sections : array(),
	);
}

/**
 * One section card: the label field and the items textarea, under the names
 * the classic card posts (`<prefix>[label]`, `<prefix>[items]`).
 *
 * @param string $prefix  Input-name prefix (`now[groups][0]`, or the template's `now[groups][__G__]`).
 * @param array  $section {label, items[]} (empty for the trailing new card).
 * @param string $hint    Optional hint under the label field.
 * @return string
 */
function now_card_html( $prefix, array $section, $hint = '' ) {
	$items = (array) ( $section['items'] ?? array() );
	$label = \snt_kit_field(
		'text',
		$prefix . '[label]',
		__( 'Section label', 'signal-and-noise-tools' ),
		(string) ( $section['label'] ?? '' ),
		array(
			'placeholder' => 'Building',
			'hint'        => '' !== (string) $hint ? (string) $hint : null,
		)
	);
	$lines = \snt_kit_field(
		'textarea',
		$prefix . '[items]',
		__( 'Items — one per line', 'signal-and-noise-tools' ),
		implode( "\n", array_map( 'strval', $items ) ),
		array(
			'rows'        => 5,
			'placeholder' => __( "One line about what you are doing\nAnother line", 'signal-and-noise-tools' ),
		)
	);
	return \snt_kit_tag( 'os-card', array( 'compact' => true ), '<os-stack gap="8">' . $label . $lines . '</os-stack>' );
}

/**
 * The intro: which page this edits, and when it was last saved.
 *
 * @param array $s From now_state().
 * @return string
 */
function now_intro_html( array $s ) {
	$link = \snt_kit_link( '/now', home_url( '/now' ) );
	if ( ! empty( $s['sections'] ) ) {
		$intro = sprintf(
			/* translators: 1: link to the live /now page, 2: the last-saved date as code */
			\snt_kit_esc( __( 'This form is the editor for the live %1$s page. Saving here regenerates it. Last saved: %2$s.', 'signal-and-noise-tools' ) ),
			$link,
			\snt_kit_code( (string) ( $s['page']['updated'] ?? '' ), false )
		);
	} else {
		$intro = sprintf(
			/* translators: %s: link to the /now page */
			\snt_kit_esc( __( 'This form is the editor for the %s page. Add sections below and save to publish it.', 'signal-and-noise-tools' ) ),
			$link
		);
	}
	return '<p class="snt-prose">' . $intro . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( "Each card is one section on /now: a label, then its items one per line. Incomplete cards are refused at save (items need a label; a label needs at least one item). Removing every card clears the override: the page falls back to the theme's built-in content (it is never silently blanked).", 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'The last card is always a new section. To remove a section, clear its label and its items; to reorder, move the text between cards.', 'signal-and-noise-tools' ) ) . '</p>';
}

/**
 * The cards: one per stored section, then the new one the template stood for.
 *
 * @param array<int,array<string,mixed>> $sections Parsed sections.
 * @return string
 */
function now_cards_html( array $sections ) {
	$out = '';
	$i   = 0;
	foreach ( $sections as $section ) {
		$out .= now_card_html( 'now[groups][' . $i . ']', (array) $section );
		$i++;
	}
	$out .= now_card_html(
		'now[groups][__G__]',
		array( 'items' => array() ),
		__( 'New section: fill in a label and at least one item to add it, or leave both empty.', 'signal-and-noise-tools' )
	);
	return '<os-stack gap="12">' . $out . '</os-stack>';
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_now( array $ctx ) {
	unset( $ctx );
	$s    = now_state();
	$form = \snt_kit_form(
		'now_save',
		now_cards_html( $s['sections'] ),
		array( 'submit' => __( 'Save now page', 'signal-and-noise-tools' ) )
	);
	return \snt_kit_section( __( 'Now page', 'signal-and-noise-tools' ), now_intro_html( $s ) . $form );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/now'] = __NAMESPACE__ . '\\paint_content_now';
		return $painters;
	}
);
