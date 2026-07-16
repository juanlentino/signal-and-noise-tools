<?php
/**
 * Signal & Noise — MCP server: resources (v9.50.0). Read-only, live-generated
 * surfaces exposed over resources/list + resources/read on BOTH doors — a
 * client can fetch these directly, no tool call needed. Sub-project B.
 *
 * Every resource here is live-generated, never a stale repo doc read: the
 * abilities catalog walks the LIVE WP_Abilities_Registry (superseding
 * docs/ai-abilities-catalog.md as the machine-readable surface — that file
 * goes stale between releases, this can't), design-tokens and llms-txt pass
 * through the theme's own read abilities, and changelog-latest reads the
 * plugin's OWN installed CHANGELOG.md — the exact file that ships with this
 * running code, not a snapshot of it.
 *
 * Degrade contract: an unavailable underlying ability (unregistered,
 * permission-denied, or a WP_Error from execute()) NEVER becomes a JSON-RPC
 * protocol error here — it degrades to a text/plain content block saying so.
 * This mirrors tools/call's own convention (inc/mcp/mcp-tools.php's isError
 * results): the resource itself was found, its live data source just isn't
 * available right now.
 *
 * @package SignalNoiseTools
 * @since 9.50.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The resources/list result: the 4 R2 resource descriptors, identical on both
 * doors (resources are read-only regardless of which door asks).
 *
 * @return array{resources:array<int,array<string,string>>}
 */
function sn_mcp_resources_list() {
	return array(
		'resources' => array(
			array(
				'uri'         => 'sn://abilities-catalog',
				'name'        => 'Abilities catalog',
				'description' => 'Every registered WordPress ability (slug, label, description, category), generated live from the registry — supersedes docs/ai-abilities-catalog.md as the machine-readable surface.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'sn://changelog-latest',
				'name'        => 'Changelog (latest)',
				'description' => "The top entries of this plugin's own installed CHANGELOG.md.",
				'mimeType'    => 'text/markdown',
			),
			array(
				'uri'         => 'sn://design-tokens',
				'name'        => 'Design tokens',
				'description' => "The theme's color, typography, and spacing tokens (JSON passthrough of get-design-tokens).",
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'sn://llms-txt',
				'name'        => 'llms.txt manifest',
				'description' => "The theme's rendered llms.txt AI-crawler manifest.",
				'mimeType'    => 'text/plain',
			),
		),
	);
}

/**
 * Build one MCP resource content block.
 *
 * @param string $uri
 * @param string $mime
 * @param string $text
 * @return array{uri:string,mimeType:string,text:string}
 */
function sn_mcp_resource_content( $uri, $mime, $text ) {
	return array(
		'uri'      => (string) $uri,
		'mimeType' => (string) $mime,
		'text'     => (string) $text,
	);
}

/**
 * Resolve a resources/read request. Returns null for an unrecognized uri — the
 * caller (sn_mcp_handle_request) maps that to a -32602 JSON-RPC error (R4). A
 * RECOGNIZED uri always resolves to a result past this point; an unavailable
 * underlying ability degrades to an error-text content instead of returning
 * null (see sn_mcp_resource_ability_passthrough).
 *
 * @param string $uri
 * @return array{contents:array<int,array<string,string>>}|null
 */
function sn_mcp_resource_read( $uri ) {
	switch ( (string) $uri ) {
		case 'sn://abilities-catalog':
			return array( 'contents' => array( sn_mcp_resource_content( $uri, 'application/json', sn_mcp_abilities_catalog_json() ) ) );

		case 'sn://changelog-latest':
			return array( 'contents' => array( sn_mcp_resource_content( $uri, 'text/markdown', sn_mcp_changelog_latest_text() ) ) );

		case 'sn://design-tokens':
			return array( 'contents' => array( sn_mcp_resource_ability_passthrough( 'signal-and-noise/get-design-tokens', $uri, 'application/json' ) ) );

		case 'sn://llms-txt':
			return array( 'contents' => array( sn_mcp_resource_ability_passthrough( 'signal-and-noise/get-llms-txt', $uri, 'text/plain', 'content' ) ) );

		default:
			return null;
	}
}

