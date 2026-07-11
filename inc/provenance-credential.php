<?php
/**
 * Signal & Noise — verifiable provenance (D1): the per-Note Verifiable Credential.
 * Re-serializes an existing signed provenance commit (content_hash + Ed25519
 * signature over the canonical payload + Bitcoin anchor) into a W3C VC (JSON-LD),
 * served at /wp-json/signal-noise/v1/credential/<uid>. No new signing.
 *
 * @package SignalNoiseTools
 * @since 9.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_CRED_CRYPTOSUITE = 'sn-ed25519-canonical-2026';
const SN_PROV_AUTHOR_ORCID     = 'https://orcid.org/0009-0006-8151-5920';

/**
 * Select a commit from the chain: the newest by default, or the one matching
 * $version. Returns null if not found.
 *
 * @param array    $chain
 * @param int|null $version
 * @return array|null
 */
function sn_prov_cred_select_commit( $chain, $version ) {
	if ( ! is_array( $chain ) || empty( $chain ) ) {
		return null;
	}
	if ( null === $version ) {
		return end( $chain );
	}
	foreach ( $chain as $commit ) {
		if ( (int) ( $commit['version'] ?? 0 ) === (int) $version ) {
			return $commit;
		}
	}
	return null;
}

/**
 * Build the Verifiable Credential for a Note's commit, or null when one can't be
 * honestly produced (no chain, no such version, unsigned, or the stored payload
 * no longer reproduces the anchored content_hash). Never emits an unverifiable VC.
 *
 * @param int      $post_id
 * @param int|null $version  null = latest.
 * @return array<string,mixed>|null
 */
function sn_prov_credential( $post_id, $version = null ) {
	$commit = sn_prov_cred_select_commit( sn_prov_get_chain( $post_id ), $version );
	if ( null === $commit ) {
		return null;
	}
	$signature = (string) ( $commit['signature'] ?? '' );
	if ( '' === $signature ) {
		return null; // unsigned — the proof does not exist yet.
	}
	$payload = isset( $commit['payload'] ) && is_array( $commit['payload'] ) ? $commit['payload'] : null;
	if ( null === $payload ) {
		return null;
	}
	// Reproduce the exact signed bytes and self-check them against the anchored hash.
	$canonical = sn_prov_canonical_json( $payload );
	if ( sn_prov_content_hash( $canonical ) !== (string) ( $commit['content_hash'] ?? '' ) ) {
		return null; // payload drift — refuse to emit an unverifiable credential.
	}

	$url    = get_permalink( $post_id );
	$title  = wp_strip_all_tags( get_the_title( $post_id ) );
	$block  = (int) ( $commit['bitcoin_block'] ?? 0 );
	$txid   = (string) ( $commit['bitcoin_txid'] ?? '' );
	$status = $block > 0 ? 'confirmed' : 'pending';

	$anchor = array(
		'chain'  => 'bitcoin',
		'txid'   => $txid,
		'block'  => $block > 0 ? $block : null,
		'status' => $status,
	);
	if ( '' !== $txid ) {
		$anchor['explorer'] = 'https://mempool.space/tx/' . $txid;
	}

	return array(
		'@context'          => array( 'https://www.w3.org/ns/credentials/v2', 'https://schema.org/' ),
		'type'              => array( 'VerifiableCredential', 'AuthorshipCredential' ),
		'issuer'            => sn_prov_did_id(),
		'validFrom'         => get_the_date( 'c', $post_id ),
		'credentialSubject' => array(
			'id'            => $url,
			'type'          => 'CreativeWork',
			'name'          => $title,
			'url'           => $url,
			'datePublished' => get_the_date( 'c', $post_id ),
			'author'        => array(
				'type'       => 'Person',
				'name'       => 'Juan Lentino',
				'url'        => home_url( '/about/' ),
				'identifier' => SN_PROV_AUTHOR_ORCID,
			),
		),
		'evidence'          => array(
			array(
				'type'        => 'BitcoinAnchor',
				'contentHash' => 'sha256:' . (string) $commit['content_hash'],
				'version'     => (int) ( $commit['version'] ?? 0 ),
				'anchor'      => $anchor,
			),
		),
		'proof'             => array(
			'type'                  => 'DataIntegrityProof',
			'cryptosuite'           => SN_PROV_CRED_CRYPTOSUITE,
			'verificationMethod'    => sn_prov_did_verification_method_id(),
			'proofPurpose'          => 'assertionMethod',
			'proofValue'            => $signature,
			'signedPayloadB64'      => base64_encode( $canonical ),
			'verificationProcedure' => home_url( '/provenance/verify' ),
		),
	);
}
