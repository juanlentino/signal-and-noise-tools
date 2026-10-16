<?php
/**
 * Signal & Noise — the verified citation graph: the inbox and its advertisement.
 *
 * Receives Webmentions (W3C REC). The endpoint is PUBLIC by protocol necessity —
 * an inbox only reachable by authenticated callers receives nothing — so it is
 * built to accept a CLAIM and nothing more: the request can create an
 * `unverified` row and can do nothing else. It cannot set a tier, cannot cause a
 * synchronous outbound fetch, and cannot address a post that is not publicly
 * viewable. Adjudication happens later, on cron, in citations-verify.php.
 *
 * The advertisement is half the feature. An inbox nobody can discover receives
 * nothing, so the endpoint is published BOTH ways the spec allows: a Link header
 * and a <link> in the head of every publicly viewable singular page.
 *
 * @package SignalNoiseTools
 * @since 11.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CIT_REST_NS    = 'signal-noise/v1';
const SN_CIT_REST_ROUTE = '/webmention';

/** @return string The public endpoint URL. */
function sn_cit_endpoint_url() {
	return rest_url( SN_CIT_REST_NS . SN_CIT_REST_ROUTE );
}

/**
 * Resolve a target URL to a post this site will accept citations for. Gated on
 * is_post_publicly_viewable() so a draft or a private page cannot be probed for
 * existence through the citation inbox.
 *
 * @param string $target
 * @return int 0 when the target is not an acceptable local resource.
 */
function sn_cit_resolve_target( $target ) {
	$norm = sn_cit_normalize_url( $target );
	if ( '' === $norm ) {
		return 0;
	}
	if ( sn_cit_origin( $norm ) !== sn_cit_origin( home_url( '/' ) ) ) {
		return 0; // not our resource; the spec says reject.
	}
	$post_id = url_to_postid( $norm );
	if ( ! $post_id ) {
		return 0;
	}
	if ( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $post_id ) ) {
		return 0;
	}
	return (int) $post_id;
}

const SN_CIT_RATE_IP_MAX      = 10; // claims per IP per hour.
const SN_CIT_RATE_IP_WINDOW   = 3600;
const SN_CIT_RATE_HOST_MAX    = 20; // claims per source host per day.
const SN_CIT_RATE_HOST_WINDOW = 86400;

/**
 * Count one hit against a fixed-window bucket; false once the bucket is full.
 * The window starts at the bucket's first hit and is not extended by later
 * hits, so a sender that waits it out is let back in.
 *
 * @param string $bucket Bucket name (already hashed or short).
 * @param int    $max    Hits allowed per window.
 * @param int    $window Window in seconds.
 * @return bool True when this hit is within the limit.
 */
function sn_cit_rate_hit( $bucket, $max, $window ) {
	$key  = 'sn_cit_rl_' . md5( $bucket );
	$now  = time();
	$hits = get_transient( $key );
	if ( ! is_array( $hits ) || (int) ( $hits['until'] ?? 0 ) <= $now ) {
		$hits = array( 'n' => 0, 'until' => $now + $window );
	}
	if ( (int) $hits['n'] >= $max ) {
		return false;
	}
	++$hits['n'];
	set_transient( $key, $hits, max( 1, (int) $hits['until'] - $now ) );
	return true;
}

/**
 * The 429 a flooding sender sees.
 *
 * @param string $code Named refusal.
 * @param string $why  Human-readable reason.
 * @param int    $retry Seconds until a retry can succeed.
 * @return WP_Error
 */
function sn_cit_too_many( $code, $why, $retry ) {
	return new WP_Error( $code, $why, array( 'status' => 429, 'retry_after' => (int) $retry ) );
}

/**
 * Handle an inbound claim.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error 202 on accept; WP_Error 400 on a bad claim, 429 when rate limited or the inbox is full.
 */
