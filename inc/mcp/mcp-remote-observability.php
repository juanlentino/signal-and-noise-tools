<?php
/**
 * Signal & Noise — remote analytics door observability (R3 §3D Increment 4).
 *
 * Nothing recorded that the remote door was used, so every control in
 * docs/ops/remote-mcp-revoke-runbook.md assumed the owner already suspected
 * something. This module is the trigger those controls were missing.
 *
 * IT IS OBSERVATIONAL, AND THAT IS A HARD CONSTRAINT. The bridge calls into
 * this file only behind function_exists(), and a test pins that the door
 * behaves byte-identically with this module absent. A broken log must not be
 * able to shut the door — and must not be able to open it either.
 *
 * ISOLATION: this file calls nothing in mcp-remote-guard.php or audit-log.php,
 * and neither calls into it. The shape below MIRRORS inc/audit-log.php's proven
 * blob (per-day counters, a capped ring, retention) without sharing its
 * storage, exactly as the remote guard mirrors the read guard's predicate
 * rather than importing it.
 *
 * WHAT IT CANNOT DO: record WHO. Cloudflare Access issues and holds the
 * session; WordPress never sees it, so at the origin a bridge call is a valid
 * Bearer token and nothing more. The threat model's §8.4 "audit the caller"
 * requirement is NOT satisfied here and cannot be — that is Worker-side, where
 * src/guard.mjs already returns { sub, email }. Do not add a field that implies
 * otherwise.
 *
 * @package SignalNoiseTools
 * @since 11.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The record. NOT autoloaded — only the admin panel reads it. */
const SN_MCP_REMOTE_LOG_OPTION = 'sn_mcp_remote_log_v1';

/** Coalescing buffer for refusal counts. See sn_mcp_remote_record(). */
const SN_MCP_REMOTE_PENDING_TRANSIENT = 'sn_mcp_remote_pending';

/** Day-buckets older than this are dropped on write. Mirrors the audit log. */
const SN_MCP_REMOTE_LOG_RETENTION_DAYS = 90;

/**
 * Recent-call ring size. Small enough that the option stays trivial, large
 * enough to survive one ordinary phone session without rolling. It is a display
 * aid; the counters are the durable record.
 */
const SN_MCP_REMOTE_LOG_RING_CAP = 50;

/** How long pending refusals may sit before the next request flushes them. */
const SN_MCP_REMOTE_FLUSH_SECONDS = 60;

/**
 * Pending-buffer TTL. DELIBERATELY far longer than the flush window: nothing
 * schedules a flush, so if a probe stops, the last sub-minute of counts sits
 * here with no further request to trigger one. The admin read collects them. A
 * TTL near the flush window would silently discard the tail of an attack that
 * stopped — the counts most worth having.
 *
 * On object-cache sites a transient can evict before its TTL; that loses
 * buffered counts gracefully and nothing else.
 */
const SN_MCP_REMOTE_PENDING_TTL = HOUR_IN_SECONDS;

/**
 * The closed set of outcomes.
 *
 * refused_shut and refused_auth are BYTE-IDENTICAL TO THE CALLER — that is the
 * whole point of the 404 parity fix — and separable only here, in a record that
 * is admin-only and never echoed. "Calls arrived while I had it switched off"
 * is a different and more alarming fact than "someone guessed at the token".
 * Do not collapse them to match the wire, and do not leak the distinction to it.
 */
const SN_MCP_REMOTE_OUTCOMES = array(
	'dispatched',
	'refused_shut',
	'refused_auth',
	'refused_slug',
	'refused_request',
);

/**
 * Today's bucket key, in the SITE timezone.
 *
 * wp_date(), exactly as snt_audit_today_key() does. This log sits beside the
 * login audit log in the same admin area and is read by the same person; two
 * security readouts disagreeing about what "today" means would be a defect, and
 * a UTC bucket reads as wrong to anyone looking at the panel in the evening.
 *
 * DO NOT swap this to gmdate(). On a UTC server the two return the same string,
 * so the swap is invisible to any value-comparison test — which is why the pin
 * asserts wp_date was CALLED rather than what it returned.
 *
 * Known cost: changing the site timezone reinterprets stored values. Acceptable
 * for a diagnostic line, and the same trade inc/audit-log.php already makes.
 *
 * @return string
 */
function sn_mcp_remote_log_day_key() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : date( 'Y-m-d' );
}

/**
 * A timestamp for the record, in the SITE timezone. Same reasoning as the day
 * key — one timezone throughout, so nothing needs converting for display.
 *
 * @return string
 */
