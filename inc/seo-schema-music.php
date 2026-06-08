<?php
/**
 * Signal & Noise Tools — Music discography JSON-LD (schema.org).
 *
 * Emits one @graph of per-release MusicAlbum / MusicRecording nodes on the
 * /music page, built entirely from the cached discography store (no request-
 * time API calls). Juan is the schema.org `producer` — a reference to the SAME
 * canonical Person @id that inc/seo-schema.php emits (home_url('/') .
 * '#/schema/Person'), NOT a MusicGroup. `byArtist` is the primary artist (a
 * different entity). `sameAs` carries the Spotify + Muso.AI deep links.
 *
 * Companion to inc/seo-schema.php (the site-wide @graph). This module is /music-
 * scoped and reads ONLY the store, so it stays empty (emits nothing) until the
 * cron sync populates the store — degrades gracefully with no fatal.
 *
 * Honest SEO note: this strengthens Juan's entity + catalog comprehension in
 * the Knowledge Graph; it is NOT a guaranteed visual rich result (Google has no
 * dedicated producer rich card).
 *
 * Added in v4.13.0 (Music Identity).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The canonical Person @id. MUST stay identical to inc/seo-schema.php's
 * sn_schema_person() so the `producer` reference resolves to the SAME node the
 * site-wide Person schema emits (do not invent a new id).
 *
 * @return string Person node @id.
 */
function sn_music_person_id() {
	return home_url( '/' ) . '#/schema/Person';
}

/**
 * Map a store `type` to its schema.org @type. Single tracks become
 * MusicRecording; everything else (album/single/'') is a MusicAlbum.
 *
 * @param string $type Store entry type.
 * @return string schema.org @type.
 */
function sn_music_schema_type( $type ) {
	return ( 'track' === $type ) ? 'MusicRecording' : 'MusicAlbum';
}

/**
 * Build one MusicAlbum / MusicRecording node from a normalized store entry.
 *
 * @param array<string,mixed> $entry Normalized discography entry.
 * @return array<string,mixed> JSON-LD node.
 */
function sn_music_schema_node( $entry ) {
	$node = array(
		'@type'    => sn_music_schema_type( (string) ( $entry['type'] ?? '' ) ),
		'name'     => (string) ( $entry['title'] ?? '' ),
		'byArtist' => array(
			'@type' => 'MusicGroup',
			'name'  => (string) ( $entry['artist'] ?? '' ),
		),
		// Juan as producer — a reference to the canonical Person node, not a
		// new entity. Roles beyond producer (mixer/engineer) surface in the
		// visible display; schema.org has no dedicated property for them.
		'producer' => array(
			'@id' => sn_music_person_id(),
		),
	);

	$year = (int) ( $entry['year'] ?? 0 );
	if ( $year > 0 ) {
		$node['datePublished'] = (string) $year;
	}

	$image = (string) ( $entry['image'] ?? '' );
	if ( '' !== $image ) {
		$node['image'] = $image;
	}

	$same_as = array_values(
		array_filter(
			array(
				(string) ( $entry['spotify_url'] ?? '' ),
				(string) ( $entry['muso_url'] ?? '' ),
			)
		)
	);
	if ( ! empty( $same_as ) ) {
		$node['sameAs'] = $same_as;
	}

	return $node;
}

/**
 * Build + render the per-release JSON-LD <script> for the /music discography.
 * Returns '' when the store is empty (nothing to emit).
 *
 * @return string The escaped ld+json script tag, or '' if the store is empty.
 */
function sn_music_schema_jsonld() {
	$store   = sn_discography_get();
	$entries = isset( $store['entries'] ) && is_array( $store['entries'] ) ? $store['entries'] : array();
	if ( empty( $entries ) ) {
		return '';
	}

	$graph = array();
	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) ) {
			$graph[] = sn_music_schema_node( $entry );
		}
	}
	if ( empty( $graph ) ) {
		return '';
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	return '<script type="application/ld+json">'
		. wp_json_encode( $payload, JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}

/**
 * Emit the music JSON-LD in <head>, only on the /music page. Mirrors how
 * inc/seo-schema.php gates its @graph per-route (an is_*() check at the top of
 * the wp_head callback). The wp_json_encode'd payload is the only output.
 */
add_action(
	'wp_head',
	function () {
		if ( ! is_page( 'music' ) ) {
			return;
		}
		// sn_music_schema_jsonld() returns a pre-encoded, structurally-safe
		// ld+json script: wp_json_encode (WITHOUT JSON_UNESCAPED_SLASHES) escapes
		// "/" to "\/" so a literal "</script>" in any field cannot break out of the
		// script element, and title/artist are tag-sanitized on write
		// (sn_discography_normalize_entry). Defense in depth.
		echo sn_music_schema_jsonld(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	5
);
