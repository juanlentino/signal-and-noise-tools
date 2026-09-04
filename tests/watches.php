<?php
/**
 * Watches: the things that come due later, and stay quiet until they do.
 *
 * A routine fires on a clock whether or not it has anything to say, and a daily
 * message that usually says "nothing yet" trains its reader to stop opening it.
 * So a watch is SILENT until ripe, and ripens on a STATE wherever one exists —
 * a date only where nothing can be measured.
 *
 * @since 13.90.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__shape'] = null;
$GLOBALS['__drift'] = null;
function sn_shape_stability( $subject, $now ) { return $GLOBALS['__shape']; }
function snt_gsc_position_drift() { return $GLOBALS['__drift']; }
$GLOBALS['__ipv6'] = null;
function snt_ipv6_criterion_stored() { return $GLOBALS['__ipv6']; }
if ( ! defined( 'SN_PROV_INTEGRITY_OPT' ) ) { define( 'SN_PROV_INTEGRITY_OPT', 'sn_prov_integrity' ); }
$GLOBALS['__integrity'] = null;
function get_option( $k, $d = false ) { return null === $GLOBALS['__integrity'] ? $d : $GLOBALS['__integrity']; }

require __DIR__ . '/../inc/watches.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}
/** Is a given watch id in the ripe list? */
function has_id( $rows, $id ) {
	foreach ( $rows as $r ) { if ( $id === $r['id'] ) { return $r; } }
	return null;
}

echo "watches (v13.90.0)\n\n";

$BEFORE = strtotime( '2026-09-05 12:00:00 UTC' );
$AFTER  = strtotime( '2026-09-30 12:00:00 UTC' );

// --- SILENCE is the default -----------------------------------------------
$GLOBALS['__shape'] = array( 'state' => 'settling', 'reason' => '3.1 of 7 days, 20 of 24 readings' );
$GLOBALS['__drift'] = array();
ok( array() === snt_watches_ripe( $BEFORE ),
	'nothing ripe means an EMPTY list — a watch with nothing to say says nothing' );

// --- a STATE ripens it, not a date ----------------------------------------
// The shape watch carries no due date at all: it ripens when the ledger says
// settled, whenever that is. A date here would be a reminder someone has to
// honour rather than a fact the site notices.
$GLOBALS['__shape'] = array( 'state' => 'settled', 'reason' => 'unchanged across 26 readings over 7.4 days' );
$rows = snt_watches_ripe( $BEFORE );
$w = has_id( $rows, 'reader_anomalies_twin' );
ok( null !== $w, 'the twin watch ripens on STATE, before any date has passed' );
ok( false !== strpos( (string) $w['note'], '26 readings' ), 'and carries the ledger\'s own reason, not a restatement' );
ok( '' === (string) $w['due'] && empty( $w['date_only'] ), 'it declares no due date — there is nothing to wait for but the state' );

// --- an unreadable watch is NEVER ripe ------------------------------------
$GLOBALS['__shape'] = null;
ok( null === has_id( snt_watches_ripe( $BEFORE ), 'reader_anomalies_twin' ),
	'a ledger that cannot answer leaves the watch quiet — absence of evidence is not a finding' );

// --- date AND state, both halves ------------------------------------------
$GLOBALS['__shape'] = array( 'state' => 'settling', 'reason' => 'x' );
$GLOBALS['__drift'] = array( '/notes' => array( 'from' => 6.3, 'to' => 11.5, 'impressions' => 11 ) );
ok( null === has_id( snt_watches_ripe( $BEFORE ), 'notes_drift_reread' ),
	'drifting but BEFORE the re-read date: not ripe — the point is a wider sample, not an earlier look' );

$w = has_id( snt_watches_ripe( $AFTER ), 'notes_drift_reread' );
ok( null !== $w && false !== strpos( (string) $w['note'], 'still drifting' ),
	'after the date AND still drifting: ripe, with the numbers' );

