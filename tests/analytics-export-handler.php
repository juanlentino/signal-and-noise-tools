<?php
/**
 * Tests: analytics_export is registered in the admin-post handler map.
 * Run: php tests/analytics-export-handler.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

// admin-post-handler.php calls add_action at parse time — stub it.
function add_action( $h, $c = null, $p = 10, $a = 1 ) {}

// sn_admin_post_handlers() calls sn_admin_pages() for the slug allowlist — stub it.
function sn_admin_pages() {
	return array(
		array( 'slug' => 'sn-theme-options' ),
		array( 'slug' => 'sn-analytics' ),
	);
}

// sn_handle_admin_post() references sn_admin_top_tabs() and sn_admin_legacy_redirect_map()
// at runtime (never at load), so no stub needed for this test.

require __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "\nGroup: analytics_export handler registration\n";
$map = sn_admin_post_handlers();
ok( isset( $map['analytics_export'] ), 'analytics_export is in the handler allowlist' );
ok( $map['analytics_export'] === 'sn_handle_analytics_export', 'maps to the handler fn name' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
