<?php
/**
 * Share echoes (owner decision 2026-11-14). When a link to the site is pasted
 * into WhatsApp, Messenger, LinkedIn, Slack, Discord or similar, that app
 * fetches the page within seconds to build a preview. The rights-signals
 * worker (1.39.0) records each such fetch: time, path and the app's preview
 * family, nothing else. Here each human visit is joined to the most recent
 * echo on the same page (for /r/CODE, the same code) in the 72 hours before
 * it, by page and time only, never by any visitor identifier.
 *
 * An echo fires when ANYONE shares the link, and a public post draws echoes
 * from many people, so the copy says "after a share", never "from your
 * share", and names the app, never a person.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/share-echoes-store.php';
require_once __DIR__ . '/share-echoes-list.php';

/** Preview family => the app's name. The worker's fixed list (src/share-echoes.mjs). */
const SN_SHARE_ECHO_FAMILIES = array(
	'meta'           => 'Meta',
	'whatsapp'       => 'WhatsApp',
	'linkedin'       => 'LinkedIn',
	'slack'          => 'Slack',
	'discord'        => 'Discord',
	'telegram'       => 'Telegram',
	'x'              => 'X',
	'apple-messages' => 'Apple Messages',
	'other-preview'  => 'another app',
);

/**
 * Apps that fetch previews from their own servers, which Cloudflare verifies
 * when genuine: an unverified row claiming one is not a share (14 days to
 * 2026-11-14: 20 of 20 Discordbot reads came from Google's cloud). WhatsApp
 * and Apple Messages build the preview on the sender's own phone, so they
 * cannot be verified and are not gated; nor are Telegram (no verified listing
 * could be confirmed) or the other apps (Signal and Bluesky fetch from the
 * sender's side or a proxy).
 */
const SN_SHARE_ECHO_VERIFIED_FAMILIES = array( 'meta', 'linkedin', 'slack', 'discord', 'x' );

/** How far back a visit looks for the share before it, seconds. */
const SN_SHARE_ECHO_WINDOW = 259200; // 72 hours

/**
 * Fetches by one app, each this close to the one before, are ONE share,
 * credited to the last page fetched (2026-11-14 owner test). Telegram and
 * WhatsApp fetch a preview as the link is typed: /, /r/te, /r/tel ... /r/prueba
 * in 16 seconds; the last one is the link that was sent. Preview services also
 * fetch the same page two or three times. ponytail: two people sharing through
 * the same app within a minute of each other count once; fine at this volume.
 */
const SN_SHARE_ECHO_COLLAPSE = 60;

/**
 * The join key of a page: 'r:CODE' for a coded link (/r/x and /r/x/ alike),
 * else the path with its trailing slash. PURE.
 *
 * @param string $path A path, or a session's cleaned entry path.
 * @return string '' when it is not a page.
 */
function sn_share_echo_key( $path ) {
	$path = (string) $path;
	if ( '' === $path || '/' !== $path[0] ) {
		return '';
	}
	$code = function_exists( 'sn_link_code_from_path' ) ? sn_link_code_from_path( $path ) : '';
	if ( '' !== (string) $code ) {
		return 'r:' . $code;
	}
	return '/' === substr( $path, -1 ) || false !== strpos( basename( $path ), '.' ) ? $path : $path . '/';
}

/**
 * The worker's rows, validated field by field (untrusted), oldest first, with
 * repeat fetches collapsed. PURE.
 *
 * @param array $raw snt_mr_fetch( $days, 'echoes' )['rows'].
 * @return array<int,array{ts:int,family:string,path:string,key:string}>
 */
