<?php
/**
 * Signal & Noise Tools — one editing pass, one signed version.
 *
 * v11.10.0. Multiple versions are correct and expected: a Note revised next
 * week SHOULD be v4, and the chain exists to record exactly that. The problem
 * is versions BLEEDING — one editing pass producing two or three at once.
 *
 * Measured 2026-08-15 on the-master-never-moves: saves at 18:55, 19:00 and
 * 19:05 minted v1, v2 and v3. All three are permanent, public and
 * Bitcoin-anchored. v1 still carries a sentence that was removed minutes later
 * and never appeared on the published page — prose that was still being worked
 * on, preserved forever in a public repo because the webhook fired mid-edit.
 *
 * The existing coalesce in sn_prov_record() asks "did the content change?".
 * During an editing pass the answer is yes on every save, which is precisely
 * when the most half-formed text exists. The question a permanent public record
 * needs answered is "are you finished?" — approximated here by a settle window.
 *
 * THE HARD CONSTRAINT: once the Worker has signed a commit and written it to
 * the public ledger, it is immutable. That is the property the whole system
 * exists to provide, and nothing here may touch it. So superseding can only
 * ever happen BEFORE dispatch, and every uncertainty resolves to "append" —
 * the current behaviour — never to "rewrite".
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Default settle window: long enough to cover an editing pass. */
const SN_PROV_SETTLE_DEFAULT = 300;

/**
 * Hard ceiling for the settle window.
 *
 * The hourly reconcile sweep (SN_PROV_CONFIRM_HOOK) dispatches any commit still
 * 'unanchored'. A settle window at or beyond that interval could let the sweep
 * dispatch a commit the debounce still believes is private, so the window must
 * stay comfortably inside it. Half an hour leaves the sweep an unambiguous
 * margin.
 */
const SN_PROV_SETTLE_MAX = 1800;

/**
 * How long to wait after a save before signing, in seconds.
 *
 * Filterable, then clamped — a filter returning a day would silently disable
 * anchoring, and one returning a negative would schedule in the past.
 *
 * @return int
 */
function sn_prov_settle_seconds() {
	$seconds = SN_PROV_SETTLE_DEFAULT;
	if ( function_exists( 'apply_filters' ) ) {
		/**
		 * Filters the settle window before a commit is dispatched for signing.
		 *
		 * @since 11.10.0
		 *
		 * @param int $seconds Default SN_PROV_SETTLE_DEFAULT.
		 */
		$seconds = (int) apply_filters( 'sn_prov_settle_seconds', SN_PROV_SETTLE_DEFAULT );
	}
	if ( $seconds < 0 ) {
		return 0;
	}
	return $seconds > SN_PROV_SETTLE_MAX ? SN_PROV_SETTLE_MAX : $seconds;
}

/**
 * May this commit be superseded in place by a newer save?
 *
 * Only while it is provably still private. Every condition below is a way for
 * the commit to have escaped, and ANY of them — or any malformed input — means
 * append a new version instead, which is exactly what the code did before this
 * module existed. The failure mode is a spare version, never a rewritten one.
 *
 * - `$dispatch_pending`: a settle event is still scheduled, so the Worker POST
 *   has not been made by the debounce path.
 * - `status`: anything other than 'unanchored' means the Worker answered.
 * - `signature`: a signature means the Worker SIGNED it — it is in the public
 *   ledger. This is the ground truth, and it holds even when a status update
 *   was lost.
 * - `dispatch_attempted`: set immediately BEFORE the POST, so a request whose
 *   response never came back still blocks superseding. Without it, a lost
 *   response would let a second save rewrite a version the Worker had already
 *   published under the same number with a different hash.
 *
 * @param mixed $commit           Head commit from the chain.
 * @param bool  $dispatch_pending Whether a settle event is still scheduled.
 * @return bool
 */
function sn_prov_commit_is_supersedable( $commit, $dispatch_pending ) {
	if ( ! is_array( $commit ) ) {
		return false;
	}
	if ( true !== $dispatch_pending ) {
		return false;
	}
	if ( 'unanchored' !== (string) ( $commit['status'] ?? '' ) ) {
		return false;
	}
	if ( '' !== (string) ( $commit['signature'] ?? '' ) ) {
		return false;
	}
	if ( ! empty( $commit['dispatch_attempted'] ) ) {
		return false;
	}
	return true;
}
