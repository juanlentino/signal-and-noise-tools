<?php
/**
 * Bing's view of the URLs Google has not indexed (GetUrlInfo).
 *
 * Properties: (1) only the not-indexed set is asked about, minus pages excluded
 * by noindex on purpose, capped per run; (2) a fresh answer is kept and not
 * re-asked; URLs that left the set are dropped; (3) Bing's "no date" sentinel
 * never reads as a crawl; (4) a refused call is "not read", never "Bing lacks
 * it"; (5) each not-indexed row in the coverage summary carries Bing's answer.
 *
 * Run: php tests/bing-url-info.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://example.test/'; }
function sn_credential( $k ) { return 'bing_webmaster_key' === $k ? 'k' : ''; }
// The request seam: sn_bing_request() is real (bing-webmaster.php); wp_remote_get answers from a table.
$GLOBALS['__calls'] = array(); $GLOBALS['__bing'] = array();
function wp_remote_get( $url, $a = array() ) {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	$GLOBALS['__calls'][] = $q['url'] ?? '';
	return $GLOBALS['__bing'][ $q['url'] ?? '' ] ?? array( 'code' => 400, 'body' => '{"Message":"InvalidUrl"}' );
}
function is_wp_error( $x ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_next_scheduled( $h ) { return true; }

require __DIR__ . '/../inc/bing-webmaster.php';
require __DIR__ . '/../inc/search-console-coverage.php';
require __DIR__ . '/../inc/bing-url-info.php';

echo "bing url info\n\n";

$e = static function ( $state, $url ) {
	return array( 'indexed' => 0 === strpos( $state, 'Submitted and indexed' ), 'coverage_state' => $state, 'url' => $url, 'kind' => 'post' );
};
$cov = array( 'synced_at' => 1, 'entries' => array(
	'/a'      => $e( 'Submitted and indexed', 'https://example.test/a/' ),
	'/b'      => $e( 'Discovered - currently not indexed', 'https://example.test/b/' ),
	'/c'      => $e( 'URL is unknown to Google', 'https://example.test/c/' ),
	'/verify' => $e( 'Excluded by ‘noindex’ tag', 'https://example.test/verify/' ),
) );
$t = sn_bing_url_info_targets( $cov );
ok( array( '/b', '/c' ) === array_keys( $t ), 'only the not-indexed set, minus noindex on purpose: ' . implode( ',', array_keys( $t ) ) );

$many = array( 'synced_at' => 1, 'entries' => array() );
for ( $i = 0; $i < 40; $i++ ) { $many['entries'][ '/n' . $i ] = $e( 'Discovered - currently not indexed', 'https://example.test/n' . $i . '/' ); }
ok( SN_BING_URL_INFO_MAX === count( sn_bing_url_info_targets( $many ) ), 'capped per run at ' . SN_BING_URL_INFO_MAX );

// Shapes.
$crawl = array( 'ok' => true, 'code' => 200, 'body' => array( 'Url' => 'x', 'LastCrawledDate' => '/Date(1791000000000)/', 'DiscoveryDate' => '/Date(1790000000000)/', 'HttpStatus' => 200 ), 'error' => '' );
ok( 'crawled' === sn_bing_url_info_shape( $crawl, 5 )['state'], 'a crawl date reads crawled' );
$disc = array( 'ok' => true, 'code' => 200, 'body' => array( 'LastCrawledDate' => '/Date(-62135596800000)/', 'DiscoveryDate' => '/Date(1790000000000)/' ), 'error' => '' );
ok( 'discovered' === sn_bing_url_info_shape( $disc, 5 )['state'], 'Bing\'s year-1 "no date" sentinel never reads as a crawl' );
$none = array( 'ok' => true, 'code' => 200, 'body' => array( 'LastCrawledDate' => '/Date(-62135596800000)/', 'DiscoveryDate' => '/Date(-62135596800000)/' ), 'error' => '' );
ok( 'unknown' === sn_bing_url_info_shape( $none, 5 )['state'], 'no dates at all: not known to Bing' );
$err = sn_bing_url_info_shape( array( 'ok' => false, 'code' => 400, 'body' => '', 'error' => 'InvalidUrl' ), 5 );
ok( 'error' === $err['state'] && false !== strpos( sn_bing_url_info_label( $err ), 'not read' ), 'a refused call is "not read", never "Bing lacks it": ' . sn_bing_url_info_label( $err ) );
ok( null === sn_bing_url_info_label( null ), 'never asked: null, not a guess' );
ok( false !== strpos( sn_bing_url_info_label( sn_bing_url_info_shape( $crawl, 5 ) ), 'crawled' ), 'label: ' . sn_bing_url_info_label( sn_bing_url_info_shape( $crawl, 5 ) ) );

// Sync: asks once, keeps fresh answers, drops what left the set.
$GLOBALS['__bing']['https://example.test/b/'] = array( 'code' => 200, 'body' => json_encode( array( 'd' => $crawl['body'] ) ) );
$now = 1792000000;
sn_bing_url_info_sync( $cov, $now );
ok( array( 'https://example.test/b/', 'https://example.test/c/' ) === $GLOBALS['__calls'], 'one GetUrlInfo per not-indexed URL' );
$d = sn_bing_url_info_data();
ok( 'crawled' === $d['/b']['state'] && 'error' === $d['/c']['state'], 'answers are stored per path' );
$GLOBALS['__calls'] = array();
unset( $cov['entries']['/b'] );
sn_bing_url_info_sync( $cov, $now + DAY_IN_SECONDS );
ok( array( 'https://example.test/c/' ) === $GLOBALS['__calls'], 'a fresh answer is not re-asked; an error is retried' );
ok( ! isset( sn_bing_url_info_data()['/b'] ), 'a URL that left the not-indexed set is dropped' );
$GLOBALS['__calls'] = array();
sn_bing_url_info_sync( $cov, $now + 8 * DAY_IN_SECONDS );
ok( 1 === count( $GLOBALS['__calls'] ), 'a stale answer is asked again' );

// The coverage summary row carries Bing's answer.
$s = snt_gsc_coverage_summary( $cov, null, array( '/c' => sn_bing_url_info_shape( $crawl, 5 ) ) );
$rows = array_column( $s['not_indexed_paths'], 'bing', 'path' );
ok( false !== strpos( (string) $rows['/c'], 'crawled' ), 'a not-indexed row carries Bing\'s answer' );
ok( null === $rows['/verify'], 'a row Bing was not asked about carries null' );
$s0 = snt_gsc_coverage_summary( $cov, null );
ok( array_key_exists( 'bing', $s0['not_indexed_paths'][0] ) && null === $s0['not_indexed_paths'][0]['bing'], 'without the Bing map every row still has the key, null' );

ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/search-console-coverage.php' ), 'sn_bing_url_info_sync(' ), 'the weekly coverage run asks Bing at its end' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
