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

require __DIR__ . '/../inc/ai-markdown-strip.php'; // real shared stripper (v9.64.2) — the run paths call it
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

echo "\nGroup: weekly digest — deterministic fallback (honest vocabulary, v9.64.1)\n";
// Legacy caller shape: only the deprecated ungated pair — the ungated count is
// unique visitor-DAYS (analytics-integrity spec §1), so the head must say
// "visitor-days" and never call it "visits".
$digest_summary = array( 'views' => 1204, 'visits' => 389 );
$dh = sn_analytics_digest_fallback( $digest_summary, $signals );
ok( false !== strpos( $dh, 'This period: 1,204 views across 389 visitor-days.' ), 'digest fallback (legacy summary): ungated count is called visitor-days, value-for-value' );
ok( false === strpos( $dh, '389 visits' ), 'digest fallback (legacy summary): never labels the ungated count "visits"' );
ok( false !== strpos( $dh, 'sn-an-digest-list' ) && false !== strpos( $dh, 'decaying' ) && false !== strpos( $dh, 'Start here:' ), 'digest fallback: signal list + a concrete start-here line' );
ok( false !== strpos( sn_analytics_digest_fallback( array(), array() ), 'nothing needs attention' ), 'digest fallback: graceful empty without summary' );
$many = array(); for ( $i = 0; $i < 10; $i++ ) { $many[] = array( 'plain_label' => 'signal number ' . $i ); }
ok( 8 === substr_count( sn_analytics_digest_fallback( array(), $many ), '<li>' ), 'digest fallback: caps the list at 8 items' );

// The LIVE 7d fixture (owner screenshots 2026-07-18): views 47, gated visits 40,
// 90 unique visitor-days of which 50 viewless. The real sn_analytics_range_totals()
// shape (post-v9.63.0) carries all four + the strict integrity_violation bool.
$honest_summary = array(
	'views' => 47, 'visits' => 90, 'scroll_avg' => 62.0, 'time_avg' => 41.0,
	'unique_visitor_days' => 90, 'pageview_visits' => 40, 'viewless_visits' => 50,
	'view_visit_ratio' => 1.175, 'pageviews_per_visitor_day' => 0.522,
	'scroll_avg_per_view' => 58.0, 'time_avg_per_view' => 39.0,
	'scroll_avg_per_visit' => 30.0, 'time_avg_per_visit' => 20.0,
	'integrity_violation' => false, 'exact_metrics_since' => '2026-07-01',
);
$hh = sn_analytics_digest_fallback( $honest_summary, $signals );
ok( false !== strpos( $hh, 'This period: 47 views, 40 visits (90 visitor-days, 50 of them viewless).' ), 'digest fallback (honest summary): gated visits headline + visitor-day breakdown, value-for-value' );
ok( false === strpos( $hh, '90 visits' ), 'digest fallback (honest summary): the ungated 90 is never called "visits"' );
ok( false === strpos( $hh, 'Integrity alert' ), 'digest fallback (honest summary): no integrity alert when the invariant holds' );

// The impossible case (integrity_violation): views < pageview_visits — the ONLY
// branch where "anomaly"/alert language is correct.
$violation_summary = array_merge( $honest_summary, array( 'views' => 30, 'integrity_violation' => true ) );
$vh = sn_analytics_digest_fallback( $violation_summary, $signals );
ok( false !== strpos( $vh, 'Integrity alert' ) && false !== strpos( $vh, 'impossible' ), 'digest fallback (integrity violation): still narrates as an alert-worthy anomaly' );

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

