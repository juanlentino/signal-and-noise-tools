<?php
/**
 * Unanswered searches: queries with impressions and no clicks, from the
 * synced query x page rows (inc/search-console-unanswered.php).
 *
 * Pins the filter (aggregate across pages, then 0 clicks and the floor), the
 * sort, the shown page and its weighted position, the cap line, the four
 * states, the sync wiring (a failed query x page read keeps the window), the
 * search-performance key, both leaves, and that no model call path is
 * reachable from any of it.
 *
 * Run: php tests/search-console-unanswered.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
$GLOBALS['__opts'] = array();
function get_option( $k, $d = null ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sn_setting( $p, $d = null ) { return 'search_console.property' === $p ? 'sc-domain:x.test' : $d; }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { if ( 'wp_abilities_api_init' === $t ) { $GLOBALS['__init'] = $c; } return true; }
class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function url_to_postid( $u ) { return false !== strpos( $u, '/notes/' ) || false !== strpos( $u, '/n' ) ? 7 : 0; }
function snt_gsc_window( $days = 28, $lag = 3 ) { return array( 'start' => '2026-09-01', 'end' => '2026-09-28' ); }
$GLOBALS['__calls'] = array();
function snt_gsc_query( $property, $dimensions, $window, $row_limit = 250 ) {
	$GLOBALS['__calls'][] = array( implode( ',', $dimensions ), $row_limit );
	$r = $GLOBALS['__rows'][ implode( ',', $dimensions ) ] ?? array();
	return $r;
}

require __DIR__ . '/../inc/search-console-store.php';
require __DIR__ . '/../inc/search-console-unanswered.php';
require __DIR__ . '/../inc/search-console-derive.php';
require __DIR__ . '/../inc/abilities-search-console.php';

function qp( $q, $path, $clicks, $imp, $pos ) { return array( 'query' => $q, 'path' => $path, 'clicks' => $clicks, 'impressions' => $imp, 'position' => $pos ); }
function data_with( $rows, $extra = array() ) {
	return array_merge( array( 'synced_at' => 1, 'window' => array( 'start' => '2026-09-01', 'end' => '2026-09-28' ), 'pages' => array(), 'queries' => array(), 'query_pages' => $rows, 'query_pages_capped' => false, 'query_pages_error' => '' ), $extra );
}

echo "Group: the filter\n";
$u = snt_gsc_unanswered( data_with( array(
	qp( 'lost query', '/notes/a', 0, 40, 12.0 ),
	qp( 'lost query', '/', 0, 60, 7.0 ),
	qp( 'clicked elsewhere', '/notes/b', 0, 50, 9.0 ),
	qp( 'clicked elsewhere', '/notes/c', 1, 5, 3.0 ),
	qp( 'at the floor', '/notes/d', 0, SNT_GSC_UNANSWERED_FLOOR, 20.0 ),
	qp( 'under the floor', '/notes/e', 0, SNT_GSC_UNANSWERED_FLOOR - 1, 30.0 ),
	qp( 'clicked', '/notes/f', 3, 200, 2.0 ),
) ), static function ( $p ) { return '/' === $p; } );
$qs = array_column( $u['rows'], 'query' );
ok( true === snt_gsc_path_is_index( '/' ) && true === snt_gsc_path_is_index( '/tag/x' ) && false === snt_gsc_path_is_index( '/notes/a' ), 'the live predicate: the home page and a path no post resolves to are indexes; a note is not' );
ok( 'measured' === $u['state'] && array( 'lost query', 'at the floor' ) === $qs, 'only queries with 0 clicks summed across pages and impressions at or over the floor: ' . implode( '|', $qs ) );
ok( ! in_array( 'clicked elsewhere', $qs, true ), 'a query with a click on its second page is NOT unanswered (aggregate, then filter)' );
$lost = $u['rows'][0];
ok( 100 === $lost['impressions'], 'impressions sum across the pages Google showed (40 + 60)' );
ok( abs( $lost['position'] - round( ( 12.0 * 40 + 7.0 * 60 ) / 100, 1 ) ) < 0.001, 'position is impression-weighted, not a mean of means: ' . $lost['position'] );
ok( '/' === $lost['page'], 'the page shown is the one with the most impressions' );
ok( true === $lost['no_note'] && false === $u['rows'][1]['no_note'], 'shown on the home or an index page: no_note; shown on a note: not' );
ok( SNT_GSC_UNANSWERED_FLOOR === $u['floor'] && 5 === SNT_GSC_UNANSWERED_FLOOR, 'the floor is 5 and the payload states it' );
$no_pred = snt_gsc_unanswered( data_with( array( qp( 'x', '/', 0, 9, 1.0 ) ) ) );
ok( false === $no_pred['rows'][0]['no_note'], 'no predicate marks nothing (never a guess)' );

echo "\nGroup: the sort\n";
$s = snt_gsc_unanswered( data_with( array(
	qp( 'beta', '/n/1', 0, 10, 5.0 ),
	qp( 'gamma', '/n/2', 0, 90, 5.0 ),
	qp( 'alpha', '/n/3', 0, 10, 5.0 ),
) ) );
ok( array( 'gamma', 'alpha', 'beta' ) === array_column( $s['rows'], 'query' ), 'most impressions first, ties by query' );

echo "\nGroup: the cap\n";
$many = array();
for ( $i = 0; $i < 30; $i++ ) {
	$many[] = qp( sprintf( 'q%02d', $i ), '/n/' . $i, 0, 100 - $i, 10.0 );
}
$c = snt_gsc_unanswered( data_with( $many ) );
ok( 25 === count( $c['rows'] ) && 30 === $c['total'] && 5 === $c['more'] && 25 === $c['cap'], 'capped at 25, total 30, more 5' );
ok( false !== strpos( $c['note'], '+5 more; the list is capped, not complete.' ), 'the cap line says so: ' . $c['note'] );
$exact = snt_gsc_unanswered( data_with( array_slice( $many, 0, 25 ) ) );
ok( 0 === $exact['more'] && false === strpos( $exact['note'], 'capped' ), 'exactly 25: no cap line' );
$fc = snt_gsc_unanswered( data_with( array_slice( $many, 0, 2 ), array( 'query_pages_capped' => true ) ) );
ok( true === $fc['fetch_capped'] && false !== strpos( $fc['note'], 'top 500 query and page rows' ), 'a capped read says the sync itself was a floor' );

echo "\nGroup: the empty states\n";
$never = snt_gsc_unanswered( null );
ok( 'never_synced' === $never['state'] && 'No synced data yet.' === $never['note'] && array() === $never['rows'], 'never synced: "No synced data yet."' );
$old = data_with( array() );
unset( $old['query_pages'] );
$aw = snt_gsc_unanswered( $old );
ok( 'awaiting_sync' === $aw['state'] && 0 === strpos( $aw['note'], 'No synced data yet' ), 'a window synced before this grain: no synced data yet, rows arrive with the next sync' );
$ff = snt_gsc_unanswered( data_with( null, array( 'query_pages_error' => 'quota exceeded' ) ) );
ok( 'fetch_failed' === $ff['state'] && false !== strpos( $ff['note'], 'quota exceeded' ), 'a failed read says so, with Google\'s words' );
$none = snt_gsc_unanswered( data_with( array( qp( 'clicked', '/n', 2, 300, 1.0 ) ) ) );
ok( 'measured' === $none['state'] && 'No query with impressions and no clicks in the window.' === $none['note'], 'measured and empty: "No query with impressions and no clicks in the window."' );

echo "\nGroup: the sync stores the grain; its failure keeps the window\n";
$GLOBALS['__rows'] = array(
	'page'       => array( array( 'key' => 'https://x.test/a', 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 4.0 ) ),
	'query'      => array( array( 'key' => 'q', 'clicks' => 0, 'impressions' => 9, 'ctr' => 0.0, 'position' => 4.0 ) ),
	'query,page' => array( array( 'key' => 'q', 'keys' => array( 'q', 'https://x.test/a/' ), 'clicks' => 0, 'impressions' => 9, 'ctr' => 0.0, 'position' => 4.04 ) ),
);
$p = snt_gsc_sync();
ok( in_array( array( 'query,page', SNT_GSC_QUERY_PAGE_ROW_LIMIT ), $GLOBALS['__calls'], true ), 'the sync reads query x page at the stated row limit' );
ok( array( qp( 'q', '/a', 0, 9, 4.0 ) ) === $p['query_pages'] && false === $p['query_pages_capped'], 'stored compact, path normalized, position at the display grain' );
$GLOBALS['__rows']['query,page'] = new WP_Error( 'x', 'quota exceeded' );
$p = snt_gsc_sync();
ok( ! is_wp_error( $p ) && isset( $p['pages']['/a'] ) && null === $p['query_pages'] && 'quota exceeded' === $p['query_pages_error'], 'a failed query x page read keeps the window and records the error' );

echo "\nGroup: the read door\n";
$GLOBALS['__registered'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
require __DIR__ . '/../inc/abilities-search-unanswered.php';
$GLOBALS['__opts']['snt_gsc_data'] = data_with( array( qp( 'q', '/n', 0, 9, 4.0 ) ) );
$GLOBALS['__init']();
$def = $GLOBALS['__registered']['signal-noise/search-unanswered'] ?? array();
ok( 'snt_ability_search_unanswered' === ( $def['execute_callback'] ?? '' ) && true === $def['meta']['annotations']['readonly'] && false === $def['meta']['annotations']['destructive'], 'signal-noise/search-unanswered registers read-only' );
ok( isset( $def['output_schema']['properties']['more'], $def['output_schema']['properties']['rows'] ) && false !== strpos( $def['description'], 'never a model' ), 'the schema carries rows and more; the description says never a model' );
$run = snt_ability_search_unanswered( null );
ok( true === $run['ok'] && 'q' === $run['rows'][0]['query'], 'the ability reads the stored window' );
ok( ! array_key_exists( 'unanswered', snt_search_performance_impl( $GLOBALS['__opts']['snt_gsc_data'], null ) ), 'search-performance (and its byte-identical remote twin) keeps its shape' );
$caps = (string) file_get_contents( __DIR__ . '/../inc/mcp/mcp-capabilities.php' );
ok( false !== strpos( $caps, "'signal-noise/search-unanswered'," ), 'on the read door allowlist' );
$remote = (string) file_get_contents( __DIR__ . '/../inc/abilities-remote-set-search-parts.php' ) . (string) file_get_contents( __DIR__ . '/../inc/mcp/mcp-remote-guard.php' );
ok( false === strpos( $remote, 'search-unanswered' ), 'and not on the remote door' );

echo "\nGroup: no model call path is reachable\n";
$files = array( 'inc/search-console-unanswered.php', 'inc/abilities-search-unanswered.php', 'apps/sn-dashboard/parts/leaves/monitoring-search-console-unanswered.php' );
$allowed = array( 'array', 'array_column', 'array_key_exists', 'array_merge', 'array_slice', 'call_user_func', 'count', 'defined', 'exit', 'function_exists', 'implode', 'is_array', 'is_callable', 'is_wp_error', 'max', 'round', 'sprintf', 'strcmp', 'trim', 'usort', 'empty', 'isset', 'snt_gsc_query', 'snt_gsc_url_to_path', 'url_to_postid', 'home_url', '__', 'snt_gsc_data', 'snt_gsc_unanswered', 'snt_gsc_unanswered_live', 'snt_gsc_unanswered_intro', 'snt_kit_section', 'snt_kit_badge', 'snt_kit_status_line', 'snt_kit_code', 'snt_kit_table', 'snt_kit_esc', 'get_error_message', 'search_console_unanswered_html', 'wp_register_ability', 'add_action', 'wp_error', 'snt_ability_search_unanswered' );
$called = array();
foreach ( $files as $f ) {
	$toks = token_get_all( (string) file_get_contents( __DIR__ . '/../' . $f ) );
	foreach ( $toks as $i => $t ) {
		if ( ! is_array( $t ) || ! in_array( $t[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_EXIT, T_ARRAY, T_EMPTY, T_ISSET ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( isset( $toks[ $j ] ) && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) { $j++; }
		$prev = $i - 1;
		while ( isset( $toks[ $prev ] ) && is_array( $toks[ $prev ] ) && T_WHITESPACE === $toks[ $prev ][0] ) { $prev--; }
		if ( '(' === ( $toks[ $j ] ?? null ) && ! ( is_array( $toks[ $prev ] ?? null ) && T_FUNCTION === $toks[ $prev ][0] ) ) {
			$called[ ltrim( strtolower( $t[1] ), '\\' ) ] = true;
		}
	}
}
$extra = array_diff( array_keys( $called ), $allowed );
ok( array() === $extra, 'every call in the derive and the native card is on the allowlist (no model, no HTTP): ' . implode( ',', $extra ) );
ok( count( $called ) > 15, 'the scan saw the calls (' . count( $called ) . '), so it cannot pass vacuously' );
$src = strtolower( implode( '', array_map( static function ( $f ) { return (string) file_get_contents( __DIR__ . '/../' . $f ); }, $files ) ) );
$classic = (string) file_get_contents( __DIR__ . '/../inc/search-console-admin.php' );
$classic = strtolower( substr( $classic, (int) strpos( $classic, 'function snt_gsc_render_unanswered' ) ) );
foreach ( array( 'sn_jev', 'wp_remote_', 'snt_ai_', 'sn_ai_', 'anthropic', 'workers_ai', 'openai' ) as $needle ) {
	ok( false === strpos( $src . $classic, $needle ), "no `$needle` in the derive or either leaf" );
}

echo "\nGroup: both leaves paint the same words\n";
function snt_kit_esc( $s ) { return esc_html( $s ); }
function snt_kit_section( $h, $inner, $d = '' ) { return '<os-section heading="' . esc_html( $h ) . '">' . esc_html( $d ) . $inner . '</os-section>'; }
function snt_kit_badge( $k, $t ) { return '<os-badge>' . esc_html( $t ) . '</os-badge>'; }
function snt_kit_status_line( $chips, $door = '' ) { return implode( ' ', array_column( $chips, 'label' ) ); }
function snt_kit_code( $t, $b = true ) { return '<code>' . esc_html( $t ) . '</code>'; }
function snt_kit_table( $cols, $rows, $o = array() ) { $h = ''; foreach ( $rows as $r ) { $h .= esc_html( $r['query'] ) . $r['page']['html']; } return $h; }
define( 'OPENSTATION_STANDALONE', true );
require __DIR__ . '/../apps/sn-dashboard/parts/leaves/monitoring-search-console-unanswered.php';
require __DIR__ . '/../inc/search-console-admin.php';
$fixture = snt_gsc_unanswered( data_with( $many ), static function ( $p ) { return '/n/0' === $p; } );
$native  = \SignalNoise\OpenStationHost\Dashboard\Leaves\search_console_unanswered_html( $fixture );
ob_start();
snt_gsc_render_unanswered( $fixture );
$old = (string) ob_get_clean();
foreach ( array( 'Unanswered searches', 'q00', 'q24', 'No note answers this', '+5 more; the list is capped, not complete.', '25 of 30 queries', 'no model reads them' ) as $want ) {
	ok( false !== strpos( $native, $want ) && false !== strpos( $old, $want ), "both leaves paint `$want`" );
}
ok( false === strpos( $native, 'q25' ) && false === strpos( $old, 'q25' ), 'neither paints past the cap' );
foreach ( array( null, data_with( array() ) ) as $i => $d ) {
	$e = snt_gsc_unanswered( $d );
	ob_start();
	snt_gsc_render_unanswered( $e );
	$old = (string) ob_get_clean();
	$native = \SignalNoise\OpenStationHost\Dashboard\Leaves\search_console_unanswered_html( $e );
	ok( false !== strpos( $native, $e['note'] ) && false !== strpos( $old, $e['note'] ), "empty state $i painted on both leaves: {$e['note']}" );
}
ok( false === strpos( $src . (string) file_get_contents( __DIR__ . '/../tests/search-console-unanswered.php' ), "\u{2014}" ), 'no em dash in the new copy' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
