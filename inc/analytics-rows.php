<?php
/**
 * Signal & Noise Tools: analytics rows, the pure half. Validates a query
 * (dimensions, range, class, filters, sort, limit) and turns stored rows into
 * the answer the `analytics-rows` ability and its remote twin return.
 *
 * WHY THE PATH ALLOWLIST AND THE HOSTNAME RULE. Paths and referrers are text a
 * stranger chooses: anyone can request /notes/x?ignore=previous or send a
 * crafted Referer. This answer lands in a model's context, so a stored path or
 * host is never passed through. A path is returned only when it is the site's
 * own content (a published post or page, a tag archive, the home page or a
 * known archive route); everything else collapses into "(unmatched)". A
 * referrer is returned only as a strict lowercase hostname or one of the three
 * sentinels the analytics worker writes; everything else is "(invalid)".
 * Removing either rule lets a visitor write into the reader. Do not simplify.
 *
 * THE ONE EXCEPTION (owner, 2026-10-29): on the local door only, path
 * "(unmatched)" breaks that bucket down, and each row names its path in
 * `visitor_path`, CLEANED first (inc/unmatched-paths-clean.php: lowercase
 * words, every email, id, hash, number or token a placeholder, nothing outside
 * [a-z0-9._~-], 200 characters at most). `path` itself still obeys the rule
 * above. The remote twin refuses the breakdown (inc/analytics-rows-unmatched.php,
 * the shared callback refuses it unless the local registration opts in; pinned
 * in tests/abilities-remote-set.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AROWS_DIMENSIONS = array( 'path', 'referrer', 'country', 'region', 'city', 'device', 'link_code', 'day', 'hour' ); // link_code: a coded résumé link's code, read out of the stored path (inc/link-codes.php).
const SNT_AROWS_STORED_PAGE = array( 'path', 'link_code', 'day' );                       // the daily table holds these.
const SNT_AROWS_STORED_DIMS = array( 'referrer', 'country', 'device', 'region', 'city' ); // the dims table, one per row and day.
const SNT_AROWS_MAX_DIMS   = 4; // 2026-11-01: up to four at once (owner brief "MCP full analytics access").
const SNT_AROWS_SOURCES    = array( 'auto', 'stored', 'events' ); // auto: the stored rollups when they hold the combination, else Analytics Engine.
const SNT_AROWS_SORTS      = array( 'views', 'visits', 'time' );
const SNT_AROWS_SENTINELS  = array( '(direct)', '(internal)', '(unknown)' ); // written by the analytics worker, not by visitors.

/**
 * A query, refused or normalized. PURE.
 *
 * @param mixed $input           The ability input.
 * @param bool  $allow_unmatched The "(unmatched)" breakdown: only the local door passes true; every other caller, the remote twin's included, is refused (fails closed).
 * @return array|WP_Error {dims:string[], range:int|string, class:string, path:?string, referrer:?string, sort:string, limit:int, unmatched:bool}
 */