function sn_cit_handle_webmention( $request ) {
	$source = (string) $request->get_param( 'source' );
	$target = (string) $request->get_param( 'target' );

	// v13.109.5: refusals speak the SAME vocabulary as the framework's own. A
	// param-level rejection (core, from the `required` args below) already
	// returned {code, message, data:{status}}, while every handler-level refusal
	// returned a bare {error: "..."} with no machine-readable code — two shapes
	// from one endpoint, both 400. A sender could not branch on the reason
	// without string-matching English.
	//
	// Codes are `sn_cit_*`, mirroring the module's function prefix, which is the
	// convention the sibling public endpoint already follows (`sn_prov_bad_sig`
	// in inc/provenance-webhook.php). The human-readable strings are unchanged,
	// verbatim: they were already good, and they now travel as `message`.
	//
	// Status is preserved at 400 on every path. W3C Webmention REC 3.2 asks only
	// for the status; the code is additive and costs a sender nothing.
	$reject = static function ( $code, $why ) {
		return new WP_Error( $code, $why, array( 'status' => 400 ) );
	};

	// Every request counts against its IP, refusals included, before any
	// parsing or DB work: the budget exists to bound what a flood costs.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! sn_cit_rate_hit( 'ip|' . $ip, SN_CIT_RATE_IP_MAX, SN_CIT_RATE_IP_WINDOW ) ) {
		return sn_cit_too_many( 'sn_cit_rate_limited_ip', 'too many claims from this address; try again later', SN_CIT_RATE_IP_WINDOW );
	}

	if ( '' === $source || '' === $target ) {
		return $reject( 'sn_cit_missing_params', 'source and target are both required' );
	}
	$ns = sn_cit_normalize_url( $source );
	if ( '' === $ns ) {
		return $reject( 'sn_cit_invalid_source', 'source must be an absolute http(s) URL' );
	}
	if ( $ns === sn_cit_normalize_url( $target ) ) {
		return $reject( 'sn_cit_source_equals_target', 'source and target must differ' );
	}
	if ( sn_cit_origin( $ns ) === sn_cit_origin( home_url( '/' ) ) ) {
		return $reject( 'sn_cit_source_not_offsite', 'source must be off-site' );
	}
	// Fail closed on an internal or unresolvable source BEFORE storing it, so the
	// verifier is never handed a row it must refuse.
	$host = wp_parse_url( $ns, PHP_URL_HOST );
	if ( $host && ! sn_cit_rate_hit( 'host|' . strtolower( (string) $host ), SN_CIT_RATE_HOST_MAX, SN_CIT_RATE_HOST_WINDOW ) ) {
		return sn_cit_too_many( 'sn_cit_rate_limited_host', 'too many claims from this source host today; try again tomorrow', SN_CIT_RATE_HOST_WINDOW );
	}
	if ( ! $host || sn_ssrf_host_blocked( $host ) ) {
		return $reject( 'sn_cit_source_unreachable', 'source host is not reachable from this site' );
	}
	$post_id = sn_cit_resolve_target( $target );
	if ( ! $post_id ) {
		return $reject( 'sn_cit_target_not_found', 'target is not a publicly viewable resource on this site' );
	}

	$result = sn_cit_record( $ns, $target, $post_id );
	if ( 'capped' === $result ) {
		return sn_cit_too_many( 'sn_cit_inbox_full', 'the citation inbox is full; try again later', SN_CIT_RATE_IP_WINDOW );
	}
	if ( 'invalid' === $result ) {
		return $reject( 'sn_cit_not_recorded', 'the claim could not be recorded' );
	}

	// 202: the claim is accepted for adjudication. It is NOT a statement that the
	// citation is real — that is exactly the conflation this whole module exists
	// to avoid, so the body says so in words.
	return new WP_REST_Response(
		array(
			'status'  => 'accepted',
			'tier'    => 'unverified',
			'message' => 'Claim recorded and queued for verification. Acceptance is not confirmation.',
		),
		202
	);
}

function sn_cit_register_route() {
	register_rest_route(
		SN_CIT_REST_NS,
		SN_CIT_REST_ROUTE,
		array(
			'methods'  => 'POST',
			'callback' => 'sn_cit_handle_webmention',
			// Public by protocol necessity; see the file docblock for why this is
			// safe — the handler can only ever create an `unverified` row.
			'permission_callback' => '__return_true',
			'args'                => array(
				'source' => array( 'required' => true ),
				'target' => array( 'required' => true ),
			),
		)
	);
}

/** Should this request advertise an inbox? Only where a citation could land. */
function sn_cit_should_advertise() {
	if ( ! is_singular() ) {
		return false;
	}
	$id = get_queried_object_id();
	return $id && ( ! function_exists( 'is_post_publicly_viewable' ) || is_post_publicly_viewable( $id ) );
}

/** The <link> half of discovery. */
function sn_cit_advertise_head() {
	if ( ! sn_cit_should_advertise() ) {
		return;
	}
	echo '<link rel="webmention" href="' . esc_url( sn_cit_endpoint_url() ) . '" />' . "\n";
}

/** The Link-header half of discovery. */
function sn_cit_advertise_header() {
	if ( headers_sent() || ! sn_cit_should_advertise() ) {
		return;
	}
	header( 'Link: <' . esc_url_raw( sn_cit_endpoint_url() ) . '>; rel="webmention"', false );
}

if ( ! defined( 'SN_CIT_TEST' ) || ! SN_CIT_TEST ) {
	add_action( 'rest_api_init', 'sn_cit_register_route' );
	add_action( 'wp_head', 'sn_cit_advertise_head' );
	add_action( 'template_redirect', 'sn_cit_advertise_header' );
}
