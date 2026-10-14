<?php
/**
 * Signal & Noise app, the Attention queue's readers: the machine-readers, search and live readers.
 *
 * A pure move from parts/attention-readers.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A machine-reader snapshot that has gone stale.
 *
 * THREE states, not two: snt_mr_snapshot_is_stale() answers true, false, or
 * NULL when there has never been a measurement — and absent is not stale. Only
 * an explicit `true` yields a row; null yields nothing here, because "nobody
 * has measured the readers" is a fact about the snapshot job, which the health
 * scan's own machine_reader_liveness check already carries.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_readers() {
	if ( ! function_exists( 'snt_mr_snapshot' ) || ! function_exists( 'snt_mr_snapshot_is_stale' ) ) {
		return attention_read();
	}
	try {
		$snap  = \snt_mr_snapshot();
		$stale = \snt_mr_snapshot_is_stale( $snap );
		$stamp = is_array( $snap ) ? attention_stamp( $snap['captured_at'] ?? '' ) : '';
		if ( true !== $stale ) {
			return attention_read( array(), $stamp );
		}
		$hours = defined( 'SN_MR_SNAPSHOT_STALE_AFTER' ) ? (int) ( \SN_MR_SNAPSHOT_STALE_AFTER / 3600 ) : 6;
		return attention_read(
			array(
				attention_row( array(
					'kind'       => 'readers',
					'key'        => 'snapshot',
					'title'      => __( 'Machine-reader snapshot', 'signal-and-noise-tools' ),
					'subtitle'   => '' !== $stamp
						/* translators: 1: a timestamp in the site timezone with its zone. 2: the staleness window in hours. */
						? sprintf( __( 'Captured %1$s, older than the %2$d-hour window', 'signal-and-noise-tools' ), attention_local( $stamp ), $hours )
						/* translators: %d: the staleness window in hours. */
						: sprintf( __( 'Older than the %d-hour window', 'signal-and-noise-tools' ), $hours ),
					'tone'       => 'warning',
					'stamp'      => $stamp,
					'source'     => __( 'The machine-reader snapshot', 'signal-and-noise-tools' ),
					'door'       => attention_door( 'sn-monitoring', 'machine-readers' ),
					'door_label' => __( 'Open Machine readers in S&N Dashboard', 'signal-and-noise-tools' ),
				) ),
			),
			$stamp
		);
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Notes Google is not indexing, and notes whose body moved after its last
 * crawl. (v14.7.0, the tenth reader)
 *
 * SIX NOTES SAT "DISCOVERED, NOT INDEXED" FOR TWO MONTHS with no row anywhere:
 * the coverage map was on a tab, and nine readers read everything but it.
 * This reads the SAME rows the Posts tab paints (sn_analytics_posts_signals(),
 * flags derived once) and applies a horizon so a note published on Wednesday
 * is not a fault on Thursday:
 *
 *   not_indexed, "Crawled - currently not indexed"      on sight: a verdict, not a wait.
 *   not_indexed, "Discovered - currently not indexed"   after ATTENTION_SEARCH_DISCOVERED_DAYS.
 *   stale_crawl                                          after ATTENTION_SEARCH_STALE_DAYS since the body change.
 *
 * THE DOOR IS THE FIX. Google exposes no API to request indexing (the URL
 * Inspection API reads; the Indexing API covers job postings). The row's door
 * is Search Console's inspection page for that exact URL, where "Request
 * indexing" is one click. A note the reader has decided to leave alone is
 * acknowledged at its stamp and stays quiet until its state moves.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_search() {
	if ( ! function_exists( 'sn_analytics_posts_signals' ) ) {
		return attention_read();
	}
	try {
		$signals = \sn_analytics_posts_signals();
		$rows    = array();
		$newest  = '';
		$now     = attention_now();
		$run     = is_array( $signals['strip']['coverage_run'] ?? null ) ? (int) $signals['strip']['coverage_run']['finished_at'] : 0;
		$stamp   = $run > 0 ? attention_stamp( $run ) : '';
		if ( '' === $stamp ) {
			return attention_read(); // No inspection has ever run: nothing to say, and nothing to blame.
		}
		foreach ( (array) $signals['rows'] as $r ) {
			$flags = (array) ( $r['flags'] ?? array() );
			$pid   = (int) ( $r['id'] ?? 0 );
			$url   = (string) ( $r['permalink'] ?? '' );
			$door  = attention_search_door( $url );
			$age   = (int) ( $r['age']['value'] ?? 0 );
			if ( ! empty( $flags['not_indexed'] ) ) {
				$state     = (string) ( $r['coverage_state'] ?? '' );
				$crawled   = 0 === stripos( $state, 'Crawled' );
				if ( ! $crawled && $age < ATTENTION_SEARCH_DISCOVERED_DAYS ) {
					continue; // Google knows the URL and has not got to it yet; give it the horizon.
				}
				$rows[] = attention_row( array(
					'kind'       => 'search',
					'key'        => $pid . '-index',
					'title'      => (string) $r['title'],
					'subtitle'   => $crawled
						? __( 'Crawled and not indexed: a quality verdict. Deepen the note before requesting again.', 'signal-and-noise-tools' )
						: sprintf( /* translators: %d: days since publish. */ __( 'Discovered and not crawled after %d days. Request indexing.', 'signal-and-noise-tools' ), $age ),
					'tone'       => 'warning',
					'stamp'      => $stamp,
					'source'     => __( 'The weekly URL Inspection run (Search Console), read through the Posts view', 'signal-and-noise-tools' ),
					'door'       => $door,
					'door_label' => __( 'Inspect in Search Console', 'signal-and-noise-tools' ),
					'post_id'    => $pid,
				) );
			}
			if ( ! empty( $flags['stale_crawl'] ) ) {
				$changed = (int) ( $r['body_changed_ts'] ?? 0 );
				$crawl   = (int) ( $r['last_crawl']['value'] ?? 0 );
				if ( $changed <= 0 || ( $now - $changed ) < ATTENTION_SEARCH_STALE_DAYS * DAY_IN_SECONDS ) {
					continue; // Recently changed: Google's own revisit is still the likely path.
				}
				$rows[] = attention_row( array(
					'kind'       => 'search',
					'key'        => $pid . '-stale',
					'title'      => (string) $r['title'],
					'subtitle'   => sprintf( /* translators: 1: crawl date, 2: body change date. */ __( 'Google last crawled it on %1$s; the body changed on %2$s. The served text is old. Request indexing.', 'signal-and-noise-tools' ), gmdate( 'Y-m-d', $crawl ), gmdate( 'Y-m-d', $changed ) ),
					'tone'       => 'neutral',
					'stamp'      => attention_stamp( $changed ),
					'source'     => __( 'The weekly URL Inspection run against the signed commit chain', 'signal-and-noise-tools' ),
					'door'       => $door,
					'door_label' => __( 'Inspect in Search Console', 'signal-and-noise-tools' ),
					'post_id'    => $pid,
				) );
			}
		}
		foreach ( $rows as $row ) {
			if ( $row['stamp'] > $newest ) {
				$newest = $row['stamp'];
			}
		}
		return attention_read( $rows, '' !== $newest ? $newest : $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/** Days a "Discovered - currently not indexed" note gets before it is a row. */
const ATTENTION_SEARCH_DISCOVERED_DAYS = 7;
/** Days after a body change before an un-recrawled note is a row. */
const ATTENTION_SEARCH_STALE_DAYS = 14;

/**
 * Search Console's URL inspection page for one URL on the configured property.
 * Where the fix lives, since no API can request indexing.
 *
 * @param string $url The page URL.
 * @return string '' when the property is unknown.
 */
function attention_search_door( $url ) {
	$property = function_exists( 'sn_setting' ) ? (string) \sn_setting( 'search_console.property', '' ) : '';
	if ( '' === $property || '' === $url ) {
		return '';
	}
	return 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $property ) . '&id=' . rawurlencode( $url );
}

/**
 * The live-surge analytics signal (inc/analytics-live-surge.php): one row while the last
 * completed 5-minute slot is a surge against the same time of day. Neutral,
 * not a warning: more readers than usual is news, not a fault. Learning and
 * usual are no row (a standing row teaches its reader to stop looking).
 * Reads the realtime cache only.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_live() {
	if ( ! function_exists( 'sn_analytics_live_payload' ) ) {
		return attention_read();
	}
	try {
		$live  = \sn_analytics_live_payload( true );
		$surge = is_array( $live ) && is_array( $live['surge'] ?? null ) ? $live['surge'] : null;
		if ( null === $surge || 'surge' !== (string) ( $surge['state'] ?? '' ) ) {
			return attention_read();
		}
		$stamp = isset( $live['fetched'] ) && is_int( $live['fetched'] ) ? attention_stamp( $live['fetched'] ) : attention_stamp( attention_now() );
		$readers = (int) ( $surge['readers'] ?? 0 );
		$ratio   = $surge['ratio'] ?? null;
		return attention_read( array( attention_row( array(
			'kind'       => 'live',
			'key'        => 'surge',
			'title'      => __( 'Unusual traffic right now', 'signal-and-noise-tools' ),
			'subtitle'   => is_numeric( $ratio )
				/* translators: 1: readers in the last 5-minute slot, 2: times the usual. */
				? sprintf( __( '%1$d readers in the last completed 5-minute slot, about %2$s times the usual for this time of day', 'signal-and-noise-tools' ), $readers, number_format_i18n( (float) $ratio, 1 ) )
				/* translators: %d: readers in the last 5-minute slot. */
				: sprintf( __( '%d readers in the last completed 5-minute slot, at a time of day that is usually empty', 'signal-and-noise-tools' ), $readers ),
			'tone'       => 'neutral',
			'stamp'      => $stamp,
			'source'     => __( 'The live-surge analytics signal, last completed 5-minute slot', 'signal-and-noise-tools' ),
			'door'       => attention_door( 'sn-analytics' ),
			'door_label' => __( 'Open S&N Analytics', 'signal-and-noise-tools' ),
		) ) ), $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}
