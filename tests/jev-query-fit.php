<?php
/**
 * Query-to-page fit (16.5.0): grouping page × query rows by note with the
 * impressions floor and the per-note cap, the state, one Score per query,
 * the judge, the two readings, the pass over a fake transport, the two
 * abilities, the registries, the band's three states. Run: php tests/jev-query-fit.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['__f'] = array( 'opt' => array(), 'posts' => array(), 'calls' => array(), 'answer' => null, 'actions' => array(), 'abilities' => array(), 'scheduled' => array(), 'gsc' => array(), 'ready' => true, 'out' => '' );
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_url( $s ) { return str_replace( '&', '&amp;', (string) $s ); }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, $dec ); }
function human_time_diff( $a, $b ) { return '1 hour'; }
function get_edit_post_link( $id ) { return "https://x.test/wp-admin/post.php?post=$id&action=edit"; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__f']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__f']['opt'] ) ? $GLOBALS['__f']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__f']['opt'][ $k ] = $v; return true; }
function sn_setting( $path, $d = null ) { return 'search_console.property' === $path ? 'sc-domain:x.test' : $d; }
function get_posts( $a ) { return array_keys( $GLOBALS['__f']['posts'] ); }
function get_post( $id ) { return $GLOBALS['__f']['posts'][ $id ] ?? null; }
function get_permalink( $id ) { return "https://x.test/notes/n$id/"; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function strip_shortcodes( $s ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_next_scheduled( $h ) { return $GLOBALS['__f']['scheduled'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__f']['scheduled'][ $h ] = $r; return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__f']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sn_jev_is_ready() { return $GLOBALS['__f']['ready']; }
function snt_gsc_sync_is_ready() { return $GLOBALS['__f']['ready']; }
function snt_gsc_window( $d = 28, $l = 3 ) { return array( 'start' => '2026-08-21', 'end' => '2026-09-15' ); }
function snt_gsc_query( $property, $dims, $window, $limit = 250 ) { $GLOBALS['__f']['gsc_call'] = compact( 'property', 'dims', 'limit' ); return $GLOBALS['__f']['gsc']; }
function sn_jev_note_ids() { return array_keys( $GLOBALS['__f']['posts'] ); }
function sn_jev_ask( $state, array $questions, $key = null ) { $GLOBALS['__f']['calls'][] = compact( 'state', 'questions' ); $a = $GLOBALS['__f']['answer']; return is_callable( $a ) ? $a( $state, $questions ) : $a; }
function snt_an_panel_open( $t, $a = array() ) { $GLOBALS['__f']['out'] .= "<panel:$t>"; }
function snt_an_panel_close() { $GLOBALS['__f']['out'] .= '</panel>'; }
function snt_an_clamp_open( $n, $v = 5 ) { $GLOBALS['__f']['out'] .= "<clamp $n/$v>"; }
function snt_an_clamp_close( $n, $v = 5 ) { $GLOBALS['__f']['out'] .= '</clamp>'; }
function mkpost( $id, $title, $content = 'Body of the note.' ) { $p = new stdClass(); $p->ID = $id; $p->post_type = 'post'; $p->post_status = 'publish'; $p->post_title = $title; $p->post_content = $content; $p->post_excerpt = "About $title."; $GLOBALS['__f']['posts'][ $id ] = $p; return $p; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
function grow( $keys, $imp, $clk = 0 ) { return array( 'key' => $keys[0], 'keys' => $keys, 'clicks' => $clk, 'impressions' => $imp, 'ctr' => 0, 'position' => 5.0 ); }

require __DIR__ . '/../inc/search-console-store.php';
require __DIR__ . '/../inc/jev-query-fit.php';
require __DIR__ . '/../inc/abilities-jev.php';
require __DIR__ . '/../inc/analytics-view-search-fit.php';
foreach ( $GLOBALS['__f']['actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

// A: grouping.
mkpost( 1, 'One' ); mkpost( 2, 'Two' );
$map = sn_jev_fit_path_map();
ok( array( '/notes/n1' => 1, '/notes/n2' => 2 ) === $map, 'A1 path map from permalinks, trailing slash dropped' );
$rows = array();
for ( $i = 1; $i <= 10; $i++ ) { $rows[] = grow( array( 'https://x.test/notes/n1/', "q$i" ), 20 + $i, $i ); }
$rows[] = grow( array( 'https://x.test/notes/n1/', 'tiny' ), 19, 5 );
$rows[] = grow( array( 'https://x.test/notes/n2/', 'two only' ), 40 );
$rows[] = grow( array( 'https://x.test/about/', 'a page' ), 500 );
$rows[] = array( 'key' => 'https://x.test/notes/n2/', 'clicks' => 1, 'impressions' => 99 ); // one-dimension row, no keys
$by = sn_jev_fit_group( $rows, $map );
ok( array( 1, 2 ) === array_keys( $by ), 'A2 grouped by note; a non-note path is dropped' );
ok( SN_JEV_FIT_QUERIES_PER_NOTE === count( $by[1] ) && 'q10' === $by[1][0]['query'] && 'q3' === $by[1][7]['query'], 'A3 top eight by impressions, desc' );
ok( ! in_array( 'tiny', array_column( $by[1], 'query' ), true ), 'A4 under 20 impressions is dropped' );
ok( 1 === count( $by[2] ) && 'two only' === $by[2][0]['query'], 'A5 a row without keys is ignored' );

// B: state and questions.
$st = sn_jev_fit_state( get_post( 2 ), $by[2] );
ok( 'Two' === $st['note']['title'] && 'About Two.' === $st['note']['description'] && 'Body of the note.' === $st['note']['opening'] && array( 'q1' => 'two only' ) === $st['queries'], 'B1 state: note + queries keyed qN' );
$qs = sn_jev_fit_questions( $by[1] );
ok( 8 === count( $qs ) && 'score' === $qs['q1']['type'] && 3 === count( $qs['q1']['criteria'] ) && str_contains( $qs['q8']['instructions']['question'], 'queries.q8' ), 'B2 one three-level score per query' );

// C: judge and readings.
$ans = array( 'q1' => array( 'type' => 'score', 'score' => 1.7, 'confidence' => 0.8, 'probabilities' => array() ) );
$j = sn_jev_fit_judge( $ans, $by[2] );
ok( 1 === count( $j ) && 'two only' === $j[0]['query'] && 1.7 === $j[0]['score'] && 0.8 === $j[0]['confidence'] && 40 === $j[0]['impressions'], 'C1 judge joins the answer to its query' );
ok( array() === sn_jev_fit_judge( array( 'q1' => array( 'type' => 'noul', 'noul' => 1 ) ), $by[2] ), 'C2 a non-score answer is skipped' );
$data = array( 'notes' => array(
	1 => array( 'title' => 'One', 'path' => '/notes/n1', 'rows' => array(
		array( 'query' => 'gap big', 'clicks' => 0, 'impressions' => 300, 'score' => 0.8, 'confidence' => 0.5 ),
		array( 'query' => 'stray', 'clicks' => 4, 'impressions' => 100, 'score' => 0.2, 'confidence' => 0.9 ),
		array( 'query' => 'fine', 'clicks' => 9, 'impressions' => 900, 'score' => 1.9, 'confidence' => 0.9 ),
	) ),
	2 => array( 'title' => 'Two', 'path' => '/notes/n2', 'rows' => array( array( 'query' => 'edge', 'clicks' => 2, 'impressions' => 50, 'score' => 1.0, 'confidence' => 0.7 ) ) ),
) );
$r = sn_jev_fit_readings( $data );
ok( array( 'gap big', 'stray' ) === array_column( $r['gaps'], 'query' ), 'C3 gaps: score under 1, by impressions desc; 1.0 exactly is not a gap' );
ok( array( 'stray' ) === array_column( $r['stray'], 'query' ) && 'One' === $r['stray'][0]['note'] && 1 === $r['stray'][0]['id'], 'C4 stray: under 0.5 with clicks, carrying the note' );

// D: the pass.
$GLOBALS['__f']['gsc'] = $rows;
$GLOBALS['__f']['answer'] = function ( $state, $q ) { $a = array(); foreach ( array_keys( $q ) as $k ) { $a[ $k ] = array( 'type' => 'score', 'score' => 0.5, 'confidence' => 0.6, 'probabilities' => array() ); } return array( 'ok' => true, 'code' => 200, 'answers' => $a, 'usage' => array( 'input_tokens' => 3000 ), 'error' => '' ); };
$s = sn_jev_fit_sync();
ok( $s['ok'] && 2 === $s['judged'] && 9 === $s['queries'] && 2 === count( $GLOBALS['__f']['calls'] ), 'D1 one request per note with queries; 8 + 1 queries' );
ok( array( 'page', 'query' ) === $GLOBALS['__f']['gsc_call']['dims'] && SN_JEV_FIT_ROW_LIMIT === $GLOBALS['__f']['gsc_call']['limit'], 'D2 the GSC read is page × query at the fit row limit' );
$d = sn_jev_fit_data();
ok( 6000 === $d['usage']['input_tokens'] && '/notes/n1' === $d['notes'][1]['path'] && 8 === count( $d['notes'][1]['rows'] ), 'D3 stored with tokens and paths' );
$GLOBALS['__f']['gsc'] = new WP_Error( 'x', 'quota' );
$s2 = sn_jev_fit_sync();
ok( ! $s2['ok'] && 'quota' === $s2['error'] && 2 === count( sn_jev_fit_data()['notes'] ) && str_contains( sn_jev_fit_data()['last_error'], 'quota' ), 'D4 a failed GSC read keeps the previous pass and records the error' );
$GLOBALS['__f']['ready'] = false;
ok( 'not-ready' === sn_jev_fit_sync()['error'], 'D5 not ready, no read' );
$GLOBALS['__f']['ready'] = true;

// E: abilities and the schedule.
$ab = $GLOBALS['__f']['abilities'];
ok( false === $ab['signal-noise/jev-fit-now']['meta']['annotations']['readonly'] && true === $ab['signal-noise/jev-query-fit']['meta']['annotations']['readonly'], 'E1 fit-now writes, query-fit reads' );
$out = snt_ability_jev_query_fit();
ok( $out['judged'] && 2 === $out['notes'] && 9 === count( $out['gaps'] ) && 0 === count( $out['stray'] ), 'E2 query-fit: every 0.5 row is a gap; none is stray (0.5 is not under 0.5)' );
ok( 'weekly' === ( function () { foreach ( $GLOBALS['__f']['actions']['init'] as $cb ) { $cb(); } return $GLOBALS['__f']['scheduled'][ SN_JEV_FIT_HOOK ] ?? ''; } )(), 'E3 weekly schedule when ready' );

// F: the band's three states.
function band() { $GLOBALS['__f']['out'] = ''; ob_start(); snt_analytics_render_search_fit(); $GLOBALS['__f']['out'] .= ob_get_clean(); }
$GLOBALS['__f']['ready'] = false; band();
ok( str_contains( $GLOBALS['__f']['out'], 'Needs a TypeSafe key' ), 'F1 not ready names the two credentials' );
$GLOBALS['__f']['ready'] = true; $GLOBALS['__f']['opt'] = array(); band();
ok( str_contains( $GLOBALS['__f']['out'], 'jev-fit-now' ), 'F2 nothing synced names the ability' );
$GLOBALS['__f']['opt'][ SN_JEV_FIT_OPTION ] = array_merge( $data, array( 'synced_at' => time(), 'usage' => array(), 'last_error' => '' ) );
band(); $o = $GLOBALS['__f']['out'];
ok( str_contains( $o, '<panel:Query fit (Jev)>' ) && str_contains( $o, 'Gaps: seen, not answered' ) && str_contains( $o, '<code>gap big</code>' ) && str_contains( $o, 'Stray traffic' ) && 2 === substr_count( $o, '<code>stray</code></td>' . '<td><a href="https://x.test/wp-admin/post.php?post=1&amp;action=edit">One</a>' ) , 'F3 one panel, two tables (stray sits in both), the note links to its editor' );
ok( str_contains( $o, '2 notes, 4 queries' ), 'F4 the summary counts notes and queries' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
