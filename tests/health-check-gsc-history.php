<?php
/**
 * The Search Console sync firing while its history stops growing.
 *
 * cron_health reports when `sn_gsc_sync_daily` stops FIRING. Nothing reported
 * the other half: `snt_gsc_history_append()` returns silently on a payload with
 * no window end or no page rows, so `synced_at` stays fresh while the newest
 * snapshot ages — and `search_drift` sits on `accruing`, which reads exactly
 * like "still accumulating".
 *
 * That ambiguity cost a release on 2026-09-03: on the day the drift watch came
 * due, "one day short" and "the producer stalled" were the same readout.
 *
 * @since 13.89.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

function __( $t, $d = null ) { return $t; }
/** Minimal stand-in for the real packer; keeps `skipped` distinguishable. */
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => $skipped );
}
$GLOBALS['__data'] = null;
$GLOBALS['__hist'] = array();
function snt_gsc_data() { return $GLOBALS['__data']; }
function snt_gsc_history() { return $GLOBALS['__hist']; }

require __DIR__ . '/../inc/health-check-gsc-history.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "GSC history stall check (v13.89.0)\n\n";

$NOW = strtotime( '2026-09-03T12:00:00Z' );

// --- COULD NOT RUN is not PASSED -----------------------------------------
$GLOBALS['__data'] = null;
$GLOBALS['__hist'] = array();
$c = snt_health_check_gsc_history();
ok( 0 === $c['count'] && null !== $c['skipped'], 'never synced is SKIPPED, not a silent pass' );

$GLOBALS['__data'] = array( 'synced_at' => $NOW );
$GLOBALS['__hist'] = array();
$c = snt_health_check_gsc_history();
ok( 0 === $c['count'] && null !== $c['skipped'] && false !== strpos( (string) $c['skipped'], 'No snapshots yet' ),
	'an EMPTY history is skipped for its OWN reason — a fresh property is not a stall, and search_drift reports snapshots:0 directly' );
// Asserting the REASON, not just "skipped": deleting the empty-history branch
// falls through to the unreadable-window-end skip, which also returns skipped —
// so a looser assertion passed with the branch removed.

$GLOBALS['__hist'] = array( 'x' => array( 'end' => 'not-a-date-at-all' ) );
$c = snt_health_check_gsc_history();
ok( 0 === $c['count'] && null !== $c['skipped'], 'an unreadable window end is skipped, never a fabricated pass' );

// --- healthy: the gap IS Google's lag ------------------------------------
// Data lags ~2-3 days and the sync is daily, so this gap is the normal state.
$GLOBALS['__hist'] = array( '2026-09-01' => array( 'end' => '2026-09-01' ) );
$c = snt_health_check_gsc_history();
ok( 0 === $c['count'] && null === $c['skipped'], 'a 2-day gap is Google lag, not a stall — and it RAN, so skipped is null' );

// --- the stall ------------------------------------------------------------
$GLOBALS['__hist'] = array( '2026-08-20' => array( 'end' => '2026-08-20' ) );
$c = snt_health_check_gsc_history();
ok( 1 === $c['count'], 'a 14-day gap between the last sync and the newest snapshot is a finding' );
ok( false !== strpos( $c['findings'][0]['note'], '2026-08-20' ), 'the finding names the newest snapshot, so the gap is checkable' );
ok( false !== strpos( $c['findings'][0]['note'], 'not growing' ), 'and says the sync is running while the history is not growing' );

// --- the threshold is a boundary, not a vibe ------------------------------
// Just inside and just outside, so the comparison cannot be off by a day
// undetected — and so this suite fails if someone loosens the constant.
$GLOBALS['__hist'] = array( 'a' => array( 'end' => gmdate( 'Y-m-d', $NOW - ( SNT_GSC_HISTORY_STALL_DAYS - 1 ) * DAY_IN_SECONDS ) ) );
ok( 0 === snt_health_check_gsc_history()['count'], 'one day inside the threshold does not fire' );

$GLOBALS['__hist'] = array( 'a' => array( 'end' => gmdate( 'Y-m-d', $NOW - ( SNT_GSC_HISTORY_STALL_DAYS + 1 ) * DAY_IN_SECONDS ) ) );
ok( 1 === snt_health_check_gsc_history()['count'], 'one day outside it does' );

// --- newest, not first ----------------------------------------------------
// The store ksorts, so the newest entry is last. Reading the FIRST would flag a
// healthy site permanently once its retention window filled.
$GLOBALS['__hist'] = array(
	'2026-08-20' => array( 'end' => '2026-08-20' ),
	'2026-09-01' => array( 'end' => '2026-09-01' ),
);
$c = snt_health_check_gsc_history();
ok( 0 === $c['count'], 'the NEWEST snapshot decides — reading the oldest would flag every healthy site with history' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
