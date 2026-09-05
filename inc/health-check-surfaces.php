<?php
/**
 * Signal & Noise Tools — which SURFACE owns each Health check (v11.13.0).
 *
 * The Health tab had become four different kinds of thing on one scroll under
 * one fraction: defects, worklists, measurements, and scan meta. Every arc that
 * added a tier added a disclaimer with it, and each arc explicitly declined to
 * remove anything ("Nothing is dropped or relocated: every row stays on the
 * page" — v10.97.0). Individually justified, cumulatively unreadable: the live
 * page carried SEVEN disclaimers, four of which existed to tell the reader that
 * the numbers above them do not mean what they look like.
 *
 * Owner decision 2026-08-18: **Health answers one question — what is broken
 * that I should fix.** A check earns a place there when all three hold:
 *
 *   1. its finding is a DEFECT (something is wrong, not merely improvable);
 *   2. it can reach zero and stay there;
 *   3. no other surface already owns the same worklist.
 *
 * Everything else keeps RUNNING and keeps its place in the scan envelope — no
 * data is lost and no MCP consumer breaks — it simply renders where it belongs.
 * The scan is the data layer; a tab is one renderer of it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every check's owning surface.
 *
 * Surfaces:
 *   health    — a defect. Counted in the Health tally, which should read zero.
 *   integrity — a measurement that publishes rather than flags (report-only).
 *   deploy    — a fact about a repo/worker/build, not about this site's content.
 *   worklist  — an opportunity that never resolves; owned by the scan door.
 *
 * EXHAUSTIVE by contract: tests/health-check-surfaces.php asserts that every
 * key in the scan registry appears here and vice versa, so a new check must
 * declare where it renders instead of defaulting onto the defect count — the
 * failure mode this map exists to end.
 *
 * @return array<string,string>
 */