// THE OTHER HALF. A date alone would surface this on the 11th even if the drift
// had reverted — which is the answer, and not one that needs attention.
$GLOBALS['__drift'] = array();
$w = has_id( snt_watches_ripe( $AFTER ), 'notes_drift_reread' );
ok( null === $w, 'after the date but NO LONGER drifting: silent — it was sample noise, which is a resolution' );

// A drift history that cannot answer is not "no drift".
$GLOBALS['__drift'] = null;
ok( null === has_id( snt_watches_ripe( $AFTER ), 'notes_drift_reread' ),
	'a drift history that cannot answer leaves it quiet rather than claiming either way' );

// --- date-only watches ripen on their date and STAY ripe ------------------
$GLOBALS['__drift'] = array();
ok( null === has_id( snt_watches_ripe( $BEFORE ), 'search_coverage_reread' ), 'a date-only watch is quiet before its date' );
$w = has_id( snt_watches_ripe( $AFTER ), 'search_coverage_reread' );
ok( null !== $w && ! empty( $w['date_only'] ), 'and ripens after it, FLAGGED as date-only so a reader knows nothing was measured' );

// --- the IPv6 criterion: only ONE decision means act ----------------------
// The gauge names its own decision, which is why this is a state watch and not
// a date. Every withhold_* is a live, correct answer and must never surface.
$GLOBALS['__ipv6'] = null;
ok( null === has_id( snt_watches_ripe( $AFTER ), 'ipv6_build_ranges' ),
	'nothing stored leaves it quiet — not measured is not "the criterion says no"' );

$GLOBALS['__ipv6'] = array( 'decision' => 'withhold_unfinished_window', 'reason' => 'this window holds 10 and 196' );
ok( null === has_id( snt_watches_ripe( $AFTER ), 'ipv6_build_ranges' ),
	'a withhold decision is a live, correct answer — it does NOT surface as due' );

$GLOBALS['__ipv6'] = array( 'decision' => 'build_ranges', 'reason' => '45.4% over 30 days, 22 days covered' );
$w = has_id( snt_watches_ripe( $AFTER ), 'ipv6_build_ranges' );
ok( null !== $w, 'build_ranges — the one decision that means act — ripens it' );
ok( false !== strpos( (string) $w['note'], '22 days covered' ),
	'and it carries the gauge\'s OWN reason, which is what the build needs' );
$GLOBALS['__ipv6'] = null;

// --- a watch nobody can TEST is never ripe --------------------------------
// Unreachable against the real registry (every row is well-formed), which is
// exactly why it is injected: an unreachable guard is an untested one.
$bad = array(
	array( 'id' => 'no_callback', 'label' => 'x', 'why' => 'w', 'read' => 'r', 'date_only' => false, 'due' => '', 'ripe' => '' ),
	array( 'id' => 'missing_fn', 'label' => 'y', 'why' => 'w', 'read' => 'r', 'date_only' => false, 'due' => '', 'ripe' => 'snt_watch_that_does_not_exist' ),
	array( 'id' => 'dateless_date_only', 'label' => 'z', 'why' => 'w', 'read' => 'r', 'date_only' => true, 'due' => '' ),
);
ok( array() === snt_watches_ripe( $AFTER, $bad ),
	'a watch with no test, a missing callback, or date-only with no date is NEVER ripe' );

// And a well-formed injected row still ripens, so the assertion above is not
// passing because injection is broken.
$good = array( array( 'id' => 'ok', 'label' => 'l', 'why' => 'w', 'read' => 'r', 'date_only' => true, 'due' => '2026-01-01' ) );
ok( 1 === count( snt_watches_ripe( $AFTER, $good ) ),
	'VACUITY GUARD: injection works — a valid row through the same path IS ripe' );

