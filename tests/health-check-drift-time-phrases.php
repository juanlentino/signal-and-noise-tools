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

class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } }
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

echo "\nGroup: the pre-existing skips still hold\n";
$GLOBALS['__rows'] = false;
$r = run( json_encode( array() ) );
ok( 0 === $r['count'] && is_string( $r['skipped'] ) && false !== stripos( $r['skipped'], 'query failed' ), 'a failed post query is skipped (v13.97.5)' );
$GLOBALS['__rows'] = array();
$r = run( json_encode( array() ) );
ok( 0 === $r['count'] && null === $r['skipped'] && 0 === $GLOBALS['__ai_calls'], 'no candidate posts: ran, found nothing, asked the model nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
