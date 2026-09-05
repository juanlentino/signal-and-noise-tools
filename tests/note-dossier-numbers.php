<?php
/**
 * Standalone test: inc/note-dossier-numbers.php — the numbers block for one
 * note: views and visits over the window, Search Console in the sync's own
 * window, and the honest machine-reads line. Every reader is a stub.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7 ) ), 8 => new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ) );

function sn_analytics_post_path( $id ) { return '/notes/foo/'; }
function snt_analytics_range_dates( $days ) { return array( '2026-08-07', '2026-09-05' ); }
function sn_analytics_path_window( $path, $from, $to ) { $GLOBALS['__win_args'] = array( $path, $from, $to ); return $GLOBALS['__win']; }
function snt_gsc_data() { return $GLOBALS['__gsc']; }
function sn_path_join_key( $u ) { return '/notes/foo'; }
function snt_gsc_metrics_for_path( $k ) { return $GLOBALS['__gsc_row']; }
function snt_gsc_window_totals() { return $GLOBALS['__gsc_tot']; }
function snt_mr_snapshot() { return $GLOBALS['__snap']; }
function snt_mr_snapshot_total( $s ) { return is_array( $s ) && isset( $s['captured_at'] ) ? (int) $s['total'] : null; }
function snt_analytics_page_url( $args = array() ) { return 'https://example.test/wp-admin/admin.php?page=sn-analytics&' . http_build_query( $args ); }
function snt_desktop_admin_url( $slug, $sub = '' ) { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&slug=' . $slug . '&sub=' . $sub; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-numbers.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function tile( $block, $label ) { foreach ( $block['tiles'] as $t ) { if ( $t['label'] === $label ) { return $t; } } return null; }
echo "note dossier -- numbers\n\n";

$GLOBALS['__win']     = array( 'views' => 312, 'visits' => 187, 'days' => 9, 'site_rows' => 40 );
$GLOBALS['__gsc']     = array( 'window' => array( 'start' => '2026-08-05', 'end' => '2026-09-01' ), 'synced_at' => 1788600000, 'pages' => array() );
$GLOBALS['__gsc_row'] = array( 'clicks' => 4, 'impressions' => 120, 'position' => 8.4, 'ctr' => 0.0333 );
$GLOBALS['__gsc_tot'] = array( 'clicks' => 40, 'impressions' => 1200, 'days' => 28, 'capped' => false );
$GLOBALS['__snap']    = array( 'captured_at' => 1788600000, 'total' => 72597, 'days' => 30 );

$b = sn_note_dossier_numbers( 7, 30 );
ok( 2 === count( $b ) && 'stats' === $b[0]['kind'] && 'numbers' === $b[0]['group'] && 'status' === $b[1]['kind'], 'two blocks: the tiles, then the machine-reads line' );
ok( array( '/notes/foo/', '2026-08-07', '2026-09-05' ) === $GLOBALS['__win_args'], 'the window read gets the note path and the days as dates' );
ok( '312' === tile( $b[0], 'Views' )['value'] && '30 days' === tile( $b[0], 'Views' )['window'] && '187' === tile( $b[0], 'Visits' )['value'], 'views and visits, each naming the 30-day window' );
ok( '120' === tile( $b[0], 'Impressions' )['value'] && '4' === tile( $b[0], 'Clicks' )['value'] && false !== strpos( tile( $b[0], 'Impressions' )['window'], '2026-08-05' ) && false !== strpos( tile( $b[0], 'Clicks' )['note'], '8.4' ), 'Search Console tiles name the SYNC window, not the switch, and carry the position' );
ok( false !== strpos( $b[0]['door']['url'], 'sn_view=content' ) && false !== strpos( $b[0]['door']['url'], 'sn_range=30' ), 'the door lands on the Analytics content view for the window' );
ok( 'neutral' === $b[1]['tone'] && false !== strpos( $b[1]['text'], 'Not counted per note' ) && false !== strpos( $b[1]['meta'], '72,597' ) && false !== strpos( $b[1]['door']['url'], 'machine-readers' ), 'machine reads: not counted per note, the site-wide figure named as such, a door to the leaf' );

echo "\nthe honest zeros\n";
$GLOBALS['__win'] = array( 'views' => 0, 'visits' => 0, 'days' => 0, 'site_rows' => 40 );
$b = sn_note_dossier_numbers( 7, 7 );
ok( '0' === tile( $b[0], 'Views' )['value'] && '7 days' === tile( $b[0], 'Views' )['window'], 'no rows for this note while the site has rows: a real zero' );
$GLOBALS['__win'] = array( 'views' => 0, 'visits' => 0, 'days' => 0, 'site_rows' => 0 );
$b = sn_note_dossier_numbers( 7, 90 );
ok( '—' === tile( $b[0], 'Views' )['value'] && false !== strpos( tile( $b[0], 'Views' )['note'], 'no analytics' ), 'no rows anywhere in the window: not a zero, a note' );
$GLOBALS['__win'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( '—' === tile( $b[0], 'Views' )['value'] && false !== strpos( tile( $b[0], 'Views' )['note'], 'could not be read' ), 'a failed read is named, never a zero' );
$GLOBALS['__gsc_row'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( '—' === tile( $b[0], 'Impressions' )['value'] && false !== strpos( tile( $b[0], 'Impressions' )['note'], 'not shown' ), 'no row for this note: Google did not show it in the window' );
$GLOBALS['__gsc_tot'] = array( 'clicks' => 40, 'impressions' => 1200, 'days' => 28, 'capped' => true );
$b = sn_note_dossier_numbers( 7, 30 );
ok( false !== strpos( tile( $b[0], 'Impressions' )['note'], 'top 250' ), 'when the sync is capped, absence may be truncation and says so' );
$GLOBALS['__gsc'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( false !== strpos( tile( $b[0], 'Impressions' )['note'], 'never synced' ), 'Search Console never synced is its own sentence' );
$GLOBALS['__snap'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( false !== strpos( $b[1]['meta'], 'No site-wide measurement' ), 'no snapshot: no site-wide figure, said so' );
ok( array() === sn_note_dossier_numbers( 8, 30 ), 'a draft has no numbers: no URL a reader reaches' );
ok( array() === sn_note_dossier_numbers( 999, 30 ), 'no post, no blocks' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
