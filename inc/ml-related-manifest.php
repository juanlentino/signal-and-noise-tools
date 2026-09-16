<?php
/**
 * Signal & Noise Tools — the related-notes manifest, for the reader's agent.
 *
 * 15.5.0 (WebMCP bridge v2, arc one). The kernel's stored top matches for a
 * note, as a data-shaped `<script type="application/json" id="sn-related">`
 * block beside the verification manifest. The block on the page paints the
 * same rows for people; the bridge's `related-notes` tool reads this for
 * agents. One producer (`snt_ml_related_for_post()`), two painters.
 *
 * Three answers, kept distinct (the realtime-zero-vs-null rule): artifacts
 * never built (`built: false`), a note with nothing related (`[]`), and a note
 * with matches. A page that is not a note carries no manifest at all; the
 * bridge reads that absence as "not a note".
 *
 * @package SignalNoiseTools
 * @since 15.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RELATED_MANIFEST_LIMIT = 5;

/**
 * The manifest for one note. Pure over the kernel's answer and the posts.
 *
 * @param int $post_id
 * @return array{post_id:int,built:bool,related:array<int,array{id:int,title:string,url:string,score:float,shared_tags:array<int,string>}>}
 */
function sn_related_manifest( $post_id ) {
	$post_id = (int) $post_id;
	$rows    = function_exists( 'snt_ml_related_for_post' ) ? snt_ml_related_for_post( $post_id, SN_RELATED_MANIFEST_LIMIT ) : null;
	if ( null === $rows ) {
		return array( 'post_id' => $post_id, 'built' => false, 'related' => array() );
	}
	$mine = array_map( 'strval', (array) wp_get_post_tags( $post_id, array( 'fields' => 'slugs' ) ) );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$rid = (int) ( $row['post_id'] ?? 0 );
		if ( $rid <= 0 ) {
			continue;
		}
		$theirs = array_map( 'strval', (array) wp_get_post_tags( $rid, array( 'fields' => 'slugs' ) ) );
		$out[]  = array(
			'id'          => $rid,
			'title'       => html_entity_decode( (string) get_the_title( $rid ), ENT_QUOTES, 'UTF-8' ),
			'url'         => (string) get_permalink( $rid ),
			'score'       => round( (float) ( $row['score'] ?? 0 ), 4 ),
			'shared_tags' => array_values( array_intersect( $mine, $theirs ) ),
		);
	}
	return array( 'post_id' => $post_id, 'built' => true, 'related' => $out );
}

/**
 * Emit the manifest on singular notes (wp_head), beside the verification one.
 */
function sn_related_manifest_emit() {
	if ( ! function_exists( 'is_singular' ) || ! is_singular( 'post' ) ) {
		return;
	}
	$post_id = (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return;
	}
	// Slash-escaping stays on: '</script>' inside a title can never close the tag.
	printf( '<script type="application/json" id="sn-related">%s</script>' . "\n", wp_json_encode( sn_related_manifest( $post_id ) ) );
}
add_action( 'wp_head', 'sn_related_manifest_emit' );
