<?php
/**
 * Signal & Noise Tools: Hacker News mentions (21.0.0).
 *
 * A referrer undercounts Hacker News (its apps send none), and a submission
 * by someone else is invisible until traffic arrives. This asks Hacker News
 * directly, once an hour inside the alert run: the Algolia search API finds
 * any story whose URL is on this site, and the official API gives its live
 * points, comments and front-page position for its first three days. Both
 * are public and need no key. Nothing is sent but the site's host name.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_HN_OPT        = 'sn_hn_mentions'; // { items: id => row, checked: unix, error: string }.
const SN_HN_KEEP       = 50;               // rows kept, newest first.
const SN_HN_LIVE_DAYS  = 3;                // a story is re-read while this young.
const SN_HN_FRONT_PAGE = 30;               // positions that count as the front page.
const SN_HN_LIVE_MAX   = 5;                // young stories re-read per run: a slow API must not hold the alert run.

/**
 * GET a public JSON document. Null on any failure: a failed read is not "none".
 *
 * @param string $url Absolute URL.
 * @return mixed Decoded JSON, or null.
 */
function sn_hn_get( $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'signal-and-noise-tools', 'Accept' => 'application/json' ) ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	return json_decode( (string) wp_remote_retrieve_body( $res ), true );
}

/**
 * Fold search hits into the stored rows. PURE. A story is kept only when its
 * URL's host is this site's (the search matches the host as text anywhere in
 * a URL). A new row carries first_seen = $now, which is what makes it news.
 *
 * @param array<int|string,array> $items Stored rows by story id.
 * @param array<int,array>        $hits  Algolia hits.
 * @param string                  $host  This site's host.
 * @param int                     $now   Unix time.
 * @return array<int|string,array>
 */
function sn_hn_merge( array $items, array $hits, $host, $now ) {
	$bare = static fn( $h ) => preg_replace( '/^www\./', '', strtolower( (string) $h ) );
	foreach ( $hits as $h ) {
		$id  = (int) ( $h['objectID'] ?? 0 );
		$url = (string) ( $h['url'] ?? '' );
		if ( $id < 1 || $bare( wp_parse_url( $url, PHP_URL_HOST ) ) !== $bare( $host ) ) {
			continue;
		}
		$row = $items[ $id ] ?? array( 'first_seen' => (int) $now, 'rank' => 0, 'best_rank' => 0 );
		$items[ $id ] = array_merge( $row, array(
			'id'       => $id,
			'url'      => esc_url_raw( $url ),
			'path'     => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'title'    => sanitize_text_field( (string) ( $h['title'] ?? '' ) ),
			'created'  => (int) ( $h['created_at_i'] ?? 0 ),
			'points'   => max( (int) ( $row['points'] ?? 0 ), (int) ( $h['points'] ?? 0 ) ),
			'comments' => max( (int) ( $row['comments'] ?? 0 ), (int) ( $h['num_comments'] ?? 0 ) ),
		) );
	}
	uasort( $items, static fn( $a, $b ) => (int) $b['created'] <=> (int) $a['created'] );
	return array_slice( $items, 0, SN_HN_KEEP, true );
}

/**
 * The hourly read: find stories, then refresh the young ones live.
 *
 * @param int $now Unix time.
 * @return array<int|string,array> The stored rows.
 */
function sn_hn_refresh( $now ) {
	$state = get_option( SN_HN_OPT, array() );
	$items = is_array( $state['items'] ?? null ) ? $state['items'] : array();
	// The bare host: the search matches text, so "www." would miss a story
	// posted without it. The merge compares both forms.
	$host  = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	$found = sn_hn_get( 'https://hn.algolia.com/api/v1/search_by_date?tags=story&restrictSearchableAttributes=url&hitsPerPage=30&query=' . rawurlencode( $host ) );
	$error = is_array( $found ) && is_array( $found['hits'] ?? null ) ? '' : 'search failed';
	if ( '' === $error ) {
		$items = sn_hn_merge( $items, $found['hits'], $host, $now );
	}
	$young = array_filter( $items, static fn( $r ) => (int) $r['created'] > $now - SN_HN_LIVE_DAYS * DAY_IN_SECONDS );
	// A rank is a reading of NOW. Every row starts this run at 0 and only a
	// top list read in this run sets it again, so an aged-out story, or a run
	// whose top-list read failed, never reports a saved position as current.
	foreach ( array_keys( $items ) as $id ) {
		$items[ $id ]['rank'] = 0;
	}
	// Newest first, so the cap on the per-story reads keeps the stories most
	// likely to be moving; the rank below is read for every young story.
	$detail = array_slice( $young, 0, SN_HN_LIVE_MAX, true );
	$top   = $young ? sn_hn_get( 'https://hacker-news.firebaseio.com/v0/topstories.json' ) : null;
	foreach ( array_keys( $young ) as $id ) {
		$live = is_array( $top ) && isset( $detail[ $id ] ) ? sn_hn_get( 'https://hacker-news.firebaseio.com/v0/item/' . (int) $id . '.json' ) : null; // a failed top list: the API is down, do not wait on it again.
		if ( is_array( $live ) ) {
			$items[ $id ]['points']   = (int) ( $live['score'] ?? $items[ $id ]['points'] );
			$items[ $id ]['comments'] = (int) ( $live['descendants'] ?? $items[ $id ]['comments'] );
		}
		if ( is_array( $top ) ) {
			$pos                  = array_search( (int) $id, array_slice( array_map( 'intval', $top ), 0, SN_HN_FRONT_PAGE ), true );
			$rank                 = false === $pos ? 0 : (int) $pos + 1;
			$best                 = (int) ( $items[ $id ]['best_rank'] ?? 0 );
			$items[ $id ]['rank'] = $rank;
			$items[ $id ]['best_rank'] = $rank > 0 && ( 0 === $best || $rank < $best ) ? $rank : $best;
		}
	}
	update_option( SN_HN_OPT, array( 'items' => $items, 'checked' => (int) $now, 'error' => $error ), false );
	return $items;
}

/**
 * One line for a story: "N points, M comments[, front page #R]". PURE.
 *
 * @param array $row A stored row.
 * @return string
 */
function sn_hn_line( array $row ) {
	$p = (int) ( $row['points'] ?? 0 );
	$c = (int) ( $row['comments'] ?? 0 );
	return sprintf( '%d %s, %d %s', $p, 1 === $p ? 'point' : 'points', $c, 1 === $c ? 'comment' : 'comments' ) . ( ! empty( $row['rank'] ) ? ', front page #' . (int) $row['rank'] : '' );
}
