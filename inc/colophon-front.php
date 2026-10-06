<?php
/**
 * Signal & Noise — the colophon's front end: its stylesheet and the one real
 * record shown in the Records row (2026-10-06 spec-sheet design).
 *
 * The record is the newest published note whose latest version is signed and
 * confirmed in a Bitcoin block, read from the note's own provenance chain
 * (inc/provenance-core.php). No note qualifies: no record, the row stays text.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the colophon stylesheet.
 */
function sn_colophon_enqueue() {
	wp_enqueue_style(
		'sn-colophon-front',
		plugins_url( 'assets/colophon-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * In the head on a page carrying the shortcode, so the page track applies
 * from the first paint (a render-time enqueue lands in the footer).
 */
function sn_colophon_enqueue_early() {
	$post = get_post();
	if ( is_singular() && $post && has_shortcode( (string) $post->post_content, 'sn_colophon' ) ) {
		sn_colophon_enqueue();
	}
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_enqueue_scripts', 'sn_colophon_enqueue_early' );
}

/**
 * The newest qualifying commit in a set of chains: signed, version 1 or
 * later, confirmed with a block. PURE, so the choice is testable.
 *
 * @param array<int,array> $chains post_id => chain, newest post first.
 * @return array{post_id:int,commit:array}|null
 */
function sn_colophon_pick_record( array $chains ) {
	foreach ( $chains as $post_id => $chain ) {
		$head = is_array( $chain ) && $chain ? end( $chain ) : array();
		if ( (int) ( $head['version'] ?? 0 ) >= 1
			&& 'confirmed' === (string) ( $head['status'] ?? '' )
			&& (int) ( $head['bitcoin_block'] ?? 0 ) > 0
			&& '' !== (string) ( $head['signature'] ?? '' )
			&& '' !== (string) ( $head['content_hash'] ?? '' ) ) {
			return array( 'post_id' => (int) $post_id, 'commit' => $head );
		}
	}
	return null;
}

/**
 * The record figure for the Records row, or '' when no note qualifies.
 *
 * @return string
 */
function sn_colophon_record_html() {
	if ( ! function_exists( 'sn_prov_get_chain' ) || ! function_exists( 'get_posts' ) ) {
		return '';
	}
	$ids    = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 20, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true ) );
	$chains = array();
	foreach ( (array) $ids as $id ) {
		$chains[ (int) $id ] = sn_prov_get_chain( (int) $id );
	}
	$pick = sn_colophon_pick_record( $chains );
	if ( null === $pick ) {
		return '';
	}
	$c    = $pick['commit'];
	$hash = (string) $c['content_hash'];
	$date = substr( (string) ( $c['committed_at'] ?? '' ), 0, 10 );
	$tile = static function ( $label, $value ) {
		return '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
	};
	$title = '<a href="' . esc_url( (string) get_permalink( $pick['post_id'] ) ) . '">' . esc_html( html_entity_decode( (string) get_the_title( $pick['post_id'] ), ENT_QUOTES, 'UTF-8' ) ) . '</a>';
	return '<figure class="sn-colophon-record">'
		/* translators: %s: a note's title, linked. */
		. '<figcaption>' . sprintf( esc_html__( 'One real record: %s', 'signal-and-noise-tools' ), $title ) . '</figcaption>'
		. '<dl>'
		. $tile( __( 'Version', 'signal-and-noise-tools' ), '<span class="sn-colophon-big">v' . (int) $c['version'] . '</span>' . esc_html( $date ) )
		. $tile( __( 'Fingerprint', 'signal-and-noise-tools' ), esc_html( substr( $hash, 0, 8 ) . '…' . substr( $hash, -7 ) ) )
		. $tile( __( 'Signature', 'signal-and-noise-tools' ), esc_html__( 'Ed25519, signed', 'signal-and-noise-tools' ) )
		. $tile( __( 'Bitcoin block', 'signal-and-noise-tools' ), '<span class="sn-colophon-big">' . (int) $c['bitcoin_block'] . '</span>' )
		. '</dl>'
		. '<a class="sn-colophon-verify" href="' . esc_url( home_url( '/verify' ) ) . '">' . esc_html__( 'Verify a Note', 'signal-and-noise-tools' ) . '</a>'
		. '</figure>';
}
