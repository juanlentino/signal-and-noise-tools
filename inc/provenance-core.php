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

const SN_PROV_ALGO        = 'sn-normalize-v1';
const SN_PROV_CHAIN_META  = '_sn_prov_chain';   // serialized array of commit records
const SN_PROV_UID_META    = '_sn_prov_uid';     // per-Note UUID (ledger key)
const SN_PROV_GENESIS_META = '_sn_prov_genesis_parent'; // set by Plan 4

/**
 * Is the provenance subsystem operable? Requires ext-intl for reproducible
 * Unicode NFC normalization. If absent, the whole feature no-ops (an admin
 * notice is surfaced by Plan 5's admin module).
 */
function sn_prov_active() {
	return function_exists( 'normalizer_normalize' );
}

/**
 * Return the Note's stable provenance UUID, minting + persisting it on first
 * call. This — not the WP post ID or slug — is the ledger key, so migrations
 * and slug changes never disturb the ledger.
 *
 * @param int $post_id
 * @return string RFC 4122 v4 UUID.
 */
function sn_prov_note_uid( $post_id ) {
	$uid = get_post_meta( (int) $post_id, SN_PROV_UID_META, true );
	if ( is_string( $uid ) && '' !== $uid ) {
		return $uid;
	}
	$uid = wp_generate_uuid4();
	update_post_meta( (int) $post_id, SN_PROV_UID_META, $uid );
	return $uid;
}
