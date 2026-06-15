<?php
/**
 * Signal & Noise Tools — discography store (the normalized, source-agnostic
 * release cache the schema emitter + theme display + admin status all read).
 * Cron is the sole writer (see inc/discography-sync.php). Non-autoloaded.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DISCOGRAPHY_OPTION = 'sn_discography';

/**
 * Default store option value (empty store).
 *
 * @return array<string,mixed>
 */
function sn_discography_defaults() {
	return array(
		'entries'     => array(),
		'last_synced' => 0,
		'count'       => 0,
		'last_error'  => '',
	);
}

/**
 * Read the store, merged over defaults so callers always get the full shape.
 *
 * @return array<string,mixed>
 */
function sn_discography_get() {
	$stored = get_option( SN_DISCOGRAPHY_OPTION, array() );
	return array_merge( sn_discography_defaults(), is_array( $stored ) ? $stored : array() );
}

/**
 * Default values for a single discography entry.
 *
 * @return array<string,mixed>
 */
function sn_discography_entry_defaults() {
	return array(
		'id'          => '',
		'title'       => '',
		'artist'      => '',
		'roles'       => array(),
		'year'        => 0,
		'date'        => '',
		'type'        => 'album',
		'image'       => '',
		'spotify_id'  => '',
		'spotify_url' => '',
		'muso_url'    => '',
		'isrc'        => '',
		'upc'         => '',
		'tracks'      => array(), // B1: per-track liner notes (see sn_discography_normalize_track).
	);
}

/**
 * Normalize one per-track liner-notes record: sanitize the title + per-track
 * roles, escape the preview URL, scalar the Spotify id. Mirrors the entry-level
 * boundary sanitization so a future unescaped consumer can't inherit a payload.
 *
 * @param mixed $raw Raw track data.
 * @return array{title:string,roles:array<int,string>,preview_url:string,spotify_id:string}
 */
function sn_discography_normalize_track( $raw ) {
	$t = is_array( $raw ) ? $raw : array();
	return array(
		'title'       => sanitize_text_field( (string) ( $t['title'] ?? '' ) ),
		'roles'       => array_values( array_filter( array_map( static fn( $r ) => sanitize_text_field( (string) $r ), (array) ( $t['roles'] ?? array() ) ) ) ),
		'preview_url' => esc_url_raw( (string) ( $t['preview_url'] ?? '' ) ),
		'spotify_id'  => sanitize_text_field( (string) ( $t['spotify_id'] ?? '' ) ),
	);
}

/**
 * Normalize one raw entry: coerce types, fill missing keys, sanitize, and
 * derive a stable id (ISRC → UPC → slug(title-artist)) when none is given.
 *
 * @param array<string,mixed> $raw Raw entry data.
 * @return array<string,mixed> Normalized entry.
 */
function sn_discography_normalize_entry( $raw ) {
	$e           = array_merge( sn_discography_entry_defaults(), is_array( $raw ) ? $raw : array() );
	$e['title']  = sanitize_text_field( (string) $e['title'] );
	$e['artist'] = sanitize_text_field( (string) $e['artist'] );
	$e['year']   = (int) $e['year'];
	// v4.14.3: sanitize each role like title/artist (was trim-only). Muso credit
	// roles are external/untrusted; tag-strip at the boundary so a future
	// unescaped consumer can't inherit a stored payload. array_filter drops
	// roles that sanitize to ''.
	$e['roles']  = array_values( array_filter( array_map( static fn( $r ) => sanitize_text_field( (string) $r ), (array) $e['roles'] ) ) );
	foreach ( array( 'image', 'spotify_url', 'muso_url' ) as $u ) {
		$e[ $u ] = esc_url_raw( (string) $e[ $u ] );
	}
	foreach ( array( 'id', 'spotify_id', 'isrc', 'upc', 'type', 'date' ) as $k ) {
		$e[ $k ] = sanitize_text_field( (string) $e[ $k ] );
	}
	// Keep year + date consistent: derive the year from the full date when only
	// the date is supplied (the date is the authoritative, fuller value).
	if ( 0 === $e['year'] && '' !== $e['date'] ) {
		$e['year'] = (int) substr( $e['date'], 0, 4 );
	}
	if ( '' === $e['id'] ) {
		$e['id'] = '' !== $e['isrc'] ? $e['isrc'] : ( '' !== $e['upc'] ? $e['upc'] : sanitize_title( $e['title'] . '-' . $e['artist'] ) );
	}
	// B1: normalize + sanitize each liner-notes track; drop any that lose their title.
	$e['tracks'] = array_values(
		array_filter(
			array_map( 'sn_discography_normalize_track', (array) $e['tracks'] ),
			static function ( $t ) {
				return '' !== $t['title'];
			}
		)
	);
	return $e;
}

/**
 * Write the store: sort entries year-descending, recompute count, and record
 * the sync timestamp + last error. Non-autoloaded.
 *
 * @param array<int,array<string,mixed>> $entries    Normalized entries.
 * @param int                            $synced_ts  Unix timestamp of this sync.
 * @param string                         $last_error Error string ('' = ok).
 * @return void
 */
function sn_discography_set( array $entries, $synced_ts, $last_error ) {
	usort(
		$entries,
		function ( $a, $b ) {
			return (int) $b['year'] <=> (int) $a['year'];
		}
	);
	update_option(
		SN_DISCOGRAPHY_OPTION,
		array(
			'entries'     => array_values( $entries ),
			'last_synced' => (int) $synced_ts,
			'count'       => count( $entries ),
			'last_error'  => (string) $last_error,
		),
		false
	);
}
