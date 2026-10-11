<?php
/**
 * Signal & Noise Tools: the machine views' two charts, in S&N Analytics' own
 * vocabulary (2026-10-11 redesign, from the owner-approved prototypes).
 *
 * - Machine reads per day: readers as the line, every machine read (this
 *   site's tools included) as the dashed overlay, through snt_an_trend_svg(),
 *   the same chart the Overview paints.
 * - Verified share: one bar per family or purpose, its length the claimed
 *   reads on a shared scale, its parts how each read is known: solid verified
 *   for the purpose it claimed, outlined verified for another purpose, hatched
 *   claimed only, dashed before verification was recorded. Decorative
 *   (aria-hidden): the counts are the row's text.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads per UTC day over the window, zero-filled: every machine read, and
 * readers (every read less this site's own tools). PURE.
 *
 * @param array $rows Aggregate rows (day, hits, first_party).
 * @param int   $days Window.
 * @param int   $now  Unix time (tests pin it).
 * @return array{days:array<int,string>,all:array<int,int>,readers:array<int,int>}
 */
function snt_ev_daily_reads( array $rows, $days, $now = 0 ) {
	$now   = $now > 0 ? (int) $now : time();
	$range = snt_mr_day_range( gmdate( 'Y-m-d', $now - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now ) );
	$all   = array_fill_keys( $range, 0 );
	$own   = array_fill_keys( $range, 0 );
	foreach ( $rows as $r ) {
		$day = is_array( $r ) ? (string) ( $r['day'] ?? '' ) : '';
		if ( ! isset( $all[ $day ] ) ) {
			continue;
		}
		$hits         = max( 0, (int) ( $r['hits'] ?? 0 ) );
		$all[ $day ] += $hits;
		$own[ $day ] += empty( $r['first_party'] ) ? 0 : $hits;
	}
	$readers = array();
	foreach ( $all as $day => $n ) {
		$readers[] = $n - $own[ $day ];
	}
	return array( 'days' => $range, 'all' => array_values( $all ), 'readers' => $readers );
}

/**
 * The reads-per-day chart in a panel. Fewer than two days paints nothing.
 *
 * @param array  $rows    Aggregate rows.
 * @param int    $days    Window.
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_daily( array $rows, $days, $surface = 'classic' ) {
	$s = snt_ev_daily_reads( $rows, $days );
	if ( count( $s['days'] ) < 2 || ! function_exists( 'snt_an_trend_svg' ) ) {
		return '';
	}
	$fmt = static fn( $d ) => gmdate( 'M j', (int) strtotime( $d . ' 00:00:00 UTC' ) );
	ob_start();
	snt_an_trend_svg(
		$s['readers'],
		array(
			'overlay_series' => $s['all'],
			'axis'           => array( $fmt( $s['days'][0] ), $fmt( end( $s['days'] ) ) ),
			'id_suffix'      => 'Mr',
			/* translators: 1: readers, 2: every machine read, over the window. */
			'aria_label'     => sprintf( __( 'Machine reads per day: %1$s readers, %2$s machine reads in all', 'signal-and-noise-tools' ), number_format_i18n( array_sum( $s['readers'] ) ), number_format_i18n( array_sum( $s['all'] ) ) ),
		)
	);
	$chart = (string) ob_get_clean();
	$key   = '<p class="sn-mr-key"><span class="sn-mr-key__line"></span>' . esc_html__( 'Readers', 'signal-and-noise-tools' ) . ' <span class="sn-mr-key__dash"></span>' . esc_html__( 'Every machine read, this site’s own tools included', 'signal-and-noise-tools' ) . '</p>';
	$title = __( 'Machine reads per day (UTC)', 'signal-and-noise-tools' );
	$table = snt_ev_sr_days_table( $title, $s['days'], array( __( 'Readers', 'signal-and-noise-tools' ) => $s['readers'], __( 'Every machine read', 'signal-and-noise-tools' ) => $s['all'] ) );
	return snt_ev_box( $surface, $title, $key . $chart . $table );
}

/**
 * One verified-share bar: the claimed reads' length on a shared scale, split
 * by how each read is known.
 *
 * @param array $s   A verification split.
 * @param int   $max The largest claimed count in the list.
 * @return string
 */
