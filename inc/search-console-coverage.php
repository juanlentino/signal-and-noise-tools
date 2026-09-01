<?php
/**
 * Signal & Noise Tools — Search Console URL Inspection: index coverage per post.
 *
 * The disagreement scan (v13.57.0) found 26 of 37 notes with zero Google
 * impressions in a month, and could not say which of two different problems
 * that was: NOT INDEXED (a crawl or quality question) or INDEXED WITH NO QUERY
 * DEMAND (a topic question). Search Analytics cannot tell them apart — a page
 * Google never shows and a page Google never indexed both read as no rows.
 * The URL Inspection API can, per URL, with the service account already on
 * file: verdict, coverage state, indexing state, last crawl, canonical
 * agreement. Quota is 2,000 inspections a day per property; this needs one
 * per published post, weekly.
 *
 * Stored, never live: the weekly cron inspects and writes one option; every
 * reader (the sn-status section, the disagreement scan's evidence, the Search
 * view) reads the stored map. A reader can never make the origin spend quota.
 *
 * Keyed by the weave join key (sn_path_join_key), so it joins the GSC page
 * rows and the scan's post paths without a third spelling.
 *
 * @package SignalNoiseTools
 * @since 13.63.0
 */

defined( 'ABSPATH' ) || exit;

const SNT_GSC_COVERAGE_HOOK     = 'sn_gsc_coverage_weekly';
const SNT_GSC_COVERAGE_OPTION   = 'snt_gsc_coverage';
const SNT_GSC_INSPECT_URL       = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
const SNT_GSC_COVERAGE_MAX_URLS = 200; // per run; the API allows 2,000/day and the corpus is ~40.
const SNT_GSC_COVERAGE_STATUS   = 'snt_gsc_coverage_status';
const SNT_GSC_COVERAGE_TIMEOUT  = 15;                 // seconds per inspection; Google usually answers in 3-15s.
const SNT_GSC_COVERAGE_FRESH    = 6 * DAY_IN_SECONDS; // an entry younger than this is not re-inspected (resume).

/** Verdicts Google can return; anything else is stored verbatim but counted as 'other'. */
const SNT_GSC_COVERAGE_VERDICTS = array( 'PASS', 'NEUTRAL', 'FAIL', 'PARTIAL', 'VERDICT_UNSPECIFIED' );

/**
 * Normalize one inspection response. PURE.
 *
 * `indexed` is derived from Google's own coverageState wording ("Submitted and
 * indexed", "Indexed, not submitted in sitemap") — null when the state is
 * missing, never a guessed false. A missing result is `{error}`.
 *
 * @param array|WP_Error $resp The API response (or transport error).
 * @param int            $now
 * @return array
 */
function snt_gsc_coverage_normalize( $resp, $now ) {
	if ( is_wp_error( $resp ) ) {
		return array( 'error' => (string) $resp->get_error_code(), 'message' => (string) $resp->get_error_message(), 'inspected_at' => (int) $now );
	}
	$r = is_array( $resp ) && is_array( $resp['inspectionResult']['indexStatusResult'] ?? null ) ? $resp['inspectionResult']['indexStatusResult'] : null;
	if ( null === $r ) {
		return array( 'error' => 'no_index_status', 'message' => 'The response carried no indexStatusResult.', 'inspected_at' => (int) $now );
	}
	$coverage = (string) ( $r['coverageState'] ?? '' );
	$gc       = (string) ( $r['googleCanonical'] ?? '' );
	$uc       = (string) ( $r['userCanonical'] ?? '' );
	$indexed  = '' === $coverage ? null : ( 0 === stripos( $coverage, 'submitted and indexed' ) || 0 === stripos( $coverage, 'indexed' ) );
	return array(
		'verdict'          => (string) ( $r['verdict'] ?? '' ),
		'coverage_state'   => $coverage,
		'indexing_state'   => (string) ( $r['indexingState'] ?? '' ),
		'robots_txt_state' => (string) ( $r['robotsTxtState'] ?? '' ),
		'page_fetch_state' => (string) ( $r['pageFetchState'] ?? '' ),
		'crawled_as'       => (string) ( $r['crawledAs'] ?? '' ),
		'last_crawl_time'  => (string) ( $r['lastCrawlTime'] ?? '' ),
		'google_canonical' => $gc,
		'user_canonical'   => $uc,
		'canonical_match'  => ( '' === $gc || '' === $uc ) ? null : ( $gc === $uc ),
		'indexed'          => $indexed,
		'inspected_at'     => (int) $now,
	);
}

/**
 * One inspection. Network seam: snt_gsc_api_post() with the absolute URL.
 *
 * @param string $url      The page URL (the permalink, exactly as served).
 * @param string $property The Search Console property (siteUrl).
 * @return array|WP_Error
 */
