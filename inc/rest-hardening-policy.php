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

/*
 * TDM rights-reservation header values, as overridable constants (a site can
 * redefine any of them in wp-config.php before load) that the policy filter can
 * still refine at runtime — "filterable constants", per the Phase-2 REST audit.
 * The Content-Signal grammar is the TDMRep / Content Signals convention:
 * search=yes (indexing allowed), ai-train=no (no model training),
 * ai-input=yes (retrieval-augmented answering allowed).
 *
 * v10.70.1 — WHY THIS STRING IS BYTE-IDENTICAL TO THE EDGE'S.
 *
 * The sn-rights-signals Worker set()s Content-Signal on every response,
 * including /wp-json, so in production the value below is OVERWRITTEN and
 * never observed. It diverged for that reason and nobody noticed: this
 * constant read "search=yes, ai-train=no, ai-input=yes" (spaced, three terms)
 * while the edge published "search=yes,ai-train=no,ai-input=yes,use=reference"
 * (unspaced, four). Every probe that could have caught it — including this
 * plugin's own rights-signals health check — reads the LIVE URL, and the live
 * URL answers with the Worker's value. The origin's value was untested by
 * construction.
 *
 * That matters exactly when the Worker is not in the path: a route change, a
 * disabled Worker, a direct-to-origin request. That is the moment this header
 * becomes the only statement of the position, and it is the worst possible
 * moment for it to state a different one.
 *
 * The fourth term, use=reference, is a NON-NORMATIVE local extension — it is
 * not in the Cloudflare Content Signals vocabulary. It is carried here so the
 * origin and the edge cannot disagree; it is disclaimed where a reader meets
 * it, in robots.txt and in section 3 / the appendix of the TDM policy. Nothing
 * depends on it and a parser may ignore it.
 *
 * If the edge string changes, change this one in the same release.
 */
if ( ! defined( 'SN_TDM_RESERVATION' ) ) {
	define( 'SN_TDM_RESERVATION', '1' );
}
if ( ! defined( 'SN_TDM_POLICY_URL' ) ) {
	define( 'SN_TDM_POLICY_URL', 'https://juanlentino.com/tdm-policy/' );
}
if ( ! defined( 'SN_TDM_CONTENT_SIGNAL' ) ) {
	define( 'SN_TDM_CONTENT_SIGNAL', 'search=yes,ai-train=no,ai-input=yes,use=reference' );
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
				'TDM-Reservation' => SN_TDM_RESERVATION,
				'TDM-Policy'      => SN_TDM_POLICY_URL,
				'Content-Signal'  => SN_TDM_CONTENT_SIGNAL,
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
