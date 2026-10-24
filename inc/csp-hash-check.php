<?php
/**
 * Signal & Noise Tools: the daily CSP inline-hash check (2026-10-18).
 *
 * The theme's planned strict script-src (theme docs/CSP.md) drops
 * 'unsafe-inline' and pins the sha256 of three inline scripts: theme-init,
 * core's import map and core's emoji module. The import map changes with every
 * core update, and a stale hash silently stops every script module (the
 * dark-mode toggle, navigation). The policy lives in a Cloudflare Transform
 * Rule, so nothing in either repo can see it go stale. Once a day this reads
 * the public pages as a stranger (the login door leak check's fetch: no
 * cookies, no redirects), hashes every executable inline script, reads the
 * strict policy from the response headers and stores what is missing. It
 * stores hashes and sources, never page bodies; an unlisted script keeps its
 * first 60 characters so the note can say what it is. /contact/ is exempt by
 * design. It also records any page that answers with X-SN-CSP: lax, the
 * marker that keeps a response on the loose policy: a cookieless read of
 * these pages should never carry it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CSP_HASH_HOOK = 'sn_csp_hash_check';
const SN_CSP_HASH_OPT  = 'sn_csp_hash_last';
const SN_CSP_HASH_VERSION_OPT = 'sn_csp_hash_core_version';
// After an update, wait for the purge chain to refresh the edge before reading.
const SN_CSP_HASH_AFTER_UPDATE = 5 * 60;

/**
 * The public pages the strict policy covers (docs/CSP.md checklist).
 *
 * @return string[] Absolute URLs.
 */
function sn_csp_hash_pages() {
	// 2026-10-20: /resume/, /verify/ and /stats/ too. /verify prints its own
	// document and /stats a live config, the two pages a headless run found
	// with an inline script the strict policy would block.
	// 2026-10-24: and the /provhub family. Those are standalone HTML files on
	// the server, not WordPress pages, and the enforced policy blocked every
	// inline script on them (the verifier stuck on "Fetching sample files")
	// while this list never read them.
	$urls   = array( home_url( '/' ), home_url( '/notes/' ), home_url( '/provenance/' ), home_url( '/index/' ), home_url( '/resume/' ), home_url( '/verify/' ), home_url( '/stats/' ), home_url( '/provhub/' ), home_url( '/provhub/present/' ), home_url( '/provhub/verify/' ), home_url( '/provhub/resources/' ) );
	$latest = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ) );
	if ( ! empty( $latest[0] ) ) {
		$urls[] = (string) get_permalink( (int) $latest[0] );
	}
	return array_values( array_unique( $urls ) );
}

/**
 * One page as a stranger reads it: the login door leak check's fetch (no
 * cookies, no redirects, 10 s), with the Global Privacy Control signal every
 * probe of the site sends.
 *
 * @param string $url Absolute URL.
 * @return array{ok:bool,code:int,body:string,headers:array}
 */
function sn_csp_hash_fetch( $url ) {
	return sn_door_leak_fetch( $url, array( 'Sec-GPC' => '1' ) );
}

/**
 * Read every page and store what the strict policy lacks.
 *
 * @param callable|null $fetch Fetcher (tests); defaults to sn_csp_hash_fetch.
 * @param int|null      $now   Unix time.
 * @return array{at:int,checked:int,errors:int,covered:int,csp_seen:bool,mode:string,sources:string[],seen:array,missing:string[],lax:string[]}
 */
