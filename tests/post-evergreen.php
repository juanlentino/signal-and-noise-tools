<?php
/**
 * Tests for B5 — the per-post evergreen flag: the accessor and the Posts
 * list-table indicator column. (Meta registration/save live in post-settings.php;
 * the health-check exclusion is raw SQL, exercised via the live-WP smoke.)
 * Run: php tests/post-evergreen.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
$GLOBALS['__meta'] = array();
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function __( $s, $d = null ) { return $s; }
function add_filter() {}
function add_action() {}

require_once __DIR__ . '/../inc/post-evergreen.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "B5 evergreen flag\n\n";

// ── accessor ──
$GLOBALS['__meta'] = array( 5 => array( '_sn_evergreen' => '1' ), 6 => array() );
ok( true === sn_post_is_evergreen( 5 ), 'accessor: reads the _sn_evergreen meta as a bool true' );
ok( false === sn_post_is_evergreen( 6 ), 'accessor: missing meta → false' );
ok( false === sn_post_is_evergreen( 999 ), 'accessor: unknown post → false' );

// ── list-table column registration (inserts, preserves the rest) ──
$cols = array( 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' );
$out  = sn_evergreen_add_column( $cols );
ok( isset( $out['sn_evergreen'] ), 'column: adds the Evergreen column' );
ok( array_keys( $out ) === array( 'title', 'author', 'sn_evergreen', 'date' ), 'column: inserts just before Date, keeps the rest in order' );

// column when Date is absent → appended at the end.
$out2 = sn_evergreen_add_column( array( 'title' => 'Title' ) );
ok( array_keys( $out2 ) === array( 'title', 'sn_evergreen' ), 'column: appends when there is no Date column' );

// ── column cell markup ──
$on  = sn_evergreen_column_html( true );
$off = sn_evergreen_column_html( false );
ok( strpos( $on, 'Evergreen' ) !== false && strpos( $on, 'sn-pill' ) !== false, 'cell: flagged post renders an Evergreen pill' );
ok( strpos( $off, 'Evergreen' ) === false, 'cell: unflagged post renders no pill (a muted dash)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
