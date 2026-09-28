<?php
/**
 * Tests for inc/analytics-network-terms.php: the mirror of the analytics
 * worker's network lists (DC_ASN, RELAY_ASN, HOSTING_ASN) and the read-time
 * class predicate built from it.
 *
 * (a) The regex sources are pinned by sha256. DC_ASN's pin is the SAME value
 *     the worker's test/classifier-fingerprint.spec.js pins; RELAY_ASN and
 *     HOSTING_ASN were computed from the worker's origin/main 2c5a268 source.
 *     A worker list change means updating the mirror and these pins together.
 * (b) Every term list agrees with its regex on every ASN org seen in 90 days
 *     (tests/fixtures/analytics-asn-orgs.json), and the GENERATED SQL predicate,
 *     evaluated in PHP, classifies every (org, browser) pair as the worker does.
 * Run: php tests/analytics-network-terms.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
require __DIR__ . '/../inc/analytics-network-terms.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

$pairs = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/analytics-asn-orgs.json' ), true );
$orgs  = array_values( array_unique( array_column( (array) $pairs, 'org' ) ) );
ok( count( $orgs ) > 100, 'fixture: ' . count( $orgs ) . ' real ASN orgs from 90 days' );

echo "\nGroup: pinned regex sources\n";
ok( 'a74dd752caa9899f5147b760ddab089136daad48a18c81130ca0d65c65f18e50' === hash( 'sha256', SNT_ANALYTICS_DC_ASN_SOURCE ), 'DC_ASN source matches the worker fingerprint pin' );
ok( 'c8855b5860ac58eeb55183890039d9b9bfd890273c80d8ac1ecdac0b9cd60b19' === hash( 'sha256', SNT_ANALYTICS_RELAY_ASN_SOURCE ), 'RELAY_ASN source pinned (worker 1.21.6)' );
ok( '88fe98da45d41e923d57682ea39388bb4552518522a5c936a3919faa89be1fb4' === hash( 'sha256', SNT_ANALYTICS_HOSTING_ASN_SOURCE ), 'HOSTING_ASN source pinned (worker 1.21.6)' );

echo "\nGroup: terms are safe inside ILIKE '%...%'\n";
$terms = sn_analytics_network_terms();
foreach ( $terms as $list => $ts ) {
	ok( array() === array_filter( $ts, static function ( $t ) { return 1 !== preg_match( '/^[a-z0-9 &]+$/', $t ); } ), "$list: only a-z 0-9 space & (no %, _, quote, backslash)" );
}

/**
 * Orgs where a term list and its regex disagree.
 */
function mismatches( $source, array $ts, array $orgs ) {
	$bad = array();
	foreach ( $orgs as $o ) {
		$re = 1 === preg_match( '/' . $source . '/i', $o );
		$tm = false;
		foreach ( $ts as $t ) {
			$tm = $tm || false !== stripos( $o, $t );
		}
		if ( $re !== $tm ) {
			$bad[] = $o;
		}
	}
	return $bad;
}

echo "\nGroup: each term list equals its regex on the fixture\n";
$src = array( 'dc' => SNT_ANALYTICS_DC_ASN_SOURCE, 'relay' => SNT_ANALYTICS_RELAY_ASN_SOURCE, 'hosting' => SNT_ANALYTICS_HOSTING_ASN_SOURCE );
foreach ( $src as $list => $s ) {
	$bad = mismatches( $s, $terms[ $list ], $orgs );
	ok( array() === $bad, "$list: terms and regex agree on every org" . ( $bad ? ' (' . implode( '; ', $bad ) . ')' : '' ) );
}
$hits = count( array_filter( $orgs, static function ( $o ) { return 1 === preg_match( '/' . SNT_ANALYTICS_HOSTING_ASN_SOURCE . '/i', $o ); } ) );
ok( $hits >= 5, "hosting: the fixture exercises the list ({$hits} orgs match)" );
$dropped = array_values( array_diff( $terms['hosting'], array( 'egihosting' ) ) );
ok( array( 'EGIHosting' ) === mismatches( SNT_ANALYTICS_HOSTING_ASN_SOURCE, $dropped, $orgs ), 'negative control: dropping a hosting term flips its fixture org' );

/**
 * Evaluate a generated WHERE predicate against one row, in PHP. Understands
 * exactly the forms the builders emit: blob12 ILIKE '%t%', blob8 = 'x',
 * blob7 = / != 'x', AND, OR, NOT and parentheses. eval() is safe here: CLI-only
 * test, the input is our own generated SQL and every term goes through var_export.
 */
