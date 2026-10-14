<?php
/**
 * Standalone test: the time-relative drift check counts its AI failures.
 *
 * Until v13.97.5 every per-post AI failure (WP_Error, non-string, unparseable
 * JSON) hit `continue` with no counter -- a provider outage on every candidate
 * post packed zero findings, indistinguishable from "checked every post, no
 * drift". The file's own docblock said it "gracefully degrades when AI is
 * unavailable"; that sentence was written for the NOT CONFIGURED branch, which
 * does set `skipped`, and the two had been conflated. (#1042)
 *
 * Run: php tests/health-check-drift-time-phrases.php
 * @since 13.97.5
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST' ) ) { define( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST', 20 ); }
if ( ! defined( 'SNT_AI_DRIFT_SYSTEM' ) ) { define( 'SNT_AI_DRIFT_SYSTEM', 'system prompt' ); }
// The real value, read from the file that defines it, so the breaker cases
// below test the shipped threshold rather than a copy of it.
if ( ! defined( 'SN_HEALTH_DRIFT_BREAKER_AFTER' ) ) {
	preg_match( "/define\\(\\s*'SN_HEALTH_DRIFT_BREAKER_AFTER',\\s*(\\d+)/", (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' ), $sn_m );
	define( 'SN_HEALTH_DRIFT_BREAKER_AFTER', (int) ( $sn_m[1] ?? 0 ) );
}
if ( ! defined( 'SN_HEALTH_DRIFT_MAX_TOKENS' ) ) {
	preg_match( "/define\\(\\s*'SN_HEALTH_DRIFT_MAX_TOKENS',\\s*(\\d+)/", (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' ), $sn_m );
	define( 'SN_HEALTH_DRIFT_MAX_TOKENS', (int) ( $sn_m[1] ?? 0 ) );
}

class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_permalink( $id ) { return "https://example.test/?p=$id"; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function esc_html( $s ) { return $s; }
function apply_filters( $h, $v ) { return $v; }
function snt_ai_is_available() { return true; }
function sn_drift_verdict_get() { return null; } // no cache: every post goes to the model
function sn_drift_verdict_put() {}
function snt_ai_generate_with_constraints( $prompt, $system, $max, $purpose ) {
	$GLOBALS['__ai_calls']++;
	$GLOBALS['__ai_max'] = $max;
	$script = $GLOBALS['__ai_script'];
	return is_callable( $script ) ? $script( $GLOBALS['__ai_calls'] ) : $script;
}
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}
class SN_Drift_WPDB {
	public $posts = 'wp_posts';
	public function get_results( $sql, $mode = null ) { return $GLOBALS['__rows']; }
}
$GLOBALS['wpdb'] = new SN_Drift_WPDB();

require_once __DIR__ . '/../inc/health-check-drift-time-phrases.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function post( $id, $body ) {
	return array( 'ID' => $id, 'post_title' => "Post $id", 'post_content' => $body, 'post_modified_gmt' => '2026-01-01 00:00:00' );
}
$GLOBALS['__rows'] = array(
	post( 1, '<p>As of 2024 this is currently the newest release.</p>' ),
	post( 2, '<p>We recently shipped it; this year it is stable.</p>' ),
);
ok( array() !== sn_health_extract_time_phrase_candidates( $GLOBALS['__rows'][0]['post_content'] ), 'fixture: post 1 yields candidate phrases (otherwise nothing below exercises the model path)' );

function run( $script ) {
	$GLOBALS['__ai_calls']  = 0;
	$GLOBALS['__ai_script'] = $script;
	return sn_health_check_drift_time_phrases();
}

echo "Group: every call fails -> SKIPPED, never a pass\n";
$r = run( new WP_Error( 'http', 'rate limited' ) );
ok( 2 === $GLOBALS['__ai_calls'], 'the model was asked once per candidate post (2)' );
ok( 0 === $r['count'], 'no findings, because nothing was judged' );
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], '2 of 2' ), 'skipped names the ratio: "Every AI call failed (2 of 2)" (got ' . var_export( $r['skipped'], true ) . ')' );

echo "\nGroup: unparseable output counts as a failure too\n";
$r = run( 'this is not json' );
ok( 0 === $r['count'] && is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], '2 of 2' ), 'a model that answers prose instead of JSON is a failed call' );

echo "\nGroup: partial failure keeps the verdicts that came back and says how many did not\n";
$r = run( function ( $n ) {
	if ( 1 === $n ) { return json_encode( array( array( 'phrase' => 'currently', 'verdict' => 'stale', 'reason' => 'dated' ) ) ); }
	return new WP_Error( 'http', 'timeout' );
} );
ok( 1 === $r['count'] && 'currently' === $r['findings'][0]['phrase'], 'the successful call\'s stale verdict is a finding' );
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], '1 of 2' ), 'skipped records the one failed call' );
ok( 1 === $r['count'], '   ...and because count > 0 the tally will show the finding, not the skip (skipped is informational alongside findings)' );

echo "\nGroup: every call succeeds -> a real pass\n";
$r = run( json_encode( array() ) );
ok( 0 === $r['count'] && null === $r['skipped'], 'no stale verdicts and no failures: skipped is null' );

echo "\nGroup: the breaker stops a scan the provider refuses\n";
ok( 2 === SN_HEALTH_DRIFT_BREAKER_AFTER, 'the threshold is read from inc/health-checks.php (2)' );
$GLOBALS['__rows'] = array(
	post( 1, '<p>As of 2024 this is currently the newest release.</p>' ),
	post( 2, '<p>We recently shipped it; this year it is stable.</p>' ),
	post( 3, '<p>Today the latest build is now available.</p>' ),
	post( 4, '<p>Last year we recently moved; this month it is stable.</p>' ),
	post( 5, '<p>Currently the latest release, as of 2025.</p>' ),
);
$r = run( new WP_Error( 'http', 'Your credit balance is too low to access the API.' ) );
ok( 2 === $GLOBALS['__ai_calls'], 'five candidate posts, a refusing provider: 2 calls, not 5 (got ' . $GLOBALS['__ai_calls'] . ')' );
ok( 0 === $r['count'] && is_string( $r['skipped'] ), 'still a SKIP, never a pass' );
ok( false !== strpos( (string) $r['skipped'], 'credit balance is too low' ), 'the skip carries the provider\'s own words' );
ok( false !== strpos( (string) $r['skipped'], '2 of 2' ), 'and the ratio of what was actually asked' );

$r = run( 'this is not json' );
ok( 5 === $GLOBALS['__ai_calls'], 'prose instead of JSON never trips it: the model is answering, just badly (5 calls)' );

$r = run( function ( $n ) {
	if ( 1 === $n ) { return json_encode( array() ); }
	return new WP_Error( 'http', 'timeout' );
} );
ok( 5 === $GLOBALS['__ai_calls'], 'one success first means the provider works: later failures do not stop the scan (5 calls)' );
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], '4 of 5' ), 'and they are counted as a partial failure (4 of 5)' );

echo "\nGroup: a partial failure names the post and why (2026.10.x)\n";
$GLOBALS['__rows'] = array(
	post( 1, '<p>As of 2024 this is currently the newest release.</p>' ),
	post( 2, '<p>We recently shipped it; this year it is stable.</p>' ),
);
$r = run( function ( $n ) {
	if ( 1 === $n ) { return json_encode( array() ); }
	return new WP_Error( 'http', 'Overloaded, see https://status.example.test/x with key sk-ant-abcdefghijklmnopqrstuvwxyz123456' );
} );
ok( false !== strpos( (string) $r['skipped'], '1 of 2' ), 'still says the ratio (1 of 2)' );
ok( false !== strpos( (string) $r['skipped'], 'Post 2' ), 'names the post that was not judged (got ' . var_export( $r['skipped'], true ) . ')' );
ok( false !== strpos( (string) $r['skipped'], 'the provider refused: Overloaded' ), 'says why in words: the provider refused, with its message' );
ok( false === strpos( (string) $r['skipped'], 'https://' ) && false === strpos( (string) $r['skipped'], 'sk-ant-' ), 'no URL and no key-shaped token leaks into the reason' );
ok( isset( $r['failed'] ) && 1 === count( $r['failed'] ) && 2 === $r['failed'][0]['post_id'] && 'Post 2' === $r['failed'][0]['title'], 'structured failed rows carry post_id and title' );

$r = run( function ( $n ) {
	if ( 1 === $n ) { return json_encode( array() ); }
	return '[{"phrase": "recently", "verdict": "stale", "reason": "The post was last mod';
} );
ok( false !== strpos( (string) $r['skipped'], 'cut off at the answer limit' ), 'a JSON array that stops mid-string reads "cut off at the answer limit" (got ' . var_export( $r['skipped'], true ) . ')' );
$r = run( function ( $n ) {
	if ( 1 === $n ) { return json_encode( array() ); }
	return 'Sure! Here are the verdicts you asked for.';
} );
ok( false !== strpos( (string) $r['skipped'], 'the answer was not valid JSON (it may have been cut off)' ), 'prose reads "not valid JSON (it may have been cut off)"' );

$GLOBALS['__rows'] = array(
	post( 1, '<p>As of 2024 this is currently the newest release.</p>' ),
	post( 2, '<p>We recently shipped it; this year it is stable.</p>' ),
	post( 3, '<p>Today the latest build is now available.</p>' ),
	post( 4, '<p>Last year we recently moved; this month it is stable.</p>' ),
	post( 5, '<p>Currently the latest release, as of 2025.</p>' ),
	post( 6, '<p>Recently, this week, it shipped.</p>' ),
);
$r = run( function ( $n ) {
	if ( 1 === $n ) { return json_encode( array() ); }
	return 'not json';
} );
$s = (string) $r['skipped'];
ok( false !== strpos( $s, '5 of 6' ), 'six posts, five failed: 5 of 6' );
ok( false !== strpos( $s, 'Post 2' ) && false !== strpos( $s, 'Post 4' ) && false === strpos( $s, 'Post 5' ), 'names the first three failed posts only' );
ok( false !== strpos( $s, '+2 more' ), 'then "+2 more" (got ' . var_export( $s, true ) . ')' );
ok( 5 === count( (array) ( $r['failed'] ?? array() ) ), 'the structured rows keep all five' );
ok( false === strpos( $s, "\u{2014}" ), 'no em dash in the sentence' );

$r = run( 'not json' );
ok( false !== strpos( (string) $r['skipped'], 'Every AI call failed (6 of 6)' ) && false !== strpos( (string) $r['skipped'], 'Post 1' ), 'every call failing (no breaker) also names posts' );

$GLOBALS['__rows'] = array(
	array( 'ID' => 9, 'post_title' => '', 'post_content' => '<p>Currently stable.</p>', 'post_modified_gmt' => '2026-01-01 00:00:00' ),
	post( 2, '<p>We recently shipped it; this year it is stable.</p>' ),
);
$r = run( function ( $n ) { return 1 === $n ? 'nope' : json_encode( array() ); } );
ok( false !== strpos( (string) $r['skipped'], 'post #9' ), 'an untitled post reads post #9' );

$GLOBALS['__rows'] = array( post( 1, '<p>Currently stable.</p>' ), post( 2, '<p>Recently stable.</p>' ), post( 3, '<p>Today stable.</p>' ) );
$r = run( new WP_Error( 'http', 'Your credit balance is too low to access the API.' ) );
ok( 0 === strpos( (string) $r['skipped'], 'The AI provider refused the first 2 of 2 calls (Your credit balance is too low to access the API.), so no post was judged.' ), 'the breaker sentence is unchanged' );

echo "\nGroup: review 2026-10-14: scrub, shape, quotes, breaker\n";
$sn_scrub = sn_health_drift_scrub_reason( 'Denied. Authorization: Bearer abc.def x-api-key: k1 api-key=zz9 Basic dXNlcjpw mail ops@example.com, see www.example.com/help or status.example.com/incidents/7 org-AbC123 proj_xyz789 eyJhbGciOiJI.eyJzdWIiOiIx.SflKxwRJSMeK' );
foreach ( array( 'abc.def', 'k1', 'zz9', 'dXNlcjpw', 'ops@example.com', 'www.example.com', 'status.example.com/incidents', 'org-AbC123', 'proj_xyz789', 'eyJhbGciOiJI' ) as $sn_leak ) {
	ok( false === strpos( $sn_scrub, $sn_leak ), "scrub removes $sn_leak (got $sn_scrub)" );
}
ok( 0 === strpos( $sn_scrub, 'Denied.' ), 'scrub keeps the words around them' );
ok( false !== strpos( $sn_scrub, 'x-api-key: [redacted]' ) && false !== strpos( $sn_scrub, 'api-key: [redacted]' ), 'header names survive, only their values go (got ' . $sn_scrub . ')' );
$sn_ids = sn_health_drift_scrub_reason( 'Bad org-AbC123, proj_xyz789 and eyJhbGciOiJI.eyJzdWIiOiIx.SflKxwRJSMeK here' );
ok( 'Bad [redacted], [redacted] and [redacted] here' === $sn_ids, 'org-/proj- ids and JWT-shaped strings go, inside the 160 cut (got ' . $sn_ids . ')' );
$sn_long = sn_health_drift_scrub_reason( str_repeat( 'é', 200 ) );
ok( 160 === mb_strlen( $sn_long ) && false !== mb_check_encoding( $sn_long, 'UTF-8' ), 'the 160 cut counts characters and never splits one' );

$GLOBALS['__rows'] = array( post( 1, '<p>Currently stable.</p>' ), post( 2, '<p>Recently stable.</p>' ) );
$r = run( function ( $n ) { return 1 === $n ? json_encode( array() ) : '"just a string"'; } );
ok( false !== strpos( (string) $r['skipped'], 'the answer was not the expected list' ), 'valid JSON of the wrong shape reads "not the expected list" (got ' . var_export( $r['skipped'], true ) . ')' );
$r = run( function ( $n ) { return 1 === $n ? json_encode( array() ) : 'null'; } );
ok( false !== strpos( (string) $r['skipped'], 'the answer was not the expected list' ), 'a bare null reads "not the expected list"' );

$GLOBALS['__rows'] = array( post( 1, '<p>Currently stable.</p>' ), array( 'ID' => 2, 'post_title' => 'A "q" title', 'post_content' => '<p>Recently stable.</p>', 'post_modified_gmt' => '2026-01-01 00:00:00' ) );
$r = run( function ( $n ) { return 1 === $n ? json_encode( array() ) : 'nope'; } );
ok( false !== strpos( (string) $r['skipped'], "\u{201C}A \"q\" title\u{201D}" ), 'titles sit in typographic quotes, so a title with straight quotes still reads cleanly (got ' . var_export( $r['skipped'], true ) . ')' );

$GLOBALS['__rows'] = array( post( 1, '<p>Currently stable.</p>' ), post( 2, '<p>Recently stable.</p>' ), post( 3, '<p>Today stable.</p>' ) );
$r = run( new WP_Error( 'http', 'Invalid key sk-ant-abcdefghijklmnop at https://api.example.test/v1 for ops@example.com' ) );
ok( false === strpos( (string) $r['skipped'], 'sk-ant-' ) && false === strpos( (string) $r['skipped'], 'https://' ) && false === strpos( (string) $r['skipped'], 'ops@' ) && false !== strpos( (string) $r['skipped'], 'Invalid key' ), 'the breaker sentence is scrubbed too (got ' . var_export( $r['skipped'], true ) . ')' );

echo "\nGroup: the answer limit fits a full candidate list\n";
ok( SN_HEALTH_DRIFT_MAX_TOKENS === $GLOBALS['__ai_max'], 'the call passes SN_HEALTH_DRIFT_MAX_TOKENS, not a literal (got ' . var_export( $GLOBALS['__ai_max'], true ) . ')' );
// One compact verdict with a one-sentence reason, measured at 3.5 chars per token.
$sn_one = strlen( json_encode( array( 'phrase' => 'just released', 'verdict' => 'stale', 'reason' => 'The post was last modified in 2024, so calling it just released now misleads readers about timing.' ) ) ) / 3.5;
ok( SN_HEALTH_DRIFT_MAX_TOKENS >= (int) ceil( $sn_one * 25 ), 'the limit holds 25 verdicts (needs ' . (int) ceil( $sn_one * 25 ) . ', have ' . SN_HEALTH_DRIFT_MAX_TOKENS . ')' );

echo "\nGroup: the pre-existing skips still hold\n";
$GLOBALS['__rows'] = false;
$r = run( json_encode( array() ) );
ok( 0 === $r['count'] && is_string( $r['skipped'] ) && false !== stripos( $r['skipped'], 'query failed' ), 'a failed post query is skipped (v13.97.5)' );
$GLOBALS['__rows'] = array();
$r = run( json_encode( array() ) );
ok( 0 === $r['count'] && null === $r['skipped'] && 0 === $GLOBALS['__ai_calls'], 'no candidate posts: ran, found nothing, asked the model nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
