<?php
/**
 * Signal & Noise Tools: the /stats sections below the rhythm (v4 payload).
 * Where readers come from, how they read, humans and machines. Every figure
 * is read from a store another surface already fills; nothing here writes.
 *
 * Privacy: aggregates only, and any source or country with fewer than
 * SN_PUBLIC_STATS_MIN_GROUP visits folds into "Other" so no small group can
 * be picked out. Never-measured is not zero: a read that failed leaves its
 * list or section out.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PUBLIC_STATS_MIN_GROUP = 3;
const SN_PUBLIC_STATS_LIST_N    = 5;

/**
 * Site-wide sessions over the window: the visits field of the session rollup,
 * summed the way the SN Reading card sums it. PURE. Null when the read failed
 * or the rollup holds no day (the tile is then left out).
 *
 * @param array|null $days sn_session_rollup_read() rows.
 * @return int|null
 */
function sn_public_stats_sessions_total( $days ) {
	if ( ! is_array( $days ) || array() === $days ) {
		return null;
	}
	return (int) array_sum( array_map( static fn( $d ) => (int) ( $d['visits'] ?? 0 ), $days ) );
}

/** A source's public label; null folds it into Other. PURE. */
function sn_public_stats_source_label( $value ) {
	$value = (string) $value;
	if ( '(direct)' === $value ) {
		return __( 'Direct', 'signal-and-noise-tools' );
	}
	return ( '' === $value || '(unknown)' === $value ) ? null : $value;
}

/** A country's public name from its ISO code; null folds it into Other. PURE. */
function sn_public_stats_country_label( $code ) {
	$code = strtoupper( (string) $code );
	if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || 'XX' === $code || 'T1' === $code ) {
		return null;
	}
	$name = class_exists( 'Locale' ) ? (string) Locale::getDisplayRegion( '-' . $code, 'en' ) : '';
	return ( '' === $name || $code === $name ) ? $code : $name;
}

/**
 * Fold a dims list into public rows. PURE. A row under the minimum group size
 * (visits, or views when visits is absent), an unlabeled row, and every row
 * past the list length fold into one "Other" line. Shares are of all views.
 *
 * @param array|null $rows  [{value, views, visits?}]; null when the read failed.
 * @param callable   $label Value => label, or null to fold.
 * @return array<int,array{label:string,views:int,share:int}>|null Null when failed or empty.
 */
function sn_public_stats_fold( $rows, $label ) {
	if ( ! is_array( $rows ) ) {
		return null;
	}
	$total = array_sum( array_map( static fn( $r ) => (int) ( $r['views'] ?? 0 ), $rows ) );
	if ( $total < 1 ) {
		return null;
	}
	$out   = array();
	$other = 0;
	foreach ( $rows as $r ) {
		$views = (int) ( $r['views'] ?? 0 );
		$size  = isset( $r['visits'] ) ? (int) $r['visits'] : $views;
		$name  = $label( $r['value'] ?? '' );
		if ( null === $name || $size < SN_PUBLIC_STATS_MIN_GROUP || count( $out ) >= SN_PUBLIC_STATS_LIST_N ) {
			$other += $views;
			continue;
		}
		$out[] = array( 'label' => (string) $name, 'views' => $views, 'share' => (int) round( 100 * $views / $total ) );
	}
	if ( $other > 0 ) {
		$out[] = array( 'label' => __( 'Other', 'signal-and-noise-tools' ), 'views' => $other, 'share' => (int) round( 100 * $other / $total ) );
	}
	return $out;
}

/**
 * How they read: the SN Reading card's own rows, kept by label. PURE given
 * its inputs. Null when no row could be read.
 *
 * @param array|null $totals   sn_analytics_range_totals().
 * @param array      $dist     sn_analytics_distribution( 'scroll' ); [] when failed.
 * @param array|null $sessions sn_session_rollup_read().
 * @return array<int,array{label:string,value:string}>|null
 */
function sn_public_stats_reading_rows( $totals, $dist, $sessions ) {
	$rows = array();
	if ( function_exists( 'snt_desktop_reading_page_rows' ) ) {
		$rows = snt_desktop_reading_page_rows( $totals, (array) $dist );
	}
	if ( function_exists( 'snt_desktop_reading_visit_rows' ) ) {
		$rows = array_merge( $rows, (array) snt_desktop_reading_visit_rows( $sessions ) );
	}
	// ponytail: kept by the desktop's label, the way the desktop filters its own rows.
	$keep = array( 'Views that reached half the page', 'Average depth reached', 'Average time per view', 'One page only' );
	$rows = array_values( array_filter( $rows, static fn( $r ) => in_array( $r['label'], $keep, true ) && '' !== (string) $r['value'] ) );
	return array() === $rows ? null : $rows;
}

/**
 * Humans and machines, from the desktop's machine-readers payload. PURE.
 * Null when the sensor read failed: the section is left out, never zeros.
 *
 * @param array|null $payload snt_desktop_machine_readers_payload().
 * @return array{total:int,split:array<string,int>|null}|null
 */
