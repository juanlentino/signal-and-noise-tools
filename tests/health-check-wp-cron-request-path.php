<?php
/**
 * Tests: the in-request-cron health check (issue #1037).
 *
 * The state this reports was previously invisible, and invisible in a
 * particular way: `cron_disabled_constant` is a PROBLEM FLAG (constant set AND
 * nothing fired recently AND no system cron declared), so it reads false when
 * the constant is absent and false again when everything works. I read it as
 * "is the constant set" within an hour of shipping it and concluded my own fix
 * was inert. It was not — but nothing on the site could have told me either way.
 *
 * Run: php tests/health-check-wp-cron-request-path.php
 * @since 13.97.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

$GLOBALS['snt_state'] = 'offloaded';
function snt_wp_cron_offload_state() { return $GLOBALS['snt_state']; }
function snt_wp_cron_still_in_request_path() {
	return in_array( $GLOBALS['snt_state'], array( 'already_false', 'declined_filter' ), true );
}

require_once __DIR__ . '/../inc/health-check-wp-cron-request-path.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function run( $state ) { $GLOBALS['snt_state'] = $state; return sn_health_check_wp_cron_request_path(); }

echo "health-check-wp-cron-request-path — plugin v13.97.4\n\nGroup 1: the states that are FINE report nothing\n";
foreach ( array( 'offloaded', 'already_true', 'declined_cli' ) as $ok_state ) {
	$r = run( $ok_state );
	ok( 0 === $r['count'], "'$ok_state' is not a finding — cron is not in the request path" );
	ok( null === $r['skipped'], "   ...and the check reports that it RAN" );
}

echo "\nGroup 2: the two states that are NOT fine\n";
foreach ( array( 'already_false', 'declined_filter' ) as $bad ) {
	$r = run( $bad );
	ok( 1 === $r['count'], "'$bad' IS a finding — cron still spawns from page requests" );
	ok( $bad === $r['findings'][0]['subject_label'], "   ...and it names which state it is, not just that something is wrong" );
}

echo "\nGroup 3: the two findings do not share one sentence\n";
// They have different repairs: one is a wp-config line to change, the other is
// a deliberate filter someone chose. Collapsing them would hand the reader the
// same advice for opposite situations.
$a = run( 'already_false' )['findings'][0]['note'];
$b = run( 'declined_filter' )['findings'][0]['note'];
ok( $a !== $b, 'THE PIN: already_false and declined_filter get different notes' );
ok( false !== strpos( $a, 'wp-config' ), 'already_false points at the wp-config line' );
ok( false !== strpos( $b, 'filter' ), 'declined_filter points at the filter' );
ok( false === strpos( $b, 'wp-config.php defines' ), '   ...and does not blame wp-config for a deliberate choice' );

echo "\nGroup 4: absence of the module is not a pass\n";
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-check-wp-cron-request-path.php' ), "! function_exists( 'snt_wp_cron_offload_state' )" ),
	'a missing offload module reports SKIPPED rather than a silent zero' );

echo "\nGroup 5: registered in all four places\n";
$boot = (string) file_get_contents( dirname( __DIR__ ) . '/signal-and-noise-tools.php' );
ok( false !== strpos( $boot, 'inc/health-check-wp-cron-request-path.php' ), 'the bootstrap loads it' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-checks.php' ), "'wp_cron_request_path'" ), 'the scan runs it' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-check-surfaces.php' ), "'wp_cron_request_path'" ), 'a surface owns it' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-check-families.php' ), "'wp_cron_request_path'" ), 'a family maps it' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
