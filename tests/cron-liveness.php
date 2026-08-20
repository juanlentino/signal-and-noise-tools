<?php
/**
 * Tests for tools/cron-liveness.php — does the scheduled parity workflow show
 * signs of life?
 *
 * WHY THIS EXISTS. A cron cannot witness its own absence. If a scheduled
 * workflow stops firing — GitHub's 60-day inactivity disable, a bad cron
 * expression, a file that never reached the default branch, a repo-level
 * Actions block — it produces NO run, and no run looks exactly like a run that
 * passed. That is the failure this project keeps meeting from a new direction,
 * so the guard has to live on a DIFFERENT trigger than the thing it watches.
 *
 * The verdict function is pure and takes its clock as an argument, so the whole
 * decision table is drivable without the network. These tests call the REAL
 * producer; nothing here re-implements the rule it is checking.
 * Run: php tests/cron-liveness.php
 * @since plugin CI (unversioned — dev/CI tooling, nothing ships)
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'SN_CRON_LIVENESS_NO_MAIN', true );
require __DIR__ . '/../tools/cron-liveness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$now  = strtotime( '2026-08-25T12:00:00Z' );
$hour = 3600;
/** @return array */
function v( $runs, $created, $now, $grace = 48 ) { return sn_cron_liveness_verdict( $runs, $created, $now, $grace ); }

echo "Group: the cron is alive\n";
$r = v( array( '2026-08-25T07:00:00Z' ), '2026-08-01T00:00:00Z', $now );
ok( true === $r['ok'] && 'live' === $r['code'], 'a scheduled run five hours ago is live' );
$r = v( array( '2026-08-23T12:00:00Z' ), '2026-08-01T00:00:00Z', $now );
ok( true === $r['ok'] && 'live' === $r['code'], 'exactly at the grace boundary is still live — the boundary is inclusive, so a cron delayed to the edge does not red' );
$r = v( array( '2026-08-20T07:00:00Z', '2026-08-25T07:00:00Z', '2026-08-24T07:00:00Z' ), '2026-08-01T00:00:00Z', $now );
ok( true === $r['ok'] && 'live' === $r['code'], 'the NEWEST run decides, not the first in the list — the API does not promise an order' );
$r = v( array( '2026-08-25T12:30:00Z' ), '2026-08-01T00:00:00Z', $now );
ok( true === $r['ok'] && 'live' === $r['code'], 'a run timestamped slightly in the future is live, not an error — runner clock skew is not a dead cron' );

echo "\nGroup: the two FAILING codes are distinct, because the fixes differ\n";
$r = v( array( '2026-08-22T07:00:00Z' ), '2026-08-01T00:00:00Z', $now );
ok( false === $r['ok'] && 'stale' === $r['code'], 'runs exist but the newest is past grace: STALE — it fired before and stopped, so look at the 60-day disable or a repo-level Actions block' );
$r = v( array(), '2026-08-01T00:00:00Z', $now );
ok( false === $r['ok'] && 'never-scheduled' === $r['code'], 'no scheduled run EVER on a workflow older than grace: NEVER-SCHEDULED — it never registered, so look at the cron expression or whether the file is on the default branch' );
ok( v( array( '2026-08-22T07:00:00Z' ), '2026-08-01T00:00:00Z', $now )['code'] !== v( array(), '2026-08-01T00:00:00Z', $now )['code'], 'the two failures do NOT share a code — a single red would send you to the wrong door' );

echo "\nGroup: day one must not red\n";
$r = v( array(), '2026-08-25T06:00:00Z', $now );
ok( true === $r['ok'] && 'not-yet-due' === $r['code'], 'a workflow younger than grace with no scheduled run yet is NOT-YET-DUE — it cannot have fired, and a check that reds on the day it lands teaches everyone to ignore it' );
$r = v( array(), '2026-08-23T12:00:00Z', $now );
ok( true === $r['ok'] && 'not-yet-due' === $r['code'], 'the grace boundary is inclusive here too' );

echo "\nGroup: an unreadable clock is NOT a pass\n";
$r = v( array(), null, $now );
ok( false === $r['ok'] && 'indeterminate' === $r['code'], 'no runs AND no readable workflow age: INDETERMINATE, never ok — an instrument that cannot see must not report health' );
$r = v( array(), 'not-a-date', $now );
ok( false === $r['ok'] && 'indeterminate' === $r['code'], 'a garbage created_at is indeterminate, not not-yet-due — failing open here would hide exactly the case this check exists for' );
$r = v( array( 'not-a-date' ), '2026-08-01T00:00:00Z', $now );
ok( false === $r['ok'] && 'never-scheduled' === $r['code'], 'a run whose timestamp will not parse does not count as a run — an unparseable value is absence, not presence' );
$r = v( array( 'not-a-date', '2026-08-25T07:00:00Z' ), '2026-08-01T00:00:00Z', $now );
ok( true === $r['ok'] && 'live' === $r['code'], 'but one bad timestamp does not discard a good one beside it' );

echo "\nGroup: the message names the fix, not just the fault\n";
foreach ( array( 'stale', 'never-scheduled', 'indeterminate' ) as $code ) {
	$r = 'stale' === $code ? v( array( '2026-08-22T07:00:00Z' ), '2026-08-01T00:00:00Z', $now )
		: ( 'never-scheduled' === $code ? v( array(), '2026-08-01T00:00:00Z', $now ) : v( array(), null, $now ) );
	ok( '' !== trim( (string) $r['message'] ) && false !== strpos( $r['message'], $code ), "the $code verdict carries a message naming its own code" );
}
ok( false !== strpos( v( array(), '2026-08-01T00:00:00Z', $now )['message'], 'cron' ), 'never-scheduled points at the cron expression — the thing to actually go look at' );

echo "\nGroup: grace is a parameter, not a magic number\n";
ok( 'stale' === v( array( '2026-08-24T07:00:00Z' ), '2026-08-01T00:00:00Z', $now, 12 )['code'], 'a tighter grace reds a run the default would call live' );
ok( 'live' === v( array( '2026-08-20T07:00:00Z' ), '2026-08-01T00:00:00Z', $now, 240 )['code'], 'a looser grace accepts one the default would call stale' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