function snt_gsc_inspect_url( $url, $property ) {
	if ( ! function_exists( 'snt_gsc_api_post' ) ) {
		return new WP_Error( 'snt_gsc_no_client', 'Search Console client not loaded.' );
	}
	return snt_gsc_api_post( SNT_GSC_INSPECT_URL, array(
		'inspectionUrl' => (string) $url,
		'siteUrl'       => (string) $property,
		'languageCode'  => 'en-US',
	), SNT_GSC_COVERAGE_TIMEOUT );
}

/**
 * The published posts to inspect: id => permalink. Bounded by the per-run cap.
 *
 * @return array<int,string>
 */
function snt_gsc_coverage_targets() {
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$ids = (array) get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => SNT_GSC_COVERAGE_MAX_URLS, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$url = (string) get_permalink( (int) $id );
		if ( '' !== $url ) {
			$out[ (int) $id ] = $url;
		}
	}
	return $out;
}

/**
 * Inspect every target and store the map — INCREMENTALLY.
 *
 * v13.64.0: the first version wrote one option at the end, so a run that was
 * slow (Google answers each inspection in 3-15s; 37 posts is minutes) or
 * interrupted (a killed WP-CLI, a PHP time limit) left NOTHING. Now:
 *  - the option is written after EVERY inspection, with complete:false until
 *    the walk finishes, so a partial run is a partial map, not an absent one;
 *  - a status record (started/finished/elapsed/inspected/errors) is written at
 *    start and end, so "is it running / did it finish" is answerable;
 *  - RESUME: entries younger than SNT_GSC_COVERAGE_FRESH are kept and skipped
 *    unless $force, so a re-run after an interruption only spends quota on
 *    what is missing.
 *
 * @param bool $force Re-inspect everything, ignoring fresh entries.
 * @return array|WP_Error The stored payload.
 */
function snt_gsc_coverage_sync( $force = false ) {
	if ( ! function_exists( 'snt_gsc_sync_is_ready' ) || ! snt_gsc_sync_is_ready() ) {
		return new WP_Error( 'snt_gsc_not_ready', 'Search Console is not configured.' );
	}
	$property = (string) sn_setting( 'search_console.property', '' );
	$targets  = snt_gsc_coverage_targets();
	$started  = time();
	$prev     = snt_gsc_coverage_data();
	$entries  = array();
	$errors   = 0;
	$skipped  = 0;

	// Resume: carry fresh entries forward instead of re-spending quota on them.
	if ( ! $force && is_array( $prev ) ) {
		foreach ( (array) $prev['entries'] as $k => $e ) {
			if ( is_array( $e ) && ! isset( $e['error'] ) && ( $started - (int) ( $e['inspected_at'] ?? 0 ) ) < SNT_GSC_COVERAGE_FRESH ) {
				$entries[ (string) $k ] = $e;
			}
		}
	}

	$write = static function ( $complete ) use ( &$entries, &$errors, &$skipped, $property, $started, $targets ) {
		$payload = array(
			'property'  => $property,
			'synced_at' => time(),
			'started_at' => $started,
			'complete'  => (bool) $complete,
			'inspected' => count( $entries ),
			'errors'    => $errors,
			'skipped'   => $skipped,
			'capped'    => count( $targets ) >= SNT_GSC_COVERAGE_MAX_URLS,
			'entries'   => $entries,
		);
		update_option( SNT_GSC_COVERAGE_OPTION, $payload, false );
		return $payload;
	};
	update_option( SNT_GSC_COVERAGE_STATUS, array( 'started_at' => $started, 'finished_at' => 0, 'ok' => null, 'targets' => count( $targets ), 'resumed' => count( $entries ) ), false );

	$payload = $write( false );
	foreach ( $targets as $id => $url ) {
		$key = function_exists( 'sn_path_join_key' ) ? sn_path_join_key( $url ) : $url;
		if ( '' === $key ) {
			continue;
		}
		if ( isset( $entries[ $key ] ) ) {
			$skipped++;
			continue; // fresh from the previous run.
		}
		$entry = snt_gsc_coverage_normalize( snt_gsc_inspect_url( $url, $property ), time() );
		$entry['post_id'] = (int) $id;
		$entry['url']     = $url;
		if ( isset( $entry['error'] ) ) {
			$errors++;
		}
		$entries[ $key ] = $entry;
		$payload = $write( false ); // after EVERY inspection: an interrupted run keeps what it got.
	}
	$payload  = $write( true );
	$finished = time();
	update_option( SNT_GSC_COVERAGE_STATUS, array( 'started_at' => $started, 'finished_at' => $finished, 'elapsed' => $finished - $started, 'ok' => true, 'targets' => count( $targets ), 'inspected' => count( $entries ), 'errors' => $errors, 'skipped' => $skipped ), false );
	return $payload;
}

