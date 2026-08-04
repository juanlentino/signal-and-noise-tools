<?php
/**
 * Signal & Noise Tools — sn_apply gates 3 (mode capability) and 4
 * (idempotency). MCP consolidation session 6b.
 *
 * Gate 1 (fingerprint) and gate 2 (server-side validation) live in
 * inc/sn-apply-executors.php because they are per-change-type — this file
 * holds the two gates that are NOT per-type: capability is checked against
 * the calling IDENTITY, and idempotency is checked against a single store
 * keyed on the caller's idempotency_key, independent of what change.type
 * was requested.
 *
 * ── Gate 3: mode capability ──
 *
 * Per docs/mcp-consolidation/FINDINGS.md #5(c): the rw door's own hardening
 * (kill switch, credential-split, rate limit, audit — inc/mcp/mcp-rw-guard.php,
 * inc/mcp/mcp-rw-audit.php) is DOOR-level and already gates every rw-door
 * tool call before this ability ever runs. Gate 3 is an ABILITY-level layer
 * on TOP of that: even a request that passed the door's credential-split
 * check may be capped to a narrower set of write modes than "publish".
 *
 * "Is this the owner's bound app password" reuses the SAME identity
 * primitives R1 (mcp-rw-guard.php) already established:
 * sn_mcp_rw_bound_uuid() (the door's bound credential) and
 * sn_mcp_rw_authenticated_app_password_uuid() (this request's credential).
 * Today, by construction of R1's credential-split gate, these are ALWAYS
 * equal for any request that reaches this ability at all (an unbound or
 * mismatched UUID is denied at the door, before sn_apply's execute_callback
 * ever runs) — so snt_sn_apply_is_owner_credential() is trivially true on
 * every live call right now. It is still checked explicitly, not assumed,
 * because the spec's whole point is a FUTURE routine credential bound
 * through a mechanism this session does not build (a second, narrower-scoped
 * app password); when that exists, this is the seam that will actually
 * differentiate it. Acceptance test 5 exercises this seam directly, by
 * stubbing a mismatched authenticated UUID against a real bound UUID —
 * something the live door itself would never let happen, but the gate's own
 * logic must still refuse correctly if some future door change ever let a
 * mismatched identity through.
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_APPLY_IDEMPOTENCY_OPTION' ) ) {
	define( 'SN_APPLY_IDEMPOTENCY_OPTION', 'snt_sn_apply_idempotency_log' );
}
if ( ! defined( 'SN_APPLY_IDEMPOTENCY_CAP' ) ) {
	// Bounded recent-applies store — mirrors inc/mcp/mcp-rw-audit.php's
	// SN_MCP_RW_AUDIT_CAP magnitude (a forensics/replay window, not a
	// permanent ledger).
	define( 'SN_APPLY_IDEMPOTENCY_CAP', 500 );
}
if ( ! defined( 'SN_APPLY_IDEMPOTENCY_RETENTION_DAYS' ) ) {
	define( 'SN_APPLY_IDEMPOTENCY_RETENTION_DAYS', 14 );
}

/* ══════════════════════════════════════════════════════════════════════
 * Gate 3 — mode capability
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * Is the CURRENT request authenticated as the rw door's bound owner
 * credential? Reuses inc/mcp/mcp-rw-guard.php's identity primitives —
 * never a parallel identity scheme.
 *
 * @return bool
 */
function snt_sn_apply_is_owner_credential() {
	if ( ! function_exists( 'sn_mcp_rw_bound_uuid' ) || ! function_exists( 'sn_mcp_rw_authenticated_app_password_uuid' ) ) {
		return false;
	}
	$bound = (string) sn_mcp_rw_bound_uuid();
	$auth  = (string) sn_mcp_rw_authenticated_app_password_uuid();
	return '' !== $bound && '' !== $auth && hash_equals( $bound, $auth );
}