/**
 * The abilities catalog body: slug/label/description/category for EVERY
 * registered ability, both namespaces — this is a whole-registry discovery
 * surface, deliberately NOT filtered by either MCP door's allowlist (an agent
 * reading this resource is asking "what exists on this site", not "what can I
 * call over MCP right now"). JSON-encoded so it survives resources/read's
 * text-content shape untouched.
 *
 * @return string
 */
function sn_mcp_abilities_catalog_json() {
	$abilities = array();
	if ( function_exists( 'wp_get_abilities' ) ) {
		foreach ( wp_get_abilities() as $ability ) {
			$meta        = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
			$abilities[] = array(
				'slug'        => (string) $ability->get_name(),
				'label'       => (string) $ability->get_label(),
				'description' => (string) $ability->get_description(),
				'category'    => isset( $meta['category'] ) ? (string) $meta['category'] : '',
			);
		}
	}
	return (string) wp_json_encode( array( 'abilities' => $abilities ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * Path to the plugin's own installed CHANGELOG.md.
 *
 * @return string
 */
function sn_mcp_changelog_path() {
	return defined( 'SNT_PATH' ) ? SNT_PATH . 'CHANGELOG.md' : dirname( __DIR__, 2 ) . '/CHANGELOG.md';
}

/**
 * The top ~5 CHANGELOG.md entries, verbatim markdown, each starting at a
 * "## [" version heading (the file's own convention — see CHANGELOG.md's own
 * entries). $path is injectable so tests can slice a small fixture instead of
 * depending on this repo's real (386-entry, growing every release) file for
 * anything beyond a light sanity check. Degrades to a plain-text notice
 * (never a fatal, never a JSON-RPC error) when the file is missing or
 * unreadable.
 *
 * @param int         $limit
 * @param string|null $path
 * @return string
 */
function sn_mcp_changelog_latest_text( $limit = 5, $path = null ) {
	$path = null !== $path ? $path : sn_mcp_changelog_path();
	if ( ! is_readable( $path ) ) {
		return 'Unavailable: CHANGELOG.md not readable.';
	}

	$raw   = (string) file_get_contents( $path );
	// Split just before every "## [" heading; the file's leading title (e.g.
	// "# Changelog") becomes its own (discarded) first chunk.
	$parts = preg_split( '/(?=^## \[)/m', $raw );
	$parts = is_array( $parts ) ? $parts : array();

	$entries = array_values( array_filter(
		$parts,
		static function ( $chunk ) {
			return 0 === strpos( ltrim( $chunk ), '## [' );
		}
	) );
	$entries = array_slice( $entries, 0, max( 0, (int) $limit ) );

	return trim( implode( '', $entries ) );
}

/**
 * Passthrough a read ability's output into a resource content block, degrading
 * to an error-text content (never a JSON-RPC error, never a fatal) when the
 * ability is unregistered, permission-denied, or its execute() returns a
 * WP_Error — the exact same three failure modes tools/call already handles,
 * applied to the resources surface. When $extract_key is given, the resource
 * text is that single string field of the ability's result (llms.txt's
 * rendered manifest body) rather than the whole result JSON-encoded.
 *
 * @param string      $slug
 * @param string      $uri
 * @param string      $mime
 * @param string|null $extract_key
 * @return array{uri:string,mimeType:string,text:string}
 */
function sn_mcp_resource_ability_passthrough( $slug, $uri, $mime, $extract_key = null ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
	if ( ! $ability ) {
		return sn_mcp_resource_content( $uri, 'text/plain', 'Unavailable: ability not registered (' . $slug . ').' );
	}

	$perm = $ability->check_permissions( array() );
	if ( is_wp_error( $perm ) || false === $perm ) {
		return sn_mcp_resource_content( $uri, 'text/plain', 'Unavailable: permission denied for ' . $slug . '.' );
	}

	$out = $ability->execute( array() );
	if ( is_wp_error( $out ) ) {
		return sn_mcp_resource_content( $uri, 'text/plain', 'Unavailable: ' . $out->get_error_message() );
	}

	if ( null !== $extract_key ) {
		$text = ( is_array( $out ) && isset( $out[ $extract_key ] ) && is_string( $out[ $extract_key ] ) )
			? $out[ $extract_key ]
			: (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return sn_mcp_resource_content( $uri, $mime, $text );
	}

	return sn_mcp_resource_content( $uri, $mime, (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}
