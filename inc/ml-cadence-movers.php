<?php
/**
 * Signal & Noise: a quiet week explained, not only flagged.
 *
 * When the views rhythm flag fires (inc/ml-cadence.php), the same rollups say
 * which surfaces moved: the pages (the path rollup the watch already read)
 * and the sources (the referrer breakdown in the dims table), each compared
 * against its OWN median over the same trailing weeks the watch used. Top
 * movers rank by absolute shortfall (usual minus this week), so the flag
 * arrives carrying its own arithmetic. Deterministic: no model, no new
 * collection, and nothing is read until the flag fires.
 *
 * The floor: a surface whose usual weekly visits sit under SNT_MOVERS_FLOOR
 * is never named: a noise threshold, not privacy (the owner's analytics
 * reads stopped flooring on 2026-10-23; movers kept the 3). It folds into one "(withheld)"
 * bucket with summed figures. The gate is the USUAL visits, not this week's,
 * because a surface that collapsed to zero is exactly the one that explains a
 * quiet week, and a this-week gate would withhold every one of them.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ML_RHYTHM_MOVERS_TOP = 3; // Movers named per dimension.
const SNT_MOVERS_FLOOR = 3;          // Usual weekly visits under this are noise, folded into (withheld).

// ponytail: one fixed share. Under a quarter of the site's shortfall, the top
// page is one of many rather than the cause; tune here if it reads wrong.
const SNT_ML_RHYTHM_DOMINANT_SHARE = 0.25;

/**
 * Rank one dimension's surfaces by shortfall against their own baseline.
 *
 * @param array<string,array<int,array{views:int,visits:int}>> $weekly Key => week start => totals.
 * @param int[] $history_starts The watch's post-birth history week starts.
 * @param int   $current_start  The flagged week's start.
 * @param int   $site_shortfall The flag's own expected minus current views.
 * @return array{top:array,withheld:array{items:int,current:int,usual:int,shortfall:int},falling:int,spread:bool}
 */
function snt_ml_views_movers_rank( array $weekly, array $history_starts, $current_start, $site_shortfall ) {
	$floor    = SNT_MOVERS_FLOOR;
	$named    = array();
	$withheld = array( 'items' => 0, 'current' => 0, 'usual' => 0, 'shortfall' => 0 );
	foreach ( $weekly as $key => $weeks ) {
		$views  = array();
		$visits = array();
		foreach ( $history_starts as $s ) {
			$views[]  = (int) ( $weeks[ $s ]['views'] ?? 0 ); // After birth, absence is a real zero.
			$visits[] = (int) ( $weeks[ $s ]['visits'] ?? 0 );
		}
		$usual   = (int) round( snt_ml_views_median( $views ) );
		$current = (int) ( $weeks[ $current_start ]['views'] ?? 0 );
		if ( $usual <= $current ) {
			continue; // Held or grew: not part of the shortfall.
		}
		if ( snt_ml_views_median( $visits ) < $floor ) {
			$withheld['items']++;
			$withheld['current'] += $current;
			$withheld['usual']   += $usual;
			$withheld['shortfall'] = $withheld['usual'] - $withheld['current'];
			continue;
		}
		$named[] = array( 'key' => (string) $key, 'current' => $current, 'usual' => $usual, 'shortfall' => $usual - $current );
	}
	usort( $named, static function ( $a, $b ) {
		return $b['shortfall'] === $a['shortfall'] ? strcmp( $a['key'], $b['key'] ) : $b['shortfall'] <=> $a['shortfall'];
	} );
	$lead = $named ? $named[0]['shortfall'] : 0;
	return array(
		'top'      => array_slice( $named, 0, SNT_ML_RHYTHM_MOVERS_TOP ),
		'withheld' => $withheld,
		'falling'  => count( $named ) + $withheld['items'],
		'spread'   => $site_shortfall > 0 && $lead < SNT_ML_RHYTHM_DOMINANT_SHARE * $site_shortfall,
	);
}

/**
 * The movers for a fired views flag: pages from the rows the watch already
 * read, sources from one referrer read per week of the same baseline.
 *
 * @param array $rows           The watch's sn_analytics_daily_range() rows.
 * @param int[] $history_starts Post-birth history week starts.
 * @param int   $current_start  The flagged week's start.
 * @param int   $site_shortfall Expected minus current views.
 * @return array{paths:array,sources:array|null} Sources null = not read.
 */
