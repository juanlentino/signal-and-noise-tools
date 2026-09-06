<?php
/**
 * Signal & Noise Tools — the kit's display elements, painted from PHP.
 *
 * See inc/openstation-kit.php for the escaping these build on.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `<os-stat>`: a value, a label, an optional caption, and a severity swatch
 * when the reading is not fine. The swatch reads the app tone contract
 * (`data-tone` = danger|warning|info|neutral), which is why `ok` paints none.
 *
 * @param string $value   The big number.
 * @param string $label   The small label.
 * @param string $caption Optional caption.
 * @param string $kind    Pill kind (`ok` paints no swatch).
 * @param array  $attrs   Extra attributes.
 * @return string
 */
function snt_kit_stat( $value, $label, $caption = '', $kind = '', array $attrs = array() ) {
	$tone = snt_kit_tone( $kind );
	$base = array(
		'value'   => (string) $value,
		'label'   => (string) $label,
		'caption' => '' !== (string) $caption ? (string) $caption : null,
	);
	if ( '' !== (string) $kind && 'success' !== $tone ) {
		$base['swatch']    = true;
		$base['data-tone'] = $tone;
	}
	return snt_kit_tag( 'os-stat', array_merge( $base, $attrs ) );
}

/**
 * `<os-section heading description>` around painted HTML.
 *
 * @param string $heading     Heading.
 * @param string $inner       Inner HTML.
 * @param string $description Optional description.
 * @param array  $attrs       Extra attributes.
 * @return string
 */
function snt_kit_section( $heading, $inner, $description = '', array $attrs = array() ) {
	return snt_kit_tag(
		'os-section',
		array_merge(
			array(
				'heading'     => (string) $heading,
				'description' => '' !== (string) $description ? (string) $description : null,
			),
			$attrs
		),
		$inner
	);
}

/**
 * `<os-notice tone>`; dismissible only when asked.
 *
 * @param string $kind        Pill kind or tone.
 * @param string $inner       Inner HTML.
 * @param bool   $dismissible Whether the notice can be dismissed.
 * @return string
 */
function snt_kit_notice( $kind, $inner, $dismissible = false ) {
	return snt_kit_tag(
		'os-notice',
		array(
			'tone'            => snt_kit_tone( $kind ),
			'not-dismissible' => ! $dismissible,
		),
		$inner
	);
}

/**
 * `<os-badge tone>` and `<os-chip tone>`.
 *
 * @param string $kind Pill kind or tone.
 * @param string $text Text.
 * @return string
 */
function snt_kit_badge( $kind, $text ) {
	return snt_kit_tag( 'os-badge', array( 'tone' => snt_kit_tone( $kind ) ), snt_kit_esc( $text ) );
}

/**
 * @param string $text Text.
 * @param string $kind Pill kind or tone ('' for the plain chip).
 * @return string
 */
function snt_kit_chip( $text, $kind = '' ) {
	return snt_kit_tag( 'os-chip', array( 'tone' => '' !== (string) $kind ? snt_kit_tone( $kind ) : null ), snt_kit_esc( $text ) );
}

/**
 * `<os-code>`; block by default, wrapped so long lines fold.
 *
 * @param string $text  Code text (escaped here).
 * @param bool   $block Block or inline.
 * @return string
 */
function snt_kit_code( $text, $block = true ) {
	return snt_kit_tag( 'os-code', array( 'block' => (bool) $block, 'wrap' => (bool) $block ), snt_kit_esc( $text ) );
}

/**
 * `<os-empty-state icon heading description>`.
 *
 * @param string $heading     Heading.
 * @param string $description Description.
 * @param string $icon        Dashicons slug (without the prefix is fine).
 * @return string
 */
function snt_kit_empty( $heading, $description = '', $icon = '' ) {
	return snt_kit_tag(
		'os-empty-state',
		array(
			'heading'     => (string) $heading,
			'description' => '' !== (string) $description ? (string) $description : null,
			'icon'        => '' !== (string) $icon ? (string) $icon : null,
		)
	);
}

/**
 * The in-body tab strip (`<os-tabs class="os-app-list__tabs">`) bound to a
 * state key: a pick writes the key and repaints. No panels — the server
 * paints the chosen leaf.
 *
 * @param string               $active Active value.
 * @param array<string,string> $items  value => label, in order.
 * @param string               $bind   State key.
 * @param string               $label  Accessible label.
 * @return string
 */
function snt_kit_tabs( $active, array $items, $bind = 'sub', $label = '' ) {
	$tabs = '';
	foreach ( $items as $value => $text ) {
		$tabs .= snt_kit_tag( 'os-tab', array( 'value' => (string) $value ), snt_kit_esc( $text ) );
	}
	return snt_kit_tag(
		'os-tabs',
		array(
			'class'   => 'os-app-list__tabs snt-subtabs',
			'value'   => (string) $active,
			'os-bind' => (string) $bind,
			'label'   => '' !== (string) $label ? (string) $label : null,
		),
		$tabs
	);
}
