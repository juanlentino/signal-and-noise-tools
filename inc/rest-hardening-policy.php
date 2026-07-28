<?php
/**
 * Signal & Noise — REST hardening policy: the decision layer.
 *
 * Pure route/namespace matching, deliberately free of WordPress hooks so the
 * standalone harness can drive the real functions rather than stub them. The
 * wiring that consumes these decisions lives in inc/rest-hardening.php.
 *
 * @see docs/REST-HARDENING.md Rationale, hook order, verification matrix.
 *
 * @package SignalNoise
 * @since 9.83.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source of truth for every REST hardening decision.
 *
 * - remove:    route prefixes dropped for anonymous callers.
 * - strip:     post types whose rendered fields are emptied for anonymous.
 * - protected: namespaces that survive even if a filter adds them to `remove`.
 *              sn-prov/v1 backs the public cryptographic verifier;
 *              signal-noise/v1 backs the MCP tooling.
 * - headers:   name => value pairs added to every REST response.
 *
 * @return array{remove:string[],strip:string[],protected:string[],headers:array<string,string>}
 */
function snt_rest_hardening_policy() {
	return apply_filters(
		'snt_rest_hardening_policy',
		array(
			'remove'    => array(
				'/wp/v2/users',
				'/wp/v2/users/(?P<id>[\d]+)',
				'/wp/v2/comments',
				'/batch/v1',
			),
			'strip'     => array( 'post', 'page' ),
			'protected' => array( 'sn-prov/v1', 'signal-noise/v1' ),
			'headers'   => array(
				'TDM-Reservation' => '1',
				'TDM-Policy'      => 'https://juanlentino.com/tdm-policy/',
			),
		)
	);
}

/**
 * Is $route inside a namespace the policy refuses to remove?
 *
 * @param string   $route      Route key, e.g. '/sn-prov/v1/status'.
 * @param string[] $namespaces Protected namespaces.
 * @return bool
 */
function snt_rest_hardening_is_protected( $route, $namespaces ) {
	foreach ( (array) $namespaces as $ns ) {
		$prefix = '/' . trim( (string) $ns, '/' );
		if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Remove $route for anonymous callers? Prefix-match rather than equality, so
 * listing '/wp/v2/users' also takes '/wp/v2/users/me' and the
 * application-password subroutes. The protected veto is checked first and wins.
 *
 * @param string $route  Route key from the rest_endpoints map.
 * @param array  $policy Output of snt_rest_hardening_policy().
 * @return bool
 */
function snt_rest_hardening_should_remove( $route, $policy ) {
	$protected = isset( $policy['protected'] ) ? $policy['protected'] : array();
	if ( snt_rest_hardening_is_protected( $route, $protected ) ) {
		return false;
	}
	$remove = isset( $policy['remove'] ) ? $policy['remove'] : array();
	foreach ( (array) $remove as $pattern ) {
		$pattern = rtrim( (string) $pattern, '/' );
		if ( '' === $pattern ) {
			continue;
		}
		if ( $route === $pattern || 0 === strpos( $route, $pattern . '/' ) ) {
			return true;
		}
	}
	return false;
}
