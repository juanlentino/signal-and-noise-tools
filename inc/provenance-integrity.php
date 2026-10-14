<?php
/**
 * Signal & Noise Tools — server-side provenance integrity sweep (13th Content-Health check).
 *
 * The /verify page checks one Note at a time, client-side, only when a reader
 * visits; the 2026-07-21 flattened-content_text repair was exactly the drift
 * class a scheduled fleet self-check would have caught first. This module IS
 * that self-check: for each anchored Note it verifies the trust triangle
 *
 *   (a) the stored chain payload still re-canonicalizes (sn_prov_canonical_json
 *       → sn_prov_content_hash, the EXACT bytes the credential system signed)
 *       to the anchored content_hash;
 *   (b) the Note's published .json twin — fetched over HTTP through the real
 *       public URL, i.e. the live cache stack — still carries the same words
 *       as the signed payload under whitespace-collapse normalization (the
 *       twin's content_text is ONE flattened line vs the payload's paragraphs,
 *       so the collapse folds ALL whitespace; same semantic as the /verify
 *       JS roughNormalize, built on the SHARED sn_prov_normalize_v1 — never a
 *       second divergent normalizer);
 *   (c) the public ledger record notes/<uid>/v<n>.json exists on
 *       raw.githubusercontent.com and attests the same hash, and
 *       keys/provenance-keys.json still serves the published key id;
 *   (d) the key THAT COMMIT names (pubkey_id) is still published in
 *       keys/provenance-keys.json. Leg (c) asks only about the CURRENT key,
 *       which says nothing about the retired keys every historical Note
 *       depends on after a rotation — dropping one strands them all, and
 *       before v13.36.0 nothing looked.
 *
 * Failure UX names WHICH leg failed, and an outage is never drift: the
 * *_unreachable codes are classified separately from mismatch/missing so a
 * GitHub blip or an edge 500 can't masquerade as tampering.
 *
 * Bounding + cadence: at most SN_PROV_INTEGRITY_NOTES_PER_RUN Notes per run,
 * rotating oldest-checked-first so full fleet coverage accrues across runs;
 * every fetch is timeout-capped; per-Note last-checked state + the sweep
 * summary persist in an autoload=no option (NEVER a transient — transients
 * are flush-volatile under this stack's persistent object cache). The sweep
 * rides the existing Content-Health scan cadence (sn_health_run_scan(), an
 * admin-button / run-health-scan-ability surface — it can never run inline
 * on a front-end request), so no parallel cron is invented. Outbound targets
 * are two fixed known hosts: this site's own home URL and
 * raw.githubusercontent.com.
 *
 * Surfaces: the provenance_integrity Content-Health check (registered in
 * sn_health_run_scan() like every sibling) and the readonly
 * signal-noise/provenance-integrity-status ability (latest sweep summary +
 * per-note failures; reads stored state ONLY and never triggers a sweep).
 * No new bare REST routes.
 *
 * @package SignalNoiseTools
 * @since 9.80.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable sweep state (autoload=no option): per-Note last-checked + last sweep summary. */
const SN_PROV_INTEGRITY_OPT = 'sn_prov_integrity_state';

/** Notes verified per run; the rest wait for the oldest-checked-first rotation. */
const SN_PROV_INTEGRITY_NOTES_PER_RUN = 10;

/** Per-fetch timeout (seconds) for the twin/ledger/key requests. */
const SN_PROV_INTEGRITY_FETCH_TIMEOUT = 5;

/** Consecutive-sweep 404s (a REAL "absent" answer, vs a network error) on the
 *  keys file or a twin before the outage verdict escalates to the distinct
 *  keys_missing / twin_missing finding. One 404 can be an edge blip or a
 *  mid-deploy window; three consecutive sweeps saying "absent" is absence. */
const SN_PROV_INTEGRITY_404_STREAK = 3;

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so the ability hook keeps its place.
require_once __DIR__ . '/provenance-integrity-fetch-parts.php';
require_once __DIR__ . '/provenance-integrity-sweep-parts.php';
require_once __DIR__ . '/provenance-integrity-report-parts.php';
