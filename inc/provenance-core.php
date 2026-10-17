<?php
/**
 * Signal & Noise Tools — Notes provenance: commit-chain core.
 *
 * Pure, offline data layer: turns a published Note into a byte-stable
 * canonical payload and appends it to a per-Note commit chain in postmeta.
 * Emits `sn_prov_committed` for the Worker webhook (Plan 3) to hook. This
 * module does NO networking and holds NO keys.
 *
 * Naming: the editorial Provenance pillar owns `sn_provenance_*`; this
 * commit-chain subsystem uses short-form `sn_prov_*`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_ALGO        = 'sn-normalize-v2';
const SN_PROV_CHAIN_META  = '_sn_prov_chain';   // serialized array of commit records
const SN_PROV_UID_META    = '_sn_prov_uid';     // per-Note UUID (ledger key)
const SN_PROV_GENESIS_META = '_sn_prov_genesis_parent'; // set by Plan 4
const SN_PROV_LAST_COMMIT_META = '_sn_prov_last_commit_gmt'; // v11.11.8: denormalized freshness clock (MySQL datetime, GMT)

// The rest of the module lives in four parts files (house line cap, 2026-10-15),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/provenance-core-normalize-parts.php';
require_once __DIR__ . '/provenance-core-chain-parts.php';
require_once __DIR__ . '/provenance-core-record-parts.php';
require_once __DIR__ . '/provenance-core-subject-parts.php';
require_once __DIR__ . '/provenance-change-class.php';
