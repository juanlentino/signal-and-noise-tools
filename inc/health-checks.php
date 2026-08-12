<?php
/**
 * Signal & Noise Tools — Content Health checks.
 *
 * Detection-only scans of the post / attachment graph. Four independent
 * checks, all dispatched from a single "Run scan" button on the Health
 * admin tab. Results cache for 24h in a transient — visiting the tab
 * shows the last scan; clicking "Run scan" re-computes and overwrites.
 *
 * The checks intentionally do NOT call any AI / LLM service in v1.
 * Findings are surfaced as plain lists with deep-links to the editor;
 * the user fixes them manually for the read-only checks; AI-assisted
 * Suggest+Apply ships for missing_alt + drift_time_phrases (v4.0.0) and
 * orphaned_media (v4.1.0).
 *
 * The 5 checks (as of v4.1.0):
 *
 *   1. missing_alt          — image attachments and inline <img> tags
 *                             without an alt attribute. AI Suggest+Apply.
 *   2. orphaned_media       — image attachments not used as a featured
 *                             image and not referenced in any post body
 *                             (image MIME only since v4.1.1, B-02).
 *                             AI verdict + force-delete since v4.1.0.
 *   3. broken_links         — internal links in post_content that 404 or
 *                             return network errors (cached HEAD requests).
 *   4. stale_posts          — published posts unedited in the last 12 months
 *                             (excluding those flagged `_sn_evergreen`, v8.11.0 B5).
 *                             Read-only; AI Suggest was scoped out of v4.1.0
 *                             per evergreen-site mismatch.
 *   5. drift_time_phrases   — time-relative phrases (recently, last year,
 *                             as of YYYY) whose meaning decays. AI verdict
 *                             since v3.7.0; Suggest+Apply since v4.0.0
 *                             (raw-position resolver fix v4.1.1, B-01).
 *   6. unlinked_mentions    — a note mentions another note's title without
 *                             linking it (v7.4.0). Zero-AI at scan time;
 *                             AI Suggest+Apply via inc/ai-link-suggest.php.
 *
 * @package SignalNoiseTools
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v6.47.2: the scan result is stored in a DURABLE option (autoload=no), not a
// transient. On a site with a persistent object cache (e.g. Breeze/Redis on
// Cloudways), transients live in the object cache, so the cache flush a caching
// plugin fires on a plugin update wiped the last scan — the owner had to re-run
// it after every update. An option is a real wp_options row that survives object-
// cache flushes, so the scan now persists until the next manual run. The KEY name
// is unchanged (it does not collide: a transient was stored under the
// `_transient_`-prefixed option, never this bare key).
define( 'SN_HEALTH_CACHE_KEY',     'sn_health_last_scan' );
// No longer a hard expiry (the option never auto-expires). Retained as the
// "scan is stale" DISPLAY threshold: the Dashboard attention strip flags a scan
// older than this so the user knows to re-run (inc/admin-tab-dashboard.php).
define( 'SN_HEALTH_CACHE_TTL',     DAY_IN_SECONDS );
define( 'SN_HEALTH_STALE_MONTHS',  12 );
define( 'SN_HEALTH_LINK_CACHE_TTL', DAY_IN_SECONDS );
// v4.9.0 (T1): Cloudflare security-header drift probe caches for 6h. The
// transient holds the array of MISSING header names; an empty array means the
// edge delivered all 5 delegated headers on the last probe.
define( 'SN_HEALTH_CF_HEADERS_TTL', 6 * HOUR_IN_SECONDS );
// v4.1.1 (B-10): cap candidates per post in drift-detection. AI max_tokens=600
// budgets for ~25 verdicts; truncation mid-JSON would drop the post silently.
define( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST', 25 );
define( 'SN_HEALTH_LINK_TIMEOUT',  5 );
// v7.4.0: cap pairs per source in the unlinked-mentions check. One prolific
// source could otherwise flood the findings table; the remainder surfaces on
// the next scan after the first batch is fixed.
define( 'SN_HEALTH_MENTIONS_MAX_PER_SOURCE', 5 );

// v4.2.0 PROMPT DESIGN (D-09): paired with inc/ai-drift-phrase-suggest.php's
// SNT_AI_DRIFT_SUGGEST_SYSTEM. Detection and suggestion are intentionally
// split — this prompt returns flagged positions; the suggest prompt proposes
// replacement phrases.
const SNT_AI_DRIFT_SYSTEM = "You are an editor evaluating whether time-relative phrases in a post are still accurate given the post's last_modified date vs. 'now'.\n\n" .
	"For each candidate in the input JSON, return ONLY a JSON array of objects:\n" .
	"[{\"phrase\": \"<phrase>\", \"verdict\": \"stale\" | \"ok\" | \"unsure\", \"reason\": \"<one sentence>\"}]\n\n" .
	"Rules:\n" .
	"- Be conservative. Only return \"stale\" if the phrase is materially misleading given the date gap.\n" .
	"- \"as of YYYY\" is ok if YYYY >= last_modified year; stale if the gap > 1 year and the surrounding context implies current state.\n" .
	"- \"recently\" / \"just released\" are stale when last_modified is > 12 months ago.\n" .
	"- \"this year\" / \"this month\" are stale when last_modified year/month doesn't match now.\n" .
	"- \"the latest\" is unsure (cannot verify without external knowledge).\n" .
	"- Output JSON only. No markdown, no preamble.";

/**
 * Run all 4 checks and cache the combined result. Returns the array
 * regardless of cache state (callers wanting the cached version
 * should sn_health_last_scan() instead).
 */