function snt_arows_validate( $input, $allow_unmatched = false ) {
	$in   = is_array( $input ) ? $input : array();
	$bad  = static fn( $m ) => new WP_Error( 'ability_invalid_input', $m, array( 'status' => 400 ) );
	if ( ! $allow_unmatched && '(unmatched)' === ( $in['path'] ?? null ) ) {
		return $bad( 'path "(unmatched)" is served on the local door only.' );
	}
	$dims = $in['dimensions'] ?? null;
	if ( ! is_array( $dims ) || ! $dims || count( $dims ) > SNT_AROWS_MAX_DIMS || count( array_filter( $dims, 'is_string' ) ) !== count( $dims ) || array_diff( $dims, SNT_AROWS_DIMENSIONS ) || count( array_unique( $dims ) ) !== count( $dims ) ) {
		return $bad( 'dimensions must be one to four different values of ' . implode( ', ', SNT_AROWS_DIMENSIONS ) . '.' );
	}
	$dims  = array_values( $dims );
	$other = array_values( array_diff( $dims, array( 'day' ) ) );
	// A window is a range ending today, or from and to in the site's days, never both.
	$from = $in['from'] ?? null;
	$to   = $in['to'] ?? null;
	if ( null !== $from || null !== $to ) {
		if ( isset( $in['range'] ) ) {
			return $bad( 'give a range or from and to, not both.' );
		}
		if ( ! snt_analytics_is_ymd( $from ) || ! snt_analytics_is_ymd( $to ) || $from > $to ) {
			return $bad( 'from and to must both be days (YYYY-MM-DD, the site\'s time zone), from no later than to.' );
		}
		$range = 'custom';
	} else {
		$range = $in['range'] ?? 30;
		if ( ! snt_analytics_range_is_valid( $range ) ) {
			return $bad( 'range must be one of 7, 14, 30, 90, 365 or "all", or give from and to.' );
		}
		$range = 'all' === (string) $range ? 'all' : (int) $range;
	}
	$class = $in['class'] ?? 'human';
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		return $bad( 'class must be one of human, suspect, bot.' );
	}
	$source = $in['source'] ?? 'auto';
	if ( ! in_array( $source, SNT_AROWS_SOURCES, true ) ) {
		return $bad( 'source must be auto, stored or events.' );
	}
	$sort = $in['sort'] ?? 'views';
	// Time is measured per page: it means something when the rows are pages.
	if ( ! in_array( $sort, SNT_AROWS_SORTS, true ) || ( 'time' === $sort && ! array_intersect( $dims, array( 'path', 'link_code' ) ) && array( 'day' ) !== $dims ) ) {
		return $bad( 'sort must be views, visits or time; time only when path or link_code is a dimension, or day alone (time is measured per page).' );
	}
	$limit = $in['limit'] ?? 50;
	if ( ! is_int( $limit ) || $limit < 1 || $limit > 500 ) {
		return $bad( 'limit must be an integer from 1 to 500.' );
	}
	$path      = null;
	$unmatched = false;
	if ( '(unmatched)' === ( $in['path'] ?? null ) ) {
		// 2026-10-29: break the bucket down by cleaned path (inc/analytics-rows-unmatched.php).
		if ( ! in_array( 'path', $dims, true ) || array_diff( $dims, array( 'path', 'day' ) ) || 'events' === $source ) {
			return $bad( 'path "(unmatched)" breaks the stored bucket down by path, so dimensions must be path, or path and day, from the stored rows.' );
		}
		$unmatched = true;
	} elseif ( isset( $in['path'] ) ) {
		$path = snt_arows_input_path( $in['path'] );
		if ( null === $path ) {
			return $bad( 'path must be a site path starting with "/".' );
		}
	}
	$referrer = null;
	if ( isset( $in['referrer'] ) ) {
		$referrer = snt_arows_host( $in['referrer'] );
		if ( '(invalid)' === $referrer || $unmatched ) {
			return $bad( 'referrer must be a hostname, and not with the "(unmatched)" breakdown.' );
		}
	}
	return array( 'dims' => $dims, 'range' => $range, 'from' => $from, 'to' => $to, 'source' => $source, 'class' => $class, 'path' => $path, 'referrer' => $referrer, 'sort' => $sort, 'limit' => $limit, 'unmatched' => $unmatched, 'other' => $other );
}

/** A caller's path filter: query and fragment stripped, canonical, or null. PURE. */
function snt_arows_input_path( $raw ) {
	if ( ! is_string( $raw ) || strlen( $raw ) > 300 || '/' !== ( $raw[0] ?? '' ) || preg_match( '/[\x00-\x20<>"\\\\]/', $raw ) ) {
		return null;
	}
	return sn_analytics_canonical_path( (string) strtok( strtok( $raw, '?' ), '#' ) );
}

/** A stored or caller referrer as a strict lowercase hostname, a worker sentinel, or "(invalid)". PURE. */
function snt_arows_host( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return '(invalid)';
	}
	$h = strtolower( trim( (string) $raw ) );
	if ( '' === $h ) {
		return '(direct)';
	}
	if ( in_array( $h, SNT_AROWS_SENTINELS, true ) ) {
		return $h;
	}
	$h = preg_replace( '/^www\./', '', $h );
	$ok = strlen( $h ) <= 253 && preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $h );
	return $ok ? $h : '(invalid)';
}

