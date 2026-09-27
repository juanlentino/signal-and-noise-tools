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
		'resume_downloads' => __( 'Resume PDF downloads', 'signal-and-noise-tools' ),
		'subscribes'       => __( 'Feed subscribe clicks', 'signal-and-noise-tools' ),
		'shares'           => __( 'Notes shared', 'signal-and-noise-tools' ),
		'verifies'         => __( 'Signatures checked', 'signal-and-noise-tools' ),
		'research_links'   => __( 'Research links followed (SSRN, DOI, Zenodo, ORCID)', 'signal-and-noise-tools' ),
		'readers_per_note' => __( 'Readers per note published', 'signal-and-noise-tools' ),
		'doi_downloads'    => __( 'DOI downloads', 'signal-and-noise-tools' ),
		'inquiries'        => __( 'Inquiries', 'signal-and-noise-tools' ),
		'notes_published'  => __( 'Notes published', 'signal-and-noise-tools' ),
		'feed_clicks'      => __( 'Clicked through from a feed', 'signal-and-noise-tools' ),
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
 * One key signal beside the hero number: the same ruled metric the audience
 * tiles use, so Home reads as one system.
 *
 * @param string     $label Label.
 * @param array|null $row   A layer row {value, window}.
 * @return string
 */
function north_star_key_html( $label, $row ) {
	$v = is_array( $row ) ? ( $row['value'] ?? null ) : null;
	return '<div class="snt-home__metric snt-ns-hero__key">'
		. '<div class="snt-home__metric-label">' . \snt_kit_esc( $label ) . '</div>'
		. '<strong>' . \snt_kit_esc( null === $v ? '·' : number_format_i18n( $v ) ) . '</strong>'
		. '<span class="snt-home__metric-delta">' . \snt_kit_esc( null === $v ? __( 'not measured', 'signal-and-noise-tools' ) : __( 'last 7 days', 'signal-and-noise-tools' ) ) . '</span>'
		. '</div>';
}

/**
 * The hero: the number Home is for, its four weeks, and the three signals
 * that matter most right now. Every other signal waits behind "All signals".
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
	$delta  = (int) $r['value'] - (int) $r['previous'];
	$change = sprintf( /* translators: 1: signed change, 2: three-week average */ __( '%1$s vs last week · 3-week avg %2$s', 'signal-and-noise-tools' ), ( $delta > 0 ? '+' : '' ) . $delta, number_format_i18n( (float) $r['prior_avg'], 1 ) );
	$series = array_map( 'intval', (array) ( $r['series'] ?? array() ) );
	// Four labelled weeks, oldest first. At single-digit counts a chart was
	// mostly empty axis; the numbers with their week say more.
	$now   = time();
	$weeks = '';
	foreach ( $series as $i => $n ) {
		$start  = $now - ( count( $series ) - $i ) * 7 * DAY_IN_SECONDS;
		$weeks .= '<li><span>' . \snt_kit_esc( wp_date( 'M j', $start ) ) . '</span><strong>' . \snt_kit_esc( number_format_i18n( $n ) ) . '</strong></li>';
	}
	$trend = '<ol class="snt-ns-hero__weeks" aria-label="' . \snt_kit_esc( __( 'Engaged readers per week, oldest first', 'signal-and-noise-tools' ) ) . '">' . $weeks . '</ol>';
	$layers = (array) ( $r['layers'] ?? array() );
	$intent = (array) ( $layers['intent'] ?? array() );

	$hero = '<div class="snt-ns-hero">'
		. '<div class="snt-ns-hero__main">'
		. '<div class="snt-home__metric-label">' . \snt_kit_esc( __( 'Engaged readers, last 7 days', 'signal-and-noise-tools' ) ) . '</div>'
		. '<strong class="snt-ns-hero__value">' . \snt_kit_esc( number_format_i18n( (int) $r['value'] ) ) . '</strong>'
		. '<span class="snt-home__metric-delta"' . ( $delta < 0 ? ' data-tone="warning"' : '' ) . '>' . \snt_kit_esc( $change ) . '</span>'
		. $trend
		. '</div>'
		. '<div class="snt-ns-hero__keys">'
		. north_star_key_html( __( 'Resume PDF downloads', 'signal-and-noise-tools' ), $intent['resume_downloads'] ?? null )
		. north_star_key_html( __( 'Research links followed', 'signal-and-noise-tools' ), $intent['research_links'] ?? null )
		. north_star_key_html( __( 'Notes shared', 'signal-and-noise-tools' ), $intent['shares'] ?? null )
		. '</div></div>';

	$cols = array(
		'<section><h3 class="snt-home__detail-h">' . \snt_kit_esc( __( 'Intent', 'signal-and-noise-tools' ) ) . '</h3>' . north_star_layer_html( $intent ) . '</section>',
		'<section><h3 class="snt-home__detail-h">' . \snt_kit_esc( __( 'Return', 'signal-and-noise-tools' ) ) . '</h3>' . north_star_layer_html( (array) ( $layers['return'] ?? array() ) ) . '</section>',
		'<section><h3 class="snt-home__detail-h">' . \snt_kit_esc( __( 'Inputs', 'signal-and-noise-tools' ) ) . '</h3>' . north_star_layer_html( (array) ( $layers['inputs'] ?? array() ) ) . '</section>',
	);
	$note = __( 'Counted per visitor per day: cookieless, so a reader on two days counts twice.', 'signal-and-noise-tools' )
		. ( ! empty( $r['capped'] ) ? ' ' . __( 'The event cap was hit; the count is a floor.', 'signal-and-noise-tools' ) : '' );
	$edit = \snt_kit_go( __( 'Change what counts', 'signal-and-noise-tools' ), array( 'tab' => 'monitoring', 'sub' => 'analytics', 'current' => $tab ) );
	$all  = \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => __( 'All signals', 'signal-and-noise-tools' ), 'hint' => __( 'intent, return, inputs', 'signal-and-noise-tools' ), 'class' => 'snt-ns-all' ),
		\snt_kit_grid( $cols, 220, 18 ) . '<p class="snt-hint">' . \snt_kit_esc( $note ) . ' ' . $edit . '</p>'
	);

	return '<section class="snt-home__section snt-north-star" aria-labelledby="snt-home-ns-heading">'
		. '<div class="snt-home__section-heading"><h2 id="snt-home-ns-heading">' . \snt_kit_esc( __( 'North star', 'signal-and-noise-tools' ) ) . '</h2></div>'
		. $hero . $all . '</section>';
}
