<?php
/**
 * Signal & Noise Tools — /.well-known/agent-card.json (A2A Agent Card).
 *
 * WHY THIS CARD DOES NOT CLAIM A2A JSON-RPC. An A2A Agent Card advertises a
 * service endpoint, and the A2A protocol's own bindings are JSON-RPC, gRPC and
 * HTTP+JSON. **This site does not speak any of them.** It speaks MCP. Pointing
 * `url` at the MCP endpoint while implying an A2A binding would advertise an
 * interface that does not answer — the exact failure inc/agent-discovery.php
 * exists to prevent ("advertising a 404 from a discovery document is the
 * failure this whole module exists to fix").
 *
 * So `preferredTransport` is declared as MCP, which the specification permits:
 * §12 is "Custom Binding Guidelines", so the transport vocabulary is
 * extensible rather than closed. An A2A client reading this card learns what
 * this agent actually speaks and can decline. That is a discovery document.
 * The alternative — a conformant-looking card over an endpoint that rejects
 * `message/send` — is a trap, and would be a strange thing to publish from a
 * site whose subject is provenance.
 *
 * If the site ever implements an A2A binding, `url` and `preferredTransport`
 * move together and this comment comes out.
 *
 * WHY SKILLS ARE DERIVED. `skills` is built from the live abilities registry
 * intersected with the READ door's allowlist, so the card cannot advertise a
 * tool the door will not run, and cannot go stale when the allowlist changes.
 * Nothing here is hand-typed. The rw door never appears.
 *
 * @package Signal_And_Noise_Tools
 * @since   12.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_AGENT_A2A_PATH' ) ) {
	define( 'SN_AGENT_A2A_PATH', '/.well-known/agent-card.json' );
}

if ( ! defined( 'SN_AGENT_A2A_TYPE' ) ) {
	define( 'SN_AGENT_A2A_TYPE', 'application/json; charset=utf-8' );
}

/**
 * Does this request address the A2A agent card?
 *
 * @param string $uri
 * @return bool
 */
function sn_agent_a2a_is_request( $uri ) {
	return sn_agent_normalize_path( $uri ) === SN_AGENT_A2A_PATH;
}

/**
 * The skills array, derived from the abilities registry ∩ the read allowlist.
 *
 * An ability the read door will not run is not a skill this agent has, so the
 * intersection is the whole point. Descriptions come from the ability's own
 * metadata rather than a parallel table that could drift away from it.
 *
 * @return array<int,array<string,string>>
 */
function sn_agent_a2a_skills() {
	if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'sn_mcp_is_allowed' ) ) {
		return array();
	}

	$skills = array();
	foreach ( wp_get_abilities() as $ability ) {
		$name = (string) $ability->get_name();
		if ( 0 !== strpos( $name, 'signal-noise/' ) ) {
			continue;
		}
		if ( ! sn_mcp_is_allowed( $name, SN_MCP_DOOR_READ ) ) {
			continue;
		}
		$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
		$label       = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
		if ( '' === $description ) {
			// A skill with no description is not publishable under the spec,
			// and inventing one here would be exactly the parallel table this
			// function exists to avoid. Skip it rather than pad the shape.
			continue;
		}
		$skills[] = array(
			'id'          => $name,
			'name'        => '' !== $label ? $label : $name,
			'description' => $description,
		);
	}

	usort(
		$skills,
		static function ( $a, $b ) {
			return strcmp( $a['id'], $b['id'] );
		}
	);

	return $skills;
}

/**
 * The A2A Agent Card document.
 *
 * `capabilities` declares the A2A streaming/push features as false because this
 * agent implements none of them. Saying so is the point: an A2A client that
 * reads `streaming: true` and opens a stream would hang.
 *
 * @return array<string,mixed>
 */
function sn_agent_a2a_card() {
	$home = function_exists( 'home_url' ) ? (string) home_url() : '';
	$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

	return array(
		'id'                 => $home,
		'name'               => '' !== $name ? $name : 'Signal & Noise',
		'description'        => 'Read-only agent surface over this site: analytics, content health, provenance and deploy status. Speaks MCP, not A2A JSON-RPC — see preferredTransport before dispatching.',
		'url'                => function_exists( 'sn_agent_mcp_endpoint_url' ) ? sn_agent_mcp_endpoint_url() : '',
		'version'            => defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '',
		'preferredTransport' => 'MCP',
		// supportedInterfaces, not additionalInterfaces. The A2A specification
		// page reachable on 2026-08-23 documented the latter; the conformance
		// scanner rejected a card without the former ("Missing or empty
		// required field \"supportedInterfaces\""), which is the live spec
		// talking. Evidence over a partial doc read.
		//
		// The transport stays MCP. The scanner's complaint was a MISSING FIELD,
		// never the value — and if it ever does reject the value, the answer is
		// still not to claim a JSON-RPC binding this site does not serve.
		'supportedInterfaces' => array(
			array(
				'url'       => function_exists( 'sn_agent_mcp_endpoint_url' ) ? sn_agent_mcp_endpoint_url() : '',
				'transport' => 'MCP',
			),
		),
		'capabilities'       => array(
			'streaming'              => false,
			'pushNotifications'      => false,
			'stateTransitionHistory' => false,
		),
		'defaultInputModes'  => array( 'application/json' ),
		'defaultOutputModes' => array( 'application/json' ),
		'skills'             => sn_agent_a2a_skills(),
		'provider'           => array(
			'organization' => '' !== $name ? $name : 'Signal & Noise',
			'url'          => $home,
		),
		'authentication'     => array(
			'required'    => true,
			'type'        => 'application-password',
			'description' => 'HTTP Basic with a WordPress application password. The authenticated user needs manage_options; anonymous requests receive 401.',
		),
	);
}

/** Serve the A2A card. */
function sn_agent_a2a_send() {
	sn_agent_send_document( sn_agent_a2a_card(), SN_AGENT_A2A_TYPE );
}

/**
 * Advertise the card in /.well-known/agents.json, alongside the other
 * standard-named documents. Same reasoning as
 * sn_agent_advertise_discovery_surfaces(): a public document named on a public
 * index gives away nothing /.well-known/ does not already.
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_agent_a2a_advertise_surface( $surfaces ) {
	$home       = function_exists( 'home_url' ) ? (string) home_url() : '';
	$surfaces[] = array(
		'type'        => 'a2a-agent-card',
		'url'         => $home . SN_AGENT_A2A_PATH,
		'format'      => 'application/json',
		'title'       => 'A2A Agent Card',
		'description' => 'Agent-to-agent discovery card. Declares an MCP transport, not an A2A JSON-RPC binding.',
	);
	return $surfaces;
}

/** Route the request. Priority 0, matching the sibling discovery documents. */
function sn_agent_a2a_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_agent_a2a_is_request( $req ) ) {
		sn_agent_a2a_send();
		exit;
	}
}

if ( ! defined( 'SN_AGENT_DISCOVERY_TEST' ) || ! SN_AGENT_DISCOVERY_TEST ) {
	add_action( 'template_redirect', 'sn_agent_a2a_maybe_serve', 0 );
	add_filter( 'sn_agents_surfaces', 'sn_agent_a2a_advertise_surface' );
}
