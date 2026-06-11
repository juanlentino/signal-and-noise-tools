<?php
/**
 * v5.0.0: one-time removal of the orphaned `sn_login_rewrites_flushed` option.
 *
 * Mirrors the sn_webhooks_migrate_autoload pattern (sentinel-gated admin_init
 * migration): runs once, deletes the DB orphan, sets a sentinel, idempotent
 * on re-run. Self-contained option-store stubs (no WP load).
 *
 * @since 5.0.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
$GLOBALS['__test_options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__test_options'][ $k ] ); return true; }
function add_action() {}

$pass = 0; $fail = 0;
function oo_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg (exp " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n"; }
}

require dirname( __DIR__ ) . '/inc/migrate-orphan-options.php';

// Seed the orphan + clear the sentinel, then run the migration.
$GLOBALS['__test_options']['sn_login_rewrites_flushed'] = 1;
unset( $GLOBALS['__test_options']['sn_orphan_options_removed_v5'] );
sn_migrate_remove_orphan_options();
oo_eq( false, array_key_exists( 'sn_login_rewrites_flushed', $GLOBALS['__test_options'] ), 'orphan option deleted' );
oo_eq( 1, $GLOBALS['__test_options']['sn_orphan_options_removed_v5'] ?? null, 'sentinel set after migration' );

// Idempotent: re-seed the orphan; with the sentinel present the migration must
// early-return and NOT delete again (proves it runs exactly once).
$GLOBALS['__test_options']['sn_login_rewrites_flushed'] = 1;
sn_migrate_remove_orphan_options();
oo_eq( true, array_key_exists( 'sn_login_rewrites_flushed', $GLOBALS['__test_options'] ), 'idempotent: sentinel gates re-run (no second delete)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
