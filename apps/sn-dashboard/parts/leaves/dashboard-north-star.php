<?php
/**
 * The north star band at the top of S&N Home: one number, its trend, and the
 * three layers under it (intent, return, inputs). Reads snt_nsm_reading(),
 * the same record the `signal-noise/north-star` ability returns.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Labels for the layer rows, keyed as the reading keys them.
 *
 * @return array<string,string>
 */
function north_star_labels() {
	return array(
		'deep_readers'     => __( 'Deep readers (2+ pages)', 'signal-and-noise-tools' ),
		'actions'          => __( 'Downloads and outbound clicks', 'signal-and-noise-tools' ),
		'career_visits'    => __( 'Resume and contact visits', 'signal-and-noise-tools' ),
		'readers_per_note' => __( 'Readers per note published', 'signal-and-noise-tools' ),
		'doi_downloads'    => __( 'DOI downloads', 'signal-and-noise-tools' ),
		'inquiries'        => __( 'Inquiries', 'signal-and-noise-tools' ),
		'notes_published'  => __( 'Notes published', 'signal-and-noise-tools' ),
		'rss_readers'      => __( 'RSS readers', 'signal-and-noise-tools' ),
		'search_clicks'    => __( 'Search clicks', 'signal-and-noise-tools' ),
	);
}

/**
 * One layer as a facts list. A null value says why, never zero.
 *
 * @param array $rows Layer rows.
 * @return string
 */
function north_star_layer_html( array $rows ) {
	$labels = north_star_labels();
	$facts  = array();
	foreach ( $rows as $key => $row ) {
		$value   = $row['value'] ?? null;
		$window  = (string) ( $row['window'] ?? '' );
		$facts[] = null === $value
			? array( 'label' => $labels[ $key ] ?? $key, 'value' => (string) ( $row['pending'] ?? __( 'No data yet', 'signal-and-noise-tools' ) ), 'tone' => 'neutral' )
			: array( 'label' => $labels[ $key ] ?? $key, 'value' => number_format_i18n( $value, is_float( $value ) ? 1 : 0 ) . ( '' !== $window ? ' · ' . $window : '' ) );
	}
	return \snt_kit_kv( $facts );
}

/**
 * The band.
 *
 * @param string $tab Current tab.
 * @return string
 */
function north_star_html( $tab ) {
	if ( ! function_exists( '\snt_nsm_reading' ) ) {
		return '';
	}
	$r = \snt_nsm_reading();
	if ( empty( $r['configured'] ) ) {
		return \snt_kit_section( __( 'North star', 'signal-and-noise-tools' ), \snt_kit_empty( __( 'Needs the analytics credentials.', 'signal-and-noise-tools' ) ) );
	}
	$delta = (int) $r['value'] - (int) $r['previous'];
	$sign  = $delta > 0 ? '+' : '';
	$cap   = sprintf( /* translators: 1: signed change, 2: four-week average */ __( '%1$s vs last week · 3-week avg %2$s', 'signal-and-noise-tools' ), $sign . $delta, number_format_i18n( (float) $r['prior_avg'], 1 ) );
	$stat  = \snt_kit_stat( number_format_i18n( (int) $r['value'] ), __( 'Engaged readers, last 7 days', 'signal-and-noise-tools' ), $cap, $delta < 0 ? 'warning' : '' );

	$layers = (array) ( $r['layers'] ?? array() );
	$cols   = array(
		'<section><h3 class="snt-home__detail-h">' . \snt_kit_esc( __( 'Intent', 'signal-and-noise-tools' ) ) . '</h3>' . north_star_layer_html( (array) ( $layers['intent'] ?? array() ) ) . '</section>',
		'<section><h3 class="snt-home__detail-h">' . \snt_kit_esc( __( 'Return', 'signal-and-noise-tools' ) ) . '</h3>' . north_star_layer_html( (array) ( $layers['return'] ?? array() ) ) . '</section>',
		'<section><h3 class="snt-home__detail-h">' . \snt_kit_esc( __( 'Inputs', 'signal-and-noise-tools' ) ) . '</h3>' . north_star_layer_html( (array) ( $layers['inputs'] ?? array() ) ) . '</section>',
	);
	$note = __( 'Counted per visitor per day: cookieless, so a reader on two days counts twice.', 'signal-and-noise-tools' )
		. ( ! empty( $r['capped'] ) ? ' ' . __( 'The event cap was hit; the count is a floor.', 'signal-and-noise-tools' ) : '' );
	$edit = \snt_kit_go( __( 'Change what counts', 'signal-and-noise-tools' ), array( 'tab' => 'monitoring', 'sub' => 'analytics', 'current' => $tab ) );

	return \snt_kit_section(
		__( 'North star', 'signal-and-noise-tools' ),
		$stat . \snt_kit_grid( $cols, 220, 18 ) . '<p class="snt-hint">' . \snt_kit_esc( $note ) . ' ' . $edit . '</p>',
		'',
		array( 'class' => 'snt-north-star' )
	);
}
