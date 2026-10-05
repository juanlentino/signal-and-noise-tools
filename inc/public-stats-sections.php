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
const SN_PUBLIC_STATS_READ_CAP  = 500; // the accessor limit the sources and countries reads pass.
const SN_PUBLIC_STATS_MACHINES_OPT  = 'sn_public_stats_machines';
const SN_PUBLIC_STATS_MACHINES_LAST  = 'sn_public_stats_machines_last';
const SN_PUBLIC_STATS_MACHINES_HOOK = 'sn_public_stats_machines_refresh';

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
 * @param int        $all   Every view in the window; the rows' sum when smaller.
 * @return array<int,array{label:string,views:int,share:int}>|null Null when failed or empty.
 */
function sn_public_stats_fold( $rows, $label, $all = 0 ) {
	if ( ! is_array( $rows ) ) {
		return null;
	}
	$sum = array_sum( array_map( static fn( $r ) => (int) ( $r['views'] ?? 0 ), $rows ) );
	if ( $sum < 1 ) {
		return null;
	}
	// The accessor keeps its top SN_PUBLIC_STATS_READ_CAP rows. Only a read
	// that filled the cap has a dropped tail, which is Other, with shares of
	// every view. A shorter read short of the total is missing coverage (a
	// failed dimension day), never Other: its shares stay of the rows read.
	$total = count( $rows ) >= SN_PUBLIC_STATS_READ_CAP ? max( $sum, (int) $all ) : $sum;
	$out   = array();
	$other = $total - $sum;
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
 * Machine reads over the report window. PURE. The total is the sensor's
 * uncapped per-day totals cut to [from, to]; the identity split is from the
 * aggregate rows of the same days, and only when that read was not capped.
 * Null when the totals read failed, was capped, or does not reach the
 * window's first day: the section is left out, never zeros.
 *
 * @param array|null $totals snt_mr_fetch( 31, 'totals' ).
 * @param array|null $agg    snt_mr_fetch( 31 ).
 * @param string     $from   Window start, Y-m-d.
 * @param string     $to     Window end, Y-m-d.
 * @return array{total:int,split:array<string,int>|null}|null
 */
function sn_public_stats_machines( $totals, $agg, $from, $to ) {
	if ( ! is_array( $totals ) || empty( $totals['ok'] ) || ! empty( $totals['truncated'] ) ) {
		return null;
	}
	$in   = static fn( $r ) => is_array( $r ) && (string) ( $r['day'] ?? '' ) >= $from && (string) ( $r['day'] ?? '' ) <= $to;
	$days = array_values( array_filter( (array) ( $totals['rows'] ?? array() ), $in ) );
	if ( ! in_array( $from, array_column( $days, 'day' ), true ) ) {
		return null;
	}
	$split = null;
	if ( is_array( $agg ) && ! empty( $agg['ok'] ) && empty( $agg['truncated'] ) && function_exists( 'snt_desktop_machine_readers_identity' ) ) {
		$split = snt_desktop_machine_readers_identity( array_values( array_filter( (array) ( $agg['rows'] ?? array() ), $in ) ) );
	}
	return array( 'total' => (int) array_sum( array_column( $days, 'hits' ) ), 'split' => $split );
}

/**
 * The stored machine snapshot when it is of this window. PURE. The public
 * page never calls the sensor: an hourly event stores the snapshot, and a
 * snapshot of another window leaves the section out.
 *
 * @param mixed  $stored get_option( SN_PUBLIC_STATS_MACHINES_OPT ).
 * @param string $from   Window start.
 * @param string $to     Window end.
 * @return array|null
 */
function sn_public_stats_machines_stored( $stored, $from, $to ) {
	return is_array( $stored ) && $from === ( $stored['from'] ?? '' ) && $to === ( $stored['to'] ?? '' ) && is_array( $stored['machines'] ?? null ) ? $stored['machines'] : null;
}

/**
 * Why a sensor read gave no machine figures. PURE. '' when it gave them.
 *
 * @param array|null $totals snt_mr_fetch( 31, 'totals' ).
 * @param string     $from   Window start.
 * @return string
 */
function sn_public_stats_machines_why( $totals, $from ) {
	if ( ! is_array( $totals ) || empty( $totals['ok'] ) ) {
		return 'totals read failed: ' . ( is_array( $totals ) ? (string) ( $totals['error'] ?? 'unknown' ) : 'no read' );
	}
	if ( ! empty( $totals['truncated'] ) ) {
		return 'totals read capped';
	}
	$days = array_column( (array) ( $totals['rows'] ?? array() ), 'day' );
	return in_array( $from, $days, true ) ? '' : 'totals read does not reach ' . $from . ' (' . count( $days ) . ' rows, first ' . (string) ( $days[0] ?? 'none' ) . ')';
}

/** Hourly: read the sensor off the render path and store this window's snapshot, and why when it could not. */
function sn_public_stats_machines_refresh() {
	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		return;
	}
	list( $from, $to ) = sn_public_stats_window();
	$totals = snt_mr_fetch( 31, 'totals' );
	$m      = sn_public_stats_machines( $totals, snt_mr_fetch( 31 ), $from, $to );
	$why    = null === $m ? sn_public_stats_machines_why( $totals, $from ) : '';
	update_option( SN_PUBLIC_STATS_MACHINES_LAST, array( 'at' => time(), 'why' => $why ), false );
	if ( null !== $m ) {
		update_option( SN_PUBLIC_STATS_MACHINES_OPT, array( 'from' => $from, 'to' => $to, 'machines' => $m ), false );
		delete_transient( SN_PUBLIC_STATS_CACHE_KEY ); // the next render reads it, not one an hour later.
	}
}

