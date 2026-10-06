<?php
/**
 * Tests for inc/post-rest-fields.php: the sn_provenance and sn_edge REST
 * fields the OpenStation Posts window reads. Contracts ported verbatim from
 * tests/desktop-mode-explorer.php when the inert Explorer module was removed
 * (2026-10-06).
 * Run: php tests/post-rest-fields.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__actions'] = array();
function add_action( $hook, $cb ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function __( $t, $d = null ) { return $t; }
$GLOBALS['__rest_fields'] = array();
function register_rest_field( $type, $name, $args = array() ) { $GLOBALS['__rest_fields'][ $type . ':' . $name ] = $args; }
$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }
$GLOBALS['__caps'] = array();
function current_user_can( $cap ) { return $GLOBALS['__caps'][ $cap ] ?? true; }
$GLOBALS['__meta'] = array();
$GLOBALS['__meta_writes'] = array();
function get_post_meta( $post_id, $key = '', $single = false ) { return $GLOBALS['__meta'][ $post_id ][ $key ] ?? ''; }
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['__meta_writes'][] = array( $post_id, $key ); return true; }
$GLOBALS['__opts'] = array();
function get_option( $name, $default = false ) { return $GLOBALS['__opts'][ $name ] ?? $default; }
$GLOBALS['__is_note'] = array();
function sn_prov_is_note( $post_id ) { return ! empty( $GLOBALS['__is_note'][ $post_id ] ); }
$GLOBALS['__chains'] = array();
function sn_prov_get_chain( $post_id ) { return $GLOBALS['__chains'][ $post_id ] ?? array(); }

require __DIR__ . '/../inc/post-rest-fields.php';
const SN_CF_PROBE_LOG_OPT = 'sn_cf_purge_probe_log';
const SN_CF_PROBE_ALGO    = 2;
require __DIR__ . '/../inc/note-dossier-state.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

foreach ( $GLOBALS['__actions']['rest_api_init'] ?? array() as $cb ) { $cb(); }

echo "Group: registration\n";
ok( array() === $GLOBALS['__routes'], 'no REST route: the /desktop/discography route went with the Explorer it served' );
$field = $GLOBALS['__rest_fields']['post:sn_provenance'] ?? null;
ok( is_array( $field ) && 'snt_post_provenance_field' === ( $field['get_callback'] ?? null ), 'the sn_provenance REST field is registered on post' );
$edge_field = $GLOBALS['__rest_fields']['post:sn_edge'] ?? null;
ok( is_array( $edge_field ) && 'snt_post_edge_field' === ( $edge_field['get_callback'] ?? null ), 'the sn_edge REST field is registered on post' );
ok( in_array( 'view', $edge_field['schema']['context'] ?? array(), true ), 'sn_edge rides the VIEW context: the Posts window sends no context arg, so edit-only would never ship' );
$posts_fields = (string) file_get_contents( __DIR__ . '/../inc/openstation-preferences.php' );
ok( false !== strpos( $posts_fields, "'sn_provenance', 'sn_edge'" ), 'the Posts window still asks for both fields (SNT_OS_POSTS_FIELDS)' );

echo "\nGroup: sn_edge\n";
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'time' => 2000, 'post_id' => 21, 'url' => 'https://x.test/b', 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
	array( 'time' => 1500, 'post_id' => 22, 'url' => 'https://x.test/c', 'result' => 'fresh', 'algo' => 1 ),
	array( 'time' => 1000, 'post_id' => 21, 'url' => 'https://x.test/b', 'result' => 'fresh', 'algo' => 2 ),
);
ok( null === snt_post_edge_field( array( 'id' => 20 ) ), 'a post with no probe row yields null: a gap, never fresh' );
ok( array( 'state' => 'stale', 'verified_at' => 2000, 'escalated' => true ) === snt_post_edge_field( array( 'id' => 21 ) ), 'the NEWEST current-detector row wins' );
ok( null === snt_post_edge_field( array( 'id' => 22 ) ), 'a retired-detector row (algo 1) is not a verdict' );
$GLOBALS['__caps'] = array( 'manage_options' => false );
ok( null === snt_post_edge_field( array( 'id' => 21 ) ), 'sn_edge is NOT public: a reader without manage_options gets null' );
$GLOBALS['__caps'] = array();
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = 'not-an-array';
ok( null === snt_post_edge_field( array( 'id' => 21 ) ), 'a corrupt log yields null, not a notice' );
unset( $GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] );

echo "\nGroup: sn_provenance\n";
ok( null === snt_post_provenance_field( array( 'id' => 10 ) ), 'a non-Note post yields null' );
$GLOBALS['__is_note'][11] = true;
ok( null === snt_post_provenance_field( array( 'id' => 11 ) ), 'a Note with no chain yields null: "unsigned" is not "signed zero times"' );
$GLOBALS['__is_note'][12] = true;
$GLOBALS['__meta'][12]['_sn_prov_uid'] = 'uuid-12';
$GLOBALS['__chains'][12] = array(
	array( 'version' => 0, 'status' => 'genesis', 'committed_at' => '2026-01-01T00:00:00Z', 'content_hash' => 'aaa' ),
	array( 'version' => 1, 'status' => 'confirmed', 'committed_at' => '2026-01-02T00:00:00Z', 'content_hash' => 'bbb' ),
	array( 'version' => 2, 'status' => 'confirmed', 'committed_at' => '2026-01-03T00:00:00Z', 'content_hash' => 'ccc' ),
	array( 'version' => 3, 'status' => 'pending', 'committed_at' => '2026-01-04T00:00:00Z', 'content_hash' => 'ddd' ),
);
$prov = snt_post_provenance_field( array( 'id' => 12 ) );
ok( 3 === $prov['versions'], 'versions reports the chain head version (genesis v0 does not inflate it)' );
ok( 'pending' === $prov['status'], 'status is the NEWEST commit\'s status' );
ok( 2 === $prov['anchored'], 'anchored counts confirmed commits only' );
ok( 'uuid-12' === $prov['uid'], 'the ledger UID rides along when present' );
ok( 4 === count( $prov['commits'] ) && 'ddd' === $prov['commits'][3]['content_hash'], 'commits preserve order, newest last, with their hashes' );
$GLOBALS['__is_note'][13] = true;
$long = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$long[] = array( 'version' => $i, 'status' => 'confirmed', 'committed_at' => '2026-01-01T00:00:00Z', 'content_hash' => 'h' . $i );
}
$GLOBALS['__chains'][13] = $long;
$prov = snt_post_provenance_field( array( 'id' => 13 ) );
ok( 20 === count( $prov['commits'] ) && 11 === $prov['commits'][0]['version'] && 30 === $prov['commits'][19]['version'], 'the commit list is capped at the NEWEST 20' );
ok( 30 === $prov['versions'], 'the cap is visible, not silent: the head version survives it' );
$GLOBALS['__is_note'][14] = true;
$GLOBALS['__chains'][14] = array( array( 'version' => 1, 'status' => 'unanchored', 'committed_at' => '2026-02-01T00:00:00Z', 'content_hash' => 'eee' ) );
$prov = snt_post_provenance_field( array( 'id' => 14 ) );
ok( null === $prov['uid'], 'a Note without a persisted UID reports null: no fabricated key' );
ok( array() === $GLOBALS['__meta_writes'], 'NO MINTING ON READ: the field never writes post meta' );

echo "\nGroup: the Explorer is gone\n";
ok( ! file_exists( __DIR__ . '/../inc/desktop-mode-explorer.php' ) && ! file_exists( __DIR__ . '/../assets/desktop-mode-explorer.js' ), 'the inert Explorer module and its script are removed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
