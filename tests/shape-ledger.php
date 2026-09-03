<?php
/**
 * The shape ledger.
 *
 * The property that matters is STRUCTURE vs CONTENT. `reader-anomalies` carries
 * an `excluded` map keyed by family name, so a family crossing the eligibility
 * floor removes a key. If that reads as a shape change the instrument cries wolf
 * weekly and nobody reads it by the second month — the same failure the apple-ai
 * exemption was built to stop.
 *
 * @since 13.84.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( "DAY_IN_SECONDS" ) ) { define( "DAY_IN_SECONDS", 86400 ); }
if ( ! defined( "HOUR" ) ) { define( "HOUR", 3600 ); }

$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }

require __DIR__ . '/../inc/shape-ledger.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $m\n"; } else { $fail++; echo "FAIL  $m\n"; } }

// ── STRUCTURE vs CONTENT ────────────────────────────────────────────────────
$a = array( 'excluded' => array( 'perplexity' => 14, 'feed' => 11 ), 'n' => 1 );
$b = array( 'excluded' => array( 'feed' => 11, 'google-ai' => 10, 'amazon-ai' => 9 ), 'n' => 2 );
ok( sn_shape_fingerprint( $a, array( 'excluded' ) ) === sn_shape_fingerprint( $b, array( 'excluded' ) ), 'a family crossing the floor is CONTENT, not shape — declared-open keys collapse' );

// Without the declaration it IS a change: the wildcard must be earned, not assumed.
ok( sn_shape_fingerprint( $a ) !== sn_shape_fingerprint( $b ), 'and an UNDECLARED map still compares key by key' );

// A value type changing under an open path is still a shape change.
$c = array( 'excluded' => array( 'feed' => '11' ), 'n' => 1 );
ok( sn_shape_fingerprint( $a, array( 'excluded' ) ) !== sn_shape_fingerprint( $c, array( 'excluded' ) ), 'a type change UNDER an open path is still a shape change' );

// ── Order independence ──────────────────────────────────────────────────────
ok( sn_shape_fingerprint( array( 'a' => 1, 'b' => 'x' ) ) === sn_shape_fingerprint( array( 'b' => 'x', 'a' => 1 ) ), 'map key order does not move the fingerprint' );
$r1 = array( 'rows' => array( array( 'k' => 'a', 'v' => 1 ), array( 'k' => 'b', 'v' => 2 ) ) );
$r2 = array( 'rows' => array( array( 'v' => 2, 'k' => 'b' ), array( 'v' => 1, 'k' => 'a' ) ) );
ok( sn_shape_fingerprint( $r1 ) === sn_shape_fingerprint( $r2 ), 'row order does not move it either' );

// A list folds to the UNION, so an optional field on ONE row is visible.
$u1 = array( 'rows' => array( array( 'k' => 'a' ) ) );
$u2 = array( 'rows' => array( array( 'k' => 'a' ), array( 'k' => 'b', 'extra' => 1 ) ) );
ok( sn_shape_fingerprint( $u1 ) !== sn_shape_fingerprint( $u2 ), 'an optional field appearing on one row IS a shape change' );

// ── Real changes are caught ─────────────────────────────────────────────────
$base = array( 'ok' => true, 'counts' => array( 'a' => 1 ) );
ok( sn_shape_fingerprint( $base ) !== sn_shape_fingerprint( array( 'ok' => true, 'counts' => array( 'a' => 1, 'b' => 2 ) ) ), 'an added key is a shape change' );
ok( sn_shape_fingerprint( $base ) !== sn_shape_fingerprint( array( 'ok' => true ) ), 'a removed subtree is a shape change' );
ok( sn_shape_fingerprint( array( 'v' => 1 ) ) !== sn_shape_fingerprint( array( 'v' => null ) ), 'int -> null is a shape change (the mad:0 vs null distinction)' );

// ── The ledger ──────────────────────────────────────────────────────────────
$T = 1800000000;
$fp = sn_shape_fingerprint( $base );
sn_shape_ledger_record( 's', $fp, $T );
$e = sn_shape_ledger_record( 's', $fp, $T + 3600 );
ok( 2 === $e['readings'] && $T === $e['since'], 'an unchanged shape accumulates readings and keeps its since' );

$e = sn_shape_ledger_record( 's', 'DIFFERENT', $T + 7200 );
ok( 1 === $e['readings'] && ( $T + 7200 ) === $e['since'], 'a change RESETS the clock — that is the whole point' );
ok( 1 === count( $e['changes'] ) && $e['changes'][0]['from'] === $fp, 'and the change is recorded with what it moved from' );

// ── The verdict needs BOTH gates ────────────────────────────────────────────
ok( 'unknown' === sn_shape_stability( 'never-seen', $T )['state'], 'never recorded is UNKNOWN, not unstable — an absent instrument does not vote' );

$GLOBALS['__opt'] = array();
sn_shape_ledger_record( 'x', 'F', $T );
for ( $i = 1; $i < 30; $i++ ) { sn_shape_ledger_record( 'x', 'F', $T + $i * 60 ); }
$v = sn_shape_stability( 'x', $T + 30 * 60 );
ok( 'settling' === $v['state'], '30 readings inside an HOUR is not settled — the span gate holds' );
ok( false !== strpos( $v['reason'], 'days' ), 'and the reason names the span' );

$GLOBALS['__opt'] = array();
sn_shape_ledger_record( 'y', 'F', $T );
sn_shape_ledger_record( 'y', 'F', $T + 8 * DAY_IN_SECONDS );
$v = sn_shape_stability( 'y', $T + 8 * DAY_IN_SECONDS );
ok( 'settling' === $v['state'], 'two readings eight days apart is not settled either — the count gate holds' );
ok( false !== strpos( $v['reason'], 'readings' ), 'and the reason names the count' );

$GLOBALS['__opt'] = array();
for ( $i = 0; $i < 30; $i++ ) { sn_shape_ledger_record( 'z', 'F', $T + $i * 8 * HOUR ); }
$v = sn_shape_stability( 'z', $T + 30 * 8 * HOUR );
ok( 'settled' === $v['state'], 'both gates satisfied -> settled' );
ok( false !== strpos( $v['reason'], 'unchanged across' ), 'and it states the evidence' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