/** A stored dimension value made safe to return. PURE. */
function snt_arows_clean( $dim, $value, array $real ) {
	$v = (string) $value;
	if ( 'path' === $dim ) {
		$p = sn_analytics_canonical_path( (string) strtok( strtok( $v, '?' ), '#' ) );
		// A coded résumé link is served content (inc/link-codes.php), not a visitor's guess.
		return ( function_exists( 'sn_path_is_served' ) ? sn_path_is_served( $p, $real ) : isset( $real[ $p ] ) ) ? $p : '(unmatched)';
	}
	if ( 'link_code' === $dim ) {
		$code = function_exists( 'sn_link_code_from_path' ) ? sn_link_code_from_path( (string) strtok( strtok( $v, '?' ), '#' ) ) : '';
		return '' === $code ? '(invalid)' : $code;
	}
	if ( 'referrer' === $dim ) {
		return snt_arows_host( $v );
	}
	if ( 'city' === $dim || 'region' === $dim ) {
		// Cloudflare's place names, as stored: printable text only, at most 128 characters.
		$t = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $v ) );
		return '' === $t ? '(unknown)' : ( mb_strlen( $t ) > 128 ? '(invalid)' : $t );
	}
	if ( 'hour' === $dim ) {
		return preg_match( '/^([01]\d|2[0-3])$/', $v ) ? $v : '(invalid)';
	}
	if ( 'country' === $dim ) {
		return preg_match( '/^[A-Z0-9]{2}$/', $v ) ? $v : '(invalid)';
	}
	if ( 'device' === $dim ) {
		return preg_match( '/^[a-z]{1,20}$/', $v ) ? $v : '(invalid)';
	}
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '(invalid)';
}

/**
 * Stored rows into the answer. PURE. Each input row carries the dimension
 * values by name plus views, visits, pageview_visits, time_sum, time_events,
 * scroll_events (the engagement sums null where not stored). Rows are cleaned,
 * merged after cleaning, sorted and cut to the limit. No floor (owner,
 * 2026-10-23): a value seen once is named; the public /stats page keeps its own.
 *
 * @param array<int,array<string,mixed>> $rows  Stored rows.
 * @param array                          $q     From snt_arows_validate().
 * @param array<string,bool>             $real  Canonical paths that are site content.
 * @return array{rows:array,row_count:int,truncated:bool}
 */
function snt_arows_shape( array $rows, array $q, array $real ) {
	$keyed = array();
	foreach ( $rows as $r ) {
		$keys = array();
		foreach ( $q['dims'] as $d ) {
			$keys[ $d ] = snt_arows_clean( $d, $r[ $d ] ?? '', $real );
		}
		$id = implode( "\n", $keys );
		$m  = $keyed[ $id ] ?? array( 'keys' => $keys, 'views' => 0, 'visits' => 0, 'pv' => null, 'ts' => null, 'te' => null, 'se' => null );
		$m['views']  += (int) ( $r['views'] ?? 0 );
		$m['visits'] += (int) ( $r['visits'] ?? 0 );
		foreach ( array( 'pv' => 'pageview_visits', 'ts' => 'time_sum', 'te' => 'time_events', 'se' => 'scroll_events' ) as $k => $col ) {
			if ( isset( $r[ $col ] ) ) {
				$m[ $k ] = ( $m[ $k ] ?? 0 ) + (float) $r[ $col ];
			}
		}
		$keyed[ $id ] = $m;
	}
	$rows = array_map( static function ( $m ) {
		$v = (int) $m['views'];
		return $m['keys'] + array(
			'views'               => $v,
			'pageview_visits'     => null === $m['pv'] ? (int) $m['visits'] : (int) $m['pv'],
			'time_avg_per_view'   => null !== $m['ts'] && null !== $m['te'] && $v > 0 ? round( $m['ts'] / $v, 1 ) : null,
			'scroll_avg_per_view' => null !== $m['se'] && $v > 0 ? round( 25 * $m['se'] / $v, 1 ) : null,
		);
	}, array_values( $keyed ) );
	$by = array( 'views' => 'views', 'visits' => 'pageview_visits', 'time' => 'time_avg_per_view' )[ $q['sort'] ];
	usort( $rows, static fn( $a, $b ) => ( (float) ( $b[ $by ] ?? 0 ) <=> (float) ( $a[ $by ] ?? 0 ) ) ?: strcmp( implode( "\n", array_intersect_key( $a, array_flip( $q['dims'] ) ) ), implode( "\n", array_intersect_key( $b, array_flip( $q['dims'] ) ) ) ) );
	$n = count( $rows );
	return array( 'rows' => array_slice( $rows, 0, $q['limit'] ), 'row_count' => min( $n, $q['limit'] ), 'truncated' => $n > $q['limit'] );
}
