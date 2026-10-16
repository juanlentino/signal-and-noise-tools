<?php
/**
 * Signal & Noise Tools: index coverage HISTORY, one reading per weekly run.
 *
 * The weekly URL Inspection run (inc/search-console-coverage.php) overwrites
 * one map, so "has Google started crawling less often?" could not be asked
 * backwards. Each completed run now also appends one summary reading here:
 * the counts and the median days since Google's last crawl, never per-URL
 * dumps. Oldest first, capped at two years of weekly runs, not autoloaded.
 *
 * Only a COMPLETE weekly run appends. A single-URL inspection (the day-3 and
 * day-10 passes) is not a run, and an interrupted walk is a partial map.
 *
 * @package SignalNoiseTools
 */

defined( 'ABSPATH' ) || exit;

const SNT_GSC_COVERAGE_HISTORY_OPTION = 'snt_gsc_coverage_history';
const SNT_GSC_COVERAGE_HISTORY_CAP    = 104; // two years of weekly runs.
const SNT_GSC_COVERAGE_HISTORY_SHOWN  = 8;   // rows a reader sees.

/**
 * One reading from a completed run's payload. PURE.
 *
 * @param array $payload The stored coverage map (snt_gsc_coverage_data() shape).
 * @return array<string,int|float|null>
 */
function snt_gsc_coverage_history_reading( array $payload ) {
	$at   = (int) ( $payload['synced_at'] ?? 0 );
	$s    = snt_gsc_coverage_summary( $payload, null );
	$crawled_not = 0;
	$ages        = array();
	foreach ( (array) ( $payload['entries'] ?? array() ) as $e ) {
		if ( ! is_array( $e ) || isset( $e['error'] ) ) {
			continue;
		}
		if ( 0 === stripos( (string) ( $e['coverage_state'] ?? '' ), 'crawled - currently not indexed' ) ) {
			$crawled_not++;
		}
		$t = strtotime( (string) ( $e['last_crawl_time'] ?? '' ) );
		if ( false !== $t && $t > 0 && $at >= $t ) {
			$ages[] = ( $at - $t ) / DAY_IN_SECONDS;
		}
	}
	$median = null;
	if ( array() !== $ages ) {
		sort( $ages );
		$mid    = intdiv( count( $ages ), 2 );
		$median = round( 0 === count( $ages ) % 2 ? ( $ages[ $mid - 1 ] + $ages[ $mid ] ) / 2 : $ages[ $mid ], 1 );
	}
	return array(
		'synced_at'             => $at,
		'inspected'             => (int) $s['inspected'],
		'indexed'               => (int) $s['indexed'],
		'not_indexed'           => (int) $s['not_indexed'],
		'crawled_not_indexed'   => $crawled_not,
		'unknown'               => (int) $s['unknown'],
		'errors'                => (int) $s['errors'],
		'median_crawl_age_days' => $median,
		'crawl_times'           => count( $ages ),
	);
}

/** The site-local day a reading belongs to. */
function snt_gsc_coverage_history_day( $ts ) {
	return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d', (int) $ts ) : gmdate( 'Y-m-d', (int) $ts );
}

/**
 * Append one reading: oldest first, capped. PURE. A second run on the same
 * day (Sync now after the cron) replaces that day's reading, so the rows stay
 * one per run day rather than a "moved 0" pair.
 *
 * @param array $history Existing readings, oldest first.
 * @param array $reading From snt_gsc_coverage_history_reading().
 * @param int   $cap
 * @return array
 */
function snt_gsc_coverage_history_push( array $history, array $reading, $cap = SNT_GSC_COVERAGE_HISTORY_CAP ) {
	$history = array_values( $history );
	$last    = end( $history );
	if ( is_array( $last ) && snt_gsc_coverage_history_day( (int) ( $last['synced_at'] ?? 0 ) ) === snt_gsc_coverage_history_day( (int) $reading['synced_at'] ) ) {
		array_pop( $history );
	}
	$history[] = $reading;
	return array_slice( $history, -max( 1, (int) $cap ) );
}

/** The stored readings, oldest first ([] before the first completed run). */
function snt_gsc_coverage_history() {
	$h = function_exists( 'get_option' ) ? get_option( SNT_GSC_COVERAGE_HISTORY_OPTION, array() ) : array();
	return is_array( $h ) ? array_values( array_filter( $h, 'is_array' ) ) : array();
}

/**
 * Record a completed run. Called once at the end of snt_gsc_coverage_sync().
 *
 * @param array $payload The completed map.
 * @return void
 */
