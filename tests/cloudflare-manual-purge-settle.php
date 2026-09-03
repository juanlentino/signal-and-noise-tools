<?php
/**
 * A manual purge's verdict is recorded once it has SETTLED, never from the
 * inline sample that raced propagation.
 *
 * The inline probe runs in the same request that dispatched the zone purge, so
 * it books "stale" whenever the colo serving the origin box has not caught up.
 * Measured 2026-09-02/03: four of eleven manual purges recorded stale — one at
 * 04:09:42, twenty-nine seconds after a fresh at 04:09:13 — while every AUTO
 * purge over the same window resolved fresh, because auto purges take the
 * theme's deferred cron verify and manual ones were excluded from it.
 *
 * Two of the three outcomes here record NOTHING. That is the point: the log's
 * standing rule is that an absence of evidence is not a verdict.
 *
 * @since 13.88.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__sched']    = array();
$GLOBALS['__recorded'] = array();
$GLOBALS['__report']   = null;

function add_action( $h, $c, $p = 10, $a = 1 ) {}
function get_option( $n, $d = false ) { return $GLOBALS['__report'] ?? $d; }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
function wp_next_scheduled( $h, $args = array() ) {
	foreach ( $GLOBALS['__sched'] as $e ) {
		if ( $e['hook'] === $h && $e['args'] === $args ) { return $e['time']; }
	}
	return false;
}
function wp_unschedule_event( $t, $h, $args = array() ) {
	$GLOBALS['__sched'] = array_values( array_filter( $GLOBALS['__sched'], static function ( $e ) use ( $h, $args ) {
		return ! ( $e['hook'] === $h && $e['args'] === $args );
	} ) );
	return true;
}
function wp_schedule_single_event( $t, $h, $args = array() ) {
	$GLOBALS['__sched'][] = array( 'time' => $t, 'hook' => $h, 'args' => $args );
	return true;
}
/** The spy: what actually reaches the durable log. */
function snt_cf_probe_record( array $entry ) { $GLOBALS['__recorded'][] = $entry; }

require __DIR__ . '/../inc/cloudflare-manual-purge-settle.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}
function reset_state() {
	$GLOBALS['__sched'] = array(); $GLOBALS['__recorded'] = array();
}

echo "manual purge settle (v13.88.0)\n\n";

// --- superseded: a newer purge owns the answer ---------------------------
reset_state();
$GLOBALS['__report'] = array( 'epoch' => 99, 'verify' => 'cron', 'resolved' => false );
snt_cf_settle_manual_purge( 42 );
ok( array() === $GLOBALS['__recorded'], 'a superseded epoch records NOTHING — that edge state is nobody\'s question now' );
ok( array() === $GLOBALS['__sched'], 'and it does not keep re-checking a purge that has been replaced' );

// --- not settled yet: absence of evidence, not a verdict -----------------
reset_state();
$GLOBALS['__report'] = array( 'epoch' => 42, 'resolved' => false ); // no verify marker
snt_cf_settle_manual_purge( 42, 1 );
ok( array() === $GLOBALS['__recorded'], 'an UNSETTLED report records nothing — resolved still holds the inline sample' );
ok( 1 === count( $GLOBALS['__sched'] ), 'it schedules another look' );
ok( array( 42, 2 ) === $GLOBALS['__sched'][0]['args'], 'and carries the attempt forward, so the budget is finite' );

// The marker, not the delay, is what decides. WP-cron is traffic-driven, so a
// fixed wait would be racing another cron — the same bug one layer up.
reset_state();
$GLOBALS['__report'] = array( 'epoch' => 42, 'verify' => 'inline', 'resolved' => true );
snt_cf_settle_manual_purge( 42, 1 );
ok( array() === $GLOBALS['__recorded'], 'verify:inline is NOT settled, even when resolved is true' );

// --- the budget is bounded ----------------------------------------------
reset_state();
$GLOBALS['__report'] = array( 'epoch' => 42, 'resolved' => false );
snt_cf_settle_manual_purge( 42, SN_CF_SETTLE_MAX_ATTEMPTS );
ok( array() === $GLOBALS['__recorded'] && array() === $GLOBALS['__sched'],
	'at the attempt cap it gives up SILENTLY rather than guessing a verdict' );

// --- settled: the trustworthy reading ------------------------------------
reset_state();
$GLOBALS['__report'] = array( 'epoch' => 42, 'verify' => 'cron', 'resolved' => true );
snt_cf_settle_manual_purge( 42, 1 );
ok( 1 === count( $GLOBALS['__recorded'] ), 'a settled report records exactly one row' );
ok( 'fresh' === $GLOBALS['__recorded'][0]['result'], 'resolved:true after the deferred verify is FRESH' );
ok( 'manual_zone_purge' === $GLOBALS['__recorded'][0]['source'], 'and it is attributed to the manual purge' );
ok( array() === $GLOBALS['__sched'], 'a settled verdict stops the chain' );

// A genuinely stale edge must STILL be reportable. The fix must not become a
// filter that can only ever say fresh — that would be the opposite failure.
reset_state();
$GLOBALS['__report'] = array( 'epoch' => 42, 'verify' => 'cron', 'resolved' => false );
snt_cf_settle_manual_purge( 42, 1 );
ok( 1 === count( $GLOBALS['__recorded'] ) && 'stale' === $GLOBALS['__recorded'][0]['result'],
	'resolved:false AFTER the deferred verify is a real STALE — this never becomes a fresh-only filter' );

// --- scheduling hygiene ---------------------------------------------------
reset_state();
snt_cf_schedule_settle( 42, 1 );
snt_cf_schedule_settle( 42, 1 );
ok( 1 === count( $GLOBALS['__sched'] ), 'scheduling is deduplicated: pressing Purge repeatedly cannot stack checks' );
ok( ! snt_cf_schedule_settle( 42, SN_CF_SETTLE_MAX_ATTEMPTS + 1 ), 'past the cap it refuses to schedule' );
ok( ! snt_cf_schedule_settle( 0, 1 ), 'epoch 0 is not a purge and schedules nothing' );

// The delay must clear the theme's own deferred verify (75s), or the first look
// would routinely find an unsettled report and burn an attempt.
ok( SN_CF_SETTLE_DELAY > 75, 'the first check lands after the theme deferred verify it is waiting on' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
