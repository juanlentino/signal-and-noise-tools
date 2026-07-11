<?php
/**
 * Signal & Noise — MCP server: capabilities (allowlist SoT, protocol version,
 * server identity). Pure data; no side effects. Sub-project B of the
 * machine-readability program (see docs/superpowers/specs/2026-07-11-*B-mcp-*).
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_MCP_PROTOCOL_VERSION' ) ) {
	// Fallback MCP protocol revision. The endpoint echoes the client's requested
	// version when we support it (sn_mcp_negotiate_version), so this is only used
	// when the client asks for something we don't recognize.
	define( 'SN_MCP_PROTOCOL_VERSION', '2025-06-18' );
}

/**
 * The v1 read-only allowlist: ability slugs exposed as MCP tools. This is the
 * single security gate — tools/list advertises exactly these and tools/call
 * rejects anything not here. Cross-namespace (plugin + theme) resolves through
 * the one global WP_Abilities_Registry.
 *
 * @return string[]
 */
function sn_mcp_allowlist() {
	$slugs = array(
		// Plugin (signal-noise/) — operational reads.
		'signal-noise/get-health-scan',
		'signal-noise/uptime-status',
		'signal-noise/get-deploy-status',
		'signal-noise/get-analytics-summary',
		'signal-noise/get-rss-stats',
		'signal-noise/get-cron-history',
		'signal-noise/list-cron-events',
		'signal-noise/get-insights',
		'signal-noise/get-narration',
		// Theme (signal-and-noise/) — identity + design system.
		'signal-and-noise/get-theme-version',
		'signal-and-noise/get-latest-theme-tag',
		'signal-and-noise/get-design-tokens',
		'signal-and-noise/get-design-system-summary',
		'signal-and-noise/get-active-template-structure',
		'signal-and-noise/list-block-patterns',
	);

	/**
	 * Filter the MCP tool allowlist. v1 is read-only; a later phase can add slugs
	 * (e.g. the deferred reads) with one callback. Callbacks MUST return slugs of
	 * abilities that are safe to expose read-only over an admin-gated endpoint.
	 *
	 * @param string[] $slugs
	 */
	return (array) apply_filters( 'sn_mcp_allowlist', $slugs );
}

/**
 * Is a slug exposed over MCP? Gates tools/call, not just tools/list.
 *
 * @param string $slug
 * @return bool
 */
function sn_mcp_is_allowed( $slug ) {
	return in_array( (string) $slug, sn_mcp_allowlist(), true );
}

/**
 * Server identity for the initialize response.
 *
 * @return array{name:string,version:string}
 */
function sn_mcp_server_info() {
	return array(
		'name'    => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Signal & Noise',
		'version' => defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '',
	);
}

/**
 * Negotiate the protocol version: echo the client's requested revision when we
 * recognize it, else our pinned default. Makes us robust to spec revisions
 * without a code change.
 *
 * @param string $requested
 * @return string
 */
function sn_mcp_negotiate_version( $requested ) {
	$supported = array( '2025-06-18', '2025-03-26', '2024-11-05' );
	return in_array( (string) $requested, $supported, true ) ? (string) $requested : SN_MCP_PROTOCOL_VERSION;
}