function sn_share_echoes_rows( array $raw ) {
	$rows = array();
	foreach ( $raw as $r ) {
		$r      = (array) $r;
		$family = (string) ( $r['family'] ?? '' );
		$path   = (string) ( $r['path'] ?? '' );
		// The worker's ISO time only (toISOString()): date_create would also read
		// "", "now" or "tomorrow" as a time. Milliseconds kept: a typed burst lands within one second.
		$iso    = is_string( $r['observed_at'] ?? null ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/D', $r['observed_at'] );
		$at     = $iso ? date_create_immutable( $r['observed_at'] ) : false;
		$ts     = false === $at ? false : (int) $at->format( 'U' );
		if ( ! isset( SN_SHARE_ECHO_FAMILIES[ $family ] ) || 1 !== preg_match( '#^/[^\s<>"\'\\\\]{0,511}$#', $path ) || false === $ts ) {
			continue;
		}
		$key = sn_share_echo_key( $path );
		if ( in_array( $family, SN_SHARE_ECHO_VERIFIED_FAMILIES, true ) && '1' !== (string) ( $r['verified'] ?? '' ) ) {
			continue;
		}
		// Meta's fetcher also crawls on its own schedule (about 300 page fetches a
		// day here, 2026-11), so a Meta fetch is a share only on an /r/ link,
		// which nothing finds without being sent it.
		if ( 'meta' === $family && 0 !== strpos( $key, 'r:' ) ) {
			continue;
		}
		$rows[] = array( 'ts' => (int) $ts, 'ms' => (float) $at->format( 'U.u' ), 'family' => $family, 'path' => $path, 'key' => $key );
	}
	usort( $rows, static fn( $a, $b ) => $a['ms'] <=> $b['ms'] );
	// One share per burst per app: a later fetch within the window replaces the
	// burst's share with itself, so the last page fetched is the one kept.
	$out  = array();
	$open = array(); // family => index in $out of its burst's share, and when its last fetch was.
	foreach ( $rows as $r ) {
		$f = $r['family'];
		if ( isset( $open[ $f ] ) && $r['ms'] - $open[ $f ]['ms'] <= SN_SHARE_ECHO_COLLAPSE ) {
			$out[ $open[ $f ]['i'] ] = $r;
			$open[ $f ]['ms']        = $r['ms'];
			continue;
		}
		$open[ $f ] = array( 'i' => count( $out ), 'ms' => $r['ms'] );
		$out[]      = $r;
	}
	return array_map( static fn( $r ) => array_diff_key( $r, array( 'ms' => 1 ) ), $out );
}

/**
 * The share a visit followed: the latest echo on its page in the 72 hours
 * before it, or null. PURE.
 *
 * @param array  $echoes sn_share_echoes_rows() rows, oldest first.
 * @param string $key    The visit's page key.
 * @param int    $start  The visit's start, Unix time.
 * @return array{family:string,app:string,at:int,gap_s:int}|null
 */
function sn_share_echo_for( array $echoes, $key, $start ) {
	$hit = null;
	foreach ( $echoes as $e ) {
		if ( $e['ts'] > $start ) {
			break;
		}
		if ( $e['key'] === $key && '' !== $key && $start - $e['ts'] <= SN_SHARE_ECHO_WINDOW ) {
			$hit = $e;
		}
	}
	return null === $hit ? null : array( 'family' => $hit['family'], 'app' => SN_SHARE_ECHO_FAMILIES[ $hit['family'] ], 'at' => $hit['ts'], 'gap_s' => $start - $hit['ts'] );
}

/** A session's page key: its code, else its entry page. PURE. */
function sn_share_echo_session_key( array $s ) {
	return '' !== (string) ( $s['link_code'] ?? '' ) ? 'r:' . $s['link_code'] : sn_share_echo_key( (string) ( $s['entry'] ?? '' ) );
}

/** Whether a session's entry view was a read (inc/analytics-reads.php). PURE. */
function sn_share_echo_session_read( array $s ) {
	return ! empty( $s['pages'][0]['read'] );
}

/**
 * Each session with `share`: the share it followed (app, gap, whether the
 * visit was a read), or null. PURE.
 *
 * @param array $sessions Session rows (inc/analytics-sessions-list.php).
 * @param array $echoes   sn_share_echoes_rows() rows.
 * @return array
 */
function sn_share_echo_annotate( array $sessions, array $echoes ) {
	return array_map( static function ( $s ) use ( $echoes ) {
		$hit        = sn_share_echo_for( $echoes, sn_share_echo_session_key( $s ), (int) strtotime( (string) ( $s['start'] ?? '' ) ) );
		$s['share'] = null === $hit ? null : array( 'app' => $hit['app'], 'family' => $hit['family'], 'gap_s' => $hit['gap_s'], 'read' => sn_share_echo_session_read( $s ) );
		return $s;
	}, $sessions );
}
