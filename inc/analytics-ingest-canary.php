<?php
/**
 * Signal & Noise Tools — the analytics ingest canary.
 *
 * On a site this quiet a day with no human pageview happens, and the ingest
 * check (inc/health-check-analytics-ingest.php) could not tell it from a dead
 * collector. Once an hour this sends one pageview to the real collector
 * (/_sn/px, through Cloudflare, with the public beacon key) under a
 * SignalNoise- user agent. The analytics Worker files that as this site's own
 * tool (identity 7) in sn_owner_hits, never in human, suspect or bot, so it
 * changes no count. The ingest check reads it back: a canary row in the last
 * 24 hours proves the collector writes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_INGEST_CANARY_HOOK = 'sn_ingest_canary';
const SN_INGEST_CANARY_UA   = 'SignalNoise-IngestCanary/1.0';
const SN_INGEST_CANARY_OPT  = 'sn_ingest_canary_last';

/**
 * The beacon body: a pageview of the home page. PURE.
 *
 * @param string $token The public beacon key.
 * @return array
 */
function sn_ingest_canary_payload( $token ) {
	return array( 'k' => (string) $token, 'e' => 'pv', 'u' => '/' );
}

/**
 * Send one canary pageview and store what the collector answered.
 *
 * @param callable|null $post Test seam: ( $url, $args ) => wp_remote_post result.
 * @return array The stored { at, http, why }.
 */
function sn_ingest_canary_send( $post = null ) {
	$token = function_exists( 'sn_rss_tracker_token' ) ? (string) sn_rss_tracker_token() : '';
	$last  = array( 'at' => time(), 'http' => 0, 'why' => '' );
	if ( '' === $token ) {
		$last['why'] = 'no beacon key configured';
	} else {
		$post = $post ?? 'wp_remote_post';
		$res  = $post(
			home_url( '/_sn/px' ),
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'user-agent'  => SN_INGEST_CANARY_UA,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( sn_ingest_canary_payload( $token ) ),
			)
		);
		$last['http'] = (int) wp_remote_retrieve_response_code( $res );
		$last['why']  = is_wp_error( $res ) ? $res->get_error_message() : '';
	}
	update_option( SN_INGEST_CANARY_OPT, $last, false );
	return $last;
}

if ( ! defined( 'SN_INGEST_CANARY_TEST' ) || ! SN_INGEST_CANARY_TEST ) {
	add_action( SN_INGEST_CANARY_HOOK, 'sn_ingest_canary_send', 10, 0 );
	add_action(
		'init',
		static function () {
			if ( ! wp_next_scheduled( SN_INGEST_CANARY_HOOK ) ) {
				wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', SN_INGEST_CANARY_HOOK );
			}
		}
	);
}
