<?php
/**
 * CLI fixture for inc/integrity-trust-admin.php — the Integrity → Trust checks
 * leaf (v10.47.0).
 *
 * The leaf is a second VIEW of four checks that live in the health scan. The
 * risk that view carries is DRIFT: a check renamed or dropped in
 * inc/health-checks.php would leave a permanently empty card here, and an empty
 * card in a trust surface reads exactly like a pass. So the first assertion is
 * that every key it displays still exists in the real scan registry.
 *
 * Run: php tests/integrity-trust-admin.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function add_action( $h = null, $cb = null, $p = 10, $a = 1 ) {}

require __DIR__ . '/../inc/integrity-trust-admin.php';

echo "Integrity → Trust checks (v10.47.0)\n\n";

// ── Anti-drift: every displayed key must be a REAL check in the scan registry ──
// Read from source rather than running the scan (it walks every post and probes
// the network); the registry is a literal map, so a string search is exact.
$health_src = (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' );
foreach ( array_keys( snt_trust_check_keys() ) as $key ) {
	ok( false !== strpos( $health_src, "'" . $key . "'" ),
		"trust key '$key' still exists in the health-check registry (a renamed check would leave a silently empty card)" );
}
ok( 4 === count( snt_trust_check_keys() ), 'exactly four trust checks are surfaced' );

// ── Cards: clear / findings / absent ──
$scan = array(
	'scanned_at' => 1785874574,
	'checks'     => array(
		'provenance_integrity' => array( 'count' => 0, 'findings' => array(), 'label' => 'x' ),
		'ledger_ci'            => array( 'count' => 2, 'findings' => array(), 'label' => 'x' ),
		'rights_signals'       => array( 'count' => 0, 'findings' => array(), 'label' => 'x' ),
		// rights_anchored deliberately ABSENT — the "not run" path.
	),
);
$cards = snt_trust_cards( $scan );
ok( 4 === count( $cards ), 'a card is emitted for every trust check, including the missing one' );
ok( 'clear' === $cards[0]['value'] && 'ok' === $cards[0]['pill']['kind'], 'a zero-finding check reads clear/ok' );
ok( '2 findings' === $cards[1]['value'] && 'warn' === $cards[1]['pill']['kind'], 'a check with findings reads its count and pills warn' );
ok( 'not run' === $cards[3]['value'] && 'warn' === $cards[3]['pill']['kind'],
	'an ABSENT check reads "not run" and pills warn — never silently omitted, because a gap in a trust surface must not look like a pass' );

// ── No scan at all ──
$none = snt_trust_cards( null );
ok( 4 === count( $none ), 'with no scan, all four still render' );
ok( 'not run' === $none[0]['value'], 'with no scan every card reads "not run"' );
foreach ( $none as $c ) {
	ok( 'warn' === $c['pill']['kind'], 'no-scan cards never pill ok (absence of evidence is not a pass): ' . $c['label'] );
}

// ── Malformed input must not fatal (the scan option is user-writable state) ──
ok( 4 === count( snt_trust_cards( array( 'checks' => 'not-an-array' ) ) ), 'a malformed checks value degrades to "not run", never a fatal' );
ok( 4 === count( snt_trust_cards( array() ) ), 'an empty scan array degrades cleanly' );

// Singular/plural on the count.
$one = snt_trust_cards( array( 'checks' => array( 'provenance_integrity' => array( 'count' => 1 ) ) ) );
ok( '1 finding' === $one[0]['value'], 'one finding is singular' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