// --- every row is answerable ----------------------------------------------
// A watch nobody can act on is a note, not a watch.
foreach ( snt_watches() as $watch ) {
	ok( '' !== (string) ( $watch['read'] ?? '' ) && '' !== (string) ( $watch['why'] ?? '' ),
		'watch "' . $watch['id'] . '" says WHERE to look and WHY it matters' );
	ok( ! empty( $watch['date_only'] ) xor '' !== (string) ( $watch['ripe'] ?? '' ),
		'watch "' . $watch['id'] . '" is either date-only or state-tested, never both and never neither' );
}


// ── integrity re-sweep watch (the post_modified-silent write) ──────────────
echo "\nintegrity re-sweep watch\n";
$T0 = strtotime( '2026-09-05T00:00:00Z' );
$before = '2026-09-03T22:00:00Z'; // BEFORE the silent write
$after  = '2026-09-04T06:00:00Z'; // after it

$GLOBALS['__integrity'] = null;
$v = snt_watch_ripe_integrity_resweep( array(), $T0 );
ok( false === $v['ripe'] && false !== strpos( $v['note'], 'no sweep' ), 'no recorded sweep is not ripe (it reports absence, never a pass)' );

// NEGATIVE CONTROL: a partial re-sweep must REFUSE, because a zero-mismatch
// reading over a slice is the sampling artifact this watch exists to catch.
$GLOBALS['__integrity'] = array( 'notes' => array(
	11 => array( 'last_checked' => $after,  'failures' => array() ),
	12 => array( 'last_checked' => $before, 'failures' => array() ),
	13 => array( 'last_checked' => $before, 'failures' => array() ),
) );
$v = snt_watch_ripe_integrity_resweep( array(), $T0 );
ok( false === $v['ripe'], 'NEGATIVE CONTROL: a PARTIAL re-sweep is not ripe even with zero mismatches' );
ok( false !== strpos( $v['note'], '1 of 3' ), '...and it says how far the coverage got (' . $v['note'] . ')' );

// Whole fleet re-checked after the write, all clean.
$GLOBALS['__integrity'] = array( 'notes' => array(
	11 => array( 'last_checked' => $after, 'failures' => array() ),
	12 => array( 'last_checked' => $after, 'failures' => array() ),
) );
$v = snt_watch_ripe_integrity_resweep( array(), $T0 );
ok( true === $v['ripe'] && false !== strpos( $v['note'], 'no hash_mismatch' ), 'a FULL re-sweep with no drift is ripe and says so' );

// An outage leg is missing evidence, never drift — it must not read as failure.
$GLOBALS['__integrity'] = array( 'notes' => array(
	11 => array( 'last_checked' => $after, 'failures' => array() ),
	12 => array( 'last_checked' => $after, 'failures' => array( 'twin_unreachable' ) ),
) );
$v = snt_watch_ripe_integrity_resweep( array(), $T0 );
ok( true === $v['ripe'] && false !== strpos( $v['note'], 'outage' ) && false === strpos( $v['note'], 'report hash_mismatch' ),
	'an outage leg is named as missing evidence, not folded into drift' );

// Real drift names the subjects.
$GLOBALS['__integrity'] = array( 'notes' => array(
	11 => array( 'last_checked' => $after, 'failures' => array( 'hash_mismatch' ) ),
	12 => array( 'last_checked' => $after, 'failures' => array() ),
) );
$v = snt_watch_ripe_integrity_resweep( array(), $T0 );
ok( true === $v['ripe'] && false !== strpos( $v['note'], 'hash_mismatch' ) && false !== strpos( $v['note'], '11' ),
	'a real hash_mismatch is ripe and NAMES the drifting subject' );

// A sweep stamped exactly AT the cutoff counts as after it (boundary).
$GLOBALS['__integrity'] = array( 'notes' => array(
	11 => array( 'last_checked' => SNT_WATCH_SILENT_WRITE_AT, 'failures' => array() ),
) );
$v = snt_watch_ripe_integrity_resweep( array(), $T0 );
ok( true === $v['ripe'], 'a check stamped exactly at the write instant counts as covering it' );
$GLOBALS['__integrity'] = null;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
