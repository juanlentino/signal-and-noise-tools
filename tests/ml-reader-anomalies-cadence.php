<?php
/**
 * The shape ledger's INPUT RATE — the property v13.84.0 shipped without.
 *
 * The gate (7 days AND 24 readings) was correct and unreachable: the only
 * guaranteed caller of the pipeline is WordPress's WEEKLY site-health check,
 * so 24 readings needed ~24 weeks. These assertions pin the hourly driver and,
 * more importantly, the ORDERING that makes it free.
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$GLOBALS['__hooks'] = array();

/** Recording stub: the registrations ARE the subject under test. */
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['__hooks'][] = array(
		'hook'     => $hook,
		'cb'       => $cb,
		'priority' => (int) $priority,
	);
}

require __DIR__ . '/../inc/machine-readers-snapshot.php';
require __DIR__ . '/../inc/mr-series.php';
require __DIR__ . '/../inc/ml-reader-anomalies.php';

$fail = 0;
$n    = 0;
/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Label.
 * @return void
 */
function ok( $cond, $msg ) {
	global $fail, $n;
	$n++;
	if ( ! $cond ) {
		$fail++;
		echo "FAIL: $msg\n";
	}
}

// --- The guard actually fired -------------------------------------------
// function_exists()/defined() guards fail SILENTLY into inertness. If this
// registration is absent the ledger simply never fills and nothing else here
// would notice, so this assertion is the one that must not be skippable.
$mine = array_values( array_filter(
	$GLOBALS['__hooks'],
	static function ( $h ) {
		return 'snt_ml_reader_anomalies_record_shape' === $h['cb'];
	}
) );
ok( 1 === count( $mine ), 'the shape recorder registers exactly once' );
ok( ! empty( $mine ) && SN_MR_SNAPSHOT_HOOK === $mine[0]['hook'], 'it rides the snapshot cron hook' );
ok( function_exists( 'snt_ml_reader_anomalies_record_shape' ), 'the callback it names actually exists' );

// --- The ordering that makes it free ------------------------------------
// DERIVED from the snapshot's own registration, never a hardcoded 10: if
// someone raises the snapshot's priority this must go red, and a test that
// remembered "10" would stay green while the pipeline started paying for its
// own hourly outbound request.
$warmers = array_values( array_filter(
	$GLOBALS['__hooks'],
	static function ( $h ) {
		return 'snt_mr_snapshot_refresh' === $h['cb'] && SN_MR_SNAPSHOT_HOOK === $h['hook'];
	}
) );
ok( 1 === count( $warmers ), 'the snapshot refresher is registered on the same hook' );
ok(
	! empty( $mine ) && ! empty( $warmers ) && $mine[0]['priority'] > $warmers[0]['priority'],
	'the recorder runs AFTER the fetch that warms its transient'
);

// --- The transient the ordering depends on ------------------------------
// The whole "zero extra calls" claim rests on both callers building the SAME
// cache key. snt_mr_fetch( $days = 30, $view = 'aggregate' ) keys on both, so
// this holds only while the two windows agree AND the pipeline uses the
// default view.
ok( SN_MR_SNAPSHOT_DAYS === SN_MR_SERIES_WINDOW, 'both callers request the same window, so they share a cache key' );

// --- Fail-closed: a broken sensor records no shape ----------------------
// A degenerate payload must never be able to look settled.
$unavailable = snt_ml_reader_unavailable( 'sensor_unavailable', '2026-08-01', '2026-08-30' );
ok( false === $unavailable['ok'], 'the unavailable payload is not ok' );
ok( ! isset( $unavailable['counts'] ), 'the unavailable payload is a different shape, not a zeroed one' );

$pass = $n - $fail;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
