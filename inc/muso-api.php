<?php
/**
 * Signal & Noise Tools — Muso.AI public credits client (zero credential).
 *
 * Juan's verified producer credits live on Muso.AI. The documented developer
 * API (x-api-key) is gated, but the public credits SPA at credits.muso.ai calls
 * an UNAUTHENTICATED, CORS-open endpoint that is also reachable server-side:
 *
 *   GET https://api2.muso.ai/api/v4/profile/<PROFILE_ID>/credits?limit=&offset=
 *       (no auth header; browser-ish Accept / Origin / Referer / X-App-Version)
 *
 * It answers { result, code, data:{ totalCount, limit, offset, hasMoreToLoad,
 * items[] } }. Each item carries the credited roles, the track (incl. its
 * Spotify track id), the album (incl. Spotify-hosted artwork), the release date,
 * and the artist(s).
 *
 * This module does TWO things and nothing else:
 *   1. sn_muso_fetch_credits()       — paginate the endpoint, MERGE all pages,
 *                                       and FAIL CLOSED (WP_Error) on any
 *                                       transport/HTTP error so the sync keeps
 *                                       its last-good store rather than storing a
 *                                       truncated discography.
 *   2. sn_muso_albums_from_credits() — the ONLY parser of Muso's field names:
 *                                       group the flat track-credits BY album.id
 *                                       into partial store entries (union of
 *                                       roles, primary artist, year, Muso
 *                                       artwork, a representative track spotifyId
 *                                       for later Spotify album resolution).
 *
 * No request-time use: only inc/discography-sync.php (cron + "Sync now") calls
 * these. No credential to store, mask, or rotate.
 *
 * Caveat: api2.muso.ai is an undocumented internal endpoint and could change;
 * the official x-api-key developer API remains the documented fallback.
 *
 * Added in v4.13.0 (Music Identity).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MUSO_API_BASE        = 'https://api2.muso.ai/api/v4';
const SN_MUSO_PROFILE_OPT     = 'sn_muso_profile_id';
const SN_MUSO_DEFAULT_PROFILE = '50d6a7c0-2d7b-4557-94b6-c472fa949df2';
const SN_MUSO_PAGE_LIMIT      = 100;
const SN_MUSO_PAGE_CEILING    = 50; // pagination safety stop (≤5,000 credits)
const SN_MUSO_ERR_KEY         = 'sn_muso_last_error';

/**
 * Resolve the Muso profile id: SN_MUSO_PROFILE_ID constant (wp-config lock) >
 * the sn_muso_profile_id option (admin-saved) > Juan's default profile.
 *
 * @return string Profile id (never empty).
 */
function sn_muso_profile_id() {
	if ( defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID ) {
		return (string) SN_MUSO_PROFILE_ID;
	}
	$opt = (string) get_option( SN_MUSO_PROFILE_OPT, '' );
	return '' !== $opt ? $opt : SN_MUSO_DEFAULT_PROFILE;
}

/**
 * Browser-ish headers the public endpoint expects. No credential — these only
 * mimic the SPA's Origin/Referer/version so the upstream answers server-side.
 *
 * @return array<string,string>
 */
function sn_muso_request_headers() {
	return array(
		'Accept'        => 'application/json',
		'Origin'        => 'https://credits.muso.ai',
		'Referer'       => 'https://credits.muso.ai/',
		'X-App-Version' => '4.0#131#web',
		'User-Agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
	);
}

/**
 * Record the most recent fetch failure (URL + HTTP code + short body excerpt).
 * No credential is ever in the URL, so this is safe to surface in the admin.
 *
 * @param string $url     Requested URL.
 * @param int    $code    HTTP status (0 = transport error).
 * @param string $message Error/body excerpt.
 * @return void
 */
function sn_muso_record_error( $url, $code, $message ) {
	set_transient(
		SN_MUSO_ERR_KEY,
		array(
			'url'     => (string) $url,
			'code'    => (int) $code,
			'message' => (string) $message,
			'when'    => time(),
		),
		30 * MINUTE_IN_SECONDS
	);
}

/**
 * Read the most recent recorded fetch error, if any.
 *
 * @return array{url:string,code:int,message:string,when:int}|null
 */
function sn_muso_last_error() {
	$err = get_transient( SN_MUSO_ERR_KEY );
	return is_array( $err ) ? $err : null;
}

/**
 * Fetch one page of credits. Returns the decoded `data` envelope, or a WP_Error
 * on any transport/HTTP/shape failure (recording the error transient).
 *
 * @param string $profile_id Muso profile id.
 * @param int    $offset     Pagination offset.
 * @param int    $limit      Page size.
 * @return array<string,mixed>|WP_Error The `data` object, or WP_Error.
 */
