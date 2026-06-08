<?php
/**
 * Signal & Noise Tools — featured release (the one "press play" player on /music).
 *
 * A small, settings-driven companion to the discography grid: the owner pastes a
 * Spotify URL (track / album / playlist / …) in Monitoring → Music, this parses
 * it to {type,id}, stores it, and exposes it over the standalone-safe
 * `sn_music_featured` filter the theme's [sn_music_featured] shortcode reads to
 * render a single featured Spotify embed at the top of /music.
 *
 * Mirrors the discography filter contract: the plugin owns the data + the
 * add_filter; the theme reads `apply_filters('sn_music_featured', array())` and
 * degrades to nothing when the plugin is absent or the setting is empty.
 *
 * Added in v4.14.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MUSIC_FEATURED_OPT = 'sn_music_featured';

/**
 * Spotify embed types we accept (track/album/playlist/episode/show/artist).
 *
 * @return string
 */
function sn_music_featured_types() {
	return 'track|album|playlist|episode|show|artist';
}

/**
 * Parse a Spotify share URL or URI into { type, id }, or null when it isn't a
 * recognizable Spotify link. Handles the locale path (/intl-xx/), the ?si=…
 * tracking query, and the spotify:type:id URI form. Spotify ids are 22-char
 * base62; anything shorter/garbage is rejected.
 *
 * @param string $input Pasted URL / URI.
 * @return array{type:string,id:string}|null
 */
function sn_music_featured_parse( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return null;
	}
	$types = sn_music_featured_types();

	// URL: open.spotify.com[/intl-xx]/<type>/<22-char id>
	if ( preg_match( '#open\.spotify\.com/(?:intl-[a-z]{2,}/)?(' . $types . ')/([A-Za-z0-9]{22})#', $input, $m ) ) {
		return array(
			'type' => $m[1],
			'id'   => $m[2],
		);
	}
	// URI: spotify:<type>:<22-char id>
	if ( preg_match( '#^spotify:(' . $types . '):([A-Za-z0-9]{22})$#', $input, $m ) ) {
		return array(
			'type' => $m[1],
			'id'   => $m[2],
		);
	}
	return null;
}

/**
 * Read the featured release. Returns array() when unset or malformed; otherwise
 * { type, id, embed_url, open_url } (the plugin owns URL construction so the
 * theme just renders).
 *
 * @return array{type:string,id:string,embed_url:string,open_url:string}|array{}
 */
function sn_music_featured_get() {
	$v = get_option( SN_MUSIC_FEATURED_OPT, array() );
	if ( ! is_array( $v ) || empty( $v['type'] ) || empty( $v['id'] ) ) {
		return array();
	}
	$type = (string) $v['type'];
	$id   = (string) $v['id'];
	return array(
		'type'      => $type,
		'id'        => $id,
		'embed_url' => 'https://open.spotify.com/embed/' . $type . '/' . $id,
		'open_url'  => 'https://open.spotify.com/' . $type . '/' . $id,
	);
}

/**
 * The `sn_music_featured` filter callback — the cross-package contract the
 * theme's [sn_music_featured] shortcode reads. Returns the featured config (or
 * array() when unset); the supplied default is ignored.
 *
 * @param mixed $value Theme-supplied default (array()).
 * @return array The featured config.
 */
function sn_music_featured_filter( $value ) {
	return sn_music_featured_get();
}

// Registration — skipped under the CLI test harness (which calls the functions
// directly and does not stub add_filter).
if ( ! defined( 'SN_MUSIC_FEATURED_TEST' ) || ! SN_MUSIC_FEATURED_TEST ) {
	add_filter( 'sn_music_featured', 'sn_music_featured_filter' );
}
