<?php
/**
 * Signal & Noise — Remote MCP payload contract mirror (phase 2 of the
 * versioned contract).
 *
 * Phase 1 lives in the WORKER repo (sn-remote-mcp-worker v0.5.0): the door
 * pins the response ENVELOPE it authors and exposes CONTRACT_VERSION on
 * `initialize` (`_meta["sn/contractVersion"]`) and on `/status`
 * (`contract_version`). This file is the origin's half — the worker is a
 * pass-through and cannot see payload shapes, so the shapes are pinned where
 * they are authored: the 8 remote twin abilities' `output_schema`s, which the
 * parity suite already holds byte-identical to their admin registrations.
 *
 * THE COUPLING (design doc: sn-remote-mcp-worker
 * docs/plans/2026-08-27-versioned-contract-design.md, open question 2):
 *
 * - CI direction: SN_REMOTE_CONTRACT_VERSION_HASHES pins (version → sha256
 *   over the canonical JSON of the 8 output_schemas). A change to any remote
 *   ability's PAYLOAD shape fails tests/remote-contract-shapes.php unless the
 *   version moves with it, and a version bump without a shape change fails
 *   the same pin. Keys and TYPES only, never values — pinning values trains
 *   fixture-updating reflexes that turn a guard into a rubber stamp.
 * - INSTALL direction: cross-repo skew in this estate lands at INSTALL time,
 *   not at CI (the two repos ship independently), so no CI job reads the
 *   other repo. Instead the deploy-workers probe compares the worker's live
 *   `/status.contract_version` against SN_REMOTE_CONTRACT_VERSION and carries
 *   the comparison in its result — skew becomes an observable exactly where
 *   it lands.
 *
 * A DECLARATION, never a gate: nothing refuses on a mismatch, mirroring the
 * worker's own always-advertise decision.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mirrors CONTRACT_VERSION in sn-remote-mcp-worker src/mcp.mjs. Bump BOTH
// (worker constant + this mirror + the hash map below) when any remote
// payload shape or the door's envelope changes.
// v13.52.0: '1' -> '2' — three ratified twins joined the map
// (provenance-integrity, machine-readers, cron-health). The worker's
// CONTRACT_VERSION bumps in the same arc; until its deploy lands, the deploy
// probe reads contract_match:false, which is the observed-not-refused design.
const SN_REMOTE_CONTRACT_VERSION = '2';

// version → sha256 over sn_remote_contract_shape_hash()'s canonical JSON of
// the 8 remote twins' output_schemas. Every version maps to a DISTINCT hash:
// a version bump without a shape change is a lie the test refuses.
const SN_REMOTE_CONTRACT_VERSION_HASHES = array(
	'1' => '90f2ce6597120d1dc2dd46f28b38916fac08783a3da5937fb032417ba3a32c20',
	// RED-then-pin, 2026-09-01: the failing test computed this over the
	// 11-twin map. Never hand-derived.
	'2' => '5232aaa9c622fcbefcb93e3599521ab30007549fb30e3881d889f093ceb7743e',
);

/**
 * Canonical sha256 over a slug-keyed map of output_schemas.
 *
 * Canonicalization: recursive key sort, then plain json_encode — so array
 * ORDER inside a schema (e.g. a type union) stays significant while the
 * incidental order of registration does not. Pure function of its input;
 * runtime never calls it (the runtime half of the contract is only the
 * VERSION constant, read by the deploy probe comparison).
 *
 * @param array $schemas Map of ability slug => output_schema array.
 * @return string 64-char lowercase hex sha256.
 */
function sn_remote_contract_shape_hash( $schemas ) {
	if ( ! is_array( $schemas ) ) {
		return '';
	}
	$canon = sn_remote_contract_ksort_deep( $schemas );
	return hash( 'sha256', (string) json_encode( $canon ) );
}

/**
 * Recursively key-sort associative arrays; leave list arrays in order.
 *
 * A JSON-schema `type` union like array('object','null') is a LIST whose
 * order is part of the byte-identical parity pin, so lists must not be
 * sorted; only string-keyed maps are.
 *
 * @param mixed $value Any schema fragment.
 * @return mixed
 */
function sn_remote_contract_ksort_deep( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$out = array();
	foreach ( $value as $k => $v ) {
		$out[ $k ] = sn_remote_contract_ksort_deep( $v );
	}
	if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) ) {
		ksort( $out );
	}
	return $out;
}