function sn_public_stats_machines( $payload ) {
	if ( ! is_array( $payload ) || empty( $payload['ok'] ) || ! isset( $payload['total'] ) ) {
		return null;
	}
	$split = isset( $payload['edge_verified'] ) && is_array( $payload['edge_verified'] ) ? array_map( 'intval', $payload['edge_verified'] ) : null;
	return array( 'total' => (int) $payload['total'], 'split' => $split );
}

/**
 * Label, decorative bar, number in text. Escaped.
 *
 * @param array<int,array{label:string,text:string,pct:int}> $rows Rows.
 * @return string
 */
function sn_public_stats_bars_html( array $rows ) {
	$out = '<ul class="sn-public-stats__bars">';
	foreach ( $rows as $r ) {
		$out .= '<li><span class="sn-public-stats__bar-label">' . esc_html( $r['label'] ) . '</span>'
			. '<span class="sn-public-stats__bar" aria-hidden="true"><span style="width:' . (int) max( 0, min( 100, $r['pct'] ) ) . '%"></span></span>'
			. '<span class="sn-public-stats__bar-value">' . esc_html( $r['text'] ) . '</span></li>';
	}
	return $out . '</ul>';
}

/** A folded list as bars, shares as text. */
function sn_public_stats_share_list( array $rows ) {
	// A share that rounds to 0 is still a real group: "<1%", never "0%".
	return sn_public_stats_bars_html( array_map( static fn( $r ) => array( 'label' => $r['label'], 'text' => ( $r['share'] < 1 && $r['views'] > 0 ? '<1' : $r['share'] ) . '%', 'pct' => $r['share'] ), $rows ) );
}

/**
 * Sections 3 and 4. '' for any part that was not read.
 *
 * @param array $data The assembled payload.
 * @return string
 */
function sn_public_stats_sections_html( $data ) {
	$where = '';
	foreach ( array( 'sources' => __( 'Sources', 'signal-and-noise-tools' ), 'countries' => __( 'Countries', 'signal-and-noise-tools' ) ) as $key => $title ) {
		if ( ! empty( $data[ $key ] ) ) {
			$where .= '<h3>' . esc_html( $title ) . '</h3>' . sn_public_stats_share_list( $data[ $key ] );
		}
	}
	$cols = '';
	if ( '' !== $where ) {
		$cols .= '<section class="sn-public-stats__col"><h2>' . esc_html__( 'Where readers come from', 'signal-and-noise-tools' ) . '</h2>' . $where
			. '<p class="sn-public-stats__note">' . esc_html__( 'Sources and countries with fewer than 3 visits are grouped as Other.', 'signal-and-noise-tools' ) . '</p></section>';
	}
	if ( ! empty( $data['reading'] ) ) {
		$cols .= '<section class="sn-public-stats__col"><h2>' . esc_html__( 'How they read', 'signal-and-noise-tools' ) . '</h2><dl class="sn-public-stats__facts">';
		foreach ( $data['reading'] as $r ) {
			$cols .= '<dt>' . esc_html( $r['label'] ) . '</dt><dd>' . esc_html( $r['value'] ) . '</dd>';
		}
		$cols .= '</dl></section>';
	}
	$out = '' === $cols ? '' : '<div class="sn-public-stats__cols">' . $cols . '</div>';

	$m = $data['machines'] ?? null;
	if ( ! is_array( $m ) ) {
		return $out;
	}
	$views = (int) ( $data['views'] ?? 0 );
	$max   = max( 1, $views, (int) $m['total'] );
	$out  .= '<section class="sn-public-stats__machines"><h2>' . esc_html__( 'Humans and machines', 'signal-and-noise-tools' ) . '</h2>'
		. sn_public_stats_bars_html( array(
			array( 'label' => __( 'Human views', 'signal-and-noise-tools' ), 'text' => number_format_i18n( $views ), 'pct' => (int) round( 100 * $views / $max ) ),
			array( 'label' => __( 'Machine reads', 'signal-and-noise-tools' ), 'text' => number_format_i18n( (int) $m['total'] ), 'pct' => (int) round( 100 * (int) $m['total'] / $max ) ),
		) );
	$split = is_array( $m['split'] ) ? array_filter( $m['split'] ) : array();
	$all   = array_sum( $split );
	if ( $all > 0 ) {
		$names = array(
			'verified'     => __( 'Verified by Cloudflare', 'signal-and-noise-tools' ),
			'unverified'   => __( 'Named themselves, not verified', 'signal-and-noise-tools' ),
			'not_measured' => __( 'Not measured', 'signal-and-noise-tools' ),
		);
		$rows = array();
		foreach ( $names as $k => $label ) {
			if ( isset( $split[ $k ] ) ) {
				$pct    = (int) round( 100 * $split[ $k ] / $all );
				$rows[] = array( 'label' => $label, 'text' => $pct . '%', 'pct' => $pct );
			}
		}
		$out .= '<h3>' . esc_html__( 'Who the machines are', 'signal-and-noise-tools' ) . '</h3>' . sn_public_stats_bars_html( $rows );
	}
	return $out . '<p class="sn-public-stats__note">' . esc_html__( 'Automated counts bot pageviews that reached the tracker. Machine reads count every request the edge sensor saw. They are different measures.', 'signal-and-noise-tools' )
		. ' <a href="https://github.com/juanlentino/signal-and-noise-provenance">' . esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . '</a></p></section>';
}
