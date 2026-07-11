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
 * honestly produced (not a public published Note, no chain, no such version,
 * unsigned, or the stored payload no longer reproduces the anchored content_hash).
 * Never emits an unverifiable VC, and never a credential for non-public content.
 *
 * @param int      $post_id
 * @param int|null $version  null = latest.
 * @return array<string,mixed>|null
 */
function sn_prov_credential( $post_id, $version = null ) {
	// Only a public, published Note earns a credential: proof.signedPayloadB64 embeds the
	// canonical payload (which includes the post content), so a password-protected or
	// non-published Note would leak its content through this public endpoint.
	$post = get_post( (int) $post_id );
	if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		return null;
	}
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

/**
 * REST callback for GET /wp-json/signal-noise/v1/credential/<uid>.
 *
 * @param WP_REST_Request|object $request
 * @return WP_REST_Response|WP_Error
 */
function sn_prov_cred_rest( $request ) {
	$uid     = (string) $request->get_param( 'uid' );
	$post_id = sn_prov_post_by_uid( $uid );
	$v       = $request->get_param( 'v' );
	$vc      = $post_id
		? sn_prov_credential( (int) $post_id, ( null === $v || '' === $v ) ? null : (int) $v )
		: null;
	if ( null === $vc ) {
		// One opaque 404 for BOTH "uid resolves to nothing" AND "uid resolves to a
		// published-but-uncredentialed Note", so the endpoint is not an existence
		// oracle (mirrors the DID route's single body-less 404). No content is ever
		// disclosed in either branch; this only removes the distinguishable code.
		return new WP_Error( 'sn_prov_no_credential', 'No verifiable credential for this Note.', array( 'status' => 404 ) );
	}
	$resp = new WP_REST_Response( $vc, 200 );
	$resp->header( 'Content-Type', 'application/vc+ld+json' );
	return $resp;
}

/**
 * Register the credential REST route on the shared plugin namespace.
 */
function sn_prov_cred_register_route() {
	$ns = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	register_rest_route(
		$ns,
		'/credential/(?P<uid>[A-Za-z0-9-]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'sn_prov_cred_rest',
			'permission_callback' => '__return_true', // public: VCs are meant to be verified by anyone.
		)
	);
}

/**
 * Advertise the .json twin's credential from a Note's <head>.
 */
function sn_prov_cred_head_link() {
	if ( ! function_exists( 'is_singular' ) || ! is_singular( 'post' ) ) {
		return;
	}
	$post_id = get_the_ID();
	if ( ! $post_id || null === sn_prov_credential( (int) $post_id, null ) ) {
		return; // no signed credential yet — nothing to advertise.
	}
	$uid = function_exists( 'sn_prov_note_uid' ) ? (string) sn_prov_note_uid( $post_id ) : '';
	if ( '' === $uid ) {
		return;
	}
	$ns  = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	$url = function_exists( 'rest_url' ) ? rest_url( $ns . '/credential/' . rawurlencode( $uid ) ) : '';
	printf(
		'<link rel="alternate" type="application/vc+ld+json" href="%s" title="Verifiable Credential">' . "\n",
		esc_url( $url )
	);
}

/**
 * Advertise the DID document + the credential convention in sub-project A's
 * discovery manifest (the theme owns the filter).
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_prov_cred_advertise_surface( $surfaces ) {
	$home = function_exists( 'home_url' ) ? home_url() : '';
	$ns   = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	$surfaces[] = array(
		'type'        => 'did-web',
		'url'         => $home . '/.well-known/did.json',
		'format'      => 'application/did+json',
		'title'       => 'DID document',
		'description' => 'did:web issuer identity + the Ed25519 public key that verifies Note authorship credentials.',
	);
	$surfaces[] = array(
		'type'        => 'verifiable-credential',
		'url'         => $home . '/wp-json/' . $ns . '/credential/{note-uid}',
		'format'      => 'application/vc+ld+json',
		'title'       => 'Authorship credentials',
		'description' => "Each Note's Bitcoin-anchored authorship proof as a W3C Verifiable Credential (JSON-LD).",
	);
	return $surfaces;
}

if ( ! defined( 'SN_PROV_CRED_TEST' ) || ! SN_PROV_CRED_TEST ) {
	add_action( 'rest_api_init', 'sn_prov_cred_register_route' );
	add_action( 'wp_head', 'sn_prov_cred_head_link' );
	add_filter( 'sn_agents_surfaces', 'sn_prov_cred_advertise_surface' );
}
