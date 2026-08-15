<?php
/**
 * Signal & Noise Tools — the scheduled half of per-post purge verification.
 *
 * The pure comparison lives in cloudflare-purge-verify.php; this file is the
 * thin wrapper that fetches, decides, and escalates. Split so the decision is
 * unit-testable without HTTP — the same shape the ledger-CI check uses.
 *
 * Escalation is bounded ON PURPOSE: one zone purge, once, per post save. A
 * zone purge is expensive (it discards every cached object site-wide), so a
 * retry loop that kept firing them would trade a stale page for a permanently
 * cold cache. One escalation clears the object; if it is STILL stale after
 * that, the fault is not propagation and a human needs to see it — which is
 * exactly what the recorded outcome and the ledger's own daily run are for.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch one URL and return its body, or null when it cannot be read.
 *
 * Null rather than '' so snt_cf_probe_is_stale() sees "unknown" and declines
 * to escalate, instead of reading an outage as a difference.
 *
 * @param string $url Absolute URL.
 * @return string|null
 */
function snt_cf_probe_fetch( $url ) {
	$resp = wp_remote_get( $url, array(
		'timeout'     => 10,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'User-Agent'    => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' purge-probe',
			// Never let the probe's own request be answered from a proxy cache
			// between us and the edge; we are measuring the edge itself.
			'Cache-Control' => 'no-cache',
		),
	) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}
	$body = wp_remote_retrieve_body( $resp );
	return is_string( $body ) && '' !== $body ? $body : null;
}

/**
 * The scheduled probe: did the per-post purge actually clear the edge?
 *
 * @param int $post_id Post whose permalink was purged.
 * @return void
 */
function snt_cf_verify_post_purge( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! sn_cf_is_configured() ) {
		return;
	}
	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return;
	}

	// The cache-buster misses the edge AND the origin page cache, so this reads
	// what WordPress renders right now — the same trick verify-pages.mjs uses.
	$fresh_url = add_query_arg( 'sn-cache-probe', '1', $permalink );
	$stale     = snt_cf_probe_is_stale(
		snt_cf_probe_fetch( $permalink ),
		snt_cf_probe_fetch( $fresh_url )
	);

	if ( true !== $stale ) {
		// Fresh, or unreadable. Record only the definite answer; an outage is a
		// gap in evidence, not a verdict, and logging it as one would train the
		// reader to discount the log.
		if ( false === $stale ) {
			snt_cf_probe_record( array(
				'time'    => time(),
				'post_id' => $post_id,
				'url'     => $permalink,
				'result'  => 'fresh',
			) );
		}
		return;
	}

	// Still stale after a per-URL purge and a full propagation window. This is
	// the 2026-08-15 condition exactly. Escalate once.
	$escalated = function_exists( 'sn_cf_purge_everything' ) ? (bool) sn_cf_purge_everything() : false;
	snt_cf_probe_record( array(
		'time'      => time(),
		'post_id'   => $post_id,
		'url'       => $permalink,
		'result'    => 'stale',
		'escalated' => $escalated,
	) );

	/**
	 * Fires when a per-post purge demonstrably failed to clear the edge.
	 *
	 * @since 11.10.0
	 *
	 * @param int    $post_id   Post whose page stayed stale.
	 * @param string $permalink The stale URL.
	 * @param bool   $escalated Whether a zone purge was dispatched.
	 */
	do_action( 'sn_cf_purge_stayed_stale', $post_id, $permalink, $escalated );
}
add_action( SN_CF_PROBE_HOOK, 'snt_cf_verify_post_purge', 10, 1 );
