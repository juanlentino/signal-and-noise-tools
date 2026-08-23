<?php
/**
 * Signal & Noise Tools — discography sync orchestrator + cross-package filter.
 *
 * The SOLE writer of the discography store (inc/discography-store.php). Runs on a
 * daily WP-Cron job and on the admin "Sync now" button — never on a page render.
 * One pass:
 *
 *   Muso credits ──► group by album (inc/muso-api.php)
 *                       │
 *                       ▼  per album, if Spotify is configured
 *                    resolve the album from a representative track id
 *                    (inc/spotify-api.php): embed id / url / type / year /
 *                    artwork fallback
 *                       │
 *                       ▼
 *                 normalize → store (sorted year-desc)
 *
 * Robustness (load-bearing): the sync FAILS CLOSED. If Muso errors or returns
 * nothing, the prior store is PRESERVED (re-stamped with the new time + the
 * error) so /music never blanks on an API outage — only a successful, non-empty
 * fetch ever replaces the entries. Spotify failing is softer still: that album
 * simply keeps its Muso-only fields (artwork stays, no embed).
 *
 * Standalone-safe contract: sn_discography_entries_filter() answers the
 * `sn_discography_entries` filter the companion theme's [sn_discography]
 * shortcode reads. Plugin absent → no filter → theme sees array() → static
 * fallback. (Registration is skipped under the CLI test harness.)
 *
 * Added in v4.13.0 (Music Identity).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DISCOGRAPHY_CRON_HOOK = 'sn_discography_cron';

/**
 * Merge a Spotify album resolution into a partial Muso album entry.
 *
 * Muso artwork is PREFERRED (it's the credit-accurate cover); Spotify artwork is
 * a fallback only when Muso has none. Spotify supplies the embed id/url and the
 * album_type (Muso doesn't expose it); the Muso release year wins when present.
 *
 * @param array<string,mixed>      $album      Partial Muso album entry.
 * @param array<string,mixed>|null $resolution sn_spotify_album_for_track() output.
 * @return array<string,mixed> The merged entry.
 */
function sn_discography_merge_spotify( $album, $resolution ) {
	if ( ! is_array( $resolution ) ) {
		return $album;
	}
	$album['spotify_id']  = (string) ( $resolution['spotify_id'] ?? '' );
	$album['spotify_url'] = (string) ( $resolution['spotify_url'] ?? '' );

	$sp_type = (string) ( $resolution['type'] ?? '' );
	if ( '' === (string) ( $album['type'] ?? '' ) && '' !== $sp_type ) {
		$album['type'] = $sp_type;
	}
	$sp_year = (int) ( $resolution['year'] ?? 0 );
	if ( 0 === (int) ( $album['year'] ?? 0 ) && $sp_year > 0 ) {
		$album['year'] = $sp_year;
	}
	$sp_date = (string) ( $resolution['date'] ?? '' );
	if ( '' === (string) ( $album['date'] ?? '' ) && '' !== $sp_date ) {
		$album['date'] = $sp_date;
	}
	$sp_image = (string) ( $resolution['image'] ?? '' );
	if ( '' === (string) ( $album['image'] ?? '' ) && '' !== $sp_image ) {
		$album['image'] = $sp_image;
	}
	return $album;
}

/**
 * Run a full discography sync. Fails closed (preserves last-good) on any Muso
 * error or empty result.
 *
 * @return bool True if the store was refreshed with new entries; false if the
 *              source failed and the prior store was preserved.
 */
function sn_discography_run_sync() {
	$items = sn_muso_fetch_credits();

	// Fail closed: keep the prior entries, just re-stamp time + record the error.
	if ( is_wp_error( $items ) || ! is_array( $items ) || empty( $items ) ) {
		$message = is_wp_error( $items )
			? $items->get_error_message()
			: 'Muso returned no credits.';
		$current = sn_discography_get();
		sn_discography_set( $current['entries'], time(), $message );
		return false;
	}

	$albums = sn_muso_albums_from_credits( $items );
	if ( ! is_array( $albums ) || empty( $albums ) ) {
		$current = sn_discography_get();
		sn_discography_set( $current['entries'], time(), 'Muso credits produced no albums.' );
		return false;
	}

	$spotify_on = (bool) sn_spotify_config();

	$entries = array();
	foreach ( $albums as $album ) {
		$track_id = (string) ( $album['spotify_track_id'] ?? '' );
		if ( $spotify_on && '' !== $track_id ) {
			$resolution = sn_spotify_album_for_track( $track_id );
			$album      = sn_discography_merge_spotify( $album, $resolution );
		}
		// Drop the internal-only resolution hint before persisting.
		unset( $album['spotify_track_id'] );
		$entries[] = sn_discography_normalize_entry( $album );
	}

	sn_discography_set( $entries, time(), '' );
	return true;
}

/**
 * The `sn_discography_entries` filter callback — the cross-package contract the
 * theme's [sn_discography] timeline reads. Returns the cached store entries
 * (the supplied default is ignored; the store is the source of truth).
 *
 * @param mixed $entries Theme-supplied default (array()).
 * @return array<int,array<string,mixed>> The store's entries.
 */
function sn_discography_entries_filter( $entries ) {
	$store = sn_discography_get();
	return isset( $store['entries'] ) && is_array( $store['entries'] ) ? $store['entries'] : array();
}

/**
 * Schedule the daily sync if it isn't already scheduled. Hooked on `init`
 * (mirrors inc/insights.php) so it self-heals on the
 * next request after install — never relying on the activation hook alone, which
 * runs in the OLD version's request and can't observe a new handler.
 *
 * @return void
 */
function sn_discography_maybe_schedule() {
	if ( ! wp_next_scheduled( SN_DISCOGRAPHY_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_DISCOGRAPHY_CRON_HOOK );
	}
}

// Wiring — skipped under the CLI test harness, which calls the functions above
// directly (and doesn't stub add_action/add_filter/wp_schedule_event).
if ( ! defined( 'SN_DISCOGRAPHY_SYNC_TEST' ) || ! SN_DISCOGRAPHY_SYNC_TEST ) {
	add_action( SN_DISCOGRAPHY_CRON_HOOK, 'sn_discography_run_sync' );
	add_action( 'init', 'sn_discography_maybe_schedule' );
	add_filter( 'sn_discography_entries', 'sn_discography_entries_filter' );
}
