<?php
/**
 * OpenStation 1.1.16 stacks a server-painted table on a phone itself:
 * `<os-table stack-on-phone>` (WordPress/openstation #981, which closed our
 * #900). From that version the kit emits the native attribute and the
 * dashboard no longer loads the assets/os-kit-stack.js shim; below it, or
 * with OpenStation absent, the shim path stays exactly as it was
 * (tests/kit-table-slot-cells.php and tests/openstation-host.php pin that
 * path, since neither defines OPENSTATION_VERSION).
 *
 * Run: php tests/kit-table-stack-on-phone.php
 */

define( 'OPENSTATION_VERSION', '1.1.17' );
require_once __DIR__ . '/lib/os-leaf-harness.php';
if ( ! function_exists( 'snt_os_host_asset_handles' ) ) {
	require_once dirname( __DIR__ ) . '/inc/openstation-host-assets.php';
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "The version gate\n";
ok( snt_os_native_stack_on_phone(), 'OpenStation 1.1.17 (defined here) stacks natively' );
ok( snt_os_native_stack_on_phone( '1.1.16' ), '1.1.16, the release that shipped it, does' );
ok( ! snt_os_native_stack_on_phone( '1.1.15' ) && ! snt_os_native_stack_on_phone( '1.1.11' ), 'older versions do not' );
ok( ! snt_os_native_stack_on_phone( '' ), 'no OpenStation: the shim path' );

echo "\nThe table on 1.1.16 or newer\n";
$cols = array( array( 'key' => 'name', 'label' => 'Name' ) );
$on   = snt_kit_table( $cols, array( array( 'name' => 'a' ) ), array( 'stack_on_phone' => true ) );
ok( 1 === preg_match( '/<os-table\b[^>]*\sstack-on-phone(?:[\s=>])/', $on ), 'stack_on_phone emits the native attribute' );
ok( false === strpos( $on, 'data-snt-stack-on-phone' ), '...and not the shim marker' );
$bare = snt_kit_table( $cols, array( array( 'name' => 'a' ) ) );
ok( 1 === preg_match( '/<os-table\b[^>]*\sstack-on-phone(?:[\s=>])/', $bare ), 'a table that does not say is cards on a phone too (the default, 2026-10-25)' );
$off = snt_kit_table( $cols, array( array( 'name' => 'a' ) ), array( 'stack_on_phone' => false ) );
ok( false === strpos( $off, 'stack-on-phone' ), "'stack_on_phone' => false keeps the grid: neither marker" );

echo "\nThe dashboard's scripts on 1.1.16 or newer\n";
$scripts = snt_os_host_asset_handles( 'sn-dashboard' )['scripts'] ?? array();
ok( in_array( 'snt-os-kit', $scripts, true ) && ! in_array( 'snt-os-kit-stack', $scripts, true ), 'the shim is not loaded: OpenStation does the stacking' );
ok( array_values( $scripts ) === $scripts && ! in_array( '', $scripts, true ), 'the handle list stays a clean list' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
