<?php
/**
 * Signal & Noise Tools — minimal Spotify Web API client (album resolver).
 *
 * Spotify is deliberately NARROW here. It is NOT the credit source (producer
 * credits aren't exposed by the API) and NOT a fuzzy matcher. Muso already hands
 * us each track's exact Spotify id, so this module's only job is to lift a track
 * up to the ALBUM it belongs to:
 *
 *   sn_spotify_album_for_track($track_id):  GET /v1/tracks/{id} → .album
 *     → { spotify_id (album id), spotify_url, type (album_type), image, year }
 *
 * so the /music embed plays the album (open.spotify.com/embed/album/<id>) and the
 * schema's @type stays consistent, instead of describing a single track. ~11
 * calls per sync (one representative track per album).
 *
 * Auth: client-credentials (public catalog data, no user login). Credentials are
 * non-autoloaded, constant-lockable options — masked in the admin, never logged,
 * mirroring the Plausible/Cloudflare token pattern. The bearer token is cached in
 * a transient until just shy of its expires_in. Only the cron + "Sync now" call
 * this; never the page-render path.
 *
 * Added in v4.13.0 (Music Identity).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SPOTIFY_ID_OPT     = 'sn_spotify_client_id';
const SN_SPOTIFY_SECRET_OPT = 'sn_spotify_client_secret';
const SN_SPOTIFY_TOKEN_KEY  = 'sn_spotify_token';
const SN_SPOTIFY_ERR_KEY    = 'sn_spotify_last_error';
const SN_SPOTIFY_TOKEN_URL  = 'https://accounts.spotify.com/api/token';
const SN_SPOTIFY_API_BASE   = 'https://api.spotify.com/v1';

/**
 * Resolve Spotify client credentials: SN_SPOTIFY_CLIENT_ID / _SECRET constants
 * (wp-config lock) > the admin-saved options. Returns null unless BOTH are set,
 * which keeps the whole feature dormant (Muso-only artwork, no embeds) until the
 * owner configures Spotify.
 *
 * @return array{id:string,secret:string}|null
 */
function sn_spotify_config() {
	$id = ( defined( 'SN_SPOTIFY_CLIENT_ID' ) && SN_SPOTIFY_CLIENT_ID )
		? (string) SN_SPOTIFY_CLIENT_ID
		: (string) get_option( SN_SPOTIFY_ID_OPT, '' );
	$secret = ( defined( 'SN_SPOTIFY_CLIENT_SECRET' ) && SN_SPOTIFY_CLIENT_SECRET )
		? (string) SN_SPOTIFY_CLIENT_SECRET
		: (string) get_option( SN_SPOTIFY_SECRET_OPT, '' );
	if ( '' === $id || '' === $secret ) {
		return null;
	}
	return array(
		'id'     => $id,
		'secret' => $secret,
	);
}

/**
 * Record the most recent Spotify failure (no credential is ever in the URL).
 *
 * @param string $url     Requested URL.
 * @param int    $code    HTTP status (0 = transport).
 * @param string $message Excerpt.
 * @return void
 */
