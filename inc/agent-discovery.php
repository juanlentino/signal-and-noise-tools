<?php
/**
 * Signal & Noise Tools — standard-named agent discovery documents.
 *
 *   /.well-known/mcp/server-card.json   MCP Server Card (SEP-1649)
 *   /.well-known/api-catalog            API catalog linkset (RFC 9727)
 *
 * WHY THESE EXIST, given /.well-known/agents.json already names both the MCP
 * endpoint and the Abilities API: agents.json is a BESPOKE address. Its own
 * docblock says why — "no dominant 'agent discovery' standard exists". That was
 * true when it was written. It no longer is, and the cost is measurable:
 * Cloudflare's Agent Readiness scan (2026-08-22) reads this site as having NO
 * MCP server and NO API catalog, because it looks at the standard paths and
 * finds 404s. The capabilities were never missing; the ADDRESS was wrong.
 *
 * So these two documents add no capability, expose no new endpoint, and grant
 * no access. They restate facts already published at a bespoke address, at the
 * addresses the ecosystem actually reads. agents.json stays exactly as it is:
 * it is the richer index, and it is still what /llms.txt points at.
 *
 * READ DOOR ONLY — the rw door is deliberately absent, for the reason
 * sn_mcp_advertise_surface() (inc/mcp/mcp-endpoint.php, D5) already gives for
 * agents.json: a well-known file is an UNATTENDED discovery surface that any
 * crawler reads without a session, so it may only name the door that is safe to
 * hand to an unattended reader. A .well-known path is that argument's strongest
 * case, not an exception to it. Do not add the rw door here.
 *
 * Neither document is a grant. Both endpoints they name are authenticated
 * (manage_options + application password); publishing their location changes
 * nothing about who can open them, and agents.json has named the same MCP URL
 * publicly since v9.22.0.
 *
 * @package SignalNoiseTools
 * @since 12.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_AGENT_CARD_PATH' ) ) {
	define( 'SN_AGENT_CARD_PATH', '/.well-known/mcp/server-card.json' );
}

if ( ! defined( 'SN_AGENT_CATALOG_PATH' ) ) {
	define( 'SN_AGENT_CATALOG_PATH', '/.well-known/api-catalog' );
}

// Content types, named rather than inlined so a test can pin them. The catalog's
// type is load-bearing: RFC 9727 §3 defines an API catalog AS a linkset document,
// and a consumer that content-type-sniffs will not recognize one served as plain
// application/json. Serving the right bytes under the wrong type is the same
// failure as serving them at the wrong path.
if ( ! defined( 'SN_AGENT_CARD_TYPE' ) ) {
	define( 'SN_AGENT_CARD_TYPE', 'application/json; charset=utf-8' );
}

if ( ! defined( 'SN_AGENT_CATALOG_TYPE' ) ) {
	define( 'SN_AGENT_CATALOG_TYPE', 'application/linkset+json; charset=utf-8' );
}

/**
 * Normalize a request URI to a bare path for exact matching. Shared by both
 * route matchers so the two can never disagree about what "the same path"
 * means. Mirrors sn_prov_did_is_request()'s idiom (strtok + trim), which is
 * the established one in this plugin.
 *
 * @param string $uri Request URI, possibly with a query string.
 * @return string
 */
function sn_agent_normalize_path( $uri ) {
	$path = strtok( (string) $uri, '?' );
	return '/' . trim( (string) $path, '/' );
}

/** @param string $uri @return bool */
function sn_agent_card_is_request( $uri ) {
	return ( SN_AGENT_CARD_PATH === sn_agent_normalize_path( $uri ) );
}

/** @param string $uri @return bool */
function sn_agent_catalog_is_request( $uri ) {
	return ( SN_AGENT_CATALOG_PATH === sn_agent_normalize_path( $uri ) );
}

/**
 * Absolute URL of the MCP read door. rest_url() (not a hand-built /wp-json/)
 * so a customized rest_url_prefix stays honest — the same reason
 * sn_mcp_advertise_surface() uses it.
 *
 * @return string Empty string outside a WordPress request.
 */
function sn_agent_mcp_endpoint_url() {
	if ( ! function_exists( 'rest_url' ) || ! function_exists( 'sn_mcp_namespace' ) ) {
		return '';
	}
	return (string) rest_url( sn_mcp_namespace() . '/mcp' );
}

/** @return string Absolute URL of this site's own server card. */
function sn_agent_card_url() {
	return function_exists( 'home_url' ) ? (string) home_url( SN_AGENT_CARD_PATH ) : '';
}

/**
 * The MCP Server Card document (SEP-1649).
 *
 * `capabilities` comes from sn_mcp_capabilities_map() — the same array the
 * initialize handshake returns — so the card cannot advertise something the
 * server does not do. `authentication` is stated rather than implied: a client
 * that reads this card and tries an anonymous connect gets a 401, and saying so
 * up front is the difference between a discovery document and a trap.
 *
 * @return array<string,mixed>
 */
function sn_agent_mcp_server_card() {
	return array(
		'serverInfo'      => sn_mcp_server_info( SN_MCP_DOOR_READ ),
		'protocolVersion' => SN_MCP_PROTOCOL_VERSION,
		'transport'       => array(
			'type'     => 'streamable-http',
			'endpoint' => sn_agent_mcp_endpoint_url(),
		),
		'capabilities'    => sn_mcp_capabilities_map(),
		'authentication'  => array(
			'required'    => true,
			'type'        => 'application-password',
			'description' => 'HTTP Basic with a WordPress application password. The authenticated user needs manage_options; anonymous requests receive 401.',
		),
	);
}

