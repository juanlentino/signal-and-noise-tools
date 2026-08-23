<?php
/**
 * Signal & Noise Tools — durable store for drift-detection verdicts.
 *
 * The drift check is the only Content-Health check that spends money: one
 * `snt_ai_generate_with_constraints( …, 'drift_detect' )` per candidate post,
 * on the default model. It has always cached verdicts, keyed on
 * (post_id, post_modified_gmt, prompt_version) — deterministic inputs, so an
 * unchanged post never needs asking twice.
 *
 * It cached them in a TRANSIENT, and that is the bug this file fixes.
 *
 * MEASURED 2026-08-23. Two scans back to back: the first took 48.0s and made
 * model calls; the second took 5.4s and made none — an 8.8x speedup, the cache
 * working exactly as designed. But between an earlier warm scan and those, the
 * plugin was updated to 12.23.0, and the scan straight after the update paid for
 * the whole corpus again. On a persistent object cache a plugin update flushes
 * transients, and this repo ships several releases a day, so the cost was driven
 * by RELEASES rather than by edits or by scan cadence — re-computing verdicts
 * identical to the ones just discarded, because the posts had not changed.
 *
 * The reasoning was already written down one file over, above
 * sn_health_store_scan(): the scan RESULT is "an autoload=no option (not a
 * transient) so the scan survives the object-cache flush a caching plugin fires
 * on a plugin update". That was applied to the result and never to the thing
 * that costs money. This applies it there.
 *
 * Nothing about the verdicts changes: same model, same prompt, same keys. A
 * genuinely edited post still re-pays, and changing SNT_AI_DRIFT_SYSTEM still
 * invalidates the whole store, because prompt_version is part of the key.
 *
 * ONE option, not one per post. A per-post option would put hundreds of rows in
 * wp_options for a cache; this is a single autoload=no map, read only during a
 * scan and on no front-end request.
 *
 * @package SignalNoiseTools
 * @since 12.23.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The store. autoload=no: read during a scan, never on a front-end request. */
const SN_DRIFT_VERDICT_OPT = 'sn_drift_verdict_cache';

/** Entry lifetime, carried over verbatim from the transient it replaces. */
const SN_DRIFT_VERDICT_TTL = 2592000; // 30 * DAY_IN_SECONDS

/**
 * Entries kept. A transient expired on its own; an option does not, so the
 * store is pruned on write or it grows forever. 500 is well above the candidate
 * corpus (the check's own query is LIMIT 500), so in practice TTL prunes first
 * and this only bounds a surprise.
 */
const SN_DRIFT_VERDICT_CAP = 500;

/**
 * A cached verdict set, or null.
 *
 * Returns null — never an empty array — when there is no usable entry. An
 * absent verdict set and a post with no stale phrases are different answers,
 * and the caller must be able to tell them apart or it would skip the model
 * call and record "nothing stale" for a post nobody has ever checked.
 *
 * @param int    $post_id        Post.
 * @param string $post_modified  post_modified_gmt, verbatim.
 * @param string $prompt_version md5 of the system prompt.
 * @return array|null
 */
function sn_drift_verdict_get( $post_id, $post_modified, $prompt_version ) {
	$store = get_option( SN_DRIFT_VERDICT_OPT );
	$entry = is_array( $store ) ? ( $store[ (int) $post_id ] ?? null ) : null;
	if ( ! is_array( $entry ) || ! isset( $entry['post_modified'], $entry['prompt_version'], $entry['verdicts'], $entry['ts'] ) ) {
		return null;
	}
	if ( (string) $entry['post_modified'] !== (string) $post_modified ) {
		return null;
	}
	if ( (string) $entry['prompt_version'] !== (string) $prompt_version ) {
		return null;
	}
	// The TTL the transient enforced for us, enforced here instead.
	if ( ( time() - (int) $entry['ts'] ) > SN_DRIFT_VERDICT_TTL ) {
		return null;
	}
	return is_array( $entry['verdicts'] ) ? $entry['verdicts'] : null;
}

/**
 * Store a verdict set, pruning expired entries and capping the store.
 *
 * @param int    $post_id        Post.
 * @param string $post_modified  post_modified_gmt, verbatim.
 * @param string $prompt_version md5 of the system prompt.
 * @param array  $verdicts       The parsed verdicts.
 * @return void
 */
function sn_drift_verdict_put( $post_id, $post_modified, $prompt_version, $verdicts ) {
	$store = get_option( SN_DRIFT_VERDICT_OPT );
	$store = is_array( $store ) ? $store : array();
	$now   = time();

	$store[ (int) $post_id ] = array(
		'post_modified'  => (string) $post_modified,
		'prompt_version' => (string) $prompt_version,
		'verdicts'       => (array) $verdicts,
		'ts'             => $now,
	);

	// Prune expired first — that is what the transient did for free.
	foreach ( $store as $id => $entry ) {
		if ( ! is_array( $entry ) || ( $now - (int) ( $entry['ts'] ?? 0 ) ) > SN_DRIFT_VERDICT_TTL ) {
			unset( $store[ $id ] );
		}
	}
	// Then cap, oldest first, so a surprise cannot grow the option without bound.
	if ( count( $store ) > SN_DRIFT_VERDICT_CAP ) {
		uasort( $store, static function ( $a, $b ) {
			return (int) ( $a['ts'] ?? 0 ) <=> (int) ( $b['ts'] ?? 0 );
		} );
		$store = array_slice( $store, -SN_DRIFT_VERDICT_CAP, null, true );
	}

	update_option( SN_DRIFT_VERDICT_OPT, $store, false );
}
