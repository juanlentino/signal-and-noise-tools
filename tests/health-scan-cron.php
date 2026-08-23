<?php
/**
 * The Content-Health scan has a schedule, and the schedule means something.
 *
 * WHY THIS EXISTS. Until v12.22.2 nothing scheduled the scan: sn_health_run_scan()
 * had two callers, the run-health-scan ability and the wp-admin button, so it ran
 * when a human clicked and never otherwise. The visible cost was a Trust-checks
 * leaf showing a red Ledger CI verdict that the trust repo had cleared eleven
 * hours earlier — the panel was reading the last time anyone asked.
 *
 * The hour is load-bearing, which is the part a future edit is most likely to
 * lose. ledger-ci reads the provenance repo's DAILY verify at 07:00 UTC, so a
 * scan that runs before that reads yesterday's verdict every single day and the
 * stale-verdict window comes straight back. That is asserted here rather than
 * left to the docblock.
 *
 * Run: php tests/health-scan-cron.php
 *
 * @since 12.22.2
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

$GLOBALS['__actions']   = array();
$GLOBALS['__scheduled'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $cb;
	return true;
}
function wp_next_scheduled( $hook ) {
	return $GLOBALS['__scheduled'][ $hook ]['ts'] ?? false;
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
	$GLOBALS['__scheduled'][ $hook ] = array( 'ts' => (int) $ts, 'recurrence' => $recurrence );
	return true;
}

require __DIR__ . '/../inc/health-scan-cron.php';

echo "Group: the hook exists and is wired\n";
ok( 'sn_health_scan_daily' === SN_HEALTH_CRON_HOOK, 'the hook name is stable (renaming it orphans the live event)' );
ok( isset( $GLOBALS['__actions'][ SN_HEALTH_CRON_HOOK ] ), 'something is hooked to it' );
ok( in_array( 'sn_health_cron_run', (array) ( $GLOBALS['__actions'][ SN_HEALTH_CRON_HOOK ] ?? array() ), true ),
	'and it is a NAMED function, so the event stays unschedulable and the callback assertable' );
ok( function_exists( 'sn_health_cron_run' ), 'the callback resolves' );

echo "\nGroup: the slot is the next 08:00 UTC, computed in UTC\n";
// 06:00 UTC — the slot is later the SAME day.
$before = strtotime( '2026-08-23 06:00:00 UTC' );
ok( strtotime( '2026-08-23 08:00:00 UTC' ) === sn_health_cron_next_slot( $before ),
	'from 06:00 UTC the next slot is 08:00 the same day' );
// 09:00 UTC — the slot has passed, so it is tomorrow.
$after = strtotime( '2026-08-23 09:00:00 UTC' );
ok( strtotime( '2026-08-24 08:00:00 UTC' ) === sn_health_cron_next_slot( $after ),
	'from 09:00 UTC it rolls to 08:00 tomorrow' );
// Exactly on the hour counts as passed — otherwise a run firing at 08:00:00
// could reschedule itself to the same instant.
ok( strtotime( '2026-08-24 08:00:00 UTC' ) === sn_health_cron_next_slot( strtotime( '2026-08-23 08:00:00 UTC' ) ),
	'exactly on the hour rolls forward, never to the same instant' );

echo "\nGroup: the HOUR is load-bearing — this is the assertion that matters\n";
// ledger-ci reads the provenance repo's daily verify at 07:00 UTC. A scan
// scheduled before that reads yesterday's verdict every day, which is the exact
// failure this schedule was added to end.
$ledger_verify_hour = 7;
ok( SN_HEALTH_CRON_UTC_HOUR > $ledger_verify_hour,
	'the scan runs AFTER the ledger verify at 07:00 UTC (' . SN_HEALTH_CRON_UTC_HOUR . ':00 > ' . $ledger_verify_hour . ':00)' );
ok( SN_HEALTH_CRON_UTC_HOUR - $ledger_verify_hour >= 1,
	'with at least an hour of margin, so a slow or retried verify still settles first' );
// The WordPress daily hooks measured on live sit at 23:42, 01:43, 02:48, 11:44,
// 16:56 and 21:07 UTC. 03:00-11:00 is empty; staying inside it keeps a ~48s
// walk off the same request window as the rollups and prunes.
ok( SN_HEALTH_CRON_UTC_HOUR >= 3 && SN_HEALTH_CRON_UTC_HOUR <= 11,
	'and inside the 03:00-11:00 UTC window that no other daily hook occupies' );

echo "\nGroup: registration\n";
$GLOBALS['__scheduled'] = array();
foreach ( (array) ( $GLOBALS['__actions']['init'] ?? array() ) as $cb ) {
	if ( is_callable( $cb ) ) { $cb(); }
}
ok( isset( $GLOBALS['__scheduled'][ SN_HEALTH_CRON_HOOK ] ), 'init schedules the event when none exists' );
ok( 'daily' === ( $GLOBALS['__scheduled'][ SN_HEALTH_CRON_HOOK ]['recurrence'] ?? '' ), 'as a DAILY event' );
$first = (int) ( $GLOBALS['__scheduled'][ SN_HEALTH_CRON_HOOK ]['ts'] ?? 0 );
ok( (int) gmdate( 'H', $first ) === SN_HEALTH_CRON_UTC_HOUR, 'anchored to the fixed UTC hour, not time()+offset like its siblings' );
ok( $first > time(), 'and in the future' );

// Idempotent: an existing event is never doubled. wp_next_scheduled returns the
// stored timestamp, so a second init must not reschedule.
$existing = $GLOBALS['__scheduled'][ SN_HEALTH_CRON_HOOK ];
foreach ( (array) ( $GLOBALS['__actions']['init'] ?? array() ) as $cb ) {
	if ( is_callable( $cb ) ) { $cb(); }
}
ok( $existing === $GLOBALS['__scheduled'][ SN_HEALTH_CRON_HOOK ], 'a second init does not reschedule an existing event' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