function sn_csp_hash_run( $fetch = null, $now = null ) {
	$fetch = is_callable( $fetch ) ? $fetch : 'sn_csp_hash_fetch';
	$heads = array();
	$read  = array();
	$out   = array( 'at' => null === $now ? time() : (int) $now, 'checked' => 0, 'errors' => 0, 'covered' => 0, 'csp_seen' => false, 'mode' => 'none', 'sources' => array(), 'seen' => array(), 'missing' => array(), 'lax' => array() );
	$rank  = array( 'none' => 0, 'report-only' => 1, 'enforced' => 2 );
	foreach ( sn_csp_hash_pages() as $url ) {
		$r = call_user_func( $fetch, $url );
		++$out['checked'];
		// A redirect or a challenge page is not the page: its scripts are not ours.
		if ( empty( $r['ok'] ) || 200 !== (int) ( $r['code'] ?? 0 ) ) {
			++$out['errors'];
			continue;
		}
		$lower  = array_change_key_case( (array) ( $r['headers'] ?? array() ), CASE_LOWER );
		$strict = sn_csp_strict( $lower );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		// 2026-10-20: X-SN-CSP: lax keeps a response on the loose policy at
		// Cloudflare (inc/csp-lax-marker.php); a stranger's page never carries it.
		if ( in_array( 'lax', array_map( 'strtolower', array_map( 'trim', (array) ( $lower['x-sn-csp'] ?? array() ) ) ), true ) ) {
			$out['lax'][] = $path;
		}
		$out['csp_seen'] = $out['csp_seen'] || $strict['csp_seen'];
		if ( $rank[ $strict['mode'] ] > $rank[ $out['mode'] ] ) {
			$out['mode']    = $strict['mode'];
			$out['sources'] = $strict['sources'];
		}
		$read[] = array( $path, $strict['mode'], sn_csp_inline_scripts( (string) $r['body'] ) );
	}
	// 2026-10-24: a page counts only under the run's own mode. An exempt path
	// (/contact/, /provhub at Cloudflare) enforces the loose policy and may
	// still carry the strict Report-Only copy; once any page enforces, that
	// page blocks nothing, so its scripts are not the strict policy's to pin.
	// Decided after the loop: the run's mode depends on every page.
	foreach ( $read as list( $path, $mode, $scripts ) ) {
		if ( 'none' === $mode || $mode !== $out['mode'] ) {
			continue;
		}
		++$out['covered'];
		foreach ( $scripts as $s ) {
			$out['seen'][ $s['hash'] ]['label']   = $s['label'];
			$out['seen'][ $s['hash'] ]['pages'][] = $path;
			$heads[ $s['hash'] ]                  = $s['head'];
		}
	}
	if ( 'none' !== $out['mode'] ) {
		$out['missing'] = sn_csp_missing( array_keys( $out['seen'] ), $out['sources'] );
		// Only an unlisted script keeps its first 60 characters, for the note.
		foreach ( $out['missing'] as $h ) {
			$out['seen'][ $h ]['head'] = $heads[ $h ];
		}
	}
	update_option( SN_CSP_HASH_OPT, $out, false );
	return $out;
}

/**
 * Read again soon after an update (2026-10-24). The strict policy pins core's
 * import map, which changes with every core update, and a plugin or theme
 * update can add an inline script; enforced, a stale hash stops every script
 * module, and the daily read alone could leave that up to a day. A core
 * version that changed without an update screen (the host updated core) is
 * caught by comparing the version this check last saw. One single event a few
 * minutes out: core drops a duplicate inside ten minutes of an identical one.
 *
 * @param string     $version The running core version.
 * @param int|null   $now     Unix time.
 * @return bool Whether a read was queued.
 */
function sn_csp_hash_queue_after_update( $version = '', $now = null ) {
	$now     = null === $now ? time() : (int) $now;
	$version = (string) $version;
	if ( '' !== $version ) {
		update_option( SN_CSP_HASH_VERSION_OPT, $version, true );
	}
	if ( false !== wp_next_scheduled( SN_CSP_HASH_HOOK, array( 'after_update' ) ) ) {
		return false;
	}
	return (bool) wp_schedule_single_event( $now + SN_CSP_HASH_AFTER_UPDATE, SN_CSP_HASH_HOOK, array( 'after_update' ) );
}

/**
 * The init-time version compare: queue a read when core's version is not the
 * one this check last saw. The first sighting only records it.
 *
 * @param string $version The running core version.
 * @return bool Whether a read was queued.
 */
function sn_csp_hash_version_changed( $version ) {
	$seen = (string) get_option( SN_CSP_HASH_VERSION_OPT, '' );
	if ( '' === $seen ) {
		update_option( SN_CSP_HASH_VERSION_OPT, (string) $version, true );
		return false;
	}
	return $seen !== (string) $version ? sn_csp_hash_queue_after_update( $version ) : false;
}

if ( ! defined( 'SN_CSP_HASH_TEST' ) || ! SN_CSP_HASH_TEST ) {
	add_action( SN_CSP_HASH_HOOK, 'sn_csp_hash_run', 10, 0 );
	add_action( 'upgrader_process_complete', static fn() => sn_csp_hash_queue_after_update( (string) get_bloginfo( 'version' ) ), 99, 0 );
	add_action( 'init', static fn() => sn_csp_hash_version_changed( (string) get_bloginfo( 'version' ) ), 20 );
	add_action(
		'init',
		static function () {
			if ( ! wp_next_scheduled( SN_CSP_HASH_HOOK ) ) {
				wp_schedule_event( time() + 25 * MINUTE_IN_SECONDS, 'daily', SN_CSP_HASH_HOOK );
			}
		}
	);
}
