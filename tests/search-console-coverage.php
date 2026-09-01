<?php
/**
 * Search Console URL Inspection coverage — v13.63.0.
 *
 * Properties: (1) normalization derives `indexed` from Google's coverageState
 * wording and never guesses (missing state → null; a transport error → an
 * error entry); (2) the sync inspects every published post once, keyed by the
 * weave join key, posts the property as siteUrl, and stores errors as errors;
 * (3) readers never fetch; (4) the summary counts indexed / not / unknown /
 * errors and lists the not-indexed paths and canonical mismatches; (5) the
 * cron equals readiness (schedule when ready, unschedule when not) and is on
 * the opt-in gate map so cron_health does not call its absence "missing".
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $s, $d = null ) { return $s; }
$GLOBALS['__hooks'] = array();
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c ); return true; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
class WP_Error { public $c; public $m; public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } public function get_error_code() { return $this->c; } public function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
$GLOBALS['__sched'] = array();
function wp_next_scheduled( $h ) { return $GLOBALS['__sched'][ $h ] ?? false; }
function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['__sched'][ $h ] = array( $ts, $rec ); return true; }
function wp_unschedule_event( $ts, $h ) { unset( $GLOBALS['__sched'][ $h ] ); return true; }
$GLOBALS['__ready'] = true;
function snt_gsc_sync_is_ready() { return $GLOBALS['__ready']; }
function sn_setting( $k, $d = null ) { return 'search_console.property' === $k ? 'https://example.test/' : $d; }
$GLOBALS['__posts'] = array( 11 => 'https://example.test/notes/alpha/', 12 => 'https://example.test/notes/beta/', 13 => 'https://example.test/notes/gamma/' );
function get_posts( $a ) { $GLOBALS['__get_posts_args'] = $a; return array_keys( $GLOBALS['__posts'] ); }
function get_permalink( $id ) { return $GLOBALS['__posts'][ $id ] ?? ''; }
// The network seam: url => response.
$GLOBALS['__api'] = array(); $GLOBALS['__posted'] = array();
function snt_gsc_api_post( $url, $body ) { $GLOBALS['__posted'][] = array( $url, $body ); $k = $body['inspectionUrl']; return $GLOBALS['__api'][ $k ] ?? new WP_Error( 'snt_gsc_api_error', 'HTTP 500' ); }

require_once __DIR__ . '/../inc/path-join-key.php';
require_once __DIR__ . '/../inc/search-console-coverage.php';

echo "search console coverage — v13.63.0\n\n";
$NOW = 1_800_000_000;
$indexed = static fn( $canon = 'https://example.test/notes/alpha/' ) => array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'indexingState' => 'INDEXING_ALLOWED', 'robotsTxtState' => 'ALLOWED', 'pageFetchState' => 'SUCCESSFUL', 'crawledAs' => 'MOBILE', 'lastCrawlTime' => '2026-08-28T10:00:00Z', 'googleCanonical' => $canon, 'userCanonical' => 'https://example.test/notes/alpha/' ) ) );
$crawled_not = array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed', 'indexingState' => 'INDEXING_ALLOWED', 'lastCrawlTime' => '2026-08-01T00:00:00Z' ) ) );
$discovered  = array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Discovered - currently not indexed' ) ) );

// ─── (1) normalization ───
$n = snt_gsc_coverage_normalize( $indexed(), $NOW );
ok( true === $n['indexed'] && 'PASS' === $n['verdict'] && true === $n['canonical_match'] && '2026-08-28T10:00:00Z' === $n['last_crawl_time'], 'Submitted and indexed → indexed:true, canonical match, crawl time carried' );
ok( false === snt_gsc_coverage_normalize( $crawled_not, $NOW )['indexed'] && false === snt_gsc_coverage_normalize( $discovered, $NOW )['indexed'], '"Crawled - currently not indexed" and "Discovered - …" → indexed:false' );
ok( true === snt_gsc_coverage_normalize( array( 'inspectionResult' => array( 'indexStatusResult' => array( 'coverageState' => 'Indexed, not submitted in sitemap' ) ) ), $NOW )['indexed'], '"Indexed, not submitted in sitemap" → indexed:true (Google\'s wording, not ours)' );
$nc = snt_gsc_coverage_normalize( array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'NEUTRAL' ) ) ), $NOW );
ok( null === $nc['indexed'] && null === $nc['canonical_match'], 'missing coverageState / canonicals → null, never a guessed false' );
ok( false === snt_gsc_coverage_normalize( $indexed( 'https://example.test/notes/other/' ), $NOW )['canonical_match'], 'a differing Google canonical → canonical_match:false' );
$e = snt_gsc_coverage_normalize( new WP_Error( 'snt_gsc_api_error', 'Search Console API error (HTTP 429): quota' ), $NOW );
ok( 'snt_gsc_api_error' === $e['error'] && false !== strpos( $e['message'], '429' ) && ! isset( $e['indexed'] ), 'a transport/API error is an error entry carrying Google\'s words, with no indexed field at all' );
ok( 'no_index_status' === snt_gsc_coverage_normalize( array( 'inspectionResult' => array() ), $NOW )['error'], 'a 200 with no indexStatusResult is an error, not "unknown coverage"' );

// ─── (2) the sync ───
$GLOBALS['__api'] = array( 'https://example.test/notes/alpha/' => $indexed(), 'https://example.test/notes/beta/' => $crawled_not ); // gamma → error
$p = snt_gsc_coverage_sync();
ok( is_array( $p ) && 3 === $p['inspected'] && 1 === $p['errors'] && false === $p['capped'], 'sync: three posts inspected, one API error counted as an error' );
ok( array( '/notes/alpha', '/notes/beta', '/notes/gamma' ) === array_keys( $p['entries'] ), 'entries keyed by the WEAVE join key (trailing slash stripped) — the same spelling the GSC rows and the scan use' );
ok( 11 === $p['entries']['/notes/alpha']['post_id'] && 'https://example.test/notes/alpha/' === $p['entries']['/notes/alpha']['url'], 'each entry carries post_id + the exact URL inspected' );
ok( SNT_GSC_INSPECT_URL === $GLOBALS['__posted'][0][0] && 'https://example.test/' === $GLOBALS['__posted'][0][1]['siteUrl'] && 'https://example.test/notes/alpha/' === $GLOBALS['__posted'][0][1]['inspectionUrl'], 'POSTs the absolute inspection URL with the property as siteUrl and the permalink as inspectionUrl' );
ok( SNT_GSC_COVERAGE_MAX_URLS === ( $GLOBALS['__get_posts_args']['posts_per_page'] ?? 0 ) && 'publish' === ( $GLOBALS['__get_posts_args']['post_status'] ?? '' ), 'walks published posts, bounded by the per-run cap' );
ok( $p === get_option( SNT_GSC_COVERAGE_OPTION ), 'stored as one option' );
$GLOBALS['__ready'] = false;
ok( is_wp_error( snt_gsc_coverage_sync() ), 'not ready → WP_Error, nothing inspected' );
$GLOBALS['__ready'] = true;

// ─── (3) readers never fetch ───
$before = count( $GLOBALS['__posted'] );
ok( false === snt_gsc_coverage_for_path( 'https://example.test/notes/beta/' )['indexed'] && null === snt_gsc_coverage_for_path( '/notes/nope' ) && $before === count( $GLOBALS['__posted'] ), 'for_path joins through the key (a full URL finds /notes/beta) and never posts' );

// ─── (4) the summary ───
$s = snt_gsc_coverage_summary( snt_gsc_coverage_data() );
ok( 1 === $s['indexed'] && 1 === $s['not_indexed'] && 0 === $s['unknown'] && 1 === $s['errors'] && 3 === $s['inspected'], 'summary: 1 indexed, 1 not, 1 error' );
ok( array( '/notes/beta' ) === array_column( $s['not_indexed_paths'], 'path' ) && 'Crawled - currently not indexed' === $s['not_indexed_paths'][0]['coverage_state'], 'the not-indexed list names the path and Google\'s coverage state' );
ok( array( 'Submitted and indexed' => 1, 'Crawled - currently not indexed' => 1 ) === $s['by_coverage_state'], 'coverage states tallied verbatim' );
ok( false === snt_gsc_coverage_summary( null )['synced'] && 0 === snt_gsc_coverage_summary( null )['inspected'], 'never synced → synced:false, zeros are real zeros of a run that did not happen' );

// ─── (5) cron equals readiness; on the gate map ───
ok( in_array( SNT_GSC_COVERAGE_HOOK, array_map( static fn( $h ) => $h[0], $GLOBALS['__hooks'] ), true ), 'the cron action is registered' );
snt_gsc_coverage_schedule();
ok( 'weekly' === ( $GLOBALS['__sched'][ SNT_GSC_COVERAGE_HOOK ][1] ?? '' ), 'ready → scheduled weekly' );
$GLOBALS['__ready'] = false; snt_gsc_coverage_schedule();
ok( ! isset( $GLOBALS['__sched'][ SNT_GSC_COVERAGE_HOOK ] ), 'not ready → unscheduled (absence-when-false is correct, not missing)' );
$cd = (string) file_get_contents( __DIR__ . '/../inc/cron-dashboard.php' );
ok( 1 === preg_match( "/'SNT_GSC_COVERAGE_HOOK',\s*'sn_gsc_coverage_weekly',\s*'snt_gsc_sync_is_ready'/", $cd ), 'the opt-in gate map pairs the hook with snt_gsc_sync_is_ready (cron_health must not call readiness-absence "missing")' );
ok( 1 === preg_match( "/'SNT_GSC_COVERAGE_HOOK',\s*'sn_gsc_coverage_weekly'\s*\)/", $cd ), 'and it is on the SN-owned hooks list' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