/**
 * The API catalog linkset (RFC 9727, serialized per RFC 9264).
 *
 * Three anchors, each one an API that actually answers today. Every href is
 * live — advertising a 404 from a discovery document is the failure this whole
 * module exists to fix, and repeating it here would be self-defeating.
 *
 * No `service-doc` entries: there is no separate human documentation page for
 * these APIs, and pointing the relation at something that is not documentation
 * would be padding the shape at the cost of its truth. `status` is likewise
 * omitted — the Worker health endpoints describe Workers, not these APIs.
 *
 * @return array<string,mixed>
 */
function sn_agent_api_catalog() {
	$rest = function_exists( 'rest_url' ) ? (string) rest_url() : '';
	$mcp  = sn_agent_mcp_endpoint_url();

	$linkset = array(
		// The WP REST index is its own machine-readable description: it
		// enumerates every namespace, route, method and argument schema. So
		// anchor and service-desc are the same URL here, and that is accurate
		// rather than lazy — there is no separate OpenAPI document to point at.
		array(
			'anchor'       => $rest,
			'service-desc' => array(
				array(
					'href' => $rest,
					'type' => 'application/json',
				),
			),
		),
		// Abilities API — self-describing in the same way: each ability carries
		// its own input/output JSON Schema. Authenticated; see the server card.
		array(
			'anchor'       => function_exists( 'rest_url' ) ? (string) rest_url( 'wp-abilities/v1/abilities' ) : '',
			'service-desc' => array(
				array(
					'href' => function_exists( 'rest_url' ) ? (string) rest_url( 'wp-abilities/v1/abilities' ) : '',
					'type' => 'application/json',
				),
			),
		),
		// MCP read door — described by the server card next door, which is the
		// one genuinely non-degenerate service-desc in this catalog.
		array(
			'anchor'       => $mcp,
			'service-desc' => array(
				array(
					'href' => sn_agent_card_url(),
					'type' => 'application/json',
				),
			),
		),
	);

	return array( 'linkset' => $linkset );
}

/**
 * Emit a discovery document. Both routes are postless, so status_header( 200 )
 * is REQUIRED — template_redirect would otherwise 404 them.
 *
 * @param array<string,mixed> $doc          Document to serialize.
 * @param string              $content_type Full Content-Type header value.
 * @return void
 */
function sn_agent_send_document( $doc, $content_type ) {
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	header( 'Content-Type: ' . $content_type );
	header( 'Cache-Control: public, max-age=3600' );
	// CORS: a browser-resident agent reading a cross-origin discovery document
	// is the intended audience. Safe because both documents are public,
	// unauthenticated, and identical for every reader.
	header( 'Access-Control-Allow-Origin: *' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON endpoint; HTML escaping would corrupt the document.
	echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
}

/** @return void */
function sn_agent_card_send() {
	sn_agent_send_document( sn_agent_mcp_server_card(), SN_AGENT_CARD_TYPE );
}

/** @return void */
function sn_agent_catalog_send() {
	sn_agent_send_document( sn_agent_api_catalog(), SN_AGENT_CATALOG_TYPE );
}

/**
 * template_redirect handler (priority 0), same flush-free virtual-route
 * mechanism as the DID document and the theme's agents.json.
 *
 * @return void
 */
function sn_agent_discovery_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_agent_card_is_request( $req ) ) {
		sn_agent_card_send();
		exit;
	}
	if ( sn_agent_catalog_is_request( $req ) ) {
		sn_agent_catalog_send();
		exit;
	}
}

/**
 * Advertise the two standard documents in /.well-known/agents.json.
 *
 * agents.json is the site's own, richer index — and until now it did not know
 * about the standard-named documents published next to it, which is backwards.
 * The theme owns the `sn_agents_surfaces` filter precisely so the plugin can
 * append without a theme edit; sn_mcp_advertise_surface() is the precedent.
 *
 * Both entries are unauthenticated public documents, so naming them on an
 * unattended surface adds nothing that /.well-known/ does not already give away.
 * The rw door still never appears — see the file docblock.
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_agent_advertise_discovery_surfaces( $surfaces ) {
	$home = function_exists( 'home_url' ) ? (string) home_url() : '';
	$surfaces[] = array(
		'type'        => 'mcp-server-card',
		'url'         => $home . SN_AGENT_CARD_PATH,
		'format'      => 'application/json',
		'title'       => 'MCP Server Card',
		'description' => 'SEP-1649 server card for the read door: transport endpoint, capabilities, and how to authenticate.',
	);
	$surfaces[] = array(
		'type'        => 'api-catalog',
		'url'         => $home . SN_AGENT_CATALOG_PATH,
		'format'      => 'application/linkset+json',
		'title'       => 'API catalog',
		'description' => 'RFC 9727 linkset over the REST index, the Abilities API and the MCP read door.',
	);
	return $surfaces;
}

if ( ! defined( 'SN_AGENT_DISCOVERY_TEST' ) || ! SN_AGENT_DISCOVERY_TEST ) {
	add_action( 'template_redirect', 'sn_agent_discovery_maybe_serve', 0 );
	add_filter( 'sn_agents_surfaces', 'sn_agent_advertise_discovery_surfaces' );
}
