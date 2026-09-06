<?php
/**
 * S&N Analytics — kit helpers the chrome and view painters share.
 *
 * Picks are `go` with `{ key, value }` (the frame's picked() rules). Tables
 * and histograms are the shell's `<os-table>` / `<os-histogram>`, the same
 * parts OpenStation's own Posts window uses.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A control pick: range, class, compare, drill, event_prop, lg_range.
 *
 * @param string $label  Button text.
 * @param string $key    State key.
 * @param string $value  Pick.
 * @param bool   $active Whether this is the current value.
 * @return string
 */
function pick( $label, $key, $value, $active ) {
	return \snt_kit_button(
		(string) $label,
		'go',
		array(
			'variant' => $active ? 'primary' : 'secondary',
			'args'    => array(
				'key'   => (string) $key,
				'value' => (string) $value,
			),
		)
	);
}

/**
 * A doorway onto another view tab. The companion script activates the strip.
 *
 * @param string $label Tab label.
 * @param string $slug  View slug.
 * @return string
 */
function view_door( $label, $slug ) {
	return \snt_kit_tag(
		'os-button',
		array(
			'class'        => 'snt-go',
			'variant'      => 'link',
			'data-snt-tab' => (string) $slug,
		),
		\snt_kit_esc( (string) $label )
	);
}

/**
 * @param mixed $n Number.
 * @return string
 */
function num( $n ) {
	return function_exists( 'number_format_i18n' ) ? number_format_i18n( (int) $n ) : (string) (int) $n;
}

/**
 * KPI strip: rows of `l` (label), `n` (value), optional `sub`, `kind`.
 *
 * @param array<int,array<string,mixed>> $cards Cards.
 * @return string
 */
function stats( array $cards ) {
	$out = '';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$out .= \snt_kit_stat(
			(string) ( $card['n'] ?? '' ),
			(string) ( $card['l'] ?? '' ),
			(string) ( $card['sub'] ?? '' ),
			(string) ( $card['kind'] ?? '' )
		);
	}
	return '' === $out ? '' : '<div class="snt-stats">' . $out . '</div>';
}

require_once __DIR__ . '/paint-kit-tables.php';
