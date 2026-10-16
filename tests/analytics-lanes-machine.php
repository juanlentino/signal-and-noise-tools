<?php
/**
 * SN Traffic Lanes, machine sensor: inc/analytics-lanes-machine.php, through
 * the REAL snt_mr_fetch() and its normalizer (rights-signals #89 adds
 * owner_identity and owner_agent to the aggregate view and groups by them).
 *
 * Pins: the two new columns survive normalization as codes (null = not
 * measured); a row split by them sums the same in the existing readers;
 * precedence; exhaustiveness (reads sum to the input per day, webmcp calls
 * excluded as every reader excludes them); complete UTC days only; a
 * truncated read writes nothing; machine lanes_since from evidence.
 *
 * Run: php tests/analytics-lanes-machine.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_MR_READ_TOKEN', 'tok' );
function add_action() {}
function apply_filters( $h, $v ) { return $v; }
function delete_transient( $k ) { return true; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $e = 0 ) { return true; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_http_validate_url( $u ) { return $u; }
function is_wp_error( $x ) { return false; }
$GLOBALS['__body'] = '';
function wp_remote_get( $u, $a = array() ) { $GLOBALS['__url'] = $u; return array( 'body' => $GLOBALS['__body'] ); }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function sn_owner_agents() { return array( 'browser', 'chrome-extension', 'claude-code', 'playwright', 'script', 'mcp-local', 'other' ); }

require __DIR__ . '/lib/lanes-fake-wpdb.php';
require __DIR__ . '/../inc/machine-readers-api.php';
require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/rights-evidence-compose.php';
require __DIR__ . '/../inc/machine-readers-basis.php';
require __DIR__ . '/../inc/analytics-lanes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$now  = time();
$d    = static fn( $ago ) => gmdate( 'Y-m-d', $now - $ago * 86400 );
$raw  = static fn( $family, $hits, array $x = array(), $ago = 2 ) => $x + array( 'family' => $family, 'surface' => 'html', 'vendor' => '', 'purpose' => 'search', 'agent' => '', 'first_party' => '0', 'signed_agent' => 'none', 'verified_bot' => '', 'network' => 'Example', 'owner_identity' => '0', 'owner_agent' => '0', 'day' => $d( $ago ), 'hits' => (string) $hits );
$rows = array(
	$raw( 'search', 5, array( 'owner_identity' => '2', 'owner_agent' => '3', 'first_party' => '1' ) ), // owner_agent before own_infra
	$raw( 'unclassified-machine', 7, array( 'first_party' => '1' ) ),                                    // own_infra
	$raw( 'google-ai', 3, array( 'agent' => 'google-extended', 'purpose' => 'train', 'verified_bot' => 'Search Engine Crawler', 'signed_agent' => 'valid' ) ), // impersonation outranks proof
	$raw( 'openai', 4, array( 'signed_agent' => 'valid', 'purpose' => 'retrieval' ) ),                // web_bot_auth
	$raw( 'search', 6, array( 'verified_bot' => 'Search Engine Crawler' ) ),                           // cf_verified
	$raw( 'search', 2, array( 'verified_bot' => 'Monitoring & Analytics' ) ),                          // category_mismatch
	$raw( 'perplexity', 8, array( 'purpose' => 'user' ) ),                                             // ua_claim
	$raw( 'perplexity', 1, array( 'purpose' => 'user', 'owner_identity' => '3', 'owner_agent' => '5' ) ), // code 3 keeps its lane
	$raw( 'unclassified-machine', 9 ),                                                                  // no_claim
	$raw( 'webmcp', 50, array( 'surface' => 'sn_search', 'purpose' => 'ok' ) ),                        // a tool call, not a read
	$raw( 'search', 11, array( 'owner_identity' => '', 'owner_agent' => '' ), 4 ),                     // before the sensor stamped the codes
	$raw( 'search', 99, array(), 0 ),                                                                   // today: pending
	$raw( 'search', 98, array(), 9 ),                                                                   // the rolling window's partial oldest date
);

// --- normalization: codes, and a split that sums the same --------------------
$norm = snt_mr_normalize_rows( $rows );
ok( 2 === $norm[0]['owner_identity'] && 3 === $norm[0]['owner_agent'], 'owner_identity and owner_agent normalize to codes' );
ok( null === $norm[10]['owner_identity'] && null === snt_mr_normalize_rows( array( array( 'family' => 'search' ) ) )[0]['owner_identity'], '"" and an absent column are not measured (null), never code 0' );
ok( null === snt_mr_normalize_code( '1; DROP' ) && null === snt_mr_normalize_code( array() ) && 7 === snt_mr_normalize_code( 7 ), 'only a short digit string is a code' );
$whole = array( $raw( 'search', 10, array( 'verified_bot' => 'Search Engine Crawler' ) ) );
$split = array( $raw( 'search', 6, array( 'verified_bot' => 'Search Engine Crawler' ) ), $raw( 'search', 4, array( 'verified_bot' => 'Search Engine Crawler', 'owner_identity' => '2', 'owner_agent' => '3' ) ) );
ok( snt_mr_verification_by( snt_mr_normalize_rows( $whole ), 'family' ) == snt_mr_verification_by( snt_mr_normalize_rows( $split ), 'family' ), 'a row split by the owner columns verifies the same per family' );
ok( array_sum( array_column( snt_mr_normalize_rows( $whole ), 'hits' ) ) === array_sum( array_column( snt_mr_normalize_rows( $split ), 'hits' ) ), 'and its hits sum the same' );

// --- the run, through the real fetch -----------------------------------------
$GLOBALS['__body'] = json_encode( array( 'data' => $rows, 'rows' => count( $rows ), 'truncated' => false ) );
ok( sn_lanes_machine_run(), 'the machine pass writes' );
ok( false !== strpos( $GLOBALS['__url'], 'days=9' ) && false === strpos( $GLOBALS['__url'], 'view=' ), 'it reads nine days of the aggregate view' );
$db     = $GLOBALS['wpdb'];
$stored = static fn( $lane, $reason ) => array_sum( array_map( static fn( $r ) => $r['lane'] === $lane && $r['reason'] === $reason && $r['day'] === $d( 2 ) ? $r['hits'] : 0, $db->rows ) );
ok( 5 === $stored( 'owner_agent', 'owner_token' ) && in_array( 'claude-code', array_column( $db->rows, 'agent' ), true ), 'code 2 is owner_agent with its label, ahead of first_party' );
ok( 7 === $stored( 'own_infra', 'first_party' ), 'first_party is own_infra' );
ok( 3 === $stored( 'claimed_bot', 'impersonation' ), 'a control token is impersonation even when signed and verified' );
ok( 4 === $stored( 'verified_bot', 'web_bot_auth' ), 'a valid signature is verified_bot / web_bot_auth' );
ok( 6 === $stored( 'verified_bot', 'cf_verified' ), 'a category that fits the purpose is verified_bot / cf_verified' );
ok( 2 === $stored( 'claimed_bot', 'category_mismatch' ), 'a category that does not fit is claimed_bot / category_mismatch' );
ok( 8 === $stored( 'claimed_bot', 'ua_claim' ), 'a bare claim is claimed_bot / ua_claim' );
ok( 1 === $stored( 'claimed_bot', 'owner_token_invalid' ), 'code 3 keeps its family\'s lane, reason owner_token_invalid' );
ok( 9 === $stored( 'unknown_machine', 'no_claim' ), 'unclassified-machine is unknown_machine / no_claim' );
$day2 = array_filter( $db->rows, static fn( $r ) => $r['day'] === $d( 2 ) );
ok( 45 === array_sum( array_column( $day2, 'hits' ) ), 'reads on the day sum to the input reads, webmcp calls left out (45)' );
ok( 11 === array_sum( array_column( array_filter( $db->rows, static fn( $r ) => $r['day'] === $d( 4 ) ), 'hits' ) ), 'an unstamped day still rolls (identity unknown, folded to code 0)' );
ok( array() === array_filter( $db->rows, static fn( $r ) => in_array( $r['day'], array( $d( 0 ), $d( 9 ) ), true ) ), 'today and the partial oldest date are never written' );
ok( array() === array_filter( $db->rows, static fn( $r ) => null !== $r['visits'] || null !== $r['visitor_days'] ), 'visits and visitor-days are NULL on the machine sensor (not measured)' );
ok( 'search' === current( array_filter( array_column( array_filter( $db->rows, static fn( $r ) => 'cf_verified' === $r['reason'] ), 'purpose' ) ) ) && '' === current( array_column( array_filter( $db->rows, static fn( $r ) => 'cf_verified' === $r['reason'] ), 'family' ) ), 'verified_bot keeps purpose, not family' );
ok( 'perplexity' === current( array_column( array_filter( $db->rows, static fn( $r ) => 'ua_claim' === $r['reason'] && $r['day'] === $d( 2 ) ), 'family' ) ), 'claimed_bot keeps family and purpose' );
ok( $d( 9 ) === sn_lanes_since( 'machine' ), 'machine lanes_since is the first day a row carries the identity column' );

// --- a truncated read writes nothing ------------------------------------------
snt_mr_cache_flush();
$GLOBALS['__body'] = json_encode( array( 'data' => array( $raw( 'search', 1 ) ), 'rows' => 10000, 'truncated' => true ) );
$before            = $db->rows;
ok( ! sn_lanes_machine_run() && $before === $db->rows, 'a truncated read keeps the stored days' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
