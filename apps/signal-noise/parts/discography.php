<?php
/**
 * Signal & Noise app — the Discography section.
 *
 * The Muso.AI / Spotify release cache (inc/discography-store.php): cover art,
 * roles, year; a dossier with the track credits and the outbound links. An
 * option store has no post type, which is why WP Explorer's rebuilt seams
 * (post types only) could not carry it and this window exists.
 *
 * Registered only while the store holds entries: an empty section would be
 * an honest-but-useless tab on every site that never configured the sync.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App\State;
use function OpenStation\App\Html\esc;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

const ALBUMS_PER_PAGE = 24;

/**
 * Lower-case for matching, multibyte when the host has it.
 *
 * @param string $s Text.
 * @return string
 */
function lower( $s ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
}

/**
 * Every entry, newest year first, with a stable id.
 *
 * @return array<int,array<string,mixed>>
 */
function albums_all() {
	$store   = function_exists( 'sn_discography_get' ) ? \sn_discography_get() : array();
	$entries = isset( $store['entries'] ) && is_array( $store['entries'] ) ? array_values( $store['entries'] ) : array();
	foreach ( $entries as $i => $e ) {
		if ( ! is_array( $e ) ) {
			unset( $entries[ $i ] );
			continue;
		}
		$entries[ $i ]['_id'] = '' !== (string) ( $e['id'] ?? '' ) ? (string) $e['id'] : 'x' . substr( md5( (string) ( $e['title'] ?? '' ) . '|' . (string) ( $e['artist'] ?? '' ) ), 0, 12 );
	}
	$entries = array_values( $entries );
	usort(
		$entries,
		static function ( $a, $b ) {
			return ( (int) ( $b['year'] ?? 0 ) <=> (int) ( $a['year'] ?? 0 ) ) ?: strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
		}
	);
	return $entries;
}

/**
 * One list page of releases.
 *
 * @param State $state Session state.
 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
 */
function albums_rows( State $state ) {
	$q    = lower( trim( (string) $state->get( 'query' ) ) );
	$all  = array_values(
		array_filter(
			albums_all(),
			static function ( $e ) use ( $q ) {
				if ( '' === $q ) {
					return true;
				}
				$hay = lower( (string) ( $e['title'] ?? '' ) . ' ' . (string) ( $e['artist'] ?? '' ) . ' ' . implode( ' ', (array) ( $e['roles'] ?? array() ) ) );
				return false !== strpos( $hay, $q );
			}
		)
	);
	$page = max( 1, (int) $state->get( 'page' ) );
	$slice = array_slice( $all, ( $page - 1 ) * ALBUMS_PER_PAGE, ALBUMS_PER_PAGE );
	$items = array();
	foreach ( $slice as $e ) {
		$items[] = array(
			'id'        => $e['_id'],
			'title'     => (string) ( $e['title'] ?? '' ),
			'subtitle'  => trim( (string) ( $e['artist'] ?? '' ) . ( ! empty( $e['year'] ) ? ' · ' . (int) $e['year'] : '' ) ),
			'thumbnail' => (string) ( $e['image'] ?? '' ),
			'icon'      => 'dashicons-album',
			'meta'      => implode( ', ', array_slice( (array) ( $e['roles'] ?? array() ), 0, 2 ) ),
		);
	}
	return array(
		'items'    => $items,
		'total'    => count( $all ),
		'page'     => $page,
		'per_page' => ALBUMS_PER_PAGE,
	);
}

/**
 * A release's dossier: credits, tracks, links.
 *
 * @param string $id Entry id.
 * @return array<string,mixed>|null
 */
function albums_dossier( $id ) {
	$entry = null;
	foreach ( albums_all() as $e ) {
		if ( $e['_id'] === (string) $id ) {
			$entry = $e;
			break;
		}
	}
	if ( null === $entry ) {
		return null;
	}
	$blocks = array();
	$tracks = (array) ( $entry['tracks'] ?? array() );
	if ( $tracks ) {
		$rows = '';
		foreach ( $tracks as $i => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$roles = implode( ', ', (array) ( $t['roles'] ?? array() ) );
			$rows .= '<li class="snt-os__track"><span class="snt-os__track-n">' . ( (int) $i + 1 ) . '</span><span class="snt-os__track-title">' . text( $t['title'] ?? '' ) . '</span>' . ( '' !== $roles ? '<span class="snt-os__track-roles">' . text( $roles ) . '</span>' : '' ) . '</li>';
		}
		$blocks[] = array(
			'heading' => sprintf( /* translators: %s: a count. */ _n( '%s track', '%s tracks', count( $tracks ), 'signal-and-noise-tools' ), number_format_i18n( count( $tracks ) ) ),
			'html'    => '<ol class="snt-os__tracks">' . $rows . '</ol>',
		);
	}
	$links = array();
	foreach ( array( 'spotify_url' => __( 'Open in Spotify', 'signal-and-noise-tools' ), 'muso_url' => __( 'Credits on Muso.AI', 'signal-and-noise-tools' ) ) as $key => $label ) {
		if ( ! empty( $entry[ $key ] ) ) {
			$links[] = array( 'label' => $label, 'url' => (string) $entry[ $key ] );
		}
	}
	$chips = array();
	foreach ( (array) ( $entry['roles'] ?? array() ) as $role ) {
		$chips[] = array( 'label' => (string) $role, 'tone' => 'accent' );
	}
	return array(
		'title'     => (string) ( $entry['title'] ?? '' ),
		'subtitle'  => trim( (string) ( $entry['artist'] ?? '' ) . ( ! empty( $entry['year'] ) ? ' · ' . (int) $entry['year'] : '' ) ),
		'thumbnail' => (string) ( $entry['image'] ?? '' ),
		'chips'     => $chips,
		'blocks'    => $blocks,
		'links'     => $links,
		'edit'      => array(),
	);
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		if ( array() === albums_all() ) {
			return $sections;
		}
		$sections[] = array(
			'id'         => 'discography',
			'label'      => __( 'Discography', 'signal-and-noise-tools' ),
			'icon'       => 'dashicons-album',
			'capability' => 'manage_options',
			'position'   => 20,
			'rows'       => __NAMESPACE__ . '\albums_rows',
			'dossier'    => __NAMESPACE__ . '\albums_dossier',
			'empty'      => array(
				'heading'     => __( 'No release matches', 'signal-and-noise-tools' ),
				'description' => __( 'Search by title, artist or role.', 'signal-and-noise-tools' ),
			),
		);
		return $sections;
	}
);
