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
 * cannot be verified and are not gated.
 */
const SN_SHARE_ECHO_VERIFIED_FAMILIES = array( 'meta', 'linkedin', 'slack', 'discord', 'x', 'telegram' );

/** How far back a visit looks for the share before it, seconds. */
const SN_SHARE_ECHO_WINDOW = 259200; // 72 hours

/** Fetches of one page by one app this close together are one share (preview services fetch two or three times). */
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
		$ts     = is_string( $r['observed_at'] ?? null ) ? strtotime( $r['observed_at'] ) : false;
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
		$rows[] = array( 'ts' => (int) $ts, 'family' => $family, 'path' => $path, 'key' => $key );
	}
	usort( $rows, static fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
	$out  = array();
	$last = array();
	foreach ( $rows as $r ) {
		$k = $r['key'] . '|' . $r['family'];
		if ( isset( $last[ $k ] ) && $r['ts'] - $last[ $k ] <= SN_SHARE_ECHO_COLLAPSE ) {
			$last[ $k ] = $r['ts'];
			continue;
		}
		$last[ $k ] = $r['ts'];
		$out[]      = $r;
	}
	return $out;
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
