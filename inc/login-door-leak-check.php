<?php
/**
 * Signal & Noise Tools — the login door is published nowhere (daily check).
 *
 * The owner's rule (2026-10-18): the masked login address gets out only if the
 * owner hands it out. In one night it leaked three ways, each found by hand:
 * core redirects (customize.php's auth_redirect), the OpenStation launch URLs,
 * and the speculation rules printed into every page. Each is fixed; this check
 * keeps it that way. Once a day it reads what a stranger reads (the home page,
 * the newest note, a dead link's 404, feeds, the REST index, sitemaps,
 * robots.txt) with no cookies, bodies and response headers alike, and raises
 * the login_door_leak watch when any of them contains the door. It stores and
 * reports WHERE, never the slug.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DOOR_LEAK_HOOK = 'sn_login_door_leak_check';
const SN_DOOR_LEAK_OPT  = 'sn_login_door_leak_last';

/**
 * The public surfaces a stranger reads.
 *
 * @return string[] Absolute URLs.
 */
function sn_door_leak_surfaces() {
	$urls = array(
		home_url( '/' ),
		home_url( '/about/' ),
		home_url( '/sn-door-check-' . gmdate( 'Ymd' ) . '/' ), // A dead link: the 404 page.
		home_url( '/feed/' ),
		home_url( '/wp-json/' ),
		home_url( '/wp-sitemap.xml' ),
		home_url( '/robots.txt' ),
		home_url( '/?s=login' ),
	);
	$latest = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ) );
	if ( ! empty( $latest[0] ) ) {
		$urls[] = (string) get_permalink( (int) $latest[0] );
	}
	return array_values( array_unique( $urls ) );
}

/**
 * Whether text contains the door path: "/<slug>" not followed by another
 * path character, so "/<slug>-notes/" is not the door.
 *
 * @param string $text Body or header text.
 * @param string $slug Login slug.
 * @return bool
 */
function sn_door_leak_contains( $text, $slug ) {
	$slug = trim( (string) $slug, '/' );
	if ( '' === $slug ) {
		return false;
	}
	return 1 === preg_match( '#/' . preg_quote( $slug, '#' ) . '(?![A-Za-z0-9_-])#', (string) $text );
}

/**
 * One fetch as a stranger: no cookies, no redirects followed.
 *
 * @param string $url Absolute URL.
 * @return array{ok:bool,body:string,headers:array}
 */
function sn_door_leak_fetch( $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'cookies' => array(), 'user-agent' => 'sn-door-leak-check' ) );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'body' => '', 'headers' => array() );
	}
	$headers = wp_remote_retrieve_headers( $res );
	$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
	return array( 'ok' => true, 'body' => (string) wp_remote_retrieve_body( $res ), 'headers' => $headers );
}

/**
 * Read every surface and record where the door appears. Stores no slug.
 *
 * @param callable|null $fetch Fetcher (tests); defaults to sn_door_leak_fetch.
 * @param int|null      $now   Unix time.
 * @return array{at:int,found:string[],checked:int,errors:int}
 */
function sn_door_leak_run( $fetch = null, $now = null ) {
	$fetch = is_callable( $fetch ) ? $fetch : 'sn_door_leak_fetch';
	$slug  = function_exists( 'sn_login_get_slug' ) ? (string) sn_login_get_slug() : '';
	$out   = array( 'at' => null === $now ? time() : (int) $now, 'found' => array(), 'checked' => 0, 'errors' => 0 );
	foreach ( sn_door_leak_surfaces() as $url ) {
		$r = call_user_func( $fetch, $url );
		++$out['checked'];
		if ( empty( $r['ok'] ) ) {
			++$out['errors'];
			continue;
		}
		$head = '';
		foreach ( (array) ( $r['headers'] ?? array() ) as $k => $v ) {
			$head .= $k . ': ' . ( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . "\n";
		}
		if ( sn_door_leak_contains( (string) $r['body'] . "\n" . $head, $slug ) ) {
			$out['found'][] = $url;
		}
	}
	update_option( SN_DOOR_LEAK_OPT, $out, false );
	return $out;
}

/**
 * Watch callback: ripe when the last run found the door anywhere, or when the
 * check has gone quiet for over two days (silence is not a clean result).
 *
 * @param array $watch Watch row (unused).
 * @param int   $now   Unix time.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_login_door_leak( $watch, $now ) {
	unset( $watch );
	$last = get_option( SN_DOOR_LEAK_OPT, array() );
	if ( ! is_array( $last ) || empty( $last['at'] ) ) {
		return array( 'ripe' => false, 'note' => 'not run yet' );
	}
	$found = (array) ( $last['found'] ?? array() );
	if ( $found ) {
		return array( 'ripe' => true, 'note' => 'the login address is published on: ' . implode( ', ', array_slice( $found, 0, 8 ) ) . '. Find what prints it, fix it, then change the Login URL (one save).' );
	}
	if ( (int) $now - (int) $last['at'] > 2 * DAY_IN_SECONDS ) {
		return array( 'ripe' => true, 'note' => 'the daily check has not run since ' . gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC' );
	}
	return array( 'ripe' => false, 'note' => sprintf( 'clean on %d surfaces at %s UTC%s', (int) $last['checked'], gmdate( 'Y-m-d H:i', (int) $last['at'] ), (int) $last['errors'] > 0 ? '; ' . (int) $last['errors'] . ' unreadable' : '' ) );
}

if ( ! defined( 'SN_DOOR_LEAK_TEST' ) || ! SN_DOOR_LEAK_TEST ) {
	add_action( SN_DOOR_LEAK_HOOK, 'sn_door_leak_run', 10, 0 );
	add_action(
		'init',
		static function () {
			if ( ! wp_next_scheduled( SN_DOOR_LEAK_HOOK ) ) {
				wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'daily', SN_DOOR_LEAK_HOOK );
			}
		}
	);
}
