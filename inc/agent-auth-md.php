<?php
/**
 * Signal & Noise Tools — /auth.md (agent authentication guide).
 *
 * WHAT THIS IS, AND WHAT IT DELIBERATELY IS NOT. auth.md is a procedural guide
 * telling an agent how to obtain credentials for a service. The protocol covers
 * both OAuth registration and manually-issued credentials; this site is the
 * second kind, and the document says so in those words.
 *
 * IT DOES NOT SATISFY THE `authMd` READINESS CHECK, ON PURPOSE. That check also
 * requires /.well-known/oauth-protected-resource and an `agent_auth` block in
 * /.well-known/oauth-authorization-server carrying a register_uri. Publishing
 * those would declare this site an OAuth authorization server. **It is not one**
 * — OAuth appears in this plugin only as a CLIENT (Search Console, Cloudways),
 * the MCP door authenticates with HTTP Basic and a WordPress application
 * password, and there is no registration endpoint anywhere. A register_uri
 * pointing at nothing is the same failure as an agent card claiming a JSON-RPC
 * binding it does not serve.
 *
 * So this ships the honest half. An agent that hits the MCP door currently gets
 * a bare 401 and no guidance at all: no statement of which credential is
 * needed, who issues it, or how to ask. That is a door with no sign on it, and
 * this is the sign.
 *
 * If the site ever becomes an OAuth authorization server, the Register section
 * gains a real flow and the check becomes reachable honestly.
 *
 * @package Signal_And_Noise_Tools
 * @since   12.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_AGENT_AUTH_MD_PATH' ) ) {
	define( 'SN_AGENT_AUTH_MD_PATH', '/auth.md' );
}

/** @param string $uri @return bool */
function sn_agent_auth_md_is_request( $uri ) {
	return sn_agent_normalize_path( $uri ) === SN_AGENT_AUTH_MD_PATH;
}

/**
 * The document. Endpoint URLs are derived, never typed, so a namespace change
 * moves them here too.
 *
 * @return string
 */
function sn_agent_auth_md_document() {
	$home    = function_exists( 'home_url' ) ? (string) home_url() : '';
	$mcp     = function_exists( 'sn_agent_mcp_endpoint_url' ) ? sn_agent_mcp_endpoint_url() : '';
	$card    = $home . ( defined( 'SN_AGENT_CARD_PATH' ) ? SN_AGENT_CARD_PATH : '' );
	$contact = $home . '/contact/';

	return <<<MD
# Authenticating with juanlentino.com

This site exposes a read-only MCP server. This document tells you how to get a
credential for it, and — just as usefully — tells you what does not exist here
so you do not go looking for it.

## Discover

The server card describes the endpoint, its capabilities, and its transport:

    GET {$card}

The MCP endpoint itself is:

    {$mcp}

Public documents need no credential at all. Everything under `/.well-known/`,
`/llms.txt`, the JSON feed, and any page requested with `Accept: text/markdown`
are open, unauthenticated, and identical for every reader. **If you only need to
read published content, stop here — you do not need to authenticate.**

## Register

**There is no programmatic registration.** This site is not an OAuth
authorization server, publishes no `register_uri`, and operates no dynamic
client registration endpoint. An agent cannot enrol itself.

Credentials are issued **manually, by the site owner**, to a named human who is
accountable for the agent using them. That is a deliberate choice, not a missing
feature: the tools behind this door read analytics, content health and deploy
status for one person's site, and the door fails closed.

## Claim

Ask, via {$contact}, and say who you are and what you intend to read. If the
owner agrees, they create a WordPress application password scoped to an account
holding the `manage_options` capability and send it to you out of band.

## Exchange

There is no token exchange. The application password **is** the credential.

## Use

HTTP Basic against the MCP endpoint:

    Authorization: Basic base64(username:application_password)

The authenticated user must hold `manage_options`. An anonymous or
under-privileged request receives `401`. A `401` from this endpoint means the
credential is missing or insufficient — not that the endpoint is wrong.

Only read tools are reachable on this door. The write door is a separate
endpoint, is never advertised in any discovery document, and is not obtainable
through this process.

## Handle revoke

Application passwords are revoked by the owner from the WordPress user profile
that issued them. Revocation is immediate and unannounced: treat any sudden
`401` as revocation, stop retrying, and ask again through the same contact path.
Do not treat a `401` as a transient error to back off and retry indefinitely.

MD;
}

/** Send the document as Markdown. */
function sn_agent_auth_md_send() {
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'Access-Control-Allow-Origin: *' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown document; HTML escaping would corrupt it.
	echo sn_agent_auth_md_document();
}

/** Route the request. */
function sn_agent_auth_md_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_agent_auth_md_is_request( $req ) ) {
		sn_agent_auth_md_send();
		exit;
	}
}

/**
 * Advertise it on agents.json.
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_agent_auth_md_advertise_surface( $surfaces ) {
	$home       = function_exists( 'home_url' ) ? (string) home_url() : '';
	$surfaces[] = array(
		'type'        => 'auth-guide',
		'url'         => $home . SN_AGENT_AUTH_MD_PATH,
		'format'      => 'text/markdown',
		'title'       => 'Authentication guide',
		'description' => 'How to obtain a credential for the MCP read door. Credentials are issued manually; there is no self-service registration.',
	);
	return $surfaces;
}

if ( ! defined( 'SN_AGENT_DISCOVERY_TEST' ) || ! SN_AGENT_DISCOVERY_TEST ) {
	add_action( 'template_redirect', 'sn_agent_auth_md_maybe_serve', 0 );
	add_filter( 'sn_agents_surfaces', 'sn_agent_auth_md_advertise_surface' );
}
