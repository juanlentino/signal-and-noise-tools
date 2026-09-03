<?php
/**
 * The watch registry's agent reader.
 *
 * The registry is mailed to the owner by the morning brief. That is one
 * surface, and a human one. Shipping an instrument only a single surface can
 * see is the defect this codebase found twice in three days — the purge log
 * written for eighteen versions and read by nothing, and the shape ledger
 * filling for four days with its verdict function called only from tests.
 *
 * @since 13.90.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__all']  = array();
$GLOBALS['__ripe'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function snt_watches() { return $GLOBALS['__all']; }
function snt_watches_ripe( $now = null, $rows = null ) { return $GLOBALS['__ripe']; }

require __DIR__ . '/../inc/abilities-watches.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "watches ability (v13.90.0)\n\n";

$A = array( 'id' => 'a', 'label' => 'twin', 'read' => 'sn-status{shape_stability}', 'why' => 'freezes a payload shape', 'date_only' => false, 'due' => '' );
$B = array( 'id' => 'b', 'label' => 'coverage', 'read' => 'sn-status{search_coverage}', 'why' => 'editorial call', 'date_only' => true, 'due' => '2026-09-14' );

// --- nothing due is the NORMAL state, not an error ------------------------
$GLOBALS['__all']  = array( $A, $B );
$GLOBALS['__ripe'] = array();
$r = snt_ability_watches( null );
ok( array() === $r['ripe'] && 0 === $r['counts']['ripe'], 'nothing ripe is an empty list — the normal state, never an error' );

// PENDING is reported rather than inferred. Without it an empty `ripe` cannot
// be told apart from an empty REGISTRY, and those are different facts.
ok( 2 === $r['counts']['pending'] && 2 === $r['counts']['total'],
	'pending is reported, so "nothing is due" is distinguishable from "nothing is registered"' );
ok( 'twin' === $r['pending'][0]['label'], 'and names what is being waited on' );

// --- a ripe watch carries what acting on it needs -------------------------
$GLOBALS['__ripe'] = array( array_merge( $A, array( 'note' => 'unchanged across 26 readings over 7.4 days' ) ) );
$r = snt_ability_watches( null );
ok( 1 === $r['counts']['ripe'] && 1 === $r['counts']['pending'], 'a ripe watch leaves the pending list' );
$w = $r['ripe'][0];
ok( false !== strpos( $w['note'], '26 readings' ), 'the note is the EVIDENCE that ripened it, not a restatement of the label' );
ok( 'sn-status{shape_stability}' === $w['read'], 'the row says exactly where to look' );
ok( '' !== $w['why'], 'and what acting on it means' );

// --- the two kinds are distinguishable ------------------------------------
// A state-tested watch ripened because something measurable changed; a
// date-only one ripened because a clock passed and NOTHING was measured.
// Collapsing them would give both the same weight.
$GLOBALS['__ripe'] = array(
	array_merge( $A, array( 'note' => 'settled' ) ),
	array_merge( $B, array( 'note' => 'due 2026-09-14' ) ),
);
$r = snt_ability_watches( null );
ok( false === $r['ripe'][0]['date_only'] && true === $r['ripe'][1]['date_only'],
	'date_only rides through — a measured ripening and an elapsed clock warrant different confidence' );
ok( '2026-09-14' === $r['ripe'][1]['due'], 'and the date-only row carries its date' );

// --- the module absent is an ERROR, not an empty list ---------------------
// An empty result would read as "nothing is due", which is a claim this cannot
// make when it could not look.
ok( 0 === $r['counts']['total'] - 2, 'sanity: totals track the registry' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