function sn_mcp_remote_log_now() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
}

/**
 * The empty, valid blob.
 *
 * @return array
 */
function sn_mcp_remote_log_empty_blob() {
	return array(
		'schema'    => 1,
		'last_used' => null,
		'counters'  => array(),
		'recent'    => array(),
	);
}

/**
 * Read the blob, repairing anything missing.
 *
 * An option can be hand-edited, half-written, or restored from an older schema.
 * Every key is filled defensively so callers never index an undefined one.
 *
 * @return array
 */
function sn_mcp_remote_log_get_blob() {
	$stored = function_exists( 'get_option' ) ? get_option( SN_MCP_REMOTE_LOG_OPTION, array() ) : array();
	if ( ! is_array( $stored ) ) {
		return sn_mcp_remote_log_empty_blob();
	}
	$blob = array_merge( sn_mcp_remote_log_empty_blob(), $stored );

	$blob['schema']    = 1;
	$blob['counters']  = is_array( $blob['counters'] ) ? $blob['counters'] : array();
	$blob['recent']    = is_array( $blob['recent'] ) ? $blob['recent'] : array();
	$blob['last_used'] = is_string( $blob['last_used'] ) ? $blob['last_used'] : null;

	return $blob;
}

/**
 * Persist the blob. NEVER autoloaded — see the constant's docblock.
 *
 * @param array $blob
 * @return void
 */
function sn_mcp_remote_log_save_blob( $blob ) {
	if ( function_exists( 'update_option' ) ) {
		update_option( SN_MCP_REMOTE_LOG_OPTION, $blob, false );
	}
}

/**
 * Bound a slug for storage.
 *
 * From Task 6 on, $slug originates in an UNAUTHENTICATED request body. Today
 * every path that stores one has already checked it against the allowlist, but
 * that guarantee lives in CALLER ORDERING, and this module's header promises
 * isolation. Bounding here means a future call site recording a refusal with
 * the raw slug cannot store attacker-length strings in 50 ring rows. 191 chars
 * covers every real ability slug with room to spare.
 *
 * @param mixed $slug
 * @return string
 */
function sn_mcp_remote_log_bound_slug( $slug ) {
	if ( ! is_scalar( $slug ) ) {
		return '';
	}
	return substr( (string) $slug, 0, 191 );
}

/**
 * Apply ONE outcome to the persisted blob, immediately.
 *
 * This is the un-coalesced path. sn_mcp_remote_record() decides whether an
 * outcome comes straight here or buffers first; keeping the two separate is
 * what makes the buffering testable without a clock.
 *
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES. Anything else is dropped.
 * @param string $slug    The requested slug, or '' when there was none.
 * @return void
 */
function sn_mcp_remote_log_apply( $outcome, $slug = '' ) {
	$outcome = (string) $outcome;
	if ( ! in_array( $outcome, SN_MCP_REMOTE_OUTCOMES, true ) ) {
		return;
	}

	$blob = sn_mcp_remote_log_get_blob();
	$day  = sn_mcp_remote_log_day_key();
	$now  = sn_mcp_remote_log_now();

	$blob = sn_mcp_remote_log_add_count( $blob, $day, $outcome, 1 );

	// ONLY a dispatch is a "use". A refusal means somebody knocked; last_used
	// answering that would make the headline fact far less alarming than it
	// reads, because the owner would see a timestamp for every failed probe.
	if ( 'dispatched' === $outcome ) {
		$blob['last_used'] = $now;
	}

	array_unshift(
		$blob['recent'],
		array( 'ts' => $now, 'slug' => sn_mcp_remote_log_bound_slug( $slug ), 'outcome' => $outcome )
	);
	if ( count( $blob['recent'] ) > SN_MCP_REMOTE_LOG_RING_CAP ) {
		$blob['recent'] = array_slice( $blob['recent'], 0, SN_MCP_REMOTE_LOG_RING_CAP );
	}

	$blob = sn_mcp_remote_log_prune( $blob );

	sn_mcp_remote_log_save_blob( $blob );
}

/**
 * Add to one day's counter, creating the bucket if needed.
 *
 * Separated so the flush path can add several counts to a bucket without
 * repeating the initialisation, and so neither path can drift from the other.
 *
 * @param array  $blob
 * @param string $day     'Y-m-d'.
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES.
 * @param int    $n       How many to add.
 * @return array The modified blob.
 */
