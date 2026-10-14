<?php
/**
 * Signal & Noise Tools: provenance integrity sweep, the fetch helpers and the rotation (the twin and ledger URLs, the HTTP and JSON fetchers, the outage and reconfirm classes, the batch selector, the keys probe).
 *
 * Split from inc/provenance-integrity.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The whitespace-collapse comparison form: the SHARED sn-normalize-v1
 * pipeline (inc/provenance-core.php — comments, tags, entities, NFC, line
 * whitespace) followed by ONE final collapse of ALL whitespace including
 * newlines. The canonical payload keeps its paragraph structure while the
 * theme twin's content_text is the document flattened to one line, so
 * line-preserving comparison can never match them; "same words in the same
 * order" is the honest match semantic (paragraph breaks carry no
 * provenance). Mirrors assets/js/prov-verify-core.js roughNormalize().
 *
 * @since 9.80.0
 * @param string $raw Payload content or twin content_text.
 * @return string
 */
function sn_prov_integrity_flatten( $raw ) {
	// v13.4.0: v2 (expand signal-noise void-block text, then the v1
	// pipeline). Both comparison sides pass through here — a stored
	// payload's content is already-normalized prose (no delimiters, so v2
	// degrades to v1 over it), while a twin/raw side carrying a dynamic
	// block gets its attribute text expanded exactly as signing did.
	$s = sn_prov_normalize_v2( (string) $raw );
	return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * A Note's public .json twin URL: fragment/query stripped, trailing slash
 * normalized, '.json' appended — byte-for-byte the /verify JS derivation
 * (pastedTwinUrl / liveMatchTwinUrl).
 *
 * @since 9.80.0
 * @param string $permalink The Note's public permalink.
 * @return string
 */
function sn_prov_integrity_twin_url( $permalink ) {
	$permalink = (string) preg_replace( '/[#?].*$/', '', (string) $permalink );
	return rtrim( $permalink, '/' ) . '.json';
}

/**
 * Raw ledger base URL — the same sn_prov_ledger_owner / sn_prov_ledger_repo
 * filters inc/provenance-verify.php hands the /verify page, so this sweep
 * can never check a different ledger than the one readers verify against.
 *
 * @since 9.80.0
 * @return string
 */
function sn_prov_integrity_ledger_base() {
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	return "https://raw.githubusercontent.com/{$owner}/{$repo}/main/";
}

/**
 * Default HTTP fetcher: wp_safe_remote_get with the module timeout. Both
 * targets are fixed known hosts (own site + raw.githubusercontent.com); the
 * safe variant additionally refuses any unsafe redirect destination.
 *
 * @since 9.80.0
 * @param string $url
 * @return array{code:int,body:string} code 0 = network error (unreachable).
 */
function sn_prov_integrity_http_fetch( $url ) {
	$resp = wp_safe_remote_get(
		$url,
		array(
			'timeout'     => SN_PROV_INTEGRITY_FETCH_TIMEOUT,
			'redirection' => 2,
			'user-agent'  => 'SN-Provenance-Integrity/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '0' ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return array( 'code' => 0, 'body' => '' );
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $resp ),
		'body' => (string) wp_remote_retrieve_body( $resp ),
	);
}

/**
 * Fetch + decode a JSON document. Anything that is not an HTTP 200 carrying
 * a JSON object decodes to null — the caller decides whether that means
 * "unreachable" (network/5xx/garbage) or "missing" (404 is a real answer).
 *
 * @since 9.80.0
 * @param string   $url
 * @param callable $fetcher
 * @return array{code:int,json:array|null}
 */
function sn_prov_integrity_fetch_json( $url, $fetcher ) {
	$res  = call_user_func( $fetcher, $url );
	$code = (int) ( $res['code'] ?? 0 );
	$json = null;
	if ( 200 === $code ) {
		$decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
		$json    = is_array( $decoded ) ? $decoded : null;
	}
	return array( 'code' => $code, 'json' => $json );
}

/**
 * Is this failure code an outage rather than drift? An unreachable endpoint
 * is a gap in today's evidence, never evidence of tampering.
 *
 * @since 9.80.0
 * @param string $code Failure leg code.
 * @return bool
 */
function sn_prov_integrity_is_outage( $code ) {
	return in_array( (string) $code, array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ), true );
}

/**
 * Whether a failure code names a MISMATCH a re-read could clear (v14.7.3):
 * drift, a hash or ledger contradiction, a key the ledger no longer serves.
 * Outages are gaps and absences are owner-side states; neither changes by
 * looking again, so neither earns the sweep's early slot.
 *
 * @param string $code
 * @return bool
 */
function sn_prov_integrity_is_reconfirmable( $code ) {
	return in_array( (string) $code, array( 'twin_drift', 'hash_mismatch', 'ledger_missing', 'ledger_hash_mismatch', 'ledger_record_malformed', 'signing_key_unpublished', 'twin_missing', 'key_mismatch' ), true );
}