/**
 * The set of write modes the CURRENT calling identity is granted, before any
 * per-change-type mode-support restriction is applied (see
 * snt_sn_apply_mode_support() in inc/sn-apply-executors.php for that second,
 * independent restriction). Default: the owner's bound credential gets both
 * modes; every other identity gets 'revision' only — a routine credential
 * does not exist yet (docs/mcp-consolidation/sn-apply-spec.md's migration
 * path step 6), so this default is a forward-looking floor, not dead code.
 *
 * @return string[]
 */
function snt_sn_apply_granted_modes() {
	$is_owner = snt_sn_apply_is_owner_credential();
	$default  = $is_owner ? array( 'revision', 'publish' ) : array( 'revision' );

	/**
	 * Filter the write modes granted to the CURRENT calling identity.
	 * Callbacks MUST NOT trust anything from the request itself (args,
	 * headers) as the identity signal — only server-resolved state
	 * (current_user_can(), the authenticated app-password UUID, an explicit
	 * routine-credential registry) is a legitimate input here.
	 *
	 * @param string[] $default  ['revision','publish'] for the owner's bound
	 *                           credential today; ['revision'] otherwise.
	 * @param bool     $is_owner Whether this request authenticated as the
	 *                           rw door's bound owner credential.
	 */
	return (array) apply_filters( 'sn_apply_granted_modes', $default, $is_owner );
}

/**
 * Gate 3: mode capability. Combines two INDEPENDENT restrictions —
 * (a) does the calling identity have this mode at all
 * (snt_sn_apply_granted_modes()), and (b) does this change TYPE even support
 * being staged as a revision at all (snt_sn_apply_mode_support(), which is a
 * structural property of the absorbed impl's write mechanism, not a
 * capability grant — see that function's docblock for the full matrix).
 * Both must hold for the gate to pass; the response distinguishes which one
 * failed via `reason`.
 *
 * @param string $type           One of SNT_SN_APPLY_CHANGE_TYPES.
 * @param string $requested_mode 'revision' or 'publish'.
 * @return array{passed:bool,granted_modes:string[],mode_supported:bool,reason:string|null}
 */
function snt_sn_apply_gate_capability( $type, $requested_mode ) {
	$granted = snt_sn_apply_granted_modes();
	$support = snt_sn_apply_mode_support( $type );
	$modes   = (array) ( $support['modes'] ?? array() );

	$identity_ok = in_array( $requested_mode, $granted, true );
	$type_ok     = in_array( $requested_mode, $modes, true );

	$reason = null;
	if ( ! $type_ok ) {
		$reason = (string) ( $support['reason'] ?? 'This change type cannot be represented in the requested mode.' );
	} elseif ( ! $identity_ok ) {
		$reason = 'The calling credential is not granted the "' . (string) $requested_mode . '" mode.';
	}

	return array(
		'passed'         => $identity_ok && $type_ok,
		'granted_modes'  => array_values( $granted ),
		'mode_supported' => $type_ok,
		'reason'         => $reason,
	);
}

/* ══════════════════════════════════════════════════════════════════════
 * Gate 4 — idempotency
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * Get the idempotency blob, lazy-initializing if missing. Same lazy-init +
 * rows shape idiom as inc/mcp/mcp-rw-audit.php's sn_mcp_rw_audit_get_blob().
 *
 * @return array{rows:array<string,array{ts:int,result:mixed}>}
 */
function snt_sn_apply_idempotency_get_blob() {
	$blob = get_option( SN_APPLY_IDEMPOTENCY_OPTION, null );
	if ( ! is_array( $blob ) || ! isset( $blob['rows'] ) || ! is_array( $blob['rows'] ) ) {
		$blob = array( 'rows' => array() );
	}
	return $blob;
}

/**
 * Prune rows older than SN_APPLY_IDEMPOTENCY_RETENTION_DAYS, then cap to the
 * most recent SN_APPLY_IDEMPOTENCY_CAP rows by timestamp — same two-stage
 * (age then count) retention as inc/mcp/mcp-rw-audit.php's
 * sn_mcp_rw_audit_prune_rows().
 *
 * @param array<string,array{ts:int,result:mixed}> $rows
 * @return array<string,array{ts:int,result:mixed}>
 */
