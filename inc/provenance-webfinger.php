<?php
/**
 * Signal & Noise — WebFinger (RFC 7033): identity coherence, not federation.
 *
 * The site already answers to several standard discovery mechanisms — did:web at
 * /.well-known/did.json, the off-ledger key mirror, llms.txt, the agents manifest,
 * tdmrep.json. WebFinger adds one more ASKING mechanism that resolves to the SAME
 * identity: the Ed25519 key the provenance chain signs with. One entity,
 * discoverable several ways, all agreeing — a stronger claim than any single one.
 *
 * NOT federation. NodeInfo, the usual companion to WebFinger, is deliberately NOT
 * served: its schema (2.0 and 2.1 alike) makes `protocols` required with
 * minItems 1, and the enum is federation protocols only (activitypub, diaspora,
 * ostatus, …). This site speaks none of them, so every schema-valid NodeInfo
 * document this site could emit would be a false machine-readable claim sitting
 * beside did.json and tdmrep.json, which are true. Verified against the raw
 * schemas 2026-08-19. Do not add NodeInfo without re-reading that constraint.
 *
 * Flush-free virtual route (template_redirect pri 0), same mechanism as
 * inc/provenance-did.php and the theme's /.well-known/gpc.json.
 *
 * @package SignalNoiseTools
 * @since 11.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The acct: local part. Filterable because it is an identity decision, not a
 * mechanism one, and the rest of this file derives from it.
 *
 * @return string
 */
function sn_prov_webfinger_account_name() {
	return (string) apply_filters( 'sn_prov_webfinger_account_name', 'juan' );
}

/** @return string acct:<name>@<host> — the canonical subject. */
function sn_prov_webfinger_subject() {
	return 'acct:' . sn_prov_webfinger_account_name() . '@' . sn_prov_did_domain();
}

/**
 * Every URI this identity answers to. The DID is included on purpose: it makes
 * the same entity resolvable from either direction.
 *
 * @return string[]
 */
function sn_prov_webfinger_aliases() {
	$home = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	return array(
		sn_prov_did_id(),
		untrailingslashit( $home ),
	);
}

/**
 * Normalise a resource URI for comparison: scheme and host are case-insensitive
 * per RFC 3986, and a trailing slash on an https identity is not a difference.
 * Pure.
 *
 * @param string $resource
 * @return string
 */
function sn_prov_webfinger_normalize( $resource ) {
	$r = strtolower( trim( (string) $resource ) );
	return ( 'https://' === substr( $r, 0, 8 ) || 'http://' === substr( $r, 0, 7 ) )
		? rtrim( $r, '/' )
		: $r;
}

/**
 * Does this resource name the site's identity? Pure.
 *
 * @param string $resource
 * @return bool
 */
