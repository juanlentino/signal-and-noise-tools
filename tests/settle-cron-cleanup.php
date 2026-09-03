<?php
/**
 * Removing a cron HANDLER does not remove its scheduled EVENTS.
 *
 * v13.87.1 shipped `snt_cf_settle_manual_purge`; v13.87.2 removed the module.
 * Any site that ran the former for an hour can hold a pending event that now
 * fires into nothing on every sweep — and this plugin reports exactly that as
 * an orphaned cron, so the removal would have shown up on the dashboard as a
 * defect of its own.
 *
 * @since 13.87.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__opts']    = array();
$GLOBALS['__cleared'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $auto = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function wp_clear_scheduled_hook( $h ) { $GLOBALS['__cleared'][] = $h; }

require __DIR__ . '/../inc/settle-cron-cleanup.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "settle cron cleanup (v13.87.2)\n\n";

snt_settle_cron_cleanup_maybe_run();
ok( array( SN_SETTLE_RETIRED_HOOK ) === $GLOBALS['__cleared'], 'the retired hook is cleared on first run' );
ok( '13.87.2' === ( $GLOBALS['__opts']['snt_settle_cron_cleaned'] ?? '' ), 'and a sentinel is stamped' );

// Idempotent: this runs on admin_init, i.e. constantly.
$GLOBALS['__cleared'] = array();
snt_settle_cron_cleanup_maybe_run();
ok( array() === $GLOBALS['__cleared'], 'a second run does nothing — the sentinel holds' );

// A site that never ran v13.87.1 must not error; wp_clear_scheduled_hook is a
// no-op there, and the sentinel is stamped either way.
$GLOBALS['__opts'] = array();
$GLOBALS['__cleared'] = array();
snt_settle_cron_cleanup_maybe_run();
ok( array( SN_SETTLE_RETIRED_HOOK ) === $GLOBALS['__cleared'] && isset( $GLOBALS['__opts']['snt_settle_cron_cleaned'] ),
	'an install that never scheduled it still stamps, so this never runs twice' );

// The hook name must match what v13.87.1 actually registered, or the cleanup
// clears nothing and the orphan survives — the failure mode is silent.
ok( 'snt_cf_settle_manual_purge' === SN_SETTLE_RETIRED_HOOK,
	'the retired hook name is the one v13.87.1 registered — a typo here clears nothing, silently' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