echo "\nGroup: digest prompt — honest vocabulary + the explained is no anomaly (v9.64.1)\n";
// Live fixture (47/40/90/50): the prompt must feed the model the gated visits,
// the visitor-day totals WITH their definitions, and the structural explanation
// for visitor-days > views — the model can then never honestly claim "no
// explanation is given in the data".
list( $hp, $hs ) = sn_analytics_digest_ai_prompt( $honest_summary, $signals, '' );
ok( false !== strpos( $hp, '- Views this period: 47' ), 'honest prompt: views fact kept' );
ok( false !== strpos( $hp, 'visitor-days with at least one pageview' ) && false !== strpos( $hp, ': 40' ), 'honest prompt: gated visits fact carries its definition + the KPI-matching 40' );
ok( false !== strpos( $hp, 'including feed/RSS reads with zero pageviews' ) && false !== strpos( $hp, ': 90' ), 'honest prompt: unique visitor-days fact carries its definition + the 90' );
ok( false !== strpos( $hp, 'Viewless visitor-days' ) && false !== strpos( $hp, ': 50' ), 'honest prompt: viewless fact present with the 50' );
ok( false === strpos( $hp, '- Visits this period: 90' ), 'honest prompt: the ungated 90 is NEVER labeled "Visits" (the v9.64.0-era fact is gone)' );
ok( false !== strpos( $hp, 'Structural note: 90 visitor-days exceed 47 views because 50 visitor-days were viewless' ), 'honest prompt: the structural explanation is IN the payload, value-for-value' );
ok( false !== strpos( $hp, 'not an anomaly' ), 'honest prompt: the structural note names itself not-an-anomaly' );
ok( false !== strpos( $hs, 'NEVER describe the gap as unusual, unexplained, or an anomaly' ), 'honest system: forbids the "unexplained anomaly" claim for the structural case' );
ok( false !== strpos( $hs, 'never to be called "visits"' ), 'honest system: visitor-days are never to be called "visits"' );

// Genuine-anomaly branch: ONLY the impossible views < pageview_visits case.
list( $vp, ) = sn_analytics_digest_ai_prompt( $violation_summary, $signals, '' );
ok( false !== strpos( $vp, 'DATA INTEGRITY ANOMALY' ), 'violation prompt: integrity_violation still narrates as a genuine anomaly' );
ok( false === strpos( $vp, 'Structural note' ), 'violation prompt: the structural not-an-anomaly note is suppressed' );

// Legacy summary (no gated fields): the ungated count degrades to visitor-days
// vocabulary — never to "Visits".
list( $lp, ) = sn_analytics_digest_ai_prompt( $digest_summary, $signals, '' );
ok( false !== strpos( $lp, 'Visitor-days this period' ) && false !== strpos( $lp, ': 389' ), 'legacy prompt: ungated count is labeled visitor-days' );
ok( false === strpos( $lp, '- Visits this period: 389' ), 'legacy prompt: ungated count is never labeled "Visits"' );

echo "\nGroup: narrate system — signal vocabulary (v9.64.1)\n";
list( , $nsys ) = sn_analytics_narrate_ai_prompt( $signals );
ok( false !== strpos( $nsys, 'unique visitor-days' ), 'narrate system: defines signal "visits" as unique visitor-days' );
ok( false !== strpos( $nsys, 'never an anomaly' ), 'narrate system: visitor-days exceeding views is structural, never an anomaly' );

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

echo "\nGroup: v9.64.2 voice contract — digest system instruction (P1a + P2)\n";
list( , $vs ) = sn_analytics_digest_ai_prompt( $honest_summary, $signals, '' );
ok( false !== strpos( $vs, 'NO markdown — no asterisks, no underscores, no headings, no bullet lists, no emojis' ), 'digest system: forbids markdown entirely (P1a)' );
ok( false !== strpos( $vs, 'never write sigma, σ, backtest, interval, robust, confidence, or point estimate' ), 'digest system: bans the stats-appendix jargon — the chips carry that machinery (P2)' );
ok( false !== strpos( $vs, 'at most 4-5 short plain-English sentences' ), 'digest system: sentence budget for a phone-glance summary' );
ok( false !== strpos( $vs, '"Worth a look:"' ), 'digest system: the optional Worth-a-look closer' );
ok( false !== strpos( $vs, 'State numbers plainly (47 views, 40 visits)' ), 'digest system: numbers stated plainly, no interval dressing' );
ok( false !== strpos( $vs, '("expect a quiet week")' ) && false !== strpos( $vs, 'never as numbers with intervals' ), 'digest system: forecasts only in plain words, never numbers-with-intervals' );
ok( false !== strpos( $vs, 'at most one plain sentence' ), 'digest system: a genuine anomaly gets one plain sentence, max' );
ok( false !== strpos( $vs, 'NEVER describe the gap as unusual, unexplained, or an anomaly' ), 'digest system: v9.64.1 structural-not-anomaly rule kept intact (voice change, not facts)' );
ok( false !== strpos( $vs, 'never to be called "visits"' ), 'digest system: v9.64.1 honest vocabulary kept intact' );

