<?php
/** CLI-only actual IndexNow/Performance painters, with deterministic reader data. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require dirname( __DIR__ ) . '/lib/os-leaf-harness.php';
function sn_indexnow_is_enabled() { return true; }
function sn_indexnow_key_url() {
	return 'https://example.test/' . str_repeat( 'long-path/', 8 ) . '0123456789abcdef0123456789abcdef.txt';
}
define( 'SN_INDEXNOW_RESULT_OPT', 'fixture_indexnow' );
$leaf = $argv[1] ?? 'connections-indexnow';
if ( ! in_array( $leaf, array( 'connections-indexnow', 'site-performance' ), true ) ) { exit( 1 ); }
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/' . $leaf . '.php';
list( $tab, $sub ) = explode( '-', $leaf, 2 );
echo '<div class="snt-app" data-os-app="sn-dashboard" data-snt-tab="' . esc_attr( $tab ) . '" data-snt-layout="dashboard"><div class="snt-dashboard-body"><div class="snt-leaf">';
echo snt_leaf_paint( $tab, $sub );
echo '</div></div></div>';