function sn_health_check_surface_map() {
	return array(
		// ── DEFECTS ────────────────────────────────────────────────────────
		'missing_alt'           => 'health',
		'broken_links'          => 'health',
		'stale_posts'           => 'health',
		'drift_time_phrases'    => 'health',
		'color_drift'           => 'health',
		'cf_security_headers'   => 'health',
		'analytics_integrity'   => 'health',
		'roadmap_drift'         => 'health',
		// 23rd check (v13.96.6): the plugin registry disagreeing with
		// active_plugins. A DEFECT (the registry is wrong, not merely
		// improvable), it reaches zero and stays there, and no other
		// surface owns it - the three tests for the Health surface.
		'plugin_registry'       => 'health',
		// 24th check (v13.97.4): cron still spawned in-request. A defect (a
		// pageview pays for a 10.6s job), reaches zero with one wp-config line,
		// and unowned elsewhere - the three tests for this surface.
		'wp_cron_request_path'  => 'health',

		// ── MEASUREMENTS (Integrity) ───────────────────────────────────────
		// Both disclaim their own authority in their own copy — "a red row here
		// is a 'would fail', not a live defect", "a clean sweep here is not
		// proof a reduced-motion visitor sees no motion". A page whose headline
		// is a defect count is the wrong home for a measurement that says, in
		// four separate sentences, that it is not counting defects.
		'contrast_tokens'       => 'integrity',
		'motion_scan'           => 'integrity',
		// v13.89.1 — HEALTH, and v13.89.0 got this wrong. It was filed as
		// `integrity` on the reasoning that "nothing on the SITE is wrong, a
		// measurement stopped arriving". That misreads criterion 1 above: it
		// asks whether the finding is a DEFECT, not whether the defect sits in
		// site content. All three hold here:
		//
		//   1. DEFECT? Yes — the sync running while the history does not grow
		//      is broken, not merely improvable.
		//   2. REACHES ZERO AND STAYS? Yes — it clears when the producer
		//      resumes, and stays clear while it keeps running.
		//   3. ANOTHER SURFACE OWNS IT? No.
		//
		// `integrity` is the REPORT-ONLY tier (contrast_tokens, motion_scan),
		// so filing here sent a pass/fail check to a surface the health tally
		// does not count. It ran, found nothing, and never appeared:
		// checks_total stayed at 8 through a fresh scan. That is how it was
		// caught, and the assertion in tests/health-check-surfaces.php is what
		// keeps it caught.
		'gsc_history_stalled'   => 'health',
		// These four were ALREADY rendering on Integrity → Trust checks
		// (v10.47.0 moved them there as "the four trust checks that had been
		// marooned as rows inside an eighteen-row Health tab") — and were still
		// being counted and rendered on Health as well. Four checks on two
		// surfaces is the duplication this arc exists to end, and the earlier
		// move was right; it simply never removed the original.
		'provenance_integrity'  => 'integrity',
		'rights_signals'        => 'integrity',
		'rights_anchored'       => 'integrity',
		'ledger_ci'             => 'integrity',

		// ── DEPLOY ─────────────────────────────────────────────────────────
		// Worker version drift. Deploy Status shows all five workers with live
		// vs latest already, so this was the same answer printed twice.
		'edge_workers'          => 'deploy',

		// ── WORKLISTS (the scan door owns these) ───────────────────────────
		// Its own copy convicts it: "a clean site can carry them indefinitely".
		// An advisory that never resolves and never blocks is a worklist, and
		// the worklist already exists in three other places (sn_scan's
		// link_candidates, analytics-recommendations, ai-link/pair-suggest).
		'link_opportunities'    => 'worklist',
		// Tag vocabulary drift (v13.24.0): undescribed tags are sentences not
		// yet written, zero-post tags are prune candidates — opportunities
		// that re-open as tags arrive, never defects.
		'tag_hygiene'           => 'worklist',
		// Same shape: a mention that could be a link. Zero-AI at scan time,
		// applied through ai-link-suggest — the same door as the pairs above.
		'unlinked_mentions'     => 'worklist',
		// Link rot. A dead cited source IS wrong on the page — but it can never
		// reach zero: the external web decays continuously and outside our
		// control, which is the second half of the Health test and the reason
		// this has been advisory since 2026-07-02. It is a standing queue, not
		// a fault, so it renders with the other queues.
		'external_links'        => 'worklist',
		// Duplicated by sn_scan's near_duplicate adapter.
		'ml_cousins'            => 'worklist',
		// Duplicated by sn_scan's orphan_media adapter; its own fix hint is
		// "review whether it can be deleted", which is a queue, not a fault.
		'orphaned_media'        => 'worklist',
		// Publishing rhythm. Editorial information, and analytics already owns
		// the cadence surface.
		'ml_cadence'            => 'worklist',
		// The counterpart to stale_posts (v11.12.0): declared-timeless posts
		// that are stale by measurement. It renders as a disclosure UNDER the
		// stale_posts card rather than as its own line in the tally — the flag
		// must keep explaining itself without adding a number to a defect count.
		'stale_posts_evergreen' => 'worklist',
	);
}

/**
 * The surface a check renders on. Unknown keys fall to 'health' deliberately:
 * a check nobody classified is more safely LOUD than silently invisible, and
 * the exhaustiveness test turns that fallback into a build error anyway.
 *
 * @param string $key
 * @return string
 */
function sn_health_check_surface( $key ) {
	$map = sn_health_check_surface_map();
	return (string) ( $map[ (string) $key ] ?? 'health' );
}

/**
 * A scan's checks filtered to one surface, scan order preserved.
 *
 * @param array|null $scan
 * @param string     $surface
 * @return array<string,array>
 */
function sn_health_checks_for_surface( $scan, $surface ) {
	$out = array();
	if ( ! is_array( $scan ) ) {
		return $out;
	}
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( sn_health_check_surface( $key ) === (string) $surface ) {
			$out[ $key ] = $check;
		}
	}
	return $out;
}