function sn_health_run_scan() {
	$started = microtime( true );

	$result = array(
		'scanned_at'   => time(),
		'elapsed_ms'   => 0,
		'site_url'     => home_url( '/' ),
		'checks'       => array(
			'missing_alt'         => sn_health_check_missing_alt(),
			'orphaned_media'      => sn_health_check_orphaned_media(),
			'broken_links'        => sn_health_check_broken_links(),
			'external_links'      => sn_health_check_external_links(),
			'stale_posts'         => sn_health_check_stale_posts(),
			'drift_time_phrases'  => sn_health_check_drift_time_phrases(),
			'color_drift'         => sn_health_check_color_drift(),
			'unlinked_mentions'   => sn_health_check_unlinked_mentions(),
			'link_opportunities'  => sn_health_check_link_opportunities(),
			'cf_security_headers' => sn_health_check_cf_security_headers(),
			'edge_workers'        => sn_health_check_edge_workers(),
			// 12th check (v9.65.0): the reader of sn_analytics_integrity_alert —
			// the never-invert guard's alarm finally lands somewhere.
			'analytics_integrity' => sn_health_check_analytics_integrity(),
			// 13th check (v9.80.0): the server-side provenance integrity sweep —
			// bounded, rotating triangle check (payload hash / live .json twin /
			// public ledger + key file) over the anchored-Note fleet.
			'provenance_integrity' => sn_health_check_provenance_integrity(),
			// 14th check (v9.85.0): the rights-signals drift probe. Verifies the
			// Phase 1 rights surface live at the edge (tdmrep.json, license.xml,
			// the robots.txt Content-Signal + License lines, TDM headers on HTML
			// and /wp-json); a failure raises the standing attention chip.
			'rights_signals'       => snt_health_check_rights_signals(),
			// 15th check (v10.4.0): the public ledger's own CI. Its daily verify
			// ran red for three unseen days (2026-07-25..28) — workflow failures
			// live where nobody looks; this surfaces them on the attention chip.
			'ledger_ci'            => snt_health_check_ledger_ci(),
			// 16th check (v10.20.0): the ML cousin scan rides the 24h health
			// cycle — the cached count is what lets the attention badge and
			// health widget show it without ever computing on a pageload.
			'ml_cousins'           => sn_health_check_ml_cousins(),
			// 17th check (v10.22.0): cadence deviations — the kernel's EWMA/z
			// rhythm watch over publishing + recorded cron hooks.
			'ml_cadence'           => sn_health_check_ml_cadence(),
			// 18th check (v10.39.0): the rights-signal ANCHORING gap. The 14th
			// check asks whether the live rights surface is correct and the
			// ledger's own CI asks whether every anchored claim is sound — a
			// worker that silently stopped re-anchoring leaves both green
			// forever. This one compares the bytes being served right now
			// against the newest ledger record.
			'rights_anchored'      => snt_health_check_rights_anchored(),
			// 19th check (v10.82.0): token-level contrast, REPORT ONLY — the
			// Accessibility planned row's first half. Zero findings by design
			// (fixes are a later step against pairs a reader actually sees);
			// the payload is the pair table + a coverage sentence that says
			// this is the arithmetic tier, so a clean sweep here can never
			// be mistaken for a clean site.
			'contrast_tokens'      => sn_health_check_contrast_tokens(),
			// 20th check (report only): Motion that asks first — every declared
			// animation/transition checked for a reduced-motion counterpart
			// (gated behind no-preference, or set to none under reduce). The
			// report is the deliverable; its DETAILED table renders with the
			// Health-tab IA redesign — the checks list carries the label and
			// zero-count meanwhile, which is honest for a report-only tier.
			'motion_scan'          => sn_health_check_motion_scan(),
		),
	);
	$result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

	sn_health_store_scan( $result );
	return $result;
}

