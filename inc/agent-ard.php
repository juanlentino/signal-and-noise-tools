<?php
/**
 * Signal & Noise Tools — /.well-known/ai-catalog.json (ARD capability manifest).
 *
 * Agentic Resource Discovery (https://agenticresourcediscovery.org/). The third
 * standard-named document in the set inc/agent-discovery.php opened, and the
 * same argument applies: every entry below is a surface this site already
 * serves, restated at an address the ecosystem reads. Nothing here is new
 * capability.
 *
 * WHAT ARD ADDS OVER THE OTHER TWO, and why it is worth a third file rather
 * than a fourth entry in one of them: the API catalog answers "what APIs exist"
 * and the server card answers "how do I connect to the MCP server". ARD answers
 * "what is this site FOR" — each entry carries `representativeQueries`, short
 * natural-language examples a registry embeds so an agent can find this site
 * semantically rather than by already knowing its address. The queries are
 * therefore the load-bearing part of every entry, not decoration.
 *
 * The host identifier is the site's REAL did:web, which resolves at
 * /.well-known/did.json (inc/provenance-did.php). ARD only "suggests" that
 * format; here it is not a suggestion but the same identity the provenance
 * chain already signs against.
 *
 * @package SignalNoiseTools
 * @since 12.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_AGENT_ARD_PATH' ) ) {
	define( 'SN_AGENT_ARD_PATH', '/.well-known/ai-catalog.json' );
}

if ( ! defined( 'SN_AGENT_ARD_TYPE' ) ) {
	// ARD is plain application/json — unlike the API catalog, which RFC 9727
	// requires be typed as a linkset. Do not "harmonise" these two.
	define( 'SN_AGENT_ARD_TYPE', 'application/json; charset=utf-8' );
}

if ( ! defined( 'SN_AGENT_ARD_SPEC_VERSION' ) ) {
	define( 'SN_AGENT_ARD_SPEC_VERSION', '1.0' );
}

/** @param string $uri @return bool */
function sn_agent_ard_is_request( $uri ) {
	return ( SN_AGENT_ARD_PATH === sn_agent_normalize_path( $uri ) );
}

/**
 * Build one ARD entry. `url` and `data` are mutually exclusive in the spec
 * ("exactly one of"), and this helper only ever emits `url` — every resource
 * this site publishes has an address, so there is nothing to inline.
 *
 * @param string   $ns      Namespace segment of the URN.
 * @param string   $name    Name segment of the URN.
 * @param string   $label   Human-readable displayName.
 * @param string   $type    IANA media type.
 * @param string   $url     Absolute URL.
 * @param string[] $queries 2–5 representative queries.
 * @return array<string,mixed>
 */
function sn_agent_ard_entry( $ns, $name, $label, $type, $url, $queries ) {
	return array(
		'identifier'           => 'urn:air:' . sn_agent_ard_host() . ':' . $ns . ':' . $name,
		'displayName'          => $label,
		'type'                 => $type,
		'url'                  => $url,
		'representativeQueries' => array_values( $queries ),
	);
}

/** @return string The bare host, used in both the URN and the did:web. */
function sn_agent_ard_host() {
	$home = function_exists( 'home_url' ) ? (string) home_url() : '';
	$host = (string) wp_parse_url( $home, PHP_URL_HOST );
	return '' !== $host ? $host : 'juanlentino.com';
}

/**
 * The ARD manifest.
 *
 * Entries are the surfaces an off-site agent can actually DO something with.
 * Deliberately excluded: the sitemap and the OpenSearch description (address
 * discovery, not capability — the API catalog and agents.json already carry
 * them), and the rw MCP door, for the unattended-surface reason recorded in
 * inc/agent-discovery.php.
 *
 * @return array<string,mixed>
 */
function sn_agent_ard_manifest() {
	$home = function_exists( 'home_url' ) ? (string) home_url() : '';
	$host = sn_agent_ard_host();

	$entries = array(
		sn_agent_ard_entry(
			'server',
			'mcp',
			'Signal & Noise MCP server (read)',
			'application/mcp-server-card+json',
			$home . SN_AGENT_CARD_PATH,
			array(
				'what MCP tools does juanlentino.com expose',
				'connect to the Signal & Noise MCP server',
				'read site analytics and health over MCP',
			)
		),
		sn_agent_ard_entry(
			'catalog',
			'apis',
			'API catalog',
			'application/linkset+json',
			$home . SN_AGENT_CATALOG_PATH,
			array(
				'what APIs does juanlentino.com publish',
				'find the REST endpoints for this site',
			)
		),
		sn_agent_ard_entry(
			'corpus',
			'llms-txt',
			'llms.txt reading list',
			'text/markdown',
			$home . '/llms.txt',
			array(
				'what is juanlentino.com about',
				'which pages should an LLM read first on this site',
				'summarise the main arguments published here',
			)
		),
		sn_agent_ard_entry(
			'feed',
			'notes-json',
			'Notes JSON Feed',
			'application/feed+json',
			$home . '/feed/json/',
			array(
				'latest notes from juanlentino.com',
				'subscribe to new writing on music provenance',
			)
		),
		sn_agent_ard_entry(
			'provenance',
			'verify',
			'Note provenance verification',
			'text/html',
			$home . '/verify/',
			array(
				'verify the authorship of a note on juanlentino.com',
				'check the Bitcoin anchor for a published note',
				'is this article cryptographically signed',
			)
		),
		sn_agent_ard_entry(
			'rights',
			'tdm-policy',
			'Text-and-data-mining policy',
			'text/html',
			$home . '/tdm-policy/',
			array(
				'can I train a model on juanlentino.com',
				'what are the AI training terms for this site',
				'how do I license this content for AI use',
			)
		),
	);

	return array(
		'specVersion' => SN_AGENT_ARD_SPEC_VERSION,
		'host'        => array(
			'displayName' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Juan Lentino',
			// The site's real did:web — it resolves at /.well-known/did.json.
			'identifier'  => 'did:web:' . $host,
		),
		'entries'     => $entries,
	);
}

/** @return void */
function sn_agent_ard_send() {
	sn_agent_send_document( sn_agent_ard_manifest(), SN_AGENT_ARD_TYPE );
}

/**
 * template_redirect handler (priority 0). Separate from
 * sn_agent_discovery_maybe_serve() so this document can be removed without
 * touching the other two.
 *
 * @return void
 */
function sn_agent_ard_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_agent_ard_is_request( $req ) ) {
		sn_agent_ard_send();
		exit;
	}
}

/**
 * Advertise the ARD manifest in /.well-known/agents.json. Its own callback, in
 * its own file, so deleting this document deletes its advertisement with it.
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_agent_advertise_ard_surface( $surfaces ) {
	$home = function_exists( 'home_url' ) ? (string) home_url() : '';
	$surfaces[] = array(
		'type'        => 'ard',
		'url'         => $home . SN_AGENT_ARD_PATH,
		'format'      => 'application/json',
		'title'       => 'ARD capability manifest',
		'description' => "Agentic Resource Discovery: this site's capabilities with representative queries, for semantic discovery by registries.",
	);
	return $surfaces;
}

if ( ! defined( 'SN_AGENT_DISCOVERY_TEST' ) || ! SN_AGENT_DISCOVERY_TEST ) {
	add_action( 'template_redirect', 'sn_agent_ard_maybe_serve', 0 );
	add_filter( 'sn_agents_surfaces', 'sn_agent_advertise_ard_surface' );
}
