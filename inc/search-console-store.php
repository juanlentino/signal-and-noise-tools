<?php
/**
 * Signal & Noise Tools — Search Console data store (R6b step 2).
 *
 * NO NEW TABLE. The whole payload is a rolling window of the top N pages and
 * top N queries — kilobytes, refreshed daily. The analytics tables exist because
 * per-event rows grow without bound; this does not, so an option (autoload
 * FALSE) is the honest size of the thing.
 *
 * THE JOIN KEY IS THE PATH. Google returns absolute URLs; the analytics rollups
 * are path-keyed. Storing Google's URL verbatim would mean every read does
 * string surgery, and the two would silently fail to match on the trailing
 * slash. Normalisation happens ONCE, here, on write.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SNT_GSC_DATA_OPTION', 'snt_gsc_data' );
define( 'SNT_GSC_HISTORY_OPTION', 'snt_gsc_history' );
// Ten snapshots ≈ ten sync days: enough span for a 7-day drift read with
// slack for missed nights, small enough that the option stays kilobytes.
const SNT_GSC_HISTORY_MAX = 10;

/**
 * Reduce an absolute URL from Google to the site-relative path the analytics
 * rollups key on.
 *
 * Trailing slash is KEPT for '/' and stripped elsewhere, which is what
 * sn_analytics_top_paths() stores. A mismatch here does not error — it silently
 * joins nothing, and a table of empty search columns looks like "no search
 * traffic" rather than "the key is wrong". That failure mode is why this is one
 * function with one test rather than an inline expression at each call site.
 *
 * @param string $url Absolute URL, or already a path.
 * @return string Path beginning with '/'.
 */