/** The last run's status record, or null. */
function snt_gsc_coverage_last_status() {
	$s = function_exists( 'get_option' ) ? get_option( SNT_GSC_COVERAGE_STATUS, null ) : null;
	return is_array( $s ) && isset( $s['started_at'] ) ? $s : null;
}

/** The stored map, or null when never synced. */
function snt_gsc_coverage_data() {
	$d = function_exists( 'get_option' ) ? get_option( SNT_GSC_COVERAGE_OPTION, null ) : null;
	return is_array( $d ) && isset( $d['synced_at'], $d['entries'] ) ? $d : null;
}

/** One path's entry, or null (never inspected, or never synced). */
function snt_gsc_coverage_for_path( $path ) {
	$d = snt_gsc_coverage_data();
	if ( null === $d ) {
		return null;
	}
	$key = function_exists( 'sn_path_join_key' ) ? sn_path_join_key( (string) $path ) : (string) $path;
	return isset( $d['entries'][ $key ] ) && is_array( $d['entries'][ $key ] ) ? $d['entries'][ $key ] : null;
}

/**
 * Counts and the lists a reader acts on. PURE.
 *
 * @param array|null $d       snt_gsc_coverage_data().
 * @param array|null $inbound v13.65.0: snt_ml_inbound_by_path(); null = not computed (rows carry inbound_links:null).
 * @return array
 */
function snt_gsc_coverage_summary( $d, $inbound = null ) {
	if ( ! is_array( $d ) ) {
		return array( 'synced' => false, 'complete' => false, 'inspected' => 0, 'indexed' => 0, 'not_indexed' => 0, 'unknown' => 0, 'errors' => 0, 'by_coverage_state' => array(), 'not_indexed_paths' => array(), 'canonical_mismatch' => array() );
	}
	$idx = 0; $not = 0; $unk = 0; $err = 0; $states = array(); $not_paths = array(); $mismatch = array();
	foreach ( (array) $d['entries'] as $path => $e ) {
		if ( ! is_array( $e ) ) {
			continue;
		}
		if ( isset( $e['error'] ) ) {
			$err++;
			continue;
		}
		$cs = (string) ( $e['coverage_state'] ?? '' );
		$states[ '' === $cs ? '(none)' : $cs ] = (int) ( $states[ '' === $cs ? '(none)' : $cs ] ?? 0 ) + 1;
		if ( true === ( $e['indexed'] ?? null ) ) {
			$idx++;
		} elseif ( false === ( $e['indexed'] ?? null ) ) {
			$not++;
			$not_paths[] = array(
				'path'            => (string) $path,
				'coverage_state'  => $cs,
				'last_crawl_time' => (string) ( $e['last_crawl_time'] ?? '' ),
				'verdict'         => (string) ( $e['verdict'] ?? '' ),
				// v13.65.0: how many OTHER published notes link here. null = not computed.
				'inbound_links'   => is_array( $inbound ) ? (int) ( $inbound[ (string) $path ]['inbound'] ?? 0 ) : null,
			);
		} else {
			$unk++;
		}
		if ( false === ( $e['canonical_match'] ?? null ) ) {
			$mismatch[] = array( 'path' => (string) $path, 'google_canonical' => (string) $e['google_canonical'], 'user_canonical' => (string) $e['user_canonical'] );
		}
	}
	arsort( $states );
	return array(
		'synced'             => true,
		'inbound_available'  => is_array( $inbound ),
		'complete'           => ! array_key_exists( 'complete', $d ) || ! empty( $d['complete'] ), // v13.64.0: false = a run is in progress or was interrupted.
		'synced_at'          => (int) ( $d['synced_at'] ?? 0 ),
		'capped'             => ! empty( $d['capped'] ),
		'inspected'          => (int) ( $d['inspected'] ?? 0 ),
		'indexed'            => $idx,
		'not_indexed'        => $not,
		'unknown'            => $unk,
		'errors'             => $err,
		'by_coverage_state'  => $states,
		'not_indexed_paths'  => $not_paths,
		'canonical_mismatch' => $mismatch,
	);
}

/** Schedule equals readiness — the same contract as the daily sync. */
function snt_gsc_coverage_schedule() {
	$next = wp_next_scheduled( SNT_GSC_COVERAGE_HOOK );
	if ( function_exists( 'snt_gsc_sync_is_ready' ) && snt_gsc_sync_is_ready() ) {
		if ( ! $next ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'weekly', SNT_GSC_COVERAGE_HOOK );
		}
		return;
	}
	if ( $next ) {
		wp_unschedule_event( $next, SNT_GSC_COVERAGE_HOOK );
	}
}
add_action( 'init', 'snt_gsc_coverage_schedule' );
add_action( SNT_GSC_COVERAGE_HOOK, 'snt_gsc_coverage_sync' );