/**
 * Pick this run's batch: never-checked (0) first, then subjects whose last
 * verdict FAILED (v14.7.3, at most half the cap), then oldest-checked
 * ascending, ties broken by post id for a stable rotation, capped.
 * PURE — exhaustively testable.
 *
 * @since 9.80.0
 * @param int[]           $ids          Fleet post ids.
 * @param array<int,int>  $last_checked post id → unix time of last check (absent = never).
 * @param int             $cap
 * @return int[]
 */
function sn_prov_integrity_select_batch( array $ids, array $last_checked, $cap, array $failing = array() ) {
	$cap = max( 0, (int) $cap );
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	$by_age = static function ( $a, $b ) use ( $last_checked ) {
		$ta = (int) ( $last_checked[ $a ] ?? 0 );
		$tb = (int) ( $last_checked[ $b ] ?? 0 );
		return $ta === $tb ? $a <=> $b : $ta <=> $tb;
	};
	usort( $ids, $by_age );

	// v14.7.3: a subject whose LAST stored verdict failed is re-read ahead of
	// the rotation. Without this a failing subject came around only with the
	// rotation (46 subjects at 10 a run: five days), so a fix, or the reversal
	// of a real edit, could not be confirmed before then; three intact pages
	// sat in the Attention queue as "twin drift" for the whole rotation
	// (2026-09-14). Two guards keep coverage honest: a NEVER-read subject
	// still goes first (no verdict is worse than a stale one), and failing
	// subjects take at most half the cap, so a fleet that is mostly failing
	// cannot starve the rotation (the full-coverage pin in the suite).
	$failing = array_flip( array_map( 'intval', $failing ) );
	$never   = array_values( array_filter( $ids, static function ( $id ) use ( $last_checked ) { return 0 === (int) ( $last_checked[ $id ] ?? 0 ); } ) );
	$failed  = array_values( array_filter( $ids, static function ( $id ) use ( $failing, $last_checked ) { return isset( $failing[ $id ] ) && 0 !== (int) ( $last_checked[ $id ] ?? 0 ); } ) );
	$batch   = array_slice( $never, 0, $cap );
	$batch   = array_merge( $batch, array_slice( $failed, 0, max( 0, min( count( $failed ), intdiv( $cap, 2 ), $cap - count( $batch ) ) ) ) );
	foreach ( $ids as $id ) {
		if ( count( $batch ) >= $cap ) {
			break;
		}
		if ( ! in_array( $id, $batch, true ) ) {
			$batch[] = $id;
		}
	}
	return $batch;
}

/**
 * The keys verdict PLUS the raw HTTP code, so the sweep can distinguish a
 * 404 (a real "the file is absent" answer) from a network error and escalate
 * a PERSISTENT 404 to keys_missing after SN_PROV_INTEGRITY_404_STREAK
 * consecutive sweeps.
 *
 * @since 9.81.0
 * @param callable $fetcher
 * @return array{verdict:string,code:int,published_ids:string[]|null}
 */
function sn_prov_integrity_keys_probe( $fetcher ) {
	$key_id  = function_exists( 'sn_prov_key_id' ) ? (string) sn_prov_key_id() : '';
	$pub_b64 = function_exists( 'sn_prov_pubkey_b64' ) ? trim( (string) sn_prov_pubkey_b64() ) : '';
	if ( '' === $key_id && '' === $pub_b64 ) {
		// Nothing fetched, so nothing is known about what the document
		// publishes: null, never an empty list. An empty list would read as
		// "the document publishes no keys" and strand every Note in leg (d).
		return array( 'verdict' => 'skipped', 'code' => 0, 'published_ids' => null );
	}
	$res = sn_prov_integrity_fetch_json( sn_prov_integrity_ledger_base() . 'keys/provenance-keys.json', $fetcher );
	if ( ! is_array( $res['json'] ) || ! isset( $res['json']['keys'] ) || ! is_array( $res['json']['keys'] ) ) {
		// network-dead, 404, or un-decodable — an outage/unknown THIS sweep,
		// never a rotation claim; the sweep escalates a persistent 404.
		return array( 'verdict' => 'keys_unreachable', 'code' => (int) $res['code'], 'published_ids' => null );
	}

	// Every id the document publishes, active and retired alike. Leg (d) holds
	// each Note's OWN signing key to this list: the verdict below only asks
	// about the CURRENT key, which says nothing about the retired keys that
	// historical Notes still depend on after a rotation.
	$published_ids = array();
	foreach ( $res['json']['keys'] as $entry ) {
		if ( is_array( $entry ) && '' !== (string) ( $entry['id'] ?? '' ) ) {
			$published_ids[] = (string) $entry['id'];
		}
	}

	foreach ( $res['json']['keys'] as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( '' !== $key_id && (string) ( $entry['id'] ?? '' ) !== $key_id ) {
			continue; // not the published id.
		}
		// The id label alone must not pass a swapped key: when both sides
		// publish key bytes, they must agree.
		if ( '' !== $pub_b64 && isset( $entry['public_key_base64'] ) && trim( (string) $entry['public_key_base64'] ) !== $pub_b64 ) {
			continue;
		}
		return array( 'verdict' => 'ok', 'code' => (int) $res['code'], 'published_ids' => $published_ids );
	}
	return array( 'verdict' => 'key_mismatch', 'code' => (int) $res['code'], 'published_ids' => $published_ids );
}
