<?php
/**
 * Signal & Noise Tools: the two REST fields on posts that the OpenStation
 * Posts window reads (SNT_OS_POSTS_FIELDS, inc/openstation-preferences.php).
 *
 *   sn_provenance  the note's provenance chain summary (public: a fact about
 *                  the Note), shown as the Provenance column.
 *   sn_edge        the last edge-freshness probe verdict (admins only), shown
 *                  as the Edge column.
 *
 * Moved verbatim on 2026-10-06 from inc/desktop-mode-explorer.php, the
 * v12.4.0 WP Explorer integration (#751, contributed by Daniel López
 * Sánchez). OpenStation 1.1.6 retired the Explorer hooks that module rode,
 * so its folder, its script and its /desktop/discography route had done
 * nothing since; these two fields were the only part still read.
 *
 * @package SignalNoiseTools
 * @since 12.4.0 (sn_provenance), 14.4.0 (sn_edge)
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

/**
 * REST field callback: `sn_edge` — the last edge-freshness verdict for a post.
 *
 * The Posts window's Edge column (v14.4.0) reads this. It wraps
 * sn_note_dossier_last_probe(), the reader the note dossier already uses, so
 * the column and the dossier cannot disagree about the same row. The probe
 * log is a twenty-row SITE-WIDE buffer: a post with no row in it is
 * "unprobed" — null here, nothing painted — never "fresh".
 *
 * Not public, unlike `sn_provenance`: a stale-edge verdict is an operating
 * fact about the cache, not a fact about the Note. Anonymous readers get
 * null, which the column paints as absence.
 *
 * @since 14.4.0
 * @param array<string,mixed> $post_arr Prepared post row (needs only `id`).
 * @return array{state:string,verified_at:int,escalated:bool}|null
 */
function snt_post_edge_field( $post_arr ) {
	$post_id = (int) ( $post_arr['id'] ?? 0 );
	if ( $post_id <= 0
		|| ! function_exists( 'sn_note_dossier_last_probe' )
		|| ! function_exists( 'current_user_can' )
		|| ! current_user_can( 'manage_options' ) ) {
		return null;
	}
	$probe = sn_note_dossier_last_probe( $post_id );
	if ( null === $probe ) {
		return null;
	}
	return array(
		'state'       => (string) $probe['result'],
		'verified_at' => (int) $probe['time'],
		'escalated'   => (bool) $probe['escalated'],
	);
}

add_action( 'rest_api_init', function () {
	// The provenance field registers regardless of shell presence: it is a
	// statement about Notes, not about the Explorer, and other REST readers
	// (the theme, the verifier) may use it. Guarded inside the callback.
	register_rest_field( 'post', 'sn_edge', array(
		'get_callback' => 'snt_post_edge_field',
		'schema'       => array(
			'description' => __( 'Last edge-cache probe verdict for the post: fresh, stale, or absent when no probe is in the log.', 'signal-and-noise-tools' ),
			'type'        => array( 'object', 'null' ),
			'readonly'    => true,
			// `view` on purpose: the Posts window lists with no `context`
			// arg, so an edit-only field would never ride it. The callback,
			// not the context, is the gate.
			'context'     => array( 'view', 'edit' ),
		),
	) );

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