/**
 * Persist a scan result durably.
 *
 * v6.47.2: an autoload=no option (not a transient) so the scan survives the
 * object-cache flush a caching plugin fires on a plugin update. autoload=no keeps
 * it out of the per-request alloptions load — it is only read on the Health tab
 * and the Dashboard, both manage_options admin screens.
 *
 * @param array $result The sn_health_run_scan() result.
 */
function sn_health_store_scan( $result ) {
	update_option( SN_HEALTH_CACHE_KEY, $result, false );
}

function sn_health_last_scan() {
	$stored = get_option( SN_HEALTH_CACHE_KEY );
	return is_array( $stored ) ? $stored : null;
}


// v9.81.0: the eight self-contained checks split into per-check modules
// (verbatim, zero renames) under this thin orchestrator -- same pattern as
// the analytics-render-*.php split. Shared constants above; the shared
// sn_health_pack_check() helper below.
require_once __DIR__ . '/health-alt-quality.php';
require_once __DIR__ . '/health-check-missing-alt.php';
require_once __DIR__ . '/health-check-orphaned-media.php';
require_once __DIR__ . '/health-check-broken-links.php';
require_once __DIR__ . '/health-check-stale-posts.php';
require_once __DIR__ . '/health-check-drift-time-phrases.php';
require_once __DIR__ . '/health-check-color-drift.php';
require_once __DIR__ . '/health-check-unlinked-mentions.php';
require_once __DIR__ . '/health-check-cf-security-headers.php';
// v9.85.0 (Session 3 lane 3): the rights-signals drift probe, same pattern as
// the cf-security-headers module above.
require_once __DIR__ . '/health-check-rights-signals.php';
// v10.4.0: the public ledger CI status probe (GitHub runs API, no auth).
require_once __DIR__ . '/health-check-ledger-ci.php';
require_once __DIR__ . '/health-check-rights-anchored.php';
// v10.20.0: the ML near-duplicate cousin scan as a health check.
require_once __DIR__ . '/health-check-ml-cousins.php';
// v10.22.0: cadence deviations (publish + cron rhythms) as a health check.
require_once __DIR__ . '/health-check-ml-cadence.php';
require_once __DIR__ . '/health-contrast-tokens.php';
// v10.90.0: the usage tier that contrast_tokens' own coverage sentence asks
// for. Loaded after it — the check calls into this module, not the reverse.
require_once __DIR__ . '/health-contrast-usage.php';
// Motion that asks first (report only): rides the contrast-usage parser and
// sheet population. Loaded after it for the same reason as above.
require_once __DIR__ . '/health-motion-scan.php';

/**
 * Common per-check result envelope used by 2-4.
 */
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
	);
}
