<?php
/**
 * Standalone test: health check 25 -- the machine-reader dataset went quiet.
 *
 * The worker's sensor readout is isolate memory and says null both for a fresh
 * isolate and for a sensor that never fires. The dataset is what can tell, and
 * this check reads it through the durable snapshot. Run: php tests/health-check-machine-reader-liveness.php
 * @since 13.98.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}
$GLOBALS['__stale'] = false;
function snt_mr_snapshot() { return $GLOBALS['__snap']; }
function snt_mr_snapshot_has_measurement( $snap ) { return is_array( $snap ) && is_int( $snap['captured_at'] ?? null ); }
function snt_mr_snapshot_is_stale( $snap ) { return snt_mr_snapshot_has_measurement( $snap ) ? $GLOBALS['__stale'] : null; }
require_once __DIR__ . '/../inc/health-check-machine-reader-liveness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$captured = gmmktime( 3, 0, 0, 6, 5, 2026 ); // 2026-06-05 03:00Z: yesterday = 2026-06-04 (deliberately not real-time yesterday, see Group 4)
function series( $captured, $days, $hits_fn ) {
	$by = array();
	for ( $i = 1; $i <= $days; $i++ ) { $d = gmdate( 'Y-m-d', $captured - $i * DAY_IN_SECONDS ); $h = $hits_fn( $i ); if ( null !== $h ) { $by[ $d ] = $h; } }
	return array( 'captured_at' => $captured, 'days' => $days, 'total' => array_sum( $by ), 'by_day' => $by );
}

echo "health-check-machine-reader-liveness -- plugin v13.98.0\n\nGroup 1: skips are skips, not passes\n";
$GLOBALS['__snap'] = null;
$r = sn_health_check_machine_reader_liveness();
ok( 0 === $r['count'] && is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], 'No machine-reader snapshot' ), 'no snapshot yet -> skipped, names why' );
$GLOBALS['__snap'] = series( $captured, 30, function ( $i ) { return 400; } );
$GLOBALS['__stale'] = true;
$r = sn_health_check_machine_reader_liveness();
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], 'stale' ), 'a stale snapshot -> skipped (an old reading cannot judge today)' );
$GLOBALS['__stale'] = false;
$old = $GLOBALS['__snap']; unset( $old['by_day'] );
$r = sn_health_check_machine_reader_liveness( $old );
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], 'per-day' ), 'a pre-13.98.0 snapshot without by_day -> skipped, not a pass' );

echo "\nGroup 2: the finding\n";
$snap = series( $captured, 30, function ( $i ) { return 1 === $i ? 0 : 400; } );
$r = sn_health_check_machine_reader_liveness( $snap );
ok( 1 === $r['count'] && null === $r['skipped'], 'yesterday 0 against ~400/day -> ONE finding' );
ok( 1 === ( $r['findings'][0]['quiet_days'] ?? 0 ) && 400 === (int) round( $r['findings'][0]['baseline_mean'] ), 'the finding carries the quiet-day count and the baseline' );
ok( false !== strpos( $r['findings'][0]['note'], '2026-06-04' ) && false !== strpos( $r['findings'][0]['note'], 'sensor stopped writing' ), 'the note names the day and says the silence has two readings' );
$snap = series( $captured, 30, function ( $i ) { return $i <= 3 ? 0 : 400; } );
$r = sn_health_check_machine_reader_liveness( $snap );
ok( 1 === $r['count'] && 3 === $r['findings'][0]['quiet_days'], 'three silent days -> quiet_days 3 (the baseline excludes only yesterday, so it dips but stays above the floor)' );
$snap = series( $captured, 30, function ( $i ) { return 1 === $i ? null : 400; } ); // yesterday absent from the map entirely
$r = sn_health_check_machine_reader_liveness( $snap );
ok( 1 === $r['count'], 'a day ABSENT from by_day is a zero day (a family with no rows is absent, not 0 -- same convention as by_family)' );

echo "\nGroup 3: passes are passes\n";
$snap = series( $captured, 30, function ( $i ) { return 1 === $i ? 12 : 400; } );
$r = sn_health_check_machine_reader_liveness( $snap );
ok( 0 === $r['count'] && null === $r['skipped'], 'yesterday had hits (even few) -> ran, nothing wrong' );
$snap = series( $captured, 30, function ( $i ) { return 1 === $i ? 0 : 5; } );
$r = sn_health_check_machine_reader_liveness( $snap );
ok( 0 === $r['count'] && null === $r['skipped'], 'yesterday 0 against ~5/day -> below the floor, nothing to judge: a PASS (null), not a skip' );

echo "\nGroup 4: yesterday is relative to the CAPTURE, not to now\n";
$snap = series( $captured, 30, function ( $i ) { return 1 === $i ? 0 : 400; } );
$snap['by_day'][ gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ] = 999; // whatever "yesterday" is in real time
$r = sn_health_check_machine_reader_liveness( $snap );
ok( 1 === $r['count'], 'hits on real-time yesterday do not rescue a capture whose own yesterday was silent' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