/**
 * Watch: ripe while the last hourly refresh stored nothing, or none has run
 * in three hours. The note says why. PURE given $state.
 *
 * @param array            $watch The watch row.
 * @param int              $now   Unix time.
 * @param array|false|null $state Test seam; null reads the option.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_public_stats_machines( $watch, $now, $state = null ) {
	$state = null === $state ? get_option( SN_PUBLIC_STATS_MACHINES_LAST, false ) : $state;
	if ( ! is_array( $state ) ) {
		return array( 'ripe' => false, 'note' => '' ); // no baseline yet: the schedule records one on its first call.
	}
	if ( (int) $now - (int) ( $state['at'] ?? 0 ) > 3 * HOUR_IN_SECONDS ) {
		return array( 'ripe' => true, 'note' => 'no refresh since ' . gmdate( 'Y-m-d H:i', (int) ( $state['at'] ?? 0 ) ) . ' UTC' );
	}
	$why = (string) ( $state['why'] ?? '' );
	return array( 'ripe' => '' !== $why, 'note' => $why );
}

/**
 * Keep the hourly snapshot scheduled. The first call records a baseline
 * (add_option never overwrites), so a refresh that never runs at all, a
 * dead WP-Cron included, ripens the watch three hours later.
 */
function sn_public_stats_machines_schedule() {
	if ( function_exists( 'add_option' ) ) {
		add_option( SN_PUBLIC_STATS_MACHINES_LAST, array( 'at' => time(), 'why' => '' ), '', false );
	}
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_PUBLIC_STATS_MACHINES_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', SN_PUBLIC_STATS_MACHINES_HOOK );
	}
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'sn_public_stats_machines_schedule' );
	add_action( SN_PUBLIC_STATS_MACHINES_HOOK, 'sn_public_stats_machines_refresh', 10, 0 );
}

/**
 * The session rows and how many days of the window they cover. PURE. The
 * nightly rollup records the day before, so the newest day of the window can
 * lag; a run of days from the window's first day is shown with its count
 * ("29 of 30 days"). A hole inside the run, or a missing first day, is a
 * failure, not a lag: null, and Visits and One page only are left out.
 *
 * @param array|null $rows sn_session_rollup_read() rows.
 * @param string     $from Window start.
 * @param string     $to   Window end.
 * @return array{rows:array,days:int}|null
 */
function sn_public_stats_session_coverage( $rows, $from, $to ) {
	if ( ! is_array( $rows ) || array() === $rows ) {
		return null;
	}
	$have = array_flip( array_column( $rows, 'day' ) );
	$days = 0;
	for ( $t = strtotime( $from . ' 00:00:00 UTC' ), $end = strtotime( $to . ' 00:00:00 UTC' ); $t <= $end && isset( $have[ gmdate( 'Y-m-d', $t ) ] ); $t += 86400 ) {
		++$days;
	}
	$inside = count( array_filter( array_keys( $have ), static fn( $d ) => (string) $d >= $from && (string) $d <= $to ) );
	return ( $days > 0 && $days === $inside ) ? array( 'rows' => $rows, 'days' => $days ) : null;
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
	$cols .= sn_public_stats_machines_html( $data );
	return '' === $cols ? '' : '<div class="sn-public-stats__cols">' . $cols . '</div>';
}

/**
 * Humans and machines, the third column. '' when no snapshot of this window.
 *
 * @param array $data The assembled payload.
 * @return string
 */
function sn_public_stats_machines_html( $data ) {
	$m = $data['machines'] ?? null;
	if ( ! is_array( $m ) ) {
		return '';
	}
	$out   = '';
	$views = (int) ( $data['views'] ?? 0 );
	$max   = max( 1, $views, (int) $m['total'] );
	$out  .= '<section class="sn-public-stats__col sn-public-stats__machines"><h2>' . esc_html__( 'Humans and machines', 'signal-and-noise-tools' ) . '</h2>'
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
