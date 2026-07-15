<?php
/**
 * Standalone fixture tests for inc/analytics-narrator.php.
 *
 * Render-path hardening: sn_analytics_narrate_ai() / sn_analytics_digest_ai()
 * must NEVER call snt_ai_generate_with_constraints() on a cache miss — they
 * schedule a single out-of-band event and degrade to a same-key last-good
 * (else null → the caller's deterministic fallback). Only the paired
 * *_ai_run() functions (the scheduled event's handler) may call the AI
 * client. A counting stub on snt_ai_generate_with_constraints() is the
 * pin: it must stay at 0 across every render-path call in the cache-miss
 * groups below, and increase by exactly 1 per *_ai_run() invocation.
 *
 * Run: php tests/analytics-narrator.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }

// ── AI wrapper — counting stub (the pin) ──
$GLOBALS['__ai_calls']  = 0;
$GLOBALS['__ai_return'] = null;
function snt_ai_generate_with_constraints( $prompt, $system, $max = 256, $feature = 'generic' ) {
	$GLOBALS['__ai_calls']++;
	$GLOBALS['__ai_prompt'] = $prompt; $GLOBALS['__ai_system'] = $system; $GLOBALS['__ai_feature'] = $feature;
	return $GLOBALS['__ai_return'];
}

// ── transient store ──
$GLOBALS['__transients'] = array();
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

// ── option store ──
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }

// ── cron store — args-aware, mirrors tests/provenance-webhook.php's idiom ──
$GLOBALS['__sched'] = array();
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['__sched'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
	return true;
}
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['__sched'] as $e ) {
		if ( $e['hook'] === $hook && $e['args'] === $args ) { return $e['ts'] > 0 ? $e['ts'] : 1; }
	}
	return false;
}
function add_action( $h, $c = null, $p = 10, $a = 1 ) { /* module-scope registration only; tests call *_ai_run() directly */ }

require __DIR__ . '/../inc/analytics-narrator.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$signals = array(
	array( 'kind' => 'anomaly', 'confidence' => 'high', 'plain_label' => 'Views ran above the 30-day norm on 2026-06-20 (4.2σ-robust)' ),
	array( 'kind' => 'trajectory', 'confidence' => 'medium', 'plain_label' => '/notes/x is decaying (-38% over the window)' ),
);
$signals_b = array(
	array( 'kind' => 'anomaly', 'confidence' => 'high', 'plain_label' => 'A completely different signal set' ),
);

echo "Group: deterministic fallback\n";
$html = sn_analytics_narrate_fallback( array(), $signals );
ok( false !== strpos( $html, 'Views ran above' ) && false !== strpos( $html, 'decaying' ), 'fallback: composes the signal plain_labels' );
ok( false !== strpos( sn_analytics_narrate_fallback( array(), array() ), 'nothing needs attention' ), 'fallback: graceful empty when no signals' );

echo "\nGroup: narrate — cache miss NEVER calls the AI client\n";
$GLOBALS['__ai_calls'] = 0;
$r = sn_analytics_narrate( array(), $signals );
ok( 0 === $GLOBALS['__ai_calls'], 'render path: zero AI-client calls on a cache miss' );
ok( 'fallback' === $r['source'] && false !== strpos( $r['narrative'], 'decaying' ), 'render path: renders the deterministic fallback on cache miss' );
list( $narr_prompt, $narr_system ) = sn_analytics_narrate_ai_prompt( $signals );
$narr_key = sn_analytics_ai_cache_key( 'narrate', $narr_prompt, $narr_system, 'analytics_digest' );
ok( false !== wp_next_scheduled( SN_ANALYTICS_NARRATE_HOOK, array( array(), $signals ) ), 'cache miss schedules the out-of-band generator with the render args' );

echo "\nGroup: narrate — dedupe (wp_next_scheduled guard)\n";
$sched_before = count( $GLOBALS['__sched'] );
sn_analytics_narrate( array(), $signals );
ok( 0 === $GLOBALS['__ai_calls'], 'second cache-miss render still makes zero AI-client calls' );
ok( count( $GLOBALS['__sched'] ) === $sched_before, 'second cache-miss render does NOT schedule a duplicate event' );