function snt_ml_views_movers( array $rows, array $history_starts, $current_start, $site_shortfall ) {
	$week = 7 * DAY_IN_SECONDS;
	$keep = array_flip( array_merge( $history_starts, array( $current_start ) ) );

	$paths = array();
	foreach ( $rows as $row ) {
		$ts = is_array( $row ) ? strtotime( (string) ( $row['day'] ?? '' ) . ' UTC' ) : false;
		if ( false === $ts ) {
			continue;
		}
		$start = $current_start + (int) floor( ( $ts - $current_start ) / $week ) * $week;
		if ( ! isset( $keep[ $start ] ) ) {
			continue;
		}
		$path = (string) ( $row['path'] ?? '' );
		$paths[ $path ][ $start ]['views']  = ( $paths[ $path ][ $start ]['views'] ?? 0 ) + max( 0, (int) ( $row['views'] ?? 0 ) );
		$paths[ $path ][ $start ]['visits'] = ( $paths[ $path ][ $start ]['visits'] ?? 0 ) + max( 0, (int) ( $row['visits'] ?? 0 ) );
	}

	$sources = null;
	if ( function_exists( 'sn_analytics_top_sources' ) ) {
		$sources = array();
		foreach ( array_keys( $keep ) as $start ) {
			$read = sn_analytics_top_sources( gmdate( 'Y-m-d', $start ), gmdate( 'Y-m-d', $start + $week - DAY_IN_SECONDS ), 'human', 500 );
			if ( ! is_array( $read ) ) {
				$sources = null; // A failed week would read as a collapse: say unread instead.
				break;
			}
			foreach ( $read as $r ) {
				$sources[ (string) ( $r['value'] ?? '' ) ][ $start ] = array( 'views' => (int) ( $r['views'] ?? 0 ), 'visits' => (int) ( $r['visits'] ?? 0 ) );
			}
		}
	}

	return array(
		'paths'   => snt_ml_views_movers_rank( $paths, $history_starts, $current_start, $site_shortfall ),
		'sources' => null === $sources ? null : snt_ml_views_movers_rank( $sources, $history_starts, $current_start, $site_shortfall ),
	);
}

/**
 * The movers as one sentence block for the health note.
 *
 * @param array|null $movers From snt_ml_views_movers().
 * @return string Empty when there is nothing to explain.
 */
function snt_ml_views_movers_sentence( $movers ) {
	if ( ! is_array( $movers ) ) {
		return '';
	}
	$parts = array();
	$dims  = array(
		'paths'   => array( __( 'Pages that moved most', 'signal-and-noise-tools' ), __( 'page', 'signal-and-noise-tools' ), __( 'pages', 'signal-and-noise-tools' ) ),
		'sources' => array( __( 'Sources that moved most', 'signal-and-noise-tools' ), __( 'source', 'signal-and-noise-tools' ), __( 'sources', 'signal-and-noise-tools' ) ),
	);
	foreach ( $dims as $dim => $words ) {
		$m = $movers[ $dim ] ?? null;
		if ( ! is_array( $m ) ) {
			/* translators: %s: "sources" or "pages". */
			$parts[] = sprintf( __( 'The %s could not be read.', 'signal-and-noise-tools' ), $words[2] );
			continue;
		}
		$items = array();
		foreach ( (array) $m['top'] as $t ) {
			/* translators: 1: page or source, 2: this week's views, 3: usual weekly views. */
			$items[] = sprintf( __( '%1$s (this week %2$s vs usual %3$s)', 'signal-and-noise-tools' ), $t['key'], number_format_i18n( $t['current'] ), number_format_i18n( $t['usual'] ) );
		}
		$w = $m['withheld'];
		if ( $w['items'] > 0 ) {
			/* translators: 1: count, 2: "pages" or "sources", 3: this week's views, 4: usual weekly views. */
			$items[] = sprintf( __( '(withheld) %1$s %2$s under the floor (this week %3$s vs usual %4$s)', 'signal-and-noise-tools' ), number_format_i18n( $w['items'] ), 1 === $w['items'] ? $words[1] : $words[2], number_format_i18n( $w['current'] ), number_format_i18n( $w['usual'] ) );
		}
		if ( $items ) {
			$parts[] = $words[0] . ': ' . implode( '; ', $items ) . '.';
		}
		if ( ! empty( $m['spread'] ) && $m['falling'] > 0 ) {
			/* translators: 1: "page" or "source", 2: count, 3: "pages" or "sources". */
			$parts[] = sprintf( __( 'No single %1$s explains it: the shortfall is spread across %2$s %3$s.', 'signal-and-noise-tools' ), $words[1], number_format_i18n( $m['falling'] ), $words[2] );
		}
	}
	return implode( ' ', $parts );
}
