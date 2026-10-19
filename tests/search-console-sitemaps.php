<?php
/**
 * Search Console sitemaps report: read weekly with the coverage run, shown in plain words.
 *
 * Properties: (1) the API's string counts become integers and `indexed` (deprecated)
 * is never kept; (2) a failed read keeps the last good record with the error beside
 * it; (3) the view says when Google last read each sitemap, how many URLs it
 * carries, and its errors and warnings; a pending sitemap says Google has not read
 * it; (4) the coverage run reads the report at its end; (5) the ability carries it.
 *
 * Run: php tests/search-console-sitemaps.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'ABSPATH', '/' );
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
class WP_Error { public $c; public $m; public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } public function get_error_code() { return $this->c; } public function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
$GLOBALS['__api'] = null; $GLOBALS['__api_path'] = '';
function snt_gsc_api_get( $path ) { $GLOBALS['__api_path'] = $path; return $GLOBALS['__api']; }

require __DIR__ . '/../inc/search-console-sitemaps.php';

echo "search console sitemaps\n\n";

$body = array( 'sitemap' => array(
	array( 'path' => 'https://example.test/wp-sitemap.xml', 'lastSubmitted' => '2026-09-01T10:00:00Z', 'lastDownloaded' => '2026-10-17T03:12:00.5Z', 'isPending' => false, 'isSitemapsIndex' => true, 'warnings' => '0', 'errors' => '2', 'contents' => array( array( 'type' => 'web', 'submitted' => '68', 'indexed' => '0' ), array( 'type' => 'image', 'submitted' => '4' ) ) ),
	array( 'path' => 'https://example.test/news.xml', 'lastSubmitted' => '2026-10-18T10:00:00Z', 'isPending' => true, 'warnings' => '1', 'errors' => '0' ),
) );
$rows = snt_gsc_sitemaps_normalize( $body );
ok( 2 === count( $rows ), 'one row per sitemap Google lists' );
ok( 2 === $rows[0]['errors'] && 0 === $rows[0]['warnings'] && 72 === $rows[0]['submitted'], 'string counts become integers; submitted sums every content type' );
ok( false === strpos( json_encode( $rows ), 'indexed' ), 'the deprecated `indexed` count is never kept' );
ok( '' === $rows[1]['last_downloaded'] && true === $rows[1]['is_pending'], 'a never-read sitemap keeps an empty download time, not a guessed one' );
ok( array() === snt_gsc_sitemaps_normalize( array() ), 'a property with no sitemaps is an empty list, not an error' );

// The view, in plain words.
$v = snt_gsc_sitemaps_view( array( 'synced_at' => 1, 'sitemaps' => $rows, 'last_error' => '' ) );
ok( false !== strpos( $v['lines'][0], '/wp-sitemap.xml' ) && false !== strpos( $v['lines'][0], '2026-10-17' ) && false !== strpos( $v['lines'][0], '72 URLs' ) && false !== strpos( $v['lines'][0], '2 errors' ), 'a read sitemap names its path, the day Google last read it, its URLs and its errors: ' . $v['lines'][0] );
ok( false !== strpos( $v['lines'][1], 'not read it yet' ) && false !== strpos( $v['lines'][1], '1 warning' ), 'a pending, never-read sitemap says Google has not read it: ' . $v['lines'][1] );
$v0 = snt_gsc_sitemaps_view( null );
ok( array() === $v0['lines'] && false !== strpos( $v0['note'], 'next weekly' ), 'never synced: an honest line, no rows' );
$ve = snt_gsc_sitemaps_view( array( 'synced_at' => 1, 'sitemaps' => array(), 'last_error' => '' ) );
ok( false !== strpos( $ve['note'], 'no sitemap' ), 'Google listing none says so: ' . $ve['note'] );

// The sync: one GET, kept last good record on failure.
$GLOBALS['__api'] = $body;
$r = snt_gsc_sitemaps_sync( 'sc-domain:example.test' );
ok( '/sites/sc-domain%3Aexample.test/sitemaps' === $GLOBALS['__api_path'], 'the property is escaped as a path segment' );
ok( true === $r['ok'] && 2 === count( snt_gsc_sitemaps_data()['sitemaps'] ), 'a good read is stored' );
$GLOBALS['__api'] = new WP_Error( 'snt_gsc_api_error', 'HTTP 500' );
$r = snt_gsc_sitemaps_sync( 'sc-domain:example.test' );
$d = snt_gsc_sitemaps_data();
ok( false === $r['ok'] && 2 === count( $d['sitemaps'] ) && false !== strpos( $d['last_error'], 'HTTP 500' ), 'a failed read keeps the last good record with the error beside it' );
$vf = snt_gsc_sitemaps_view( $d );
ok( false !== strpos( $vf['note'], 'HTTP 500' ) && 2 === count( $vf['lines'] ), 'and the view says the last read failed while still showing the record' );

// Wiring: the coverage run reads it, the ability carries it, both twins render it.
$cov = (string) file_get_contents( __DIR__ . '/../inc/search-console-coverage.php' );
ok( false !== strpos( $cov, 'snt_gsc_sitemaps_sync(' ), 'the weekly coverage run reads the sitemaps report at its end' );
$ab = (string) file_get_contents( __DIR__ . '/../inc/abilities-search-console.php' );
ok( 2 === substr_count( $ab, "'sitemaps'" ), 'search-coverage carries `sitemaps` in its output and its schema' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/analytics-view-search.php' ), 'snt_gsc_sitemaps_render_classic' ), 'the classic Index coverage panel renders it' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../apps/sn-analytics/parts/painters/view-search.php' ), 'snt_gsc_sitemaps_view' ), 'the native Search view renders it' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' ), "inc/search-console-sitemaps.php'" ), 'the plugin loads it' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