function sn_spotify_record_error( $url, $code, $message ) {
	set_transient(
		SN_SPOTIFY_ERR_KEY,
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
 * Read the most recent recorded Spotify error, if any.
 *
 * @return array{url:string,code:int,message:string,when:int}|null
 */
function sn_spotify_last_error() {
	$err = get_transient( SN_SPOTIFY_ERR_KEY );
	return is_array( $err ) ? $err : null;
}

/**
 * Get a client-credentials bearer token, cached until shortly before expiry.
 * Returns '' when unconfigured or on any auth failure (callers degrade to
 * Muso-only data — no embed — rather than blocking).
 *
 * @return string Bearer token, or '' if unavailable.
 */
function sn_spotify_token() {
	$cached = get_transient( SN_SPOTIFY_TOKEN_KEY );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}
	$cfg = sn_spotify_config();
	if ( ! $cfg ) {
		return '';
	}

	$response = wp_remote_post(
		SN_SPOTIFY_TOKEN_URL,
		array(
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $cfg['id'],
				'client_secret' => $cfg['secret'],
			),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		sn_spotify_record_error( SN_SPOTIFY_TOKEN_URL, 0, $response->get_error_message() );
		return '';
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	if ( 200 !== $code ) {
		sn_spotify_record_error( SN_SPOTIFY_TOKEN_URL, $code, substr( $body, 0, 240 ) );
		return '';
	}
	$data    = json_decode( $body, true );
	$token   = ( is_array( $data ) && ! empty( $data['access_token'] ) ) ? (string) $data['access_token'] : '';
	$expires = ( is_array( $data ) && ! empty( $data['expires_in'] ) ) ? (int) $data['expires_in'] : 3600;
	if ( '' !== $token ) {
		// Refresh a minute early so an in-flight sync never uses an expired token.
		set_transient( SN_SPOTIFY_TOKEN_KEY, $token, max( 60, $expires - 60 ) );
	}
	return $token;
}

/**
 * Resolve a track's Spotify id to its album. Returns null (fail soft) when
 * unconfigured, the track isn't found, or the response lacks an album — the
 * sync then keeps the album's Muso-only fields (artwork stays, no embed).
 *
 * @param string $track_spotify_id Spotify TRACK id (from Muso's track.spotifyId).
 * @return array{spotify_id:string,spotify_url:string,type:string,image:string,year:int}|null
 */
function sn_spotify_album_for_track( $track_spotify_id ) {
	$track_spotify_id = (string) $track_spotify_id;
	if ( '' === $track_spotify_id ) {
		return null;
	}
	$token = sn_spotify_token();
	if ( '' === $token ) {
		return null;
	}

	$url      = SN_SPOTIFY_API_BASE . '/tracks/' . rawurlencode( $track_spotify_id );
	$response = wp_remote_get(
		$url,
		array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		sn_spotify_record_error( $url, 0, $response->get_error_message() );
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	if ( 200 !== $code ) {
		sn_spotify_record_error( $url, $code, substr( $body, 0, 240 ) );
		return null;
	}
	delete_transient( SN_SPOTIFY_ERR_KEY ); // success clears any stale diagnostic.

	$data  = json_decode( $body, true );
	$album = ( is_array( $data ) && isset( $data['album'] ) && is_array( $data['album'] ) ) ? $data['album'] : null;
	if ( ! $album || empty( $album['id'] ) ) {
		return null;
	}

	$album_id  = (string) $album['id'];
	$album_url = ! empty( $album['external_urls']['spotify'] )
		? (string) $album['external_urls']['spotify']
		: 'https://open.spotify.com/album/' . $album_id;
	$type  = ! empty( $album['album_type'] ) ? (string) $album['album_type'] : '';
	$image = '';
	if ( ! empty( $album['images'][0]['url'] ) ) {
		$image = (string) $album['images'][0]['url'];
	}
	// Spotify's release_date precision varies (YYYY / YYYY-MM / YYYY-MM-DD per
	// release_date_precision); pass the full value through and derive the year.
	$date = ! empty( $album['release_date'] ) ? (string) $album['release_date'] : '';
	$year = '' !== $date ? (int) substr( $date, 0, 4 ) : 0;

	return array(
		'spotify_id'  => $album_id,
		'spotify_url' => $album_url,
		'type'        => $type,
		'image'       => $image,
		'year'        => $year,
		'date'        => $date,
	);
}

/**
 * Drop the cached bearer token so the next sync re-authenticates. Called from
 * the admin after the Spotify credentials change.
 *
 * @return void
 */
function sn_spotify_invalidate_token() {
	delete_transient( SN_SPOTIFY_TOKEN_KEY );
	delete_transient( SN_SPOTIFY_ERR_KEY );
}