function snt_sn_apply_idempotency_prune_rows( $rows ) {
	$cutoff = time() - SN_APPLY_IDEMPOTENCY_RETENTION_DAYS * DAY_IN_SECONDS;
	$rows   = array_filter( $rows, function( $row ) use ( $cutoff ) {
		return isset( $row['ts'] ) && (int) $row['ts'] >= $cutoff;
	} );
	if ( count( $rows ) > SN_APPLY_IDEMPOTENCY_CAP ) {
		// Oldest-first eviction: sort by ts ascending, drop the head.
		uasort( $rows, function( $a, $b ) {
			return (int) ( $a['ts'] ?? 0 ) <=> (int) ( $b['ts'] ?? 0 );
		} );
		$rows = array_slice( $rows, -1 * SN_APPLY_IDEMPOTENCY_CAP, null, true );
	}
	return $rows;
}

/**
 * Canonical target identity for idempotency scoping. Derived from the RAW
 * request target (shape only — existence/validity is gate territory, and
 * this must be computable BEFORE target resolution, since the replay check
 * runs first): 'post:812' | 'attachment:400' | 'scope:provenance_anchors'.
 * A batch canonicalizes each member and joins them SORTED (a retry that
 * lists the same targets in a different order is still the same logical
 * call). Anything unrecognizable canonicalizes to a hash of its JSON so
 * two different malformed shapes never collide.
 *
 * @param mixed $raw_target The request's `target` value (object or batch array).
 * Priority caveat (review, v10.40.0): a malformed target carrying BOTH
 * post_id and attachment_id canonicalizes as post:N (fixed priority order,
 * deterministic every call) even for a change type that resolves against
 * attachment_id. The write itself is unaffected — resolve_target() is
 * type-aware — the only consequence is idempotency scoping under a shape
 * no legitimate caller sends.
 *
 * @return string
 */
function snt_sn_apply_canonical_target( $raw_target ) {
	if ( is_array( $raw_target ) && array_is_list( $raw_target ) ) {
		$members = array_map( 'snt_sn_apply_canonical_target', $raw_target );
		sort( $members, SORT_STRING );
		return 'batch:' . implode( ',', $members );
	}
	$t = is_array( $raw_target ) ? $raw_target : array();
	if ( isset( $t['post_id'] ) && (int) $t['post_id'] > 0 ) {
		return 'post:' . (int) $t['post_id'];
	}
	if ( isset( $t['attachment_id'] ) && (int) $t['attachment_id'] > 0 ) {
		return 'attachment:' . (int) $t['attachment_id'];
	}
	if ( isset( $t['scope'] ) && '' !== (string) $t['scope'] ) {
		return 'scope:' . (string) $t['scope'];
	}
	return 'unknown:' . md5( (string) wp_json_encode( $t ) );
}

/**
 * The store key for a caller-supplied idempotency_key, SCOPED TO THE TARGET
 * (adversarial review HIGH, v10.40.0): a reused key on a DIFFERENT target
 * derives a different store key and is therefore simply a fresh execution.
 * This preserves the spec's intent — idempotency protects the RETRY of the
 * same logical call (same key, same target), never a cross-target dedupe.
 * The first cut hashed only the key ('sn_apply|'.$key), so a routine
 * reusing 'batch-item-1' across post 812 then post 907 got 812's stored
 * response (applied:true, 812's revision_id/rollback) replayed for the 907
 * call, with 907 never touched and nothing signalling the mismatch.
 * Hashed (never the raw caller strings as the array key) so an arbitrarily
 * long/malformed key can't bloat the option's key-space.
 *
 * @param string $idempotency_key
 * @param string $canonical_target From snt_sn_apply_canonical_target().
 * @return string
 */
function snt_sn_apply_idempotency_store_key( $idempotency_key, $canonical_target ) {
	return hash( 'sha256', 'sn_apply|' . (string) $idempotency_key . '|' . (string) $canonical_target );
}