function snt_gsc_coverage_history_record( $payload ) {
	if ( ! is_array( $payload ) || empty( $payload['complete'] ) ) {
		return;
	}
	$next = snt_gsc_coverage_history_push( snt_gsc_coverage_history(), snt_gsc_coverage_history_reading( $payload ) );
	update_option( SNT_GSC_COVERAGE_HISTORY_OPTION, $next, false );
}

/** "+2", "-1", "no change". */
function snt_gsc_coverage_history_signed( $d, $dec = 0 ) {
	if ( 0.0 === (float) $d ) {
		return __( 'no change', 'signal-and-noise-tools' );
	}
	return ( $d > 0 ? '+' : '-' ) . number_format_i18n( abs( $d ), $dec );
}

/**
 * What a reader sees, for the classic panel and the native painter alike.
 * PURE. `note` is the honest line under two readings, else what moved since
 * the previous run; `rows` are the last few readings, newest first.
 *
 * @param array $history Oldest first.
 * @return array{note:string,rows:array<int,array<string,string>>}
 */
function snt_gsc_coverage_history_view( array $history ) {
	$history = array_values( $history );
	$n       = count( $history );
	if ( $n < 2 ) {
		return array( 'note' => __( 'History starts with the next weekly run.', 'signal-and-noise-tools' ), 'rows' => array() );
	}
	$last = $history[ $n - 1 ];
	$prev = $history[ $n - 2 ];
	$age  = ( null === $last['median_crawl_age_days'] || null === $prev['median_crawl_age_days'] )
		? __( 'not reported', 'signal-and-noise-tools' )
		: snt_gsc_coverage_history_signed( (float) $last['median_crawl_age_days'] - (float) $prev['median_crawl_age_days'], 1 );
	$note = sprintf(
		/* translators: 1: date of the previous run, 2-5: signed changes. */
		__( 'Since the run of %1$s: indexed %2$s, not indexed %3$s, crawled but not indexed %4$s, median days since last crawl %5$s.', 'signal-and-noise-tools' ),
		snt_gsc_coverage_history_day( (int) $prev['synced_at'] ),
		snt_gsc_coverage_history_signed( (int) $last['indexed'] - (int) $prev['indexed'] ),
		snt_gsc_coverage_history_signed( (int) $last['not_indexed'] - (int) $prev['not_indexed'] ),
		snt_gsc_coverage_history_signed( (int) $last['crawled_not_indexed'] - (int) $prev['crawled_not_indexed'] ),
		$age
	);
	$rows = array();
	foreach ( array_reverse( array_slice( $history, -SNT_GSC_COVERAGE_HISTORY_SHOWN ) ) as $r ) {
		$rows[] = array(
			'run'         => snt_gsc_coverage_history_day( (int) $r['synced_at'] ),
			'inspected'   => number_format_i18n( (int) $r['inspected'] ),
			'indexed'     => number_format_i18n( (int) $r['indexed'] ),
			'not_indexed' => number_format_i18n( (int) $r['not_indexed'] ),
			'crawled_not' => number_format_i18n( (int) $r['crawled_not_indexed'] ),
			'crawl_age'   => null === $r['median_crawl_age_days'] ? __( 'not reported', 'signal-and-noise-tools' ) : number_format_i18n( (float) $r['median_crawl_age_days'], 1 ),
		);
	}
	return array( 'note' => $note, 'rows' => $rows );
}

/** Column key => label, shared by both twins. */
function snt_gsc_coverage_history_columns() {
	return array(
		'run'         => __( 'Run', 'signal-and-noise-tools' ),
		'inspected'   => __( 'Inspected', 'signal-and-noise-tools' ),
		'indexed'     => __( 'Indexed', 'signal-and-noise-tools' ),
		'not_indexed' => __( 'Not indexed', 'signal-and-noise-tools' ),
		'crawled_not' => __( 'Crawled, not indexed', 'signal-and-noise-tools' ),
		'crawl_age'   => __( 'Median days since last crawl', 'signal-and-noise-tools' ),
	);
}

/**
 * The classic twin: echoed inside the Index coverage panel.
 *
 * @return void
 */
function snt_gsc_coverage_history_render_classic() {
	$v = snt_gsc_coverage_history_view( snt_gsc_coverage_history() );
	echo '<h3>' . esc_html__( 'Coverage history', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p>' . esc_html( $v['note'] ) . '</p>';
	if ( array() === $v['rows'] ) {
		return;
	}
	echo '<div class="snt-scroll-table"><table class="widefat striped"><thead><tr>';
	foreach ( snt_gsc_coverage_history_columns() as $label ) {
		echo '<th scope="col">' . esc_html( $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $v['rows'] as $row ) {
		echo '<tr>';
		foreach ( array_keys( snt_gsc_coverage_history_columns() ) as $k ) {
			echo '<td>' . esc_html( $row[ $k ] ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}
