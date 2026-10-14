<?php
/**
 * Signal & Noise Tools — Insights Tab + Open-Question Advisor.
 *
 * Cross-system AI synthesis: reads first-party analytics + WP publish
 * history + webhook delivery patterns + cron firings + site identity in a
 * single AI call that surfaces, at most, a few UNEXPLORED OPEN QUESTIONS
 * worth developing for the site's Notes, or nothing. It does not prescribe
 * posts and there is no content calendar to fill. A hard wall (see
 * snt_insights_recommendation_blocked) drops any value-prop, product or
 * commerce framing, any answer-announcement, and the reserved
 * weighted-identity / provenance-without-institutions thesis.
 *
 * 4-surface dispatch (same pattern as Cron/Webhooks/Health):
 *   - wp-admin form (Insights tab → Run Analysis button)
 *   - REST POST  /signal-noise/v1/insights/run
 *   - REST GET   /signal-noise/v1/insights/last
 *   - Abilities API: signal-noise/run-insights-scan + get-insights
 *   - desktop-mode ⌘K: sn-cmd-insights (aiCallable)
 *
 * All four converge on the snt_insights_*_impl() pure functions below.
 *
 * @package SignalNoiseTools
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v6.51.0: cache key bumped to _v2 so any 7-day-cached scan written under the
// old recommendation shape (type/title/rationale/evidence_pills) expires out
// rather than rendering through the new open-question fields.
define( 'SN_INSIGHTS_CACHE_KEY',         'sn_insights_last_scan_v2' );
define( 'SN_INSIGHTS_CACHE_TTL',         7 * DAY_IN_SECONDS );
define( 'SN_INSIGHTS_STATE_OPT',         'sn_insights_state' );
// v7.0.1: ephemeral diagnostic — the code + message of the most recent scan
// FAILURE, so the admin surface can report the REAL error instead of a blanket
// "configure AI". Consumed by the very next admin render (Post/Redirect/Get),
// so a short TTL is right; it is never durable state.
define( 'SN_INSIGHTS_LAST_ERROR_KEY',    'sn_insights_last_error' );
define( 'SN_INSIGHTS_CRON_HOOK',         'sn_insights_weekly_scan' );
define( 'SN_INSIGHTS_POST_CAP',          100 );
define( 'SN_INSIGHTS_POST_MAX_AGE_DAYS', 730 );  // 2 years
define( 'SN_INSIGHTS_STATE_LIST_CAP',    200 );  // FIFO cap per state list
define( 'SN_INSIGHTS_SNOOZE_DAYS',       30 );
// Upper bound on how many open questions a single scan surfaces. There is NO
// lower bound: zero questions ("recommend nothing") is a valid, expected result.
define( 'SN_INSIGHTS_REC_MAX',           3 );
// Max OUTPUT tokens for the scan's AI call.
//
// v7.1.1 bumped 1500 → 2048 after a live run truncated mid-object: the model
// writes elaborate, multi-clause questions and 1500 could not finish 3 of them.
// That reading was right about the symptom and wrong about the cause.
//
// v13.20.5 raises it to the clamp, on a MEASUREMENT rather than a guess. Two
// live failures (2026-08-28) reported their own token accounting once
// snt_insights_annotate_budget() existed: the call consumed its ENTIRE 2048-token
// budget and returned 310 characters of well-formed JSON, cut mid-string. 310
// chars is ~80 tokens, so ~1,970 output tokens were generated and BILLED without
// ever reaching the text. That is extended thinking — it bills as output and
// counts against max_tokens, but never appears in the returned string. Ordinary
// truncation would have produced ~8,000 characters, not 310.
//
// So the budget must cover BOTH: ~1,970 for thinking plus the ~2,048 v7.1.1
// established the text needs. 4096 is the wrapper's hard clamp
// (snt_ai_generate_with_constraints does min(4096, …)) and lands just above that
// sum. If thinking scales with the budget rather than staying flat, this fails
// again — and says so itself now, reporting 4,096 beside a short answer. The
// real fix is turning thinking off for this call, which is not reachable from
// this plugin: the AI client is a separate plugin and we set no thinking config.
//
// Cost: output bills only when generated. Worst case ~$0.06/scan at Sonnet
// rates, on a rare manual/weekly call.
define( 'SN_INSIGHTS_MAX_TOKENS',        4096 );

// ── Body-grounding (v4.11.0): attach bounded content excerpts to the
// top posts so the advisor reasons about substance, not just metadata.
// Zero new AI calls — these ride along in the existing weekly prompt.
define( 'SN_INSIGHTS_EXCERPT_CAP',         25 );    // attach to at most the top-N posts
define( 'SN_INSIGHTS_EXCERPT_WORDS',       120 );   // per-excerpt word cap
define( 'SN_INSIGHTS_EXCERPT_TOTAL_CHARS', 60000 ); // hard backstop on the combined excerpt payload

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/insights-signals-parts.php';
require_once __DIR__ . '/insights-ai-parts.php';
require_once __DIR__ . '/insights-state-parts.php';