function sn_mcp_remote_log_add_count( $blob, $day, $outcome, $n ) {
	if ( ! in_array( $outcome, SN_MCP_REMOTE_OUTCOMES, true ) ) {
		return $blob;
	}
	if ( ! isset( $blob['counters'][ $day ] ) || ! is_array( $blob['counters'][ $day ] ) ) {
		$blob['counters'][ $day ] = array();
	}
	$current = isset( $blob['counters'][ $day ][ $outcome ] ) ? (int) $blob['counters'][ $day ][ $outcome ] : 0;
	$blob['counters'][ $day ][ $outcome ] = $current + (int) $n;
	return $blob;
}

/**
 * Is a day-bucket key past the cutoff?
 *
 * PURE, so the boundary has a witness that does not depend on the clock. An
 * unparseable key returns FALSE — keeping data you cannot classify beats
 * deleting it, and a malformed key is a bug to notice rather than to erase.
 *
 * @param string $day_key 'Y-m-d'.
 * @param string $cutoff  'Y-m-d'; anything strictly before this is expired.
 * @return bool
 */
function sn_mcp_remote_log_is_expired( $day_key, $cutoff ) {
	$day_key = (string) $day_key;
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_key ) ) {
		return false;
	}
	return $day_key < (string) $cutoff;
}

/**
 * Drop expired day-buckets.
 *
 * OPPORTUNISTIC, on write, rather than on cron. A cron can drift, be
 * unscheduled, or fail silently; a prune that runs as part of the write cannot
 * get out of step with the data it prunes. It also avoids touching the
 * cron-events registry, which is a full-sweep contract.
 *
 * The ring is capped independently, by count, so a single busy day cannot evict
 * the record that the door was used last month.
 *
 * @param array $blob
 * @return array
 */
