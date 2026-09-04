<?php
/**
 * Tests: the edge instrument can see a 5xx (issue #1002).
 *
 * `sn_edge_attack_query()`'s `probes` selection filters
 * `edgeResponseStatus_geq:400 … _leq:499`. That is right for what it measures —
 * the scan surface someone else is driving — and it means our own edge
 * reporting was STRUCTURALLY BLIND to a server error. When fourteen assets
 * failed with HTTP 503 on 2026-09-04 nothing recorded it, and eight attempts to
 * reproduce it from outside the authenticated session all returned 200.
 *
 * Run: php tests/edge-analytics-sees-5xx.php
 * @since 13.96.3
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$ana  = (string) file_get_contents( $root . '/inc/edge-analytics.php' );
$roll = (string) file_get_contents( $root . '/inc/edge-rollup.php' );

/** Source with comments stripped — prose about a rule is not the rule. */
function e5_code( $php ) {
	$out = '';
	foreach ( token_get_all( $php ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { $out .= "\n"; continue; }
		$out .= is_array( $t ) ? $t[1] : $t;
	}
	return $out;
}
$ana_code  = e5_code( $ana );
$roll_code = e5_code( $roll );

echo "edge-analytics-sees-5xx — plugin v13.96.3\n\nGroup 1: the blind spot is closed\n";

ok( false !== strpos( $ana_code, 'function sn_edge_errors_query' ), 'a 5xx query exists' );
ok( false !== strpos( $ana_code, 'edgeResponseStatus_geq:500' ), 'it filters on 5xx' );
ok( false !== strpos( $roll_code, 'sn_edge_errors_query()' ), 'the rollup CALLS it — a query nothing runs records nothing' );

echo "\nGroup 2: it names the responder, which is the point\n";
ok( false !== strpos( $ana_code, 'originResponseStatus' ),
	'originResponseStatus is requested — edge 503 with origin 503 is the origin failing; edge 503 with no origin status is Cloudflare or a Worker answering alone' );
ok( false !== strpos( $ana_code, 'cacheStatus' ), 'cacheStatus is requested' );
ok( false !== strpos( $roll_code, "'err_source'" ), 'the rollup stores a responder dimension, not just a count' );
ok( false !== strpos( $roll_code, "'err_path'" ), 'and which paths failed' );
ok( false !== strpos( $roll_code, "'-'" ), 'an absent origin status renders as `-`, not 0 — a reader must not mistake it for a status code' );

echo "\nGroup 3: blast radius\n";
// GraphQL fails the WHOLE document on one unknown field. The attack query does
// not ask for originResponseStatus; adding it there would put doors+probes at
// risk of a schema change that has nothing to do with them.
ok( false === strpos( $ana_code, 'doors:httpRequestsAdaptiveGroups' ) || false === strpos( substr( $ana_code, strpos( $ana_code, 'function sn_edge_attack_query' ), 900 ), 'originResponseStatus' ),
	'the ATTACK query does not ask for the new fields — one unknown field would fail that whole document' );
ok( false !== strpos( $roll_code, '$errz = sn_edge_query( sn_edge_errors_query()' ),
	'the 5xx query is executed separately, so its failure leaves the rest of the rollup intact' );

echo "\nGroup 4: the 4xx probe is unchanged\n";
// probes measures attack pressure. A 5xx is our failure, not an attacker's;
// folding it in would corrupt exactly the reading that selection gives.
ok( false !== strpos( $ana_code, 'edgeResponseStatus_geq:400,edgeResponseStatus_leq:499' ),
	'probes still filters 4xx only — a server error is not scan pressure and must not inflate it' );
ok( false !== strpos( $roll_code, "'atk_path'" ), 'the attack-path dimension is untouched' );

// CONTROL: the stripper kept code and dropped prose.
ok( false !== strpos( $ana_code, 'httpRequestsAdaptiveGroups' ) && false === strpos( $ana_code, 'WHY THIS IS ITS OWN DOCUMENT' ),
	'CONTROL: comments stripped, code kept' );

echo "\nGroup 5: the mapping itself, driven directly\n";
// Pure and separate from the rollup on purpose: the rollup can only be
// exercised through a stubbed database, and the first version of this test read
// the wrong side of that stub — every assertion returned zero rows, which looked
// like a broken feature and was a broken test.
// The sampling corrector the real rollup provides. Without it the extracted
// function takes its function_exists fallback and reports RAW counts — which
// looks like a correction bug and is a missing stub.
if ( ! function_exists( 'sn_edge_corrected' ) ) {
	function sn_edge_corrected( $row ) {
		$si = max( 1.0, (float) ( $row['avg']['sampleInterval'] ?? 1 ) );
		return (int) round( (int) ( $row['count'] ?? 0 ) * $si );
	}
}

// Extract ONE function by balanced braces rather than require the file, which
// needs a WordPress boot. Same idiom as tests/editor-api-smoke-population.php.
$rollup_src = (string) file_get_contents( $root . '/inc/edge-rollup.php' );
$toks = token_get_all( $rollup_src );
$fn = '';
for ( $i = 0, $n = count( $toks ); $i < $n; $i++ ) {
	if ( ! is_array( $toks[ $i ] ) || T_FUNCTION !== $toks[ $i ][0] ) { continue; }
	$j = $i + 1;
	while ( $j < $n && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) { $j++; }
	if ( ! is_array( $toks[ $j ] ) || 'sn_edge_errors_dims' !== $toks[ $j ][1] ) { continue; }
	$depth = 0; $started = false; $buf = '';
	for ( $k = $i; $k < $n; $k++ ) {
		$piece = is_array( $toks[ $k ] ) ? $toks[ $k ][1] : $toks[ $k ];
		$buf  .= $piece;
		if ( '{' === $piece ) { $depth++; $started = true; }
		elseif ( '}' === $piece ) { $depth--; if ( $started && 0 === $depth ) { break; } }
	}
	$fn = $buf; break;
}
ok( '' !== $fn, 'sn_edge_errors_dims() was extracted — if this is empty every assertion below is vacuous' );
eval( $fn ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test harness.
$rows = array(
	array( 'count' => 3, 'avg' => array( 'sampleInterval' => 1 ), 'dimensions' => array(
		'clientRequestPath' => '/a.js', 'edgeResponseStatus' => 503, 'originResponseStatus' => 503, 'cacheStatus' => 'dynamic' ) ),
	array( 'count' => 2, 'avg' => array( 'sampleInterval' => 1 ), 'dimensions' => array(
		'clientRequestPath' => '/b.css', 'edgeResponseStatus' => 503, 'originResponseStatus' => 0, 'cacheStatus' => 'miss' ) ),
	array( 'count' => 9, 'avg' => array( 'sampleInterval' => 1 ), 'dimensions' => array(
		'clientRequestPath' => '', 'edgeResponseStatus' => 500 ) ),
);
$dims = sn_edge_errors_dims( $rows );

ok( 3 === ( $dims['err_path']['/a.js'] ?? 0 ), 'the failing path is counted' );
ok( isset( $dims['err_source']['edge=503 origin=503 cache=dynamic'] ),
	'an origin-produced 503 is attributed to the ORIGIN' );
ok( isset( $dims['err_source']['edge=503 origin=- cache=miss'] ),
	'a 503 with no origin status renders origin=- — Cloudflare or a Worker answered alone, and `-` cannot be misread as a status code' );
ok( ! isset( $dims['err_source']['edge=503 origin=0 cache=miss'] ),
	'and it is NOT rendered as origin=0, which would sit in a column of status codes looking like one' );
ok( ! isset( $dims['err_path'][''] ), 'a row with no path is skipped rather than counted under an empty key' );
ok( 2 === count( $dims ), 'exactly two dimensions are produced' );

// Sampling correction, the same one every other row gets.
$sampled = array( array( 'count' => 4, 'avg' => array( 'sampleInterval' => 10 ), 'dimensions' => array(
	'clientRequestPath' => '/s.js', 'edgeResponseStatus' => 502, 'originResponseStatus' => 502 ) ) );
ok( 40 === ( sn_edge_errors_dims( $sampled )['err_path']['/s.js'] ?? 0 ),
	'a sampled row is corrected (4 x sampleInterval 10 = 40), not reported raw' );

ok( array() === sn_edge_errors_dims( array() ), 'no errors yields no dimensions — a quiet day writes nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
