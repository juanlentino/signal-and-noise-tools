<?php
/**
 * Signal & Noise — provenance pointers in the machine surfaces + the in-page
 * verification manifest (R5 rows 3+4, v11.7.0).
 *
 * Two surfaces, one module, because they publish the same thing to the same
 * adversary-slash-guest: A6, the anonymous page-context caller (threat model
 * §9.2). An agent that reads a signed note can now also verify it, from the
 * page itself, without waiting for anyone to adopt an API:
 *
 *   1. THE MANIFEST — a data-shaped <script type="application/json"> block on
 *      signed singular subjects, listing every verification call: credential,
 *      ledger record, OTS proof, key history, DID, block-header template, and
 *      the standalone verifier's repo. "Verifying travels with the content."
 *   2. THE SCHEMA POINTER — the Article node gains a PropertyValue identifier
 *      carrying the subject uid, so structured-data consumers can join the
 *      page to its ledger record without parsing the manifest.
 *
 * PRECONDITION COMPLIANCE (§9.5, argued here because this module is the one
 * the preconditions were written for):
 *   P-51 — the manifest ASSERTS NOTHING: no verdict keys exist (pinned as an
 *          absence in the suite). It lists inputs and calls; the caller
 *          computes.
 *   P-52 — zero new anonymous compute: the manifest renders into the page
 *          (cached like the page); no new route, no per-request crypto.
 *   P-53 — every URL derives from sn_prov_verify_endpoints(), THE one
 *          producer the /verify shell itself consumes — structural parity,
 *          hosts pinned in reviewed code, nothing assembled from options,
 *          meta, or content.
 *   P-55 — nothing here writes. Emission is a pure read of chain state.
 *   P-56 — data-shaped by construction: fixed keys, URLs and idents only,
 *          no imperative prose anywhere in the manifest (shape-pinned).
 *
 * Emission predicate mirrors the credential head-link (posts + any singular
 * subject with a signed chain at version >= 1): a subject with no anchored
 * record has nothing to verify, and ABSENCE is the honest render — same
 * three-way discipline as everything else in this plugin.
 *
 * @package SignalNoiseTools
 * @since 11.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ledger directory per subject kind — MIRRORS prov-verify-core.js
 * SUBJECT_ROOTS exactly; the suite pins the two maps against each other.
 *
 * @return array<string,string>
 */
function sn_prov_machine_pointers_roots() {
	return array(
		'note' => 'notes',
		'page' => 'pages',
	);
}

/**
 * The schema.org identifier node for a signed subject ('' uid → null).
 *
 * @param int $post_id Post id.
 * @return array|null PropertyValue node, or null when unsigned.
 */
function sn_prov_machine_pointers_identifier( $post_id ) {
	if ( ! function_exists( 'sn_prov_note_uid' ) ) {
		return null;
	}
	$uid = (string) sn_prov_note_uid( (int) $post_id );
	if ( '' === $uid ) {
		return null;
	}
	return array(
		'@type'      => 'PropertyValue',
		'propertyID' => home_url( '/verify' ) . '#uid',
		'value'      => $uid,
	);
}

/**
 * Build the verification manifest for a subject, or null when there is
 * nothing honest to publish (no uid, or no anchored version yet).
 *
 * @param int $post_id Post id.
 * @return array|null
 */
function sn_prov_machine_pointers_manifest( $post_id ) {
	if ( ! function_exists( 'sn_prov_note_uid' ) || ! function_exists( 'sn_prov_view_data' ) || ! function_exists( 'sn_prov_verify_endpoints' ) ) {
		return null;
	}
	$post_id = (int) $post_id;
	$uid     = (string) sn_prov_note_uid( $post_id );
	if ( '' === $uid ) {
		return null;
	}
	$vm      = (array) sn_prov_view_data( $post_id );
	$version = (int) ( $vm['version'] ?? 0 );
	if ( $version < 1 ) {
		return null; // Genesis-only / unanchored: nothing to verify yet — absence, not a stub.
	}
	$kind  = 'note';
	if ( function_exists( 'sn_prov_subject_kind' ) && function_exists( 'get_post' ) ) {
		$k = (string) sn_prov_subject_kind( get_post( $post_id ) );
		if ( isset( sn_prov_machine_pointers_roots()[ $k ] ) ) {
			$kind = $k;
		}
	}
	$root = sn_prov_machine_pointers_roots()[ $kind ];
	$ep   = sn_prov_verify_endpoints();
	$base = rtrim( $ep['ledger_base'], '/' ) . '/' . $root . '/' . rawurlencode( $uid );

	return array(
		'spec'    => home_url( '/verify' ),
		'subject' => array(
			'uid'     => $uid,
			'kind'    => $kind,
			'version' => $version,
			'url'     => get_permalink( $post_id ),
		),
		'calls'   => array(
			'credential'   => array( 'method' => 'GET', 'url' => $ep['credential_base'] . rawurlencode( $uid ), 'type' => 'application/vc+ld+json' ),
			'record'       => array( 'method' => 'GET', 'url' => $base . '/v' . $version . '.json', 'type' => 'application/json' ),
			'proof'        => array( 'method' => 'GET', 'url' => $base . '/v' . $version . '.ots', 'type' => 'application/octet-stream' ),
			'key_history'  => array( 'method' => 'GET', 'url' => $ep['keys_url'], 'type' => 'application/json' ),
			'did'          => array( 'method' => 'GET', 'url' => $ep['did_url'], 'type' => 'application/json' ),
			'block_header' => array( 'method' => 'GET', 'url_template' => rtrim( $ep['mempool_base'], '/' ) . '/block-height/{height}', 'type' => 'text/plain' ),
		),
		'standalone' => array(
			'repository'    => 'https://github.com/' . $ep['owner'] . '/' . $ep['repo'],
			'documentation' => 'https://github.com/' . $ep['owner'] . '/' . $ep['repo'] . '/blob/main/VERIFY.md',
		),
	);
}

/**
 * Emit the manifest on signed singular subjects (wp_head).
 */
function sn_prov_machine_pointers_emit() {
	if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
		return;
	}
	$post_id = (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return;
	}
	$manifest = sn_prov_machine_pointers_manifest( $post_id );
	if ( null === $manifest ) {
		return;
	}
	// Default slash-escaping kept ON deliberately: it turns any '</script>'
	// byte sequence into '<\/script>', so the JSON can never close its own tag.
	printf(
		'<script type="application/json" id="sn-verification-manifest">%s</script>' . "\n",
		wp_json_encode( $manifest )
	);
}
add_action( 'wp_head', 'sn_prov_machine_pointers_emit' );