echo "\nGroup: narrate — the event handler is the ONLY caller of the AI client\n";
$GLOBALS['__ai_return'] = 'Views spiked on the 20th; /notes/x is fading — refresh it.';
sn_analytics_narrate_ai_run( array(), $signals );
ok( 1 === $GLOBALS['__ai_calls'], 'sn_analytics_narrate_ai_run() calls the AI client exactly once' );
ok( 'analytics_digest' === $GLOBALS['__ai_feature'], 'run() tags the call with the narrate feature label' );
$cached = get_transient( $narr_key );
ok( is_array( $cached ) && false !== strpos( $cached['narrative'], 'refresh it' ), 'run() writes the generated text under the SAME key the render path computed' );

echo "\nGroup: narrate — cache hit renders cached text, zero further AI calls\n";
$r2 = sn_analytics_narrate( array(), $signals );
ok( 1 === $GLOBALS['__ai_calls'], 'cache-hit render makes NO additional AI-client call' );
ok( 'ai' === $r2['source'] && false !== strpos( $r2['narrative'], 'refresh it' ), 'cache-hit render serves the cached AI narrative' );

echo "\nGroup: narrate — last-good degrades a same-key eviction, but not a different key\n";
$last_good_before = get_option( SN_ANALYTICS_NARRATE_LASTGOOD_OPT );
ok( $narr_key === ( $last_good_before['key'] ?? '' ), 'run() persisted a last-good stamped with the cache key' );
delete_transient( $narr_key ); // simulate an object-cache flush — the underlying signals have NOT changed
$r3 = sn_analytics_narrate( array(), $signals );
ok( 1 === $GLOBALS['__ai_calls'], 'transient eviction (same signals) still makes zero new AI-client calls' );
ok( 'ai' === $r3['source'] && false !== strpos( $r3['narrative'], 'refresh it' ), 'transient eviction degrades to the same-key last-good instead of the bare fallback' );
// A DIFFERENT signal set must NOT reuse that last-good (would be stale-wrong text).
$r4 = sn_analytics_narrate( array(), $signals_b );
ok( 1 === $GLOBALS['__ai_calls'], 'a different signal set still makes zero AI-client calls on its own cache miss' );
ok( 'fallback' === $r4['source'], 'a different signal set does NOT inherit the other key\'s last-good' );

echo "\nGroup: narrate — budget-cap WP_Error leaves the cache untouched (FIX 1c)\n";
$GLOBALS['__ai_calls']   = 0;
$GLOBALS['__ai_return']  = new WP_Error();
$fresh_signals = array( array( 'kind' => 'anomaly', 'confidence' => 'high', 'plain_label' => 'Yet another distinct signal' ) );
sn_analytics_narrate_ai_run( array(), $fresh_signals );
ok( 1 === $GLOBALS['__ai_calls'], 'run() still calls the AI client once even when it returns WP_Error' );
list( $fp, $fs ) = sn_analytics_narrate_ai_prompt( $fresh_signals );
$fresh_key = sn_analytics_ai_cache_key( 'narrate', $fp, $fs, 'analytics_digest' );
ok( false === get_transient( $fresh_key ), 'WP_Error result is NOT cached' );
$r5 = sn_analytics_narrate( array(), $fresh_signals );
ok( 'fallback' === $r5['source'], 'render after a budget-cap error still degrades to the deterministic fallback (unchanged behavior)' );

echo "\nGroup: narrate() seam — no signals / filter override\n";
$GLOBALS['__ai_calls'] = 0;
$r6 = sn_analytics_narrate( array(), array() );
ok( 'fallback' === $r6['source'] && 0 === $GLOBALS['__ai_calls'], 'narrate: no signals → fallback empty-state, no AI call' );

echo "\nGroup: weekly digest — deterministic fallback\n";
$digest_summary = array( 'views' => 1204, 'visits' => 389 );
$dh = sn_analytics_digest_fallback( $digest_summary, $signals );
ok( false !== strpos( $dh, '1,204 views' ) && false !== strpos( $dh, '389 visits' ), 'digest fallback: leads with the descriptive summary line' );
ok( false !== strpos( $dh, 'sn-an-digest-list' ) && false !== strpos( $dh, 'decaying' ) && false !== strpos( $dh, 'Start here:' ), 'digest fallback: signal list + a concrete start-here line' );
ok( false !== strpos( sn_analytics_digest_fallback( array(), array() ), 'nothing needs attention' ), 'digest fallback: graceful empty without summary' );
$many = array(); for ( $i = 0; $i < 10; $i++ ) { $many[] = array( 'plain_label' => 'signal number ' . $i ); }
ok( 8 === substr_count( sn_analytics_digest_fallback( array(), $many ), '<li>' ), 'digest fallback: caps the list at 8 items' );