echo "\nGroup: v9.64.2 voice contract — narrate system instruction\n";
list( , $vns ) = sn_analytics_narrate_ai_prompt( $signals );
ok( false !== strpos( $vns, 'NO markdown — no asterisks, no underscores, no headings, no bullet lists, no emojis' ), 'narrate system: forbids markdown entirely' );
ok( false !== strpos( $vns, 'never write sigma, σ, backtest, interval, robust, confidence, or point estimate' ), 'narrate system: bans the stats-appendix jargon' );
ok( false !== strpos( $vns, 'unique visitor-days' ) && false !== strpos( $vns, 'never an anomaly' ), 'narrate system: v9.64.1 vocabulary + structural rules kept intact' );

echo "\nGroup: v9.64.2 — an instruction change busts the AI cache key (P3)\n";
ok( sn_analytics_ai_cache_key( 'digest', $dp1, 'system text A', 'analytics_digest_weekly' )
	!== sn_analytics_ai_cache_key( 'digest', $dp1, 'system text B', 'analytics_digest_weekly' ),
	'cache key is a function of the system instruction — the voice-contract change orphans the stored pre-voice digest' );

echo "\nGroup: v9.64.2 — markdown stripper, exact transforms (P1b)\n";
ok( 'Weekly Analytics Digest' === snt_ai_strip_markdown( '**Weekly Analytics Digest**' ), 'stripper: **bold** marks REMOVED, text kept (the live headline)' );
ok( 'Head' === snt_ai_strip_markdown( '## Head' ), 'stripper: heading marker removed' );
ok( 'x' === snt_ai_strip_markdown( '*x*' ), 'stripper: *italic* marks removed' );
ok( '25 × 4' === snt_ai_strip_markdown( '25 × 4' ), 'stripper: multiplication sign untouched' );
ok( '2 * 3' === snt_ai_strip_markdown( '2 * 3' ), 'stripper: spaced-asterisk arithmetic untouched' );
ok( 'bold' === snt_ai_strip_markdown( '__bold__' ), 'stripper: __bold__ marks removed' );
ok( 'emphasis' === snt_ai_strip_markdown( '_emphasis_' ), 'stripper: _italic_ marks removed' );
ok( 'pageview_visits stayed flat' === snt_ai_strip_markdown( 'pageview_visits stayed flat' ), 'stripper: intra-word underscores (field names) untouched' );
ok( "Weekly\nViews rose." === snt_ai_strip_markdown( "### Weekly\nViews rose." ), 'stripper: multiline heading marker removed, body kept' );

echo "\nGroup: v9.64.2 — the run paths store STRIPPED text (defense-in-depth, P1b)\n";
$GLOBALS['__ai_calls']  = 0;
$GLOBALS['__ai_return'] = "**Weekly Analytics Digest**\n\nViews rose to *47* this week.";
$md_signals = array( array( 'kind' => 'anomaly', 'confidence' => 'high', 'plain_label' => 'A markdown-prone signal' ) );
sn_analytics_digest_ai_run( $digest_summary, $md_signals, '' );
list( $mdp, $mds ) = sn_analytics_digest_ai_prompt( $digest_summary, $md_signals, '' );
$md_cached = get_transient( sn_analytics_ai_cache_key( 'digest', $mdp, $mds, 'analytics_digest_weekly' ) );
ok( is_array( $md_cached ) && false !== strpos( (string) $md_cached['digest'], 'Weekly Analytics Digest' ), 'digest run: the heading TEXT survives stripping' );
ok( is_array( $md_cached ) && false === strpos( (string) $md_cached['digest'], '**' ), 'digest run: no ** ever reaches the stored digest' );
ok( is_array( $md_cached ) && false !== strpos( (string) $md_cached['digest'], 'to 47 this week' ), 'digest run: italic marks removed, the number kept' );

$GLOBALS['__ai_return'] = '**Views spiked.** Refresh _the notes_.';
sn_analytics_narrate_ai_run( array(), $md_signals );
list( $mnp, $mns ) = sn_analytics_narrate_ai_prompt( $md_signals );
$mn_cached = get_transient( sn_analytics_ai_cache_key( 'narrate', $mnp, $mns, 'analytics_digest' ) );
ok( is_array( $mn_cached ) && false !== strpos( (string) $mn_cached['narrative'], 'Views spiked. Refresh the notes.' ), 'narrate run: bold + italic marks removed, prose intact' );
ok( is_array( $mn_cached ) && false === strpos( (string) $mn_cached['narrative'], '**' ) && false === strpos( (string) $mn_cached['narrative'], '_the' ), 'narrate run: no emphasis marks reach the stored narrative' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
