<?php
/**
 * Signal & Noise Tools: `wp sn owner-token --agent=<agent> [--ttl=<seconds>]`
 * (SN Traffic Lanes, Phase 1).
 *
 * Prints one line, `SN-Owner-Agent: v1.<agent>.<exp>.<sig>`, signed with the
 * owner key (inc/owner-identity-sign.php). The TTL defaults to 43200
 * seconds and may not exceed it. Nothing is stored or logged: the token
 * exists only on stdout.
 *
 * Handling (review round 1, security M4): capture it, never echo it:
 *   export SN_OWNER_AGENT="$(wp sn owner-token --agent=claude-code | cut -d' ' -f2)"
 * Playwright: page.route( 'https://juanlentino.com/**', r => r.continue( {
 *   headers: { ...r.request().headers(), 'sn-owner-agent': process.env.SN_OWNER_AGENT } } ) );
 * never extraHTTPHeaders, which sends it to every host the page touches.
 * curl: -H "SN-Owner-Agent: $SN_OWNER_AGENT" and never -L, which would
 * carry the header to wherever a redirect points.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mint the header line, or say why not.
 *
 * @param string $agent Agent (allowlisted).
 * @param mixed  $ttl   Seconds, 1 to 43200.
 * @param int    $now   Unix time.
 * @param string $key   Owner key (64 hex).
 * @return array{ok:bool,line:string,error:string}
 */
function sn_owner_token_mint( $agent, $ttl, $now, $key ) {
	$fail = static function ( $error ) {
		return array( 'ok' => false, 'line' => '', 'error' => $error );
	};
	if ( ! in_array( (string) $agent, sn_owner_agents(), true ) ) {
		return $fail( 'Unknown agent. Use one of: ' . implode( ', ', sn_owner_agents() ) . '.' );
	}
	if ( ! preg_match( '/^[1-9][0-9]*$/', (string) $ttl ) || (int) $ttl > SN_OWNER_TOKEN_MAX_TTL ) {
		return $fail( 'The TTL must be a whole number of seconds from 1 to ' . SN_OWNER_TOKEN_MAX_TTL . '.' );
	}
	if ( ! sn_owner_key_ok( $key ) ) {
		return $fail( 'The owner key is not set. Generate it in Connections › Credentials.' );
	}
	return array( 'ok' => true, 'line' => 'SN-Owner-Agent: ' . sn_owner_agent_token( $key, (string) $agent, (int) $now + (int) $ttl ), 'error' => '' );
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'sn owner-token',
		static function ( $args, $assoc ) {
			unset( $args );
			$out = sn_owner_token_mint( (string) ( $assoc['agent'] ?? '' ), $assoc['ttl'] ?? SN_OWNER_TOKEN_MAX_TTL, time(), sn_credential( 'owner_key' ) );
			if ( ! $out['ok'] ) {
				WP_CLI::error( $out['error'] );
			}
			WP_CLI::line( $out['line'] );
		},
		array(
			'shortdesc' => 'Print an SN-Owner-Agent header for an owner tool. Stores and logs nothing. Capture it into an environment variable, never echo it; in Playwright use page.route limited to https://juanlentino.com/**, never extraHTTPHeaders; never curl -L with it.',
			'synopsis'  => array(
				array( 'type' => 'assoc', 'name' => 'agent', 'description' => 'One of: ' . implode( ', ', sn_owner_agents() ) ),
				array( 'type' => 'assoc', 'name' => 'ttl', 'optional' => true, 'default' => '43200', 'description' => 'Seconds until it expires, 1 to 43200.' ),
			),
		)
	);
}