function eval_pred( $sql, $c, $br, $org ) {
	$php = preg_replace_callback( "/blob12 ILIKE '%([^']*)%'/", static function ( $m ) {
		return '(false !== stripos($o, ' . var_export( $m[1], true ) . '))';
	}, $sql );
	$php = preg_replace( "/blob8 = '([A-Za-z]+)'/", '($b === \'$1\')', $php );
	$php = preg_replace( "/blob7 != '([a-z]+)'/", '($c !== \'$1\')', $php );
	$php = preg_replace( "/blob7 = '([a-z]+)'/", '($c === \'$1\')', $php );
	$php = str_replace( array( ' AND ', ' OR ', 'NOT ' ), array( ' && ', ' || ', '! ' ), $php );
	$f   = eval( 'return static function ($c, $b, $o) { return (bool) (' . $php . '); };' );
	return $f( $c, $br, $org );
}

/**
 * The worker's classify() network half (regex), for a stored non-bot row.
 */
function worker_class( $br, $org ) {
	if ( '' !== $org && preg_match( '/' . SNT_ANALYTICS_RELAY_ASN_SOURCE . '/i', $org ) && 'Safari' === $br ) {
		return 'human';
	}
	if ( '' !== $org && ( preg_match( '/' . SNT_ANALYTICS_DC_ASN_SOURCE . '/i', $org ) || preg_match( '/' . SNT_ANALYTICS_HOSTING_ASN_SOURCE . '/i', $org ) ) ) {
		return 'suspect';
	}
	return 'human';
}

/**
 * (org, browser) pairs where the generated human predicate disagrees with the worker.
 */
function pred_mismatches( $human_sql, array $pairs ) {
	$bad = array();
	foreach ( $pairs as $p ) {
		$want = worker_class( $p['br'], $p['org'] );
		$got  = eval_pred( $human_sql, 'human', $p['br'], $p['org'] ) ? 'human' : 'suspect';
		if ( $want !== $got ) {
			$bad[] = $p['org'] . '/' . $p['br'];
		}
	}
	return $bad;
}

echo "\nGroup: the generated predicate classifies like the worker\n";
$pairs[] = array( 'org' => '', 'br' => 'Chrome' );
$human   = "(blob7 != 'bot' AND (" . sn_analytics_network_human_sql() . '))';
$bad     = pred_mismatches( $human, $pairs );
ok( array() === $bad, 'every (org, browser) pair: generated SQL == worker classify()' . ( $bad ? ' (' . implode( '; ', $bad ) . ')' : '' ) );
$relay_safari = array_filter( $pairs, static function ( $p ) { return 'Safari' === $p['br'] && preg_match( '/akamai|fastly/i', $p['org'] ); } );
ok( count( $relay_safari ) >= 2, 'fixture holds relay Safari pairs (' . count( $relay_safari ) . ')' );
ok( false === eval_pred( $human, 'bot', 'Chrome', '' ), 'a stored bot is never human' );
$no_relay = preg_replace( "/^\(blob8 = 'Safari' AND \([^)]*\)\) OR /", '', sn_analytics_network_human_sql() );
ok( $no_relay !== sn_analytics_network_human_sql(), 'negative control setup: relay clause removed' );
ok( array() !== pred_mismatches( "(blob7 != 'bot' AND ({$no_relay}))", $pairs ), 'negative control: without the relay clause relay Safari flips (red)' );

echo "\nGroup: Google Fiber is a home ISP and must stay human\n";
$fiber = array();
foreach ( array( 'Google Fiber Inc.', 'Google Fiber LLC' ) as $o ) {
	foreach ( array( 'Safari', 'Chrome' ) as $b ) {
		$fiber[] = array( 'org' => $o, 'br' => $b );
	}
}
$fiber_ok = static function ( $sql ) use ( $fiber ) {
	foreach ( $fiber as $p ) {
		if ( 'human' !== worker_class( $p['br'], $p['org'] ) || ! eval_pred( $sql, 'human', $p['br'], $p['org'] ) ) {
			return false;
		}
	}
	return true;
};
ok( $fiber_ok( $human ), 'Google Fiber Inc./LLC on Safari and Chrome: human by the mirrored regex AND the generated term predicate' );
ok( array() === mismatches( SNT_ANALYTICS_DC_ASN_SOURCE, $terms['dc'], array( 'Google Fiber Inc.', 'Google Fiber LLC' ) ), 'dc: terms and regex agree on the Google Fiber orgs' );
$loose = str_replace( "'%google llc%'", "'%google%'", $human );
ok( $loose !== $human && ! $fiber_ok( $loose ), "negative control: a loose 'google' term makes Google Fiber suspect (red)" );

echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail ? 1 : 0 );
