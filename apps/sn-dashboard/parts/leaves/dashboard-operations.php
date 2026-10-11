<?php
/**
 * S&N Home, Operations: the Systems section (dashboard-systems.php) and the
 * database overrides, both open. 2026-10-11 (owner rule: no collapsible
 * sections): each used to fold behind one line, the overrides inside the wall's.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/dashboard-systems.php'; // the Systems section: finding, cards, band.

/** Override names painted before the rest are counted out loud. */
const SNT_HOME_OVERRIDES_CAP = 10;

/**
 * Escaped items joined the American way: "a", "a and b", "a, b, and c".
 *
 * @param string[] $items Escaped HTML items.
 * @return string
 */
function and_list( array $items ) {
	$last = (string) array_pop( $items );
	if ( array() === $items ) {
		return $last;
	}
	/* translators: 1: the items before the last, 2: the last item. */
	$format = count( $items ) > 1 ? __( '%1$s, and %2$s', 'signal-and-noise-tools' ) : __( '%1$s and %2$s', 'signal-and-noise-tools' );
	return sprintf( \snt_kit_esc( $format ), implode( \snt_kit_esc( __( ', ', 'signal-and-noise-tools' ) ), $items ), $last );
}

/**
 * Operations: the Systems section, then the database overrides.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_operations_html( array $data, $tab ) {
	return '<section class="snt-home__section snt-home__section--subordinate" aria-labelledby="snt-home-ops-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-ops-heading">' . esc_html__( 'Operations', 'signal-and-noise-tools' ) . '</h2>'
		. '</div>'
		. systems_html( (array) ( $data['checks'] ?? array() ), (array) ( $data['components'] ?? array() ), $tab )
		. overrides_html( (array) ( $data['overrides'] ?? array() ) )
		. '</section>';
}

/**
 * The database overrides: a finding over every name, capped out loud. The id
 * is the anchor the attention strip links (`#sn-dash-diagnostics`).
 *
 * @param string[] $names `post_type/post_name` of each override.
 * @return string
 */
function overrides_html( array $names ) {
	$n = count( $names );
	if ( 0 === $n ) {
		return '';
	}
	/* translators: %s: number of theme items overridden in the database. */
	$finding = sprintf( _n( '%s theme item is served from the database, not from the theme files.', '%s theme items are served from the database, not from the theme files.', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ) );
	$codes   = implode( '', array_map( static fn( $name ) => \snt_kit_code( (string) $name, false ), array_slice( $names, 0, SNT_HOME_OVERRIDES_CAP ) ) );
	return \snt_kit_section(
		__( 'Database overrides', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>'
		. \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $codes )
		/* translators: %s: overrides not listed. */
		. \snt_kit_more( $n - SNT_HOME_OVERRIDES_CAP, __( '+%s more overrides; the list is capped, not complete.', 'signal-and-noise-tools' ) ),
		'',
		array( 'id' => 'sn-dash-diagnostics' )
	);
}
