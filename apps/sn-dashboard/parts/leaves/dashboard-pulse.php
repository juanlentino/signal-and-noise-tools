<?php
/**
 * S&N Home, Site pulse: the audience tiles and the audience detail under them.
 *
 * 2026.10.4 audit (batch 4): Visits is the Analytics app's gated Visits
 * (`pageview_visits`, visitor-days that saw a page), never the deprecated
 * ungated `visits`, and the ratio is views per visit, the desktop widget's
 * "Views / visit". With analytics off the tiles say "not measured"; with a
 * failed read (`pageview_visits` null, while `views` reads 0 by design) they
 * say "not read". Never 0, never a dash. Split out of dashboard.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** Below this many in the previous week, a percentage change is noise. */
const SNT_HOME_DELTA_MIN_BASE = 50;

/**
 * A tile's change line: a signed percentage, or the plain previous count
 * when the previous week is too small for a percentage to mean anything
 * (37 views to 317 read as +757%).
 *
 * @param array $d {current, previous, pct}.
 * @return string
 */
function home_delta_text( array $d ) {
	if ( ! isset( $d['pct'] ) || null === $d['pct'] ) {
		return '';
	}
	if ( isset( $d['previous'] ) && (int) $d['previous'] < SNT_HOME_DELTA_MIN_BASE ) {
		return sprintf( /* translators: %s previous week's count */ __( 'prev %s', 'signal-and-noise-tools' ), number_format_i18n( (int) $d['previous'] ) );
	}
	return ( $d['pct'] >= 0 ? '+' : '' ) . $d['pct'] . '%';
}

/**
 * Render a single Site Pulse metric cell. A value with no reading paints its
 * words in the label's size (`data-none`), never a number.
 *
 * @param string $label Label.
 * @param string $icon  Dashicon class.
 * @param string $value Primary value.
 * @param string $delta Comparison line.
 * @param string $href  Target URL.
 * @param string $tab   Current tab.
 * @param bool   $none  The value is words for a missing reading.
 * @return string
 */
function pulse_item_html( $label, $icon, $value, $delta, $href, $tab = 'dashboard', $none = false ) {
	$body = '<div class="snt-home__metric-label">'
		. '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>'
		. '<span>' . esc_html( $label ) . '</span>'
		. '</div>'
		. '<strong' . ( $none ? ' data-none' : '' ) . '>' . esc_html( $value ) . '</strong>'
		. ( '' !== $delta ? '<span class="snt-home__metric-delta">' . esc_html( $delta ) . '</span>' : '' );
	return \snt_kit_tag( 'a', go_attrs( array( 'class' => 'snt-home__metric', 'href' => (string) $href ), $href, $tab ), $body );
}

/**
 * The audience reading: the four tiles' values and change lines.
 *
 * @return array<string,array{0:string,1:string,2:bool}> views, visits, ratio, search: value, line, none.
 */