function snt_gsc_url_to_path( $url ) {
	$url  = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$path = ( 0 === strpos( $url, 'http' ) ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : $url;
	if ( '' === $path || null === $path ) {
		$path = '/';
	}
	if ( '/' !== $path ) {
		$path = rtrim( $path, '/' );
	}
	return '/' === substr( $path, 0, 1 ) ? $path : '/' . $path;
}

/**
 * The page-dimension row limit the sync requests.
 *
 * Named because TWO places depend on the same number: the fetch that sets it,
 * and snt_gsc_window_totals(), which can only report that its sum is a floor by
 * comparing against it. A literal 250 in both is a silent contract.
 *
 * @since 11.30.0
 */
const SNT_GSC_PAGE_ROW_LIMIT = 250;

/**
 * The stored payload, or null when nothing has synced.
 *
 * @return array|null
 */
function snt_gsc_data() {
	$data = get_option( SNT_GSC_DATA_OPTION, null );
	return is_array( $data ) && isset( $data['synced_at'] ) ? $data : null;
}

/**
 * Fetch the current window and store it.
 *
 * @param bool $force Unused today; reserved so a caller can bypass a future
 *                    minimum-interval guard without changing the signature.
 * @return array|WP_Error The stored payload.
 */
function snt_gsc_sync( $force = false ) {
	unset( $force );
	$property = (string) sn_setting( 'search_console.property', '' );
	if ( '' === $property ) {
		return new WP_Error( 'snt_gsc_no_property', __( 'Select a Search Console property first.', 'signal-and-noise-tools' ) );
	}
	$window = snt_gsc_window();

	$pages = snt_gsc_query( $property, array( 'page' ), $window, SNT_GSC_PAGE_ROW_LIMIT );
	if ( is_wp_error( $pages ) ) {
		return $pages;
	}
	$queries = snt_gsc_query( $property, array( 'query' ), $window, 100 );
	if ( is_wp_error( $queries ) ) {
		return $queries;
	}

	$by_path = array();
	foreach ( $pages as $row ) {
		$path = snt_gsc_url_to_path( $row['key'] );
		if ( '' === $path ) {
			continue;
		}
		// Two Google URLs can normalise to one path (http/https, trailing slash,
		// a URL-prefix property overlapping a domain one). SUM the counts and
		// take the impression-weighted position — averaging two averages
		// unweighted would let a 3-impression page drag a 3000-impression one.
		if ( isset( $by_path[ $path ] ) ) {
			$prev = $by_path[ $path ];
			$imp  = $prev['impressions'] + $row['impressions'];
			$by_path[ $path ] = array(
				'clicks'      => $prev['clicks'] + $row['clicks'],
				'impressions' => $imp,
				'position'    => $imp > 0
					? ( ( $prev['position'] * $prev['impressions'] ) + ( $row['position'] * $row['impressions'] ) ) / $imp
					: $prev['position'],
			);
		} else {
			$by_path[ $path ] = array(
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'position'    => $row['position'],
			);
		}
	}
	// CTR is derived AFTER merging, never averaged: clicks/impressions of the
	// merged pair is the real rate; the mean of two rates is not.
	foreach ( $by_path as $path => $m ) {
		$by_path[ $path ]['ctr'] = $m['impressions'] > 0 ? $m['clicks'] / $m['impressions'] : 0.0;
	}

	$payload = array(
		'property'  => $property,
		'window'    => $window,
		'pages'     => $by_path,
		'queries'   => array_slice( $queries, 0, 100 ),
		'synced_at' => time(),
	);
	update_option( SNT_GSC_DATA_OPTION, $payload, false );
	snt_gsc_history_append( $payload );
	return $payload;
}

/**
 * Append one compact position snapshot to the bounded history (v13.11.0).
 *
 * The window OVERWRITES on every sync, which is right for the tables and
 * blind for drift: position movement needs at least two observations.
 * One entry per WINDOW END (a same-day re-sync replaces, never duplicates —
 * the daily cron and a manual Sync-now on the same day are one observation),
 * positions rounded to 0.1 (the display grain; finer is noise), capped at
 * SNT_GSC_HISTORY_MAX with the oldest dropped.
 *
 * @param array $payload The just-stored window payload.
 */
function snt_gsc_history_append( $payload ) {
	$end = isset( $payload['window']['end'] ) ? (string) $payload['window']['end'] : '';
	if ( '' === $end || empty( $payload['pages'] ) ) {
		return;
	}
	$compact = array();
	foreach ( (array) $payload['pages'] as $path => $m ) {
		$compact[ $path ] = array(
			'position'    => round( (float) ( $m['position'] ?? 0 ), 1 ),
			'impressions' => (int) ( $m['impressions'] ?? 0 ),
		);
	}
	$history         = snt_gsc_history();
	$history[ $end ] = array( 'end' => $end, 'pages' => $compact );
	ksort( $history ); // window-end dates are ISO strings: lexical == chronological.
	while ( count( $history ) > SNT_GSC_HISTORY_MAX ) {
		array_shift( $history );
	}
	update_option( SNT_GSC_HISTORY_OPTION, $history, false );
}

/**
 * The stored history, keyed by window end, chronological. [] when none —
 * an empty history is a real "not yet accrued", distinct from no window.
 *
 * @return array<string,array{end:string,pages:array<string,array{position:float,impressions:int}>}>
 */
function snt_gsc_history() {
	$h = get_option( SNT_GSC_HISTORY_OPTION, array() );
	return is_array( $h ) ? $h : array();
}

/**
 * Search metrics for one path, or null when that path has none.
 *
 * NULL, not a zero row: a page Google has never shown and a page shown 400
 * times with no clicks are different facts, and a zero would state the second
 * while meaning the first.
 *
 * @param string $path
 * @return array|null ['clicks','impressions','ctr','position']
 */
function snt_gsc_metrics_for_path( $path ) {
	$data = snt_gsc_data();
	if ( null === $data ) {
		return null;
	}
	$key = snt_gsc_url_to_path( $path );
	return isset( $data['pages'][ $key ] ) ? $data['pages'][ $key ] : null;
}

/**
 * Top search queries in the stored window.
 *
 * @param int $limit
 * @return array
 */
function snt_gsc_top_queries( $limit = 10 ) {
	$data = snt_gsc_data();
	if ( null === $data ) {
		return array();
	}
	return array_slice( (array) $data['queries'], 0, max( 1, (int) $limit ) );
}

/**
 * Site-wide clicks in the stored window, with the window's real length.
 *
 * NULL when nothing has ever synced — never a zero row. A property that has
 * never been fetched and one Google reports no clicks for are different facts,
 * and a 0 would state the second while meaning the first.
 *
 * The day count is returned WITH the total because the window is whatever the
 * last sync used (28 days by default, ending `lag_days` back), not a fixed
 * seven. A caller that labels this "7d" without asking would be reporting a
 * month of clicks as a week's.
 *
 * Sums the per-path rows: the payload stores pages, not a site total, and the
 * page dimension is capped at 250 rows — so on a large site this is the total
 * over the pages Google ranked highest, not every click. Fine for a dashboard
 * figure, wrong for a report; that is why this lives beside the data rather
 * than being re-derived by each caller.
 *
 * @since 11.28.0
 * @return array{clicks:int,days:int}|null
 */
function snt_gsc_window_totals() {
	$data = snt_gsc_data();
	if ( null === $data || ! isset( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
		return null;
	}

	$clicks      = 0;
	$impressions = 0;
	foreach ( $data['pages'] as $row ) {
		if ( is_array( $row ) ) {
			$clicks      += (int) ( $row['clicks'] ?? 0 );
			$impressions += (int) ( $row['impressions'] ?? 0 );
		}
	}

	$days  = 0;
	$start = isset( $data['window']['start'] ) ? (string) $data['window']['start'] : '';
	$end   = isset( $data['window']['end'] ) ? (string) $data['window']['end'] : '';
	if ( '' !== $start && '' !== $end ) {
		$s = strtotime( $start . ' 00:00:00 UTC' );
		$e = strtotime( $end . ' 00:00:00 UTC' );
		if ( false !== $s && false !== $e && $e >= $s ) {
			// Inclusive of both endpoints, matching how the window is built.
			$days = (int) floor( ( $e - $s ) / DAY_IN_SECONDS ) + 1;
		}
	}

	// v11.30.0: SAY WHEN THE SUM IS A FLOOR.
	//
	// snt_gsc_query() fetches the page dimension with rowLimit 250, so this sums
	// at most 250 pages. Past that the total undercounts, in a known direction,
	// while presenting as exact — and the docblock saying so was no help to a
	// caller, because the RETURN VALUE never mentioned it. A figure that is
	// wrong in a knowable direction should be labelled, not annotated.
	$capped = count( (array) $data['pages'] ) >= SNT_GSC_PAGE_ROW_LIMIT;

	return array( 'clicks' => $clicks, 'impressions' => $impressions, 'days' => $days, 'capped' => $capped );
}