function sn_mcp_remote_log_prune( $blob ) {
	$cutoff = function_exists( 'wp_date' )
		? wp_date( 'Y-m-d', time() - ( SN_MCP_REMOTE_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) )
		: date( 'Y-m-d', time() - ( SN_MCP_REMOTE_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
	foreach ( array_keys( $blob['counters'] ) as $day_key ) {
		if ( sn_mcp_remote_log_is_expired( $day_key, $cutoff ) ) {
			unset( $blob['counters'][ $day_key ] );
		}
	}
	return $blob;
}

/**
 * Should the pending buffer be folded into the option now?
 *
 * PURE — it takes the buffer's age, whether this is a dispatch, and whether the
 * day has changed since the buffer started, and reads no clock and no storage.
 * The live wrapper supplies all three. Same split as
 * sn_mcp_remote_kill_switch_decision() / …_engaged().
 *
 * A day change is a flush condition in its own right, not just an age check:
 * a buffer created at 23:59:30 is only 50s old at 00:00:20, well under the
 * flush window, but it is holding YESTERDAY's counts. Buffering into it further
 * (or replacing it) would either mis-file today's count under yesterday or
 * silently drop yesterday's — the same loss the midnight pin on the flush path
 * exists to prevent, one path over.
 *
 * @param int  $age_seconds  Seconds since the buffer's first_seen.
 * @param bool $is_dispatch  True when a successful dispatch is being recorded.
 * @param bool $day_changed  True when the buffer's day no longer matches today.
 * @return bool
 */
function sn_mcp_remote_should_flush( $age_seconds, $is_dispatch, $day_changed = false ) {
	if ( (bool) $is_dispatch ) {
		return true;
	}
	if ( (bool) $day_changed ) {
		return true;
	}
	return (int) $age_seconds >= SN_MCP_REMOTE_FLUSH_SECONDS;
}

/**
 * Read the pending buffer, or null when there is none.
 *
 * @return array|null { day: string, first_seen: int, counts: array }
 */
function sn_mcp_remote_pending_get() {
	if ( ! function_exists( 'get_transient' ) ) {
		return null;
	}
	$pending = get_transient( SN_MCP_REMOTE_PENDING_TRANSIENT );
	if ( ! is_array( $pending ) || ! isset( $pending['day'], $pending['first_seen'], $pending['counts'] ) ) {
		return null;
	}
	if ( ! is_array( $pending['counts'] ) ) {
		return null;
	}
	return $pending;
}

/**
 * Fold a pending buffer into a blob and clear it.
 *
 * THE DAY KEY COMES FROM THE BUFFER, never from the clock. A set recorded at
 * 23:59:58 and flushed at 00:00:05 belongs to the day it was recorded;
 * recomputing here would file it under the wrong date and understate the busy
 * day. That is pinned.
 *
 * @param array      $blob
 * @param array|null $pending
 * @return array The blob with the pending counts folded in.
 */
function sn_mcp_remote_pending_fold( $blob, $pending ) {
	if ( null === $pending ) {
		return $blob;
	}
	foreach ( $pending['counts'] as $outcome => $n ) {
		$blob = sn_mcp_remote_log_add_count( $blob, (string) $pending['day'], (string) $outcome, (int) $n );
	}
	return $blob;
}

/**
 * Record one outcome from the bridge. THE ONLY ENTRY POINT THE BRIDGE CALLS.
 *
 * A dispatch is written straight through — it is rare, and it is the fact worth
 * having immediately. Refusals buffer, because /wp-json/signal-noise/v1/bridge
 * is a PUBLIC origin route while the door is armed — it is not behind Access
 * the way mcp.juanlentino.com is. On a DB-backed transient (the common case)
 * set_transient() IS itself an option write per request; the coalescing win is
 * not "fewer writes to the database" but a constant-size, non-autoloaded row
 * that never grows with request volume, and skipping the ring/prune work on
 * every refusal — versus rewriting a growing blob and pruning it per request.
 *
 * Refusals never produce ring rows — deliberate: a flood must not wash the
 * dispatch history out of a 50-row ring.
 *
 * Best-effort under concurrency: read-modify-write races on the transient can
 * lose or duplicate a handful of counts; acceptable for a diagnostic counter.
 *
 * This function must never throw and must never alter the caller's behaviour.
 *
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES.
 * @param string $slug    The requested slug, or ''.
 * @return void
 */
function sn_mcp_remote_record( $outcome, $slug = '' ) {
	$outcome = (string) $outcome;
	if ( ! in_array( $outcome, SN_MCP_REMOTE_OUTCOMES, true ) ) {
		return;
	}

	$is_dispatch = ( 'dispatched' === $outcome );
	$pending     = sn_mcp_remote_pending_get();
	$age         = ( null === $pending ) ? 0 : max( 0, time() - (int) $pending['first_seen'] );
	$day_changed = ( null !== $pending && sn_mcp_remote_log_day_key() !== (string) $pending['day'] );

	if ( ! $is_dispatch && ! sn_mcp_remote_should_flush( $age, false, $day_changed ) ) {
		sn_mcp_remote_pending_add( $outcome );
		return;
	}

	// A dispatch with no pending buffer has nothing to fold: skip straight to
	// sn_mcp_remote_log_apply()'s own read-modify-write rather than doing a
	// redundant get/fold/prune/save pass here first.
	//
	// (A refusal with no pending buffer never reaches this point: $age is 0 and
	// $day_changed is false when $pending is null, so should_flush() above is
	// always false and the early-return buffering path already handled it.)
	if ( null !== $pending ) {
		$blob = sn_mcp_remote_log_get_blob();
		$blob = sn_mcp_remote_pending_fold( $blob, $pending );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( SN_MCP_REMOTE_PENDING_TRANSIENT );
		}
		if ( ! $is_dispatch ) {
			// A stale or day-rolled buffer flushed by a refusal: that refusal counts too.
			$blob = sn_mcp_remote_log_add_count( $blob, sn_mcp_remote_log_day_key(), $outcome, 1 );
		}
		$blob = sn_mcp_remote_log_prune( $blob );
		sn_mcp_remote_log_save_blob( $blob );
	}

	if ( $is_dispatch ) {
		sn_mcp_remote_log_apply( $outcome, $slug );
	}
}

/**
 * Add one refusal to the pending buffer, creating it if absent.
 *
 * first_seen is stamped ONCE, at creation, so the buffer ages from when it
 * started rather than from the last thing added to it — otherwise a steady
 * flood would keep resetting the age and never flush.
 *
 * @param string $outcome
 * @return void
 */
function sn_mcp_remote_pending_add( $outcome ) {
	if ( ! function_exists( 'set_transient' ) ) {
		return;
	}
	$pending = sn_mcp_remote_pending_get();
	// The day-mismatch half of this condition is unreachable via
	// sn_mcp_remote_record(), which flushes on day change before buffering;
	// kept so a direct caller cannot make counts leak across days.
	if ( null === $pending || sn_mcp_remote_log_day_key() !== $pending['day'] ) {
		$pending = array(
			'day'        => sn_mcp_remote_log_day_key(),
			'first_seen' => time(),
			'counts'     => array(),
		);
	}
	$current                       = isset( $pending['counts'][ $outcome ] ) ? (int) $pending['counts'][ $outcome ] : 0;
	$pending['counts'][ $outcome ] = $current + 1;

	set_transient( SN_MCP_REMOTE_PENDING_TRANSIENT, $pending, SN_MCP_REMOTE_PENDING_TTL );
}