function home_pulse_reading() {
	$on     = function_exists( 'sn_analytics_config' ) && \sn_analytics_config();
	$deltas = $on && function_exists( 'sn_analytics_period_deltas' )
		? (array) \sn_analytics_period_deltas( gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() ), 'human' )
		: array();
	$pv     = (array) ( $deltas['pageview_visits'] ?? array() );
	$out    = array();
	if ( isset( $pv['current'] ) ) {
		$views  = (int) ( $deltas['views']['current'] ?? 0 );
		$visits = (int) $pv['current'];
		$out['views']  = array( number_format_i18n( $views ), home_delta_text( (array) ( $deltas['views'] ?? array() ) ), false );
		$out['visits'] = array( number_format_i18n( $visits ), home_delta_text( $pv ), false );
		$out['ratio']  = $visits > 0
			? array( number_format_i18n( $views / $visits, 1 ), home_ratio_delta( $views / $visits, (int) ( $deltas['views']['previous'] ?? 0 ), (int) ( $pv['previous'] ?? 0 ) ), false )
			: array( __( 'no visits yet', 'signal-and-noise-tools' ), '', true );
	} else {
		$word = $on ? __( 'not read', 'signal-and-noise-tools' ) : __( 'not measured', 'signal-and-noise-tools' );
		$why  = $on ? __( 'the analytics read failed', 'signal-and-noise-tools' ) : __( 'analytics is off', 'signal-and-noise-tools' );
		$out['views']  = array( $word, $why, true );
		$out['visits'] = array( $word, $why, true );
		$out['ratio']  = array( $word, $why, true );
	}
	$m    = function_exists( 'snt_dashboard_measurement_data' ) ? (array) \snt_dashboard_measurement_data() : array();
	$days = (int) ( $m['search_clicks_days'] ?? 0 );
	/* translators: %s: days in the Search Console window. */
	$window        = $days > 0 ? sprintf( _n( 'last %s day', 'last %s days', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ) ) : '';
	$out['search'] = isset( $m['search_clicks'] )
		? array( number_format_i18n( (int) $m['search_clicks'] ), $window, false )
		: array( __( 'not measured', 'signal-and-noise-tools' ), __( 'Search Console has not synced', 'signal-and-noise-tools' ), true );
	return $out;
}

/**
 * The views-per-visit tile's own change line: the ratio's move, never the
 * views delta (views and visits can move together, ratio unchanged).
 *
 * @param float $rate         This week's views per visit.
 * @param int   $prior_views  Last week's views.
 * @param int   $prior_visits Last week's visits.
 * @return string
 */
function home_ratio_delta( $rate, $prior_views, $prior_visits ) {
	if ( $prior_views <= 0 || $prior_visits <= 0 || ! function_exists( 'sn_analytics_delta' ) ) {
		return '';
	}
	$prior = $prior_views / $prior_visits;
	$d     = \sn_analytics_delta( round( $rate, 1 ), $prior );
	if ( null === $d['pct'] ) {
		return '';
	}
	// The ratio moves with BOTH inputs: a tiny week of either is noise.
	return min( $prior_views, $prior_visits ) < SNT_HOME_DELTA_MIN_BASE
		? sprintf( /* translators: %s previous week's ratio */ __( 'prev %s', 'signal-and-noise-tools' ), number_format_i18n( $prior, 1 ) )
		: ( $d['pct'] >= 0 ? '+' : '' ) . $d['pct'] . '%';
}

/**
 * Render the Site Pulse: the audience tiles, then the audience detail.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_pulse_html( array $data, $tab ) {
	$r   = home_pulse_reading();
	$url = static fn( $q ) => admin_url( 'admin.php?page=sn-analytics' . $q );
	return '<section class="snt-home__section" aria-labelledby="snt-home-pulse-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-pulse-heading">' . esc_html__( 'Site pulse', 'signal-and-noise-tools' ) . '</h2>'
		/* translators: %s: a relative time, "just now". */
		. '<span class="snt-home__timestamp">' . sprintf( esc_html__( 'Updated %s', 'signal-and-noise-tools' ), \snt_kit_relative_time( time(), __( 'just now', 'signal-and-noise-tools' ) ) ) . '</span>'
		. '</div>'
		. '<div class="snt-home__pulse-group">'
		. '<div class="snt-home__pulse-group-label">' . esc_html__( 'Audience (7 days)', 'signal-and-noise-tools' ) . '</div>'
		. '<div class="snt-home__pulse">'
		. pulse_item_html( __( 'Views', 'signal-and-noise-tools' ), 'dashicons-visibility', $r['views'][0], $r['views'][1], $url( '&sn_range=7d' ), $tab, $r['views'][2] )
		. pulse_item_html( __( 'Visits', 'signal-and-noise-tools' ), 'dashicons-groups', $r['visits'][0], $r['visits'][1], $url( '&sn_view=visits&sn_range=7d' ), $tab, $r['visits'][2] )
		. pulse_item_html( __( 'Views per visit', 'signal-and-noise-tools' ), 'dashicons-performance', $r['ratio'][0], $r['ratio'][1], $url( '&sn_range=7d' ), $tab, $r['ratio'][2] )
		. pulse_item_html( __( 'Search clicks', 'signal-and-noise-tools' ), 'dashicons-search', $r['search'][0], $r['search'][1], $url( '&sn_view=search' ), $tab, $r['search'][2] )
		. '</div>'
		// 15.3.2: the audience lists (top pages, sources, queries) belong here,
		// under the numbers they explain, not under Operations. Each column
		// names its own window (2026.10.4: queries are 28 days).
		. detail_html( (array) ( $data['panels'] ?? array() ), 'audience', __( 'Audience detail', 'signal-and-noise-tools' ) )
		. '</div></section>';
}

