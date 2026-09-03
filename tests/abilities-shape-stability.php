<?php
/**
 * The shape ledger's FIRST reader.
 *
 * v13.84.0 built the ledger, v13.85.0 gave it an hourly writer, and
 * sn_shape_stability() was called from tests and nowhere else — so the one
 * question the module exists to answer ("has this payload's structure held
 * still?") was reachable only by `wp eval`. The decision it informs, freezing a
 * shape into a remote twin, would have fallen back to recollection, which is
 * precisely what the ledger replaced.
 *
 * @since 13.88.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__opts'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $auto = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function __( $t, $d = null ) { return $t; }

// The REAL module, so thresholds and states come from the constants under test
// rather than a fixture's idea of them.
require __DIR__ . '/../inc/shape-ledger.php';
require __DIR__ . '/../inc/abilities-shape-stability.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}
/** Find one subject row by name. */
function row( $out, $name ) {
	foreach ( $out['subjects'] as $r ) {
		if ( $name === $r['subject'] ) { return $r; }
	}
	return null;
}

echo "shape-stability ability (v13.88.0)\n\n";

$NOW = time();

// --- an empty ledger is NOT "everything is stable" -----------------------
$GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ] = array();
$out = snt_ability_shape_stability( null );
ok( 'no_subjects' === $out['state'], 'an empty ledger reports no_subjects' );
ok( array() === $out['subjects'] && 0 === $out['counts']['settled'],
	'and counts NOTHING as settled — absence of evidence is never a pass' );
// The thresholds are reported even with nothing recorded, so a caller can see
// the gate before any subject exists.
ok( SN_SHAPE_STABLE_READINGS === $out['thresholds']['readings']
	&& SN_SHAPE_STABLE_DAYS === $out['thresholds']['days'],
	'thresholds come from the CONSTANTS, so a caller sees the real gate' );

// --- a subject that has settled ------------------------------------------
$settled_since = $NOW - ( SN_SHAPE_STABLE_DAYS + 1 ) * DAY_IN_SECONDS;
$GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ] = array(
	'reader-anomalies' => array(
		'fp'       => '{a:int}',
		'since'    => $settled_since,
		'readings' => SN_SHAPE_STABLE_READINGS + 5,
		'changes'  => array(),
	),
);
$out = snt_ability_shape_stability( null );
ok( 'recorded' === $out['state'], 'a populated ledger reports recorded' );
$r = row( $out, 'reader-anomalies' );
ok( is_array( $r ) && 'settled' === $r['state'], 'a subject past BOTH thresholds is settled' );
ok( $r['since'] === $settled_since && null !== $r['since_iso'],
	'the row carries when the current shape first appeared, in epoch AND ISO' );
ok( 1 === $out['counts']['settled'], 'and it is counted' );

// --- short on ONE threshold is still settling ----------------------------
// Both halves matter: a subject can be old and under-sampled, or heavily
// sampled and too young. Either alone must not read as settled.
$GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ] = array(
	'few-readings' => array( 'fp' => 'x', 'since' => $settled_since, 'readings' => SN_SHAPE_STABLE_READINGS - 1, 'changes' => array() ),
	'too-young'    => array( 'fp' => 'y', 'since' => $NOW - 3600, 'readings' => SN_SHAPE_STABLE_READINGS + 50, 'changes' => array() ),
);
$out = snt_ability_shape_stability( null );
ok( 'settling' === row( $out, 'few-readings' )['state'], 'enough DAYS but too few readings is settling, not settled' );
ok( 'settling' === row( $out, 'too-young' )['state'], 'enough READINGS but too few days is settling, not settled' );
ok( 0 === $out['counts']['settled'] && 2 === $out['counts']['settling'], 'counts reflect both' );
ok( '' !== row( $out, 'few-readings' )['reason'], 'and the reason says WHICH threshold is short' );

// --- `since` is ambiguous WITHOUT the change history (v13.88.1) -----------
// A recent `since` is either the clock starting or the countdown restarting,
// and those have opposite meanings for "can I freeze this into a twin".
// sn_shape_ledger_record() appends to `changes` only on a REAL change, so the
// distinction is exact — and stating it beats making every caller know the rule.
$GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ] = array(
	'never-moved' => array( 'fp' => 'a', 'since' => $NOW - 3600, 'readings' => 3, 'changes' => array() ),
	'just-moved'  => array(
		'fp'       => '{mad:null}',
		'since'    => $NOW - 3600,
		'readings' => 3,
		'changes'  => array(
			array( 'at' => $NOW - 3600, 'from' => '{mad:int}', 'to' => '{mad:null}' ),
		),
	),
);
$out = snt_ability_shape_stability( null );

$a = row( $out, 'never-moved' );
ok( false === $a['ever_changed'] && array() === $a['changes'],
	'a subject that has NEVER changed says so — `since` is when recording began' );

$b = row( $out, 'just-moved' );
ok( true === $b['ever_changed'],
	'a subject that HAS changed says so — `since` is when the countdown restarted, and waiting is not the answer' );
ok( 1 === count( $b['changes'] ), 'the change is returned' );
ok( '{mad:int}' === $b['changes'][0]['from'] && '{mad:null}' === $b['changes'][0]['to'],
	'with BOTH fingerprints, which is what turns "it moved" into "this key changed type"' );
ok( null !== $b['changes'][0]['at_iso'],
	'and an ISO timestamp, for correlating a shape change against a deploy' );

// Identical `since` and `readings` on both rows above: without ever_changed
// they are indistinguishable, which is exactly the ambiguity v13.88.0 shipped.
ok( $a['since'] === $b['since'] && $a['readings'] === $b['readings'],
	'VACUITY GUARD: the two subjects are identical except for their history, so this section cannot pass by accident' );

// Malformed history must not fatal or fabricate.
$GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ] = array(
	'junk' => array( 'fp' => 'a', 'since' => $NOW, 'readings' => 1, 'changes' => array( 'not-an-array', array( 'from' => 'x' ) ) ),
);
$out = snt_ability_shape_stability( null );
$j = row( $out, 'junk' );
ok( 1 === count( $j['changes'] ) && null === $j['changes'][0]['at'],
	'a malformed change entry is dropped, and one missing its timestamp reports null rather than epoch zero' );

// --- reading must not RECORD ---------------------------------------------
// A reader that fingerprinted the payload would add a reading, so polling would
// push a subject toward settled on its own. That is a diagnostic reacting to
// the operator, which this codebase removed from the cache readout on
// 2026-09-03; it must not be reintroduced here.
$before = $GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ];
snt_ability_shape_stability( null );
snt_ability_shape_stability( null );
snt_ability_shape_stability( null );
ok( $before === $GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ],
	'READING RECORDS NOTHING — three calls leave the ledger byte-identical, so polling cannot drive a subject to settled' );

// --- subject enumeration --------------------------------------------------
$GLOBALS['__opts'][ SN_SHAPE_LEDGER_OPTION ] = array(
	'zebra' => array( 'fp' => 'a', 'since' => $NOW, 'readings' => 1, 'changes' => array() ),
	'alpha' => array( 'fp' => 'b', 'since' => $NOW, 'readings' => 1, 'changes' => array() ),
	''      => array( 'fp' => 'c' ),
	'junk'  => 'not-an-array',
);
$subjects = sn_shape_ledger_subjects();
ok( array( 'alpha', 'zebra' ) === $subjects, 'subjects are enumerated, sorted, and malformed entries dropped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
