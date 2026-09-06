<?php
/**
 * Signal & Noise app — the Discography section.
 *
 * The Muso.AI / Spotify release cache (inc/discography-store.php): cover-art
 * tiles, roles, year; a dossier with the track credits and the outbound
 * links. An option store has no post type, which is why WP Explorer's
 * rebuilt seams (post types only) could not carry it and this window exists.
 *
 * Registered only while the store holds entries: an empty section would be
 * an honest-but-useless folder on every site that never configured the sync.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The store's entries, unordered and unbuilt.
 *
 * @return array<int,array<string,mixed>>
 */
function album_entries() {
	$store = function_exists( 'sn_discography_get' ) ? \sn_discography_get() : array();
	return isset( $store['entries'] ) && is_array( $store['entries'] ) ? array_values( array_filter( $store['entries'], 'is_array' ) ) : array();
}

/**
 * How many releases there are, for the root folder tile.
 *
 * The section had no `count`, so payload() built every release -- cover art,
 * tracks table, dossier and all -- on EVERY root paint, which on the phone is
 * the first screen. Counting the entries reads the same option and builds
 * nothing.
 *
 * @return int
 */
function albums_count() {
	return count( album_entries() );
}

/**
 * Every release, newest year first, each with a stable id and its dossier.
 *
 * @return array<int,array<string,mixed>>
 */
function albums_items() {
	$entries = album_entries();
	usort(
		$entries,
		static function ( $a, $b ) {
			return ( (int) ( $b['year'] ?? 0 ) <=> (int) ( $a['year'] ?? 0 ) ) ?: strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
		}
	);
	$items = array();
	foreach ( $entries as $e ) {
		$items[] = album_item( $e );
	}
	return $items;
}

/**
 * One release as the client sees it.
 *
 * @param array<string,mixed> $e Store entry.
 * @return array<string,mixed>
 */
function album_item( array $e ) {
	$id     = '' !== (string) ( $e['id'] ?? '' ) ? (string) $e['id'] : 'x' . substr( md5( (string) ( $e['title'] ?? '' ) . '|' . (string) ( $e['artist'] ?? '' ) ), 0, 12 );
	$title  = (string) ( $e['title'] ?? '' );
	$artist = (string) ( $e['artist'] ?? '' );
	$year   = (int) ( $e['year'] ?? 0 );
	$roles  = array_values( array_filter( array_map( 'strval', (array) ( $e['roles'] ?? array() ) ) ) );
	$facts  = array( array( __( 'Artist', 'signal-and-noise-tools' ), $artist ) );
	if ( $year > 0 ) {
		$facts[] = array( __( 'Year', 'signal-and-noise-tools' ), (string) $year );
	}
	if ( $roles ) {
		$facts[] = array( __( 'Roles', 'signal-and-noise-tools' ), implode( ', ', $roles ) );
	}
	$blocks = array();
	$tracks = array_values( array_filter( (array) ( $e['tracks'] ?? array() ), 'is_array' ) );
	if ( $tracks ) {
		$rows = array();
		foreach ( $tracks as $i => $t ) {
			$rows[] = array(
				'n'     => (string) ( $i + 1 ),
				'title' => (string) ( $t['title'] ?? '' ),
				'roles' => implode( ', ', array_map( 'strval', (array) ( $t['roles'] ?? array() ) ) ),
			);
		}
		$blocks[] = array(
			'heading' => __( 'Tracks', 'signal-and-noise-tools' ),
			'kind'    => 'table',
			'columns' => array(
				array( 'key' => 'n', 'label' => '#' ),
				array( 'key' => 'title', 'label' => __( 'Title', 'signal-and-noise-tools' ) ),
				array( 'key' => 'roles', 'label' => __( 'Roles', 'signal-and-noise-tools' ) ),
			),
			'rows'    => $rows,
		);
	}
	$actions = array();
	foreach ( array( 'spotify_url' => __( 'Open in Spotify', 'signal-and-noise-tools' ), 'muso_url' => __( 'Credits on Muso.AI', 'signal-and-noise-tools' ) ) as $key => $label ) {
		if ( ! empty( $e[ $key ] ) ) {
			$actions[] = array( 'label' => $label, 'url' => (string) $e[ $key ] );
		}
	}
	return array(
		'id'          => $id,
		'title'       => $title,
		'subtitle'    => trim( $artist . ( $year > 0 ? ' · ' . $year : '' ), ' ·' ),
		'thumbnail'   => (string) ( $e['image'] ?? '' ),
		'icon'        => 'dashicons-album',
		'status'      => 'publish',
		'statusLabel' => '',
		'date'        => $year > 0 ? $year . '-01-01' : '',
		'dateLabel'   => $year > 0 ? (string) $year : '',
		'badge'       => $year > 0 ? array( 'text' => (string) $year, 'tone' => 'neutral', 'title' => '' ) : null,
		'columns'     => array( 'artist' => $artist, 'year' => $year > 0 ? (string) $year : '', 'roles' => implode( ', ', $roles ) ),
		'detail'      => array(
			'hero'    => (string) ( $e['image'] ?? '' ),
			'facts'   => $facts,
			'blocks'  => $blocks,
			'actions' => $actions,
		),
	);
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		// The registration gate counts; it does not build. This ran
		// albums_items() -- every release, every dossier -- on every single
		// resolution of the registry, which is once per dispatch plus once per
		// section lookup, to answer whether the list was empty.
		if ( 0 === albums_count() ) {
			return $sections;
		}
		$sections[] = array(
			'id'         => 'discography',
			'label'      => __( 'Discography', 'signal-and-noise-tools' ),
			'icon'       => 'dashicons-album',
			'kind'       => 'album',
			'capability' => 'manage_options',
			'position'   => 20,
			'columns'    => array(
				array( 'key' => 'artist', 'label' => __( 'Artist', 'signal-and-noise-tools' ) ),
				array( 'key' => 'year', 'label' => __( 'Year', 'signal-and-noise-tools' ) ),
				array( 'key' => 'roles', 'label' => __( 'Roles', 'signal-and-noise-tools' ) ),
			),
			'count'      => __NAMESPACE__ . '\albums_count',
			'items'      => __NAMESPACE__ . '\albums_items',
		);
		return $sections;
	}
);
