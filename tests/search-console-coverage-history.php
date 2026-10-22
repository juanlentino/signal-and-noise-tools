<?php
/**
 * Index coverage history: the weekly run keeps its earlier readings.
 *
 * Properties: (1) a completed weekly sync appends ONE summary reading (counts,
 * crawled-not-indexed, median days since the last crawl); (2) the history is
 * oldest first and capped at 104; (3) a same-day re-run replaces that day's
 * reading; (4) a single-URL inspection appends nothing; (5) under two
 * readings the reader gets the honest line, and with two, what moved; (6) the
 * classic panel renders that honest line; (7) the ability carries `history`.
 *
 * Run: php tests/search-console-coverage-history.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
class WP_Error { public $c; public function __construct( $c = '', $m = '' ) { $this->c = $c; } public function get_error_code() { return $this->c; } public function get_error_message() { return ''; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function wp_next_scheduled( $h, $args = array() ) { return false; }
function snt_gsc_sync_is_ready() { return true; }
function sn_setting( $k, $d = null ) { return 'search_console.property' === $k ? 'https://example.test/' : $d; }
$GLOBALS['__posts'] = array( 11 => 'https://example.test/notes/alpha/', 12 => 'https://example.test/notes/beta/', 13 => 'https://example.test/notes/gamma/' );
function get_posts( $a ) { return array_keys( $GLOBALS['__posts'] ); }
function get_permalink( $id ) { $id = is_object( $id ) ? (int) $id->ID : $id; return $GLOBALS['__posts'][ $id ] ?? ''; }
function get_post( $id ) { return ( 900 === (int) $id || isset( $GLOBALS['__posts'][ (int) $id ] ) ) ? (object) array( 'ID' => (int) $id, 'post_status' => 'publish', 'post_password' => '', 'post_type' => 'post' ) : null; }
function get_post_meta( $id, $k, $s = false ) { return ''; } // 2026-10-22: the indexable predicate reads _sn_noindex and _sn_canonical_url.
$GLOBALS['__posts'][900] = 'https://example.test/notes/new-note/';
$GLOBALS['__api'] = array();
function snt_gsc_api_post( $url, $body, $timeout = 30 ) { return $GLOBALS['__api'][ $body['inspectionUrl'] ] ?? new WP_Error( 'snt_gsc_api_error' ); }

require_once __DIR__ . '/../inc/path-join-key.php';
require_once __DIR__ . '/../inc/search-console-coverage-history.php';
require_once __DIR__ . '/../inc/search-console-coverage.php';
require_once __DIR__ . '/../inc/abilities-search-console.php';

echo "search console coverage history\n\n";

$now   = time();
$crawl = static fn( $days ) => gmdate( 'Y-m-d\TH:i:s\Z', $now - (int) ( $days * DAY_IN_SECONDS ) );
$res   = static fn( $state, $lct = '' ) => array( 'inspectionResult' => array( 'indexStatusResult' => array( 'coverageState' => $state, 'lastCrawlTime' => $lct ) ) );
$GLOBALS['__api'] = array(
	'https://example.test/notes/alpha/' => $res( 'Submitted and indexed', $crawl( 4 ) ),
	'https://example.test/notes/beta/'  => $res( 'Crawled - currently not indexed', $crawl( 10 ) ),
	'https://example.test/notes/gamma/' => $res( 'Discovered - currently not indexed' ),
	'https://example.test/notes/new-note/' => $res( 'Submitted and indexed', $crawl( 1 ) ),
);
unset( $GLOBALS['__posts'][900] ); // the weekly walk sees three notes.

// ─── (1) a completed sync appends one reading ───
ok( array() === snt_gsc_coverage_history(), 'before any run the history is empty' );
snt_gsc_coverage_sync( true );
$h = snt_gsc_coverage_history();
ok( 1 === count( $h ), 'one completed weekly sync appends exactly one reading (not one per inspection)' );
$r = $h[0] ?? array();
ok( 3 === ( $r['inspected'] ?? null ) && 1 === $r['indexed'] && 2 === $r['not_indexed'] && 1 === $r['crawled_not_indexed'], 'the reading carries inspected / indexed / not indexed / crawled-not-indexed counts' );
ok( 7.0 === ( $r['median_crawl_age_days'] ?? null ) && 2 === $r['crawl_times'], 'median days since the last crawl is over the entries that reported one (4 and 10 -> 7.0)' );
ok( false === ( $GLOBALS['__autoload'][ SNT_GSC_COVERAGE_HISTORY_OPTION ] ?? null ), 'the history option is not autoloaded' );
ok( SNT_GSC_COVERAGE_OPTION !== SNT_GSC_COVERAGE_HISTORY_OPTION && isset( $GLOBALS['__opt'][ SNT_GSC_COVERAGE_OPTION ]['entries'] ), 'the current-reading map is still written where its readers look' );

// ─── (3) same-day re-run replaces, (4) single inspection appends nothing ───
snt_gsc_coverage_sync( true );
ok( 1 === count( snt_gsc_coverage_history() ), 'a second run the same day replaces that day\'s reading' );
$GLOBALS['__posts'][900] = 'https://example.test/notes/new-note/';
snt_gsc_inspect_one( 900, 3 );
ok( 1 === count( snt_gsc_coverage_history() ), 'a single-URL inspection is not a run: no reading appended' );
ok( null === snt_gsc_coverage_history_record( array( 'complete' => false, 'synced_at' => $now, 'entries' => array() ) ) && 1 === count( snt_gsc_coverage_history() ), 'an incomplete map is never recorded' );

// ─── (2) order and cap ───
$mk = static fn( $i ) => array( 'synced_at' => 1_700_000_000 + $i * 7 * DAY_IN_SECONDS, 'inspected' => 70, 'indexed' => 40 + $i, 'not_indexed' => 30 - $i, 'crawled_not_indexed' => 5, 'unknown' => 0, 'errors' => 0, 'median_crawl_age_days' => 8.0, 'crawl_times' => 70 );
$hist = array();
for ( $i = 0; $i < 105; $i++ ) {
	$hist = snt_gsc_coverage_history_push( $hist, $mk( $i ) );
}
ok( 104 === count( $hist ), '105 weekly readings are capped at 104 (two years)' );
ok( $mk( 1 )['synced_at'] === $hist[0]['synced_at'] && $mk( 104 )['synced_at'] === $hist[103]['synced_at'], 'oldest first: the oldest reading is the one dropped, the newest is last' );

// ─── (5) the view ───
$v0 = snt_gsc_coverage_history_view( array() );
$v1 = snt_gsc_coverage_history_view( array( $mk( 0 ) ) );
ok( 'History starts with the next weekly run.' === $v0['note'] && array() === $v0['rows'], 'no readings: the honest line, no rows' );
ok( 'History starts with the next weekly run.' === $v1['note'] && array() === $v1['rows'], 'one reading: still the honest line, nothing invented' );
$b    = $mk( 1 );
$b['median_crawl_age_days'] = 11.5;
$v2   = snt_gsc_coverage_history_view( array( $mk( 0 ), $b ) );
ok( false !== strpos( $v2['note'], 'indexed +1' ) && false !== strpos( $v2['note'], 'not indexed -1' ) && false !== strpos( $v2['note'], 'crawled but not indexed no change' ) && false !== strpos( $v2['note'], '+3.5' ), 'two readings: the note states what moved since the previous run' );
ok( 2 === count( $v2['rows'] ) && gmdate( 'Y-m-d', $b['synced_at'] ) === $v2['rows'][0]['run'], 'rows are newest first' );
ok( SNT_GSC_COVERAGE_HISTORY_SHOWN === count( snt_gsc_coverage_history_view( $hist )['rows'] ), 'a long history shows only the last few readings' );
ok( false === strpos( $v2['note'] . $v0['note'], "\u{2014}" ), 'no em dashes in the new copy' );

// ─── (6) the classic panel renders the honest line ───
$GLOBALS['__opt'][ SNT_GSC_COVERAGE_HISTORY_OPTION ] = array();
ob_start();
snt_gsc_coverage_history_render_classic();
$out = (string) ob_get_clean();
ok( false !== strpos( $out, 'History starts with the next weekly run.' ) && false === strpos( $out, '<table' ), 'classic: an empty history renders the honest line and no table' );
$GLOBALS['__opt'][ SNT_GSC_COVERAGE_HISTORY_OPTION ] = array( $mk( 0 ), $b );
ob_start();
snt_gsc_coverage_history_render_classic();
$out = (string) ob_get_clean();
ok( false !== strpos( $out, '<table' ) && false !== strpos( $out, 'Median days since last crawl' ) && false !== strpos( $out, '11.5' ), 'classic: two readings render the table with the median crawl age' );

// ─── (7) the ability carries history, backward compatibly ───
$a = snt_ability_search_coverage( null );
ok( is_array( $a ) && 2 === count( $a['history'] ?? array() ) && array_key_exists( 'status', $a ) && array_key_exists( 'entries', $a ), 'search-coverage adds `history` beside the keys it already returned' );
$schema = snt_search_console_abilities()['signal-noise/search-coverage']['output']['properties'];
ok( isset( $schema['history'] ), 'the output schema declares history' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