/**
 * Gate 4: idempotency. Looks up (key, target) — never writes here (the
 * write happens once, in snt_sn_apply_idempotency_record(), only after
 * every gate has passed AND the call actually executed, dry-run or not).
 *
 * Belt-and-braces (review HIGH, layer 2): each stored row also records the
 * canonical target it was executed against; a row whose stored target
 * doesn't match the requested one — impossible under the current key
 * derivation, but a defense against any FUTURE key-derivation change
 * reintroducing cross-target collisions — is reported as a mismatch, never
 * replayed. The caller turns that into a 409 refusal naming both targets.
 *
 * @param string $idempotency_key
 * @param string $canonical_target From snt_sn_apply_canonical_target().
 * @return array{passed:bool,first_seen:int|null,replay:array|null,target_mismatch:array{stored:string,requested:string}|null}
 *         `replay` is the FULL stored response array when this (key, target)
 *         was seen before — the caller (inc/abilities-sn-apply.php) returns
 *         it verbatim (with `replayed:true` stamped on) instead of
 *         re-executing.
 */
function snt_sn_apply_gate_idempotency( $idempotency_key, $canonical_target = '' ) {
	$idempotency_key  = (string) $idempotency_key;
	$canonical_target = (string) $canonical_target;
	if ( '' === $idempotency_key ) {
		// No key supplied — nothing to dedupe against; always a fresh call.
		return array( 'passed' => true, 'first_seen' => null, 'replay' => null, 'target_mismatch' => null );
	}

	$blob = snt_sn_apply_idempotency_get_blob();
	$key  = snt_sn_apply_idempotency_store_key( $idempotency_key, $canonical_target );

	if ( isset( $blob['rows'][ $key ] ) ) {
		$row           = $blob['rows'][ $key ];
		$stored_target = (string) ( $row['target'] ?? '' );
		if ( '' !== $stored_target && $stored_target !== $canonical_target ) {
			return array(
				'passed'          => false,
				'first_seen'      => (int) ( $row['ts'] ?? 0 ),
				'replay'          => null,
				'target_mismatch' => array( 'stored' => $stored_target, 'requested' => $canonical_target ),
			);
		}
		return array(
			'passed'          => true,
			'first_seen'      => (int) ( $row['ts'] ?? 0 ),
			'replay'          => is_array( $row['result'] ?? null ) ? $row['result'] : null,
			'target_mismatch' => null,
		);
	}

	return array( 'passed' => true, 'first_seen' => null, 'replay' => null, 'target_mismatch' => null );
}

/**
 * Record a NEW (idempotency_key, target) -> response mapping. Called once
 * per genuinely executed call (dry-run included, per the spec: "the
 * interesting evidence is usually what it tried to do") — never called
 * again for a pair already present (that would defeat the whole point: a
 * replay must return the FIRST result, unconditionally).
 *
 * @param string $idempotency_key
 * @param string $canonical_target From snt_sn_apply_canonical_target().
 * @param array  $response The full response this call is about to return.
 * @return void
 */
function snt_sn_apply_idempotency_record( $idempotency_key, $canonical_target, array $response ) {
	$idempotency_key  = (string) $idempotency_key;
	$canonical_target = (string) $canonical_target;
	if ( '' === $idempotency_key ) {
		return;
	}

	$blob = snt_sn_apply_idempotency_get_blob();
	$key  = snt_sn_apply_idempotency_store_key( $idempotency_key, $canonical_target );

	if ( isset( $blob['rows'][ $key ] ) ) {
		// Defensive: the gate already short-circuits before a second execute
		// happens, but never overwrite a first-seen record if this is ever
		// reached anyway (last-write-wins here would defeat replay honesty).
		return;
	}

	$blob['rows'][ $key ] = array( 'ts' => time(), 'target' => $canonical_target, 'result' => $response );
	$blob['rows']         = snt_sn_apply_idempotency_prune_rows( $blob['rows'] );

	update_option( SN_APPLY_IDEMPOTENCY_OPTION, $blob, false );
}