function sn_prov_webfinger_matches( $resource ) {
	$want = sn_prov_webfinger_normalize( $resource );
	if ( '' === $want ) {
		return false;
	}
	$known = array_merge( array( sn_prov_webfinger_subject() ), sn_prov_webfinger_aliases() );
	foreach ( $known as $uri ) {
		if ( sn_prov_webfinger_normalize( $uri ) === $want ) {
			return true;
		}
	}
	// http:// and https:// name the same identity; compare host-and-path only.
	$home = sn_prov_webfinger_normalize( (string) ( function_exists( 'home_url' ) ? home_url( '/' ) : '' ) );
	if ( '' !== $home ) {
		$strip = static function ( $u ) {
			return preg_replace( '#^https?://#', '', (string) $u );
		};
		if ( $strip( $home ) === $strip( $want ) && '' !== $strip( $want ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The full link set, before any rel filtering. Entries that depend on the signing
 * key are emitted ONLY when a key is configured — did.json 404s without one, and a
 * link to a 404 is a claim the site cannot keep.
 *
 * @return array<int,array<string,string>>
 */
function sn_prov_webfinger_links() {
	$home  = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
	$links = array(
		array(
			'rel'  => 'http://webfinger.net/rel/profile-page',
			'type' => 'text/html',
			'href' => $home,
		),
	);
	if ( null !== sn_prov_did_document() ) {
		$links[] = array(
			'rel'  => 'self',
			'type' => 'application/did+json',
			'href' => function_exists( 'home_url' ) ? home_url( '/.well-known/did.json' ) : '/.well-known/did.json',
		);
		$links[] = array(
			'rel'  => 'describedby',
			'type' => 'application/json',
			'href' => function_exists( 'home_url' ) ? home_url( '/.well-known/provenance-keys.json' ) : '/.well-known/provenance-keys.json',
		);
	}
	return $links;
}

/**
 * Build the JRD. RFC 7033 §4.3: when rel parameters are supplied the response
 * MUST carry only matching links — an empty links array is a correct answer, not
 * an error. Pure apart from the key read.
 *
 * @param string   $resource
 * @param string[] $rels
 * @return array<string,mixed>|null null when the resource is not this identity.
 */
function sn_prov_webfinger_document( $resource, $rels = array() ) {
	if ( ! sn_prov_webfinger_matches( $resource ) ) {
		return null;
	}
	$links = sn_prov_webfinger_links();
	if ( ! empty( $rels ) ) {
		$links = array_values(
			array_filter(
				$links,
				static function ( $link ) use ( $rels ) {
					return in_array( $link['rel'], $rels, true );
				}
			)
		);
	}
	return array(
		'subject' => sn_prov_webfinger_subject(),
		'aliases' => sn_prov_webfinger_aliases(),
		'links'   => $links,
	);
}

/**
 * Is this request for /.well-known/webfinger? Pure (takes the path).
 *
 * @param string $uri
 * @return bool
 */
function sn_prov_webfinger_is_request( $uri ) {
	$path = strtok( (string) $uri, '?' );
	$path = '/' . trim( (string) $path, '/' );
	return ( '/.well-known/webfinger' === $path );
}

/**
 * Pull `resource` and every `rel` out of a query string. Written by hand rather
 * than with parse_str() because RFC 7033 §4.3 allows rel to REPEAT, and parse_str
 * keeps only the last occurrence of a scalar key. Pure.
 *
 * @param string $uri Full request URI, or just the query.
 * @return array{resource:string,rels:string[]}
 */
function sn_prov_webfinger_parse_query( $uri ) {
	$uri   = (string) $uri;
	$qpos  = strpos( $uri, '?' );
	$query = ( false === $qpos ) ? $uri : substr( $uri, $qpos + 1 );
	$out   = array(
		'resource' => '',
		'rels'     => array(),
	);
	if ( '' === $query ) {
		return $out;
	}
	foreach ( explode( '&', $query ) as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$eq  = strpos( $pair, '=' );
		$key = ( false === $eq ) ? $pair : substr( $pair, 0, $eq );
		$val = ( false === $eq ) ? '' : urldecode( substr( $pair, $eq + 1 ) );
		if ( 'resource' === $key && '' === $out['resource'] ) {
			$out['resource'] = $val;
		} elseif ( 'rel' === $key && '' !== $val ) {
			$out['rels'][] = $val;
		}
	}
	return $out;
}

/**
 * Emit the JRD. 400 when `resource` is absent (RFC 7033 §4.2), 404 when it names
 * something that is not this identity, 200 otherwise. status_header is REQUIRED
 * (postless path → 404 by template_redirect).
 *
 * @param string $uri
 */
function sn_prov_webfinger_send( $uri ) {
	$q = sn_prov_webfinger_parse_query( $uri );
	// RFC 7033 §5: WebFinger is a cross-origin discovery mechanism; the header is
	// part of the contract, and it is set on the errors too so a browser client
	// can read WHY it failed instead of seeing an opaque CORS wall.
	header( 'Access-Control-Allow-Origin: *' );

	if ( '' === $q['resource'] ) {
		if ( function_exists( 'status_header' ) ) {
			status_header( 400 );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON endpoint; HTML escaping would corrupt the payload.
		echo wp_json_encode( array( 'error' => 'the resource parameter is required' ), JSON_UNESCAPED_SLASHES );
		return;
	}

	$doc = sn_prov_webfinger_document( $q['resource'], $q['rels'] );
	if ( null === $doc ) {
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON endpoint; HTML escaping would corrupt the payload.
		echo wp_json_encode( array( 'error' => 'no such resource on this host' ), JSON_UNESCAPED_SLASHES );
		return;
	}

	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	header( 'Content-Type: application/jrd+json; charset=utf-8' );
	header( 'Cache-Control: public, max-age=300' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- application/jrd+json from wp_json_encode; HTML escaping would corrupt the JSON.
	echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES );
}

/**
 * template_redirect handler.
 */
function sn_prov_webfinger_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_prov_webfinger_is_request( $req ) ) {
		sn_prov_webfinger_send( $req );
		exit;
	}
}

if ( ! defined( 'SN_PROV_WEBFINGER_TEST' ) || ! SN_PROV_WEBFINGER_TEST ) {
	add_action( 'template_redirect', 'sn_prov_webfinger_maybe_serve', 0 );
}