function snt_ev_share_bar( array $s, $max ) {
	$c     = max( 0, (int) ( $s['claimed'] ?? 0 ) );
	$m     = max( 0, (int) ( $s['verified_matched'] ?? 0 ) );
	$v     = max( $m, (int) ( $s['verified'] ?? 0 ) );
	$parts = array( 'matched' => $m, 'verified' => $v - $m, 'claimed' => max( 0, (int) ( $s['unverified'] ?? 0 ) ), 'before' => max( 0, (int) ( $s['unverifiable'] ?? 0 ) ) );
	$segs  = '';
	foreach ( $parts as $part => $n ) {
		if ( $n > 0 && $c > 0 ) {
			$segs .= '<span class="sn-mr-bar__seg" data-part="' . esc_attr( $part ) . '" style="--w:' . esc_attr( number_format( 100 * $n / $c, 2, '.', '' ) ) . '%"></span>';
		}
	}
	$w = $max > 0 ? max( 1, 100 * $c / $max ) : 0;
	return '<span class="sn-mr-bar" aria-hidden="true"><span class="sn-mr-bar__fill" style="--w:' . esc_attr( number_format( $w, 2, '.', '' ) ) . '%">' . $segs . '</span></span>';
}

/**
 * The key the bars and strips share, once per view, above the first of them.
 *
 * @return string
 */
function snt_ev_share_key() {
	$items = array(
		'matched'  => __( 'Verified for the purpose it claimed', 'signal-and-noise-tools' ),
		'verified' => __( 'Verified, a different purpose', 'signal-and-noise-tools' ),
		'claimed'  => __( 'Claimed by user agent only', 'signal-and-noise-tools' ),
		'before'   => __( 'Before verification was recorded', 'signal-and-noise-tools' ),
	);
	$out = '';
	foreach ( $items as $part => $label ) {
		$out .= '<span class="sn-mr-key__item"><span class="sn-mr-bar__seg" data-part="' . esc_attr( $part ) . '"></span>' . esc_html( $label ) . '</span>';
	}
	return '<p class="sn-mr-key">' . $out . '</p>';
}

/**
 * A verified-share chart: a lead sentence, then one row per item (the view
 * paints the key once, above the first chart).
 *
 * @param string $heading Panel heading.
 * @param string $lead    The finding, one sentence.
 * @param array  $items   [ ['label' => string, 'split' => array], ... ], largest first.
 * @param string $surface kit|classic.
 * @param int    $more    Items left out ("+N more").
 * @return string
 */
function snt_ev_paint_share_bars( $heading, $lead, array $items, $surface = 'classic', $more = 0 ) {
	if ( array() === $items ) {
		return snt_ev_box( $surface, $heading, snt_ev_lead( $surface, $lead ) );
	}
	$max  = max( array_map( static fn( $i ) => (int) ( $i['split']['claimed'] ?? 0 ), $items ) );
	$rows = '';
	foreach ( $items as $i ) {
		$s     = $i['split'];
		$rows .= '<div class="sn-mr-bars__row"><span class="sn-mr-bars__label">' . esc_html( (string) $i['label'] ) . '</span>' . snt_ev_share_bar( $s, $max )
			/* translators: 1: verified reads, 2: claimed reads. */
			. '<span class="sn-mr-bars__n">' . wp_kses( sprintf( __( '<strong>%1$s</strong> of %2$s verified', 'signal-and-noise-tools' ), number_format_i18n( (int) $s['verified'] ), number_format_i18n( (int) $s['claimed'] ) ), array( 'strong' => array() ) ) . snt_ev_share_parts( $s ) . '</span></div>';
	}
	/* translators: %s: items not shown. */
	$tail = $more > 0 ? snt_ev_lead( $surface, sprintf( __( '+%s more, the quiet end: the list is capped, not complete.', 'signal-and-noise-tools' ), number_format_i18n( (int) $more ) ) ) : '';
	return snt_ev_box( $surface, $heading, snt_ev_lead( $surface, $lead ) . '<div class="sn-mr-bars">' . $rows . '</div>' . $tail );
}

/**
 * The parts a bar draws besides verified, as counts under the row's figure:
 * the bar is decorative, so no part lives only in it. '' when all verified.
 *
 * @param array $s A verification split.
 * @return string
 */
function snt_ev_share_parts( array $s ) {
	$parts = array(
		/* translators: %s: reads. */
		__( '%s for another purpose', 'signal-and-noise-tools' ) => (int) ( $s['verified'] ?? 0 ) - (int) ( $s['verified_matched'] ?? 0 ),
		/* translators: %s: reads. */
		__( '%s named only', 'signal-and-noise-tools' )          => (int) ( $s['unverified'] ?? 0 ),
		/* translators: %s: reads. */
		__( '%s before verification', 'signal-and-noise-tools' ) => (int) ( $s['unverifiable'] ?? 0 ),
	);
	$out = array();
	foreach ( $parts as $fmt => $n ) {
		if ( $n > 0 ) {
			$out[] = sprintf( $fmt, number_format_i18n( $n ) );
		}
	}
	return array() === $out ? '' : '<span class="sn-mr-bars__parts">' . esc_html( implode( ' · ', $out ) ) . '</span>';
}
