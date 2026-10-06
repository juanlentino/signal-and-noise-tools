<?php
/**
 * Signal & Noise Tools: the sn_provenance REST field on posts, the note's
 * provenance chain summary (public: a fact about the Note), which the
 * OpenStation Posts window reads as its Provenance column
 * (SNT_OS_POSTS_FIELDS, inc/openstation-preferences.php).
 *
 * The sn_edge field (the post's last edge-cache probe, the Edge column) was
 * removed the same day: since #1850 no per-post probe runs, so it could only
 * report verdicts from before 2026-10-03.
 *
 * Moved verbatim on 2026-10-06 from inc/desktop-mode-explorer.php, the
 * v12.4.0 WP Explorer integration (#751, contributed by Daniel López
 * Sánchez). OpenStation 1.1.6 retired the Explorer hooks that module rode,
 * so its folder, its script and its /desktop/discography route had done
 * nothing since; its REST fields were the only part still read.
 *
 * @package SignalNoiseTools
 * @since 12.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST field callback: summarize a Note's provenance chain for the Explorer.
 *
 * null — never a fabricated empty struct — when the post is not a Note, the
 * provenance subsystem is absent, or the chain has no commits: "unsigned" and
 * "signed zero times" are different facts and the bundle renders nothing for
 * null rather than an empty ledger.
 *
 * Reads the UID meta RAW instead of via sn_prov_note_uid(), which MINTS AND
 * PERSISTS a UUID on first read — a REST GET must not write post meta.
 *
 * The commit list is capped at the newest 20 (order preserved, newest last)
 * to bound the payload on long-lived Notes; `versions` still reports the
 * full chain's head version, so the cap is visible, not silent.
 *
 * @since 12.4.0
 * @param array<string,mixed> $post_arr Prepared post row (needs only `id`).
 * @return array<string,mixed>|null
 */
function snt_post_provenance_field( $post_arr ) {
	$post_id = (int) ( $post_arr['id'] ?? 0 );
	if ( $post_id <= 0
		|| ! function_exists( 'sn_prov_get_chain' )
		|| ! function_exists( 'sn_prov_is_note' )
		|| ! sn_prov_is_note( $post_id ) ) {
		return null;
	}
	$chain = sn_prov_get_chain( $post_id );
	if ( ! $chain ) {
		return null;
	}

	$commits  = array();
	$anchored = 0;
	$version  = 0;
	foreach ( $chain as $commit ) {
		if ( ! is_array( $commit ) ) {
			continue;
		}
		$status = (string) ( $commit['status'] ?? '' );
		if ( 'confirmed' === $status ) {
			++$anchored;
		}
		$version   = max( $version, (int) ( $commit['version'] ?? 0 ) );
		$commits[] = array(
			'version'      => (int) ( $commit['version'] ?? 0 ),
			'status'       => $status,
			'committed_at' => (string) ( $commit['committed_at'] ?? '' ),
			'content_hash' => (string) ( $commit['content_hash'] ?? '' ),
		);
	}
	if ( ! $commits ) {
		return null;
	}
	$latest = $commits[ count( $commits ) - 1 ];

	$uid = get_post_meta( $post_id, defined( 'SN_PROV_UID_META' ) ? SN_PROV_UID_META : '_sn_prov_uid', true );

	return array(
		'uid'      => is_string( $uid ) && '' !== $uid ? $uid : null,
		'versions' => $version,
		'status'   => $latest['status'],
		'anchored' => $anchored,
		'commits'  => array_slice( $commits, -20 ),
	);
}

add_action( 'rest_api_init', function () {
	// The provenance field registers regardless of shell presence: it is a
	// statement about Notes, not about the Explorer, and other REST readers
	// (the theme, the verifier) may use it. Guarded inside the callback.
	register_rest_field( 'post', 'sn_provenance', array(
		'get_callback' => 'snt_post_provenance_field',
		'schema'       => array(
			'description' => __( 'Provenance chain summary for a Note: head version, anchor status, and the newest commits.', 'signal-and-noise-tools' ),
			'type'        => array( 'object', 'null' ),
			'readonly'    => true,
			'context'     => array( 'view', 'edit', 'embed' ),
		),
	) );
} );
