<?php
/**
 * Signal & Noise Tools — featured release (the one "press play" player on /music).
 *
 * A small, settings-driven companion to the discography grid: the owner pastes a
 * Spotify URL (track / album / playlist / …) in Connections → Discography, this parses
 * it to {type,id}, stores it, and exposes it over the standalone-safe
 * `sn_music_featured` filter the theme's [sn_music_featured] shortcode reads to
 * render a single featured Spotify embed at the top of /music.
 *
 * Mirrors the discography filter contract: the plugin owns the data + the
 * add_filter; the theme reads `apply_filters('sn_music_featured', array())` and
 * degrades to nothing when the plugin is absent or the setting is empty.
 *
 * Added in v4.14.0. v6.16.0 (B4): when the owner hasn't set a featured release,
 * the filter auto-derives a zero-touch fallback — the newest playable release in
 * the discography store — so /music is never headerless. The manual setting stays
 * authoritative; the derived record is flagged `auto => true` for debuggability;
 * the fallback is opt-out via the `sn_music_featured_autoderive` filter.
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
 * Build a featured record from a {type,id}: the plugin owns URL construction so
 * the theme just renders. `auto` marks the provenance (true = derived fallback,
 * false = the owner's manual setting).
 *
 * @param string $type Spotify embed type (track/album/playlist/…).
 * @param string $id   22-char Spotify id.
 * @param bool   $auto Whether this came from the auto-derive fallback.
 * @return array{type:string,id:string,embed_url:string,open_url:string,auto:bool}
 */
function sn_music_featured_record( $type, $id, $auto = false ) {
	$type = (string) $type;
	$id   = (string) $id;
	return array(
		'type'      => $type,
		'id'        => $id,
		'embed_url' => 'https://open.spotify.com/embed/' . $type . '/' . $id,
		'open_url'  => 'https://open.spotify.com/' . $type . '/' . $id,
		'auto'      => (bool) $auto,
	);
}

/**
 * Read the owner's manually set featured release. Returns array() when unset or
 * malformed; otherwise a record flagged `auto => false`. This is manual-only by
 * design: the admin pre-fill field reads it, so the auto-derive fallback must NOT
 * leak in here (else a derived value would render as if the owner had set it).
 *
 * @return array{type:string,id:string,embed_url:string,open_url:string,auto:bool}|array{}
 */
function sn_music_featured_get() {
	$v = get_option( SN_MUSIC_FEATURED_OPT, array() );
	if ( ! is_array( $v ) || empty( $v['type'] ) || empty( $v['id'] ) ) {
		return array();
	}
	return sn_music_featured_record( (string) $v['type'], (string) $v['id'], false );
}

/**
 * Map one discography entry to a featured record, or array() when it has no
 * playable Spotify album. Prefers the bare `spotify_id` (a Spotify *album* id);
 * falls back to parsing a stored `spotify_url`. The embed type is always `album`
 * — discography releases resolve to Spotify albums, so the entry's own `type`
 * (the album_type: album/single/compilation) is not a valid embed path.
 *
 * @param mixed $entry One discography entry.
 * @return array Featured record (auto-flagged) or array().
 */
function sn_music_featured_from_entry( $entry ) {
	if ( ! is_array( $entry ) ) {
		return array();
	}
	$id = isset( $entry['spotify_id'] ) ? trim( (string) $entry['spotify_id'] ) : '';
	if ( preg_match( '/^[A-Za-z0-9]{22}$/', $id ) ) {
		return sn_music_featured_record( 'album', $id, true );
	}
	// Fallback: a stored share URL, parsed through the canonical parser.
	$parsed = sn_music_featured_parse( isset( $entry['spotify_url'] ) ? (string) $entry['spotify_url'] : '' );
	if ( $parsed ) {
		return sn_music_featured_record( $parsed['type'], $parsed['id'], true );
	}
	return array();
}

/**
 * Derive a fallback featured release from the discography store when the owner
 * hasn't set one. The store is year-descending, so the first entry with a
 * playable Spotify album is the newest release — a sensible zero-touch hero.
 *
 * Opt-out / override via the `sn_music_featured_autoderive` filter (default true).
 * Standalone-safe: store function absent / empty / no playable entry → array()
 * (no hero, no fatal).
 *
 * @return array The derived featured config (auto-flagged), or array().
 */
function sn_music_featured_derive() {
	if ( ! apply_filters( 'sn_music_featured_autoderive', true ) ) {
		return array();
	}
	if ( ! function_exists( 'sn_discography_get' ) ) {
		return array();
	}
	$store   = sn_discography_get();
	$entries = ( is_array( $store ) && isset( $store['entries'] ) && is_array( $store['entries'] ) ) ? $store['entries'] : array();
	foreach ( $entries as $entry ) {
		$rec = sn_music_featured_from_entry( $entry );
		if ( ! empty( $rec ) ) {
			return $rec; // first playable = newest (store is year-desc sorted).
		}
	}
	return array();
}

/**
 * The `sn_music_featured` filter callback — the cross-package contract the
 * theme's [sn_music_featured] shortcode reads. The owner's manual setting is
 * authoritative; when unset, falls back to the auto-derived newest release so
 * /music is never headerless. The supplied default is ignored.
 *
 * @param mixed $value Theme-supplied default (array()).
 * @return array The featured config (manual, then auto-derived, then array()).
 */
function sn_music_featured_filter( $value ) {
	$manual = sn_music_featured_get();
	if ( ! empty( $manual ) ) {
		return $manual;
	}
	return sn_music_featured_derive();
}

// Registration — skipped under the CLI test harness (which calls the functions
// directly and does not stub add_filter).
if ( ! defined( 'SN_MUSIC_FEATURED_TEST' ) || ! SN_MUSIC_FEATURED_TEST ) {
	add_filter( 'sn_music_featured', 'sn_music_featured_filter' );
}
