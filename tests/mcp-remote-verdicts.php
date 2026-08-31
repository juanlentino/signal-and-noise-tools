<?php
/**
 * Totality pin for the remote door's verdict map (Phase 4, Task 4.1).
 *
 * WHY THIS EXISTS, and why it is a TEST rather than a document. The remote
 * allowlist is hand-curated by name — deliberately, because the alternative
 * ("the read door minus some") is an exclusion list, and an exclusion list
 * FAILS OPEN: the next person to add a local section silently widens what a
 * phone-reachable credentialed path can read, and nothing goes red.
 *
 * Hand-curation has its own failure, though, and it is the quiet one: it does
 * not lag safely, it lags SILENTLY. Today a new local section simply never
 * gets a remote decision, and no surface anywhere says so. This file is the
 * fix — not inheritance, but TOTALITY. Every local section must carry an
 * explicit verdict, and a verdict of `true` must name a twin that is really on
 * the remote allowlist.
 *
 * The same shape as v13.49.0's cron parity pin: a list that is derivable from
 * source should be checked against source, never remembered.
 *
 * THIS FILE EXPOSES NOTHING. It forces decisions to exist; it cannot grant
 * reach. Widening the remote door still takes registering a twin AND adding it
 * to sn_mcp_remote_slugs() — two steps, and that extra step is the boundary.
 *
 * @since plugin v13.50.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

// Minimal WP surface the three map modules touch at load.
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v = null ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';

/**
 * The three section maps, loaded from the ability modules that own them. Each
 * is read LIVE rather than copied — a copied list is exactly the drift this
 * file exists to prevent.
 */
$sn_map_files = array(
	'snt_sn_status_map'     => __DIR__ . '/../inc/abilities-sn-status.php',
	'snt_sn_metrics_map'    => __DIR__ . '/../inc/abilities-sn-metrics.php',
	'snt_sn_site_facts_map' => __DIR__ . '/../inc/abilities-sn-site-facts.php',
);

/**
 * Extract a map function's top-level keys by parsing its source. The modules
 * carry heavy WordPress dependencies at load, so the keys are read statically
 * rather than by executing them under a stub swamp — the same technique the
 * cron parity pin uses, and it needs the same vacuity guard.
 *
 * @param string $fn
 * @param string $file
 * @return string[]
 */
function sn_verdict_map_keys( $fn, $file ) {
	$src = (string) @file_get_contents( $file );
	if ( '' === $src || ! preg_match( '/function\s+' . preg_quote( $fn, '/' ) . '\s*\(.*?\n\}/s', $src, $m ) ) {
		return array();
	}
	if ( ! preg_match_all( "/^\t\t'([a-z0-9_]+)'\s*=>/m", $m[0], $k ) ) {
		return array();
	}
	return array_values( array_unique( $k[1] ) );
}

$sn_local = array();
foreach ( $sn_map_files as $fn => $file ) {
	$keys = sn_verdict_map_keys( $fn, $file );
	// VACUITY GUARD, per section map. A parser that stops matching would
	// otherwise report full verdict coverage of an empty set.
	ok( count( $keys ) > 0, "vacuity: $fn yielded section keys at all (a rotted parser must fail here, never pass)" );
	$sn_local = array_merge( $sn_local, $keys );
}
$sn_local = array_values( array_unique( $sn_local ) );
ok( count( $sn_local ) >= 25, 'vacuity: the combined local section set is the expected ORDER of magnitude (>=25), so a partial parse cannot pass' );

echo "\nGroup: every local section has an explicit remote verdict\n";

$verdicts = sn_mcp_remote_verdicts();
ok( is_array( $verdicts ) && ! empty( $verdicts ), 'sn_mcp_remote_verdicts() returns a non-empty map' );

$missing = array_values( array_diff( $sn_local, array_keys( $verdicts ) ) );
ok( array() === $missing, 'no local section lacks a remote verdict — missing: ' . implode( ', ', $missing ) );

// The reverse direction: a verdict for a section that no longer exists is
// stale, and a stale verdict is how a decision outlives the thing it decided.
$orphans = array_values( array_diff( array_keys( $verdicts ), $sn_local ) );
ok( array() === $orphans, 'no verdict names a section that no longer exists — orphans: ' . implode( ', ', $orphans ) );

echo "\nGroup: a verdict of true must name a twin that actually exists\n";

$sn_slugs   = sn_mcp_remote_slugs();
$sn_checked = 0;
foreach ( $verdicts as $section => $v ) {
	$sn_checked++;
	if ( empty( $v['remote'] ) ) {
		ok( '' !== (string) ( $v['reason'] ?? '' ), "section $section says why it is NOT remote" );
		continue;
	}
	ok(
		in_array( (string) ( $v['twin'] ?? '' ), $sn_slugs, true ),
		"section $section names a twin that is on the remote allowlist"
	);
}
ok( $sn_checked > 0, 'vacuity: the verdict loop actually ran over entries' );

echo "\nGroup: no verdict may name a corpus-reaching ability\n";
// The deny half, ENUMERATED rather than assumed: these span
// SNT_CORPUS_STATUSES (draft/pending/private) and can never be remote —
// putting unpublished draft bodies on a phone-reachable credentialed path is
// the exact asset the threat model declined over.
foreach ( array(
	'signal-noise/sn-posts',
	'signal-noise/sn-scan',
	'signal-noise/topic-clusters',
	'signal-noise/keyword-candidates',
	'signal-noise/link-candidates',
) as $forbidden ) {
	ok( ! in_array( $forbidden, $sn_slugs, true ), "$forbidden is not reachable remotely" );
}

echo "\nGroup: the map cannot grant reach on its own\n";
// A verdict is a RECORD of a decision, never its enforcement. Reach still
// requires the twin to be in sn_mcp_remote_slugs(), which this file only reads.
$sn_true = array_keys( array_filter( $verdicts, function ( $v ) { return ! empty( $v['remote'] ); } ) );
ok( count( $sn_true ) > 0, 'at least one section IS remote today, so the true-branch is exercised' );
foreach ( $sn_true as $section ) {
	ok(
		in_array( (string) $verdicts[ $section ]['twin'], $sn_slugs, true ),
		"remote section $section is backed by an allowlisted twin, not by the verdict alone"
	);
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