/**
 * Detail panels: one flat column per panel of a group, under an eyebrow.
 * 15.3.2: one painter, two homes (audience under Site pulse, ops under
 * Operations). An audience panel left with no row (every query brought no
 * click) is not an empty column: its sentence stands under the columns.
 *
 * @param array<int,array<string,mixed>> $panels  Panels.
 * @param string                         $group   'audience' | 'ops'.
 * @param string|null                    $heading The eyebrow.
 * @return string
 */
function detail_html( array $panels, $group = 'ops', $heading = null ) {
	$cols  = '';
	$count = 0;
	$quiet = array();
	foreach ( $panels as $panel ) {
		if ( ! is_array( $panel ) || (string) ( $panel['group'] ?? 'ops' ) !== $group ) {
			continue;
		}
		$rows  = array_key_exists( 'rows', $panel ) ? $panel['rows'] : null;
		$title = (string) ( $panel['title'] ?? '' ) . ( '' !== (string) ( $panel['caption'] ?? '' ) ? ' · ' . (string) $panel['caption'] : '' );
		if ( 'audience' === $group && is_array( $rows ) ) {
			// A query that brought no clicks is noise on Home.
			$all   = count( $rows );
			$rows  = array_values( array_filter( $rows, static fn( $r ) => '0' !== (string) ( $r['value'] ?? '' ) ) );
			$named = (string) ( $panel['title'] ?? '' ) . ( '' !== (string) ( $panel['caption'] ?? '' ) ? ' (' . (string) $panel['caption'] . ')' : '' );
			if ( array() === $rows ) {
				$quiet[] = $all > 0
					/* translators: %s: the panel, "Top queries (clicks, 28 days)". */
					? sprintf( __( '%s: none brought a click.', 'signal-and-noise-tools' ), $named )
					/* translators: 1: the panel, "Top queries (clicks, 28 days)", 2: its empty sentence. */
					: sprintf( __( '%1$s: %2$s.', 'signal-and-noise-tools' ), $named, lcfirst( (string) ( $panel['empty'] ?? '' ) ) );
				continue;
			}
		}
		$inner = null === $rows
			? '<p class="snt-list__empty">' . \snt_kit_esc( (string) ( $panel['unmeasured'] ?? '' ) ) . '</p>'
			: \snt_kit_list( (array) $rows, array( 'empty' => (string) ( $panel['empty'] ?? '' ) ) );
		$cols .= '<section class="snt-home__detail-col"><h3 class="snt-home__detail-h">' . \snt_kit_esc( $title ) . '</h3>' . $inner . '</section>';
		++$count;
	}
	if ( '' === $cols && array() === $quiet ) {
		return '';
	}
	$label = null === $heading ? __( 'Operations detail', 'signal-and-noise-tools' ) : (string) $heading;
	return '<div class="snt-home__detail-group">'
		. '<div class="snt-home__pulse-group-label">' . \snt_kit_esc( $label ) . '</div>'
		. ( '' !== $cols ? '<div class="snt-home__detail" style="--snt-detail-cols:' . (int) $count . '">' . $cols . '</div>' : '' )
		. ( array() !== $quiet ? '<p class="snt-hint">' . \snt_kit_esc( implode( ' ', $quiet ) ) . '</p>' : '' )
		. '</div>';
}
