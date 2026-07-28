<?php
/**
 * Tests: the reading-time LIVE feature survives the v10.0.0 surface removal.
 *
 * v10.0.0 removed the broken legacy-cleanup ADMIN UI (its "Run preview" link
 * pointed at the `sn-reading-time` slug retired in v6.18.0). This fixture now
 * pins what MUST remain: the [sn_reading_time] shortcode, the WPM constant,
 * and both cleanup functions (the only way to ever run the one-shot cleanup,
 * since the live-DB check cannot run from a release worktree).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$src = (string) file_get_contents( __DIR__ . '/../inc/reading-time.php' );

echo "Group: the live feature is untouched\n";
ok( false !== strpos( $src, "add_shortcode( 'sn_reading_time'" ), 'the [sn_reading_time] shortcode is still registered' );
ok( false !== strpos( $src, 'SN_READING_TIME_DEFAULT_WPM' ), 'the WPM constant survives' );
ok( false !== strpos( $src, 'function sn_find_legacy_reading_time' ), 'the legacy finder is KEPT (WP-CLI path)' );
ok( false !== strpos( $src, 'function sn_apply_legacy_reading_time_cleanup' ), 'the legacy applier is KEPT (WP-CLI path)' );

echo "\nGroup: the broken surface is gone\n";
ok( false === strpos( $src, "add_action( 'sn_admin_reading_time_tab'" ), 'the admin tab renderer is removed' );
ok( false === strpos( $src, 'apply_reading_time_cleanup' ), 'the destructive form button is removed' );
$tabs = (string) file_get_contents( __DIR__ . '/../inc/admin-tabs-data.php' );
ok( false === strpos( $tabs, "'reading-time'" ), 'the Content leaf is removed from the registry' );
$handler = (string) file_get_contents( __DIR__ . '/../inc/admin-post-handler.php' );
ok( false === strpos( $handler, 'apply_reading_time_cleanup' ), 'the POST action is removed from the handler map' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
