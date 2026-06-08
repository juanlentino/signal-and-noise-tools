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
		'type'        => 'album',
		'image'       => '',
		'spotify_id'  => '',
		'spotify_url' => '',
		'muso_url'    => '',
		'isrc'        => '',
		'upc'         => '',
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
	$e['title']  = trim( (string) $e['title'] );
	$e['artist'] = trim( (string) $e['artist'] );
	$e['year']   = (int) $e['year'];
	$e['roles']  = array_values( array_filter( array_map( 'trim', (array) $e['roles'] ) ) );
	foreach ( array( 'image', 'spotify_url', 'muso_url' ) as $u ) {
		$e[ $u ] = esc_url_raw( (string) $e[ $u ] );
	}
	foreach ( array( 'id', 'spotify_id', 'isrc', 'upc', 'type' ) as $k ) {
		$e[ $k ] = sanitize_text_field( (string) $e[ $k ] );
	}
	if ( '' === $e['id'] ) {
		$e['id'] = '' !== $e['isrc'] ? $e['isrc'] : ( '' !== $e['upc'] ? $e['upc'] : sanitize_title( $e['title'] . '-' . $e['artist'] ) );
	}
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