function sn_muso_fetch_page( $profile_id, $offset, $limit ) {
	$url = SN_MUSO_API_BASE . '/profile/' . rawurlencode( $profile_id ) . '/credits?'
		. http_build_query(
			array(
				'limit'  => (int) $limit,
				'offset' => (int) $offset,
			)
		);

	$response = wp_remote_get(
		$url,
		array(
			'headers' => sn_muso_request_headers(),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		sn_muso_record_error( $url, 0, $response->get_error_message() );
		return new WP_Error( 'sn_muso_transport', $response->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	if ( 200 !== $code ) {
		sn_muso_record_error( $url, $code, substr( $body, 0, 240 ) );
		return new WP_Error( 'sn_muso_http', 'Muso credits API returned HTTP ' . $code );
	}
	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
		sn_muso_record_error( $url, $code, 'unexpected response shape' );
		return new WP_Error( 'sn_muso_shape', 'Muso credits API returned an unexpected shape.' );
	}
	return $decoded['data'];
}

/**
 * Fetch ALL credit items for a profile, paginating until hasMoreToLoad is false.
 *
 * Fails CLOSED: any page error aborts the whole fetch with a WP_Error (rather
 * than returning a partial list), so the sync orchestrator preserves its
 * last-good store instead of shrinking the discography to whatever survived.
 *
 * @param string $profile_id Optional explicit profile id; defaults to sn_muso_profile_id().
 * @return array<int,array<string,mixed>>|WP_Error Raw credit items, or WP_Error.
 */
function sn_muso_fetch_credits( $profile_id = '' ) {
	$profile_id = '' !== (string) $profile_id ? (string) $profile_id : sn_muso_profile_id();
	if ( '' === $profile_id ) {
		return new WP_Error( 'sn_muso_no_profile', 'No Muso profile id configured.' );
	}

	$items  = array();
	$offset = 0;
	$pages  = 0;
	do {
		$data = sn_muso_fetch_page( $profile_id, $offset, SN_MUSO_PAGE_LIMIT );
		if ( is_wp_error( $data ) ) {
			return $data; // fail closed — no partial store.
		}
		$page_items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items      = array_merge( $items, $page_items );
		$offset    += SN_MUSO_PAGE_LIMIT;
		$has_more   = ! empty( $data['hasMoreToLoad'] );
		$pages++;
	} while ( $has_more && $pages < SN_MUSO_PAGE_CEILING );

	delete_transient( SN_MUSO_ERR_KEY ); // success clears any stale diagnostic.
	return $items;
}

/**
 * Group flat Muso track-credits BY album.id into partial store entries.
 *
 * This is the sole parser of Muso's field names. Grouping keys on album.id (NOT
 * the title) so same-title different-id releases stay distinct. Per album:
 *   - id               = Muso album id (becomes the store entry's stable id)
 *   - title            = album.title
 *   - artist           = artists[0].name of the album's first credited track
 *   - roles            = DEDUPED union of `credits` across the album's tracks
 *   - year             = year parsed from the earliest non-empty releaseDate
 *   - image            = album.avatarUrl_640_640 (fallback avatarUrl)
 *   - muso_url         = credits.muso.ai/album/<album.id>
 *   - type             = '' (Spotify resolution fills album_type later)
 *   - spotify_track_id = a representative track spotifyId on that album, used by
 *                        inc/spotify-api.php to resolve the Spotify ALBUM id so
 *                        the embed/schema describe the album, not one track.
 *
 * @param array<int,array<string,mixed>> $items Raw Muso credit items.
 * @return array<int,array<string,mixed>> Partial entries (one per album).
 */
function sn_muso_albums_from_credits( $items ) {
	if ( ! is_array( $items ) ) {
		return array();
	}

	$albums = array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$album = isset( $item['album'] ) && is_array( $item['album'] ) ? $item['album'] : array();
		$aid   = (string) ( $album['id'] ?? '' );
		if ( '' === $aid ) {
			continue; // a credit with no album can't be modeled as a release.
		}

		if ( ! isset( $albums[ $aid ] ) ) {
			$artists = isset( $item['artists'] ) && is_array( $item['artists'] ) ? $item['artists'] : array();
			$artist  = isset( $artists[0]['name'] ) ? (string) $artists[0]['name'] : '';
			$image   = (string) ( $album['avatarUrl_640_640'] ?? ( $album['avatarUrl'] ?? '' ) );

			$albums[ $aid ] = array(
				'id'               => $aid,
				'title'            => (string) ( $album['title'] ?? '' ),
				'artist'           => $artist,
				'roles'            => array(),
				'year'             => 0,
				'date'             => '',
				'image'            => $image,
				'muso_url'         => 'https://credits.muso.ai/album/' . $aid,
				'type'             => '',
				'spotify_track_id' => '',
			);
		}

		// Union the credited roles for this track into the album's role set.
		$credits = isset( $item['credits'] ) && is_array( $item['credits'] ) ? $item['credits'] : array();
		foreach ( $credits as $role ) {
			$role = trim( (string) $role );
			if ( '' !== $role && ! in_array( $role, $albums[ $aid ]['roles'], true ) ) {
				$albums[ $aid ]['roles'][] = $role;
			}
		}

		// Earliest non-empty release date wins (tracks on a release share it, but
		// be defensive against per-track variance / reissue dates). The FULL
		// YYYY-MM-DD is kept for a precise schema datePublished; the year is
		// derived from it. Lexical compare of YYYY-MM-DD == chronological.
		$rd = (string) ( $item['releaseDate'] ?? '' );
		if ( '' !== $rd && ( '' === $albums[ $aid ]['date'] || $rd < $albums[ $aid ]['date'] ) ) {
			$albums[ $aid ]['date'] = $rd;
			$albums[ $aid ]['year'] = (int) substr( $rd, 0, 4 );
		}

		// First track that exposes a Spotify id resolves the album for the embed.
		if ( '' === $albums[ $aid ]['spotify_track_id'] ) {
			$track = isset( $item['track'] ) && is_array( $item['track'] ) ? $item['track'] : array();
			$sid   = (string) ( $track['spotifyId'] ?? '' );
			if ( '' !== $sid ) {
				$albums[ $aid ]['spotify_track_id'] = $sid;
			}
		}
	}

	return array_values( $albums );
}