echo "\nGroup: digest — cache miss NEVER calls the AI client\n";
$GLOBALS['__ai_calls'] = 0;
$dg = sn_analytics_digest( $digest_summary, $signals );
ok( 0 === $GLOBALS['__ai_calls'], 'render path: zero AI-client calls on a cache miss' );
ok( 'fallback' === $dg['source'] && false !== strpos( $dg['digest'], '1,204 views' ), 'render path: renders the deterministic fallback on cache miss' );
ok( false !== wp_next_scheduled( SN_ANALYTICS_DIGEST_HOOK, array( $digest_summary, $signals, '' ) ), 'cache miss schedules the out-of-band generator with the render args' );

echo "\nGroup: digest — top_action reaches the prompt and changes the cache key\n";
list( $dp1, $ds1 ) = sn_analytics_digest_ai_prompt( $digest_summary, $signals, '' );
list( $dp2, $ds2 ) = sn_analytics_digest_ai_prompt( $digest_summary, $signals, '3 cooling posts worth a refresh' );
ok( false === strpos( $dp1, 'Top recommended action' ), 'digest prompt: no action, no context line' );
ok( false !== strpos( $dp2, 'Top recommended action: 3 cooling posts worth a refresh' ), 'digest prompt: top-action context reaches the AI prompt' );
$k1 = sn_analytics_ai_cache_key( 'digest', $dp1, $ds1, 'analytics_digest_weekly' );
$k2 = sn_analytics_ai_cache_key( 'digest', $dp2, $ds2, 'analytics_digest_weekly' );
ok( $k1 !== $k2, 'different top_action → different cache key (would otherwise serve stale-wrong text)' );

echo "\nGroup: digest — the event handler is the ONLY caller of the AI client\n";
$GLOBALS['__ai_return'] = "Strong week. Views spiked on the 20th.\n\nNext: refresh /notes/x.";
sn_analytics_digest_ai_run( $digest_summary, $signals, '' );
ok( 1 === $GLOBALS['__ai_calls'], 'sn_analytics_digest_ai_run() calls the AI client exactly once' );
ok( 'analytics_digest_weekly' === $GLOBALS['__ai_feature'], 'run() tags the call with the digest feature label' );
$dg_cached = get_transient( $k1 );
ok( is_array( $dg_cached ) && false !== strpos( $dg_cached['digest'], 'refresh /notes/x' ), 'run() writes the generated text under the SAME key the render path computed' );

echo "\nGroup: digest — cache hit renders cached text, zero further AI calls\n";
$dg2 = sn_analytics_digest( $digest_summary, $signals );
ok( 1 === $GLOBALS['__ai_calls'], 'cache-hit render makes NO additional AI-client call' );
ok( 'ai' === $dg2['source'] && false !== strpos( $dg2['digest'], 'refresh /notes/x' ), 'cache-hit render serves the cached AI digest' );

echo "\nGroup: digest — budget-cap WP_Error leaves the cache untouched (FIX 1c)\n";
$GLOBALS['__ai_calls']  = 0;
$GLOBALS['__ai_return'] = new WP_Error();
sn_analytics_digest_ai_run( $digest_summary, $signals_b, '' );
ok( 1 === $GLOBALS['__ai_calls'], 'run() still calls the AI client once even when it returns WP_Error' );
list( $bp, $bs ) = sn_analytics_digest_ai_prompt( $digest_summary, $signals_b, '' );
$b_key = sn_analytics_ai_cache_key( 'digest', $bp, $bs, 'analytics_digest_weekly' );
ok( false === get_transient( $b_key ), 'WP_Error result is NOT cached' );
$dg3 = sn_analytics_digest( $digest_summary, $signals_b );
ok( 'fallback' === $dg3['source'], 'digest: budget-cap WP_Error → deterministic fallback (unchanged behavior)' );

echo "\nGroup: digest() seam — no signals\n";
$GLOBALS['__ai_calls'] = 0;
ok( 'fallback' === sn_analytics_digest( $digest_summary, array() )['source'], 'digest: no signals → fallback empty-state' );
ok( 0 === $GLOBALS['__ai_calls'], 'no-signals path makes zero AI-client calls' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
