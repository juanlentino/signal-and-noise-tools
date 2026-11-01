<?php
/**
 * Signal & Noise Tools: the sessions read, on both MCP doors (owner brief
 * "MCP full analytics access", 2026-11-01). `signal-noise/analytics-sessions`
 * (local, as sn-metrics{analytics_sessions}) and its remote twin
 * `signal-noise/remote-analytics-sessions` page through the rows
 * inc/analytics-sessions-list.php builds, the rows the Sessions tab lists.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SESSIONS_MAX_HOURS = 72;

/** The first day Analytics Engine holds, in site time: today less 89 days. PURE given the clock. */
function snt_analytics_sessions_floor( $now ) {
	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	return ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->modify( '-' . ( SNT_AROWS_EVENTS_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
}

/**
 * The window: a day range in site time, or the last N hours; today when neither. PURE given the clock.
 *
 * @param array $in  The ability input.
 * @param int   $now Unix time.
 * @return array{lo:string,hi:string,since:int,from:string,to:string,hours:?int}|WP_Error
 */
function snt_analytics_sessions_window( array $in, $now ) {
	$bad   = static fn( $m ) => new WP_Error( 'ability_invalid_input', $m, array( 'status' => 400 ) );
	$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
	$hours = $in['hours'] ?? null;
	if ( null !== $hours ) {
		if ( isset( $in['from'] ) || isset( $in['to'] ) || ! is_int( $hours ) || $hours < 1 || $hours > SN_ANALYTICS_SESSIONS_MAX_HOURS ) {
			return $bad( 'hours must be an integer from 1 to ' . SN_ANALYTICS_SESSIONS_MAX_HOURS . ', and not with from and to.' );
		}
		$since = (int) $now - $hours * 3600;
		return array( 'lo' => gmdate( 'Y-m-d H:i:s', $since ), 'hi' => gmdate( 'Y-m-d H:i:s', (int) $now + 1 ), 'since' => $since, 'from' => ( new DateTimeImmutable( '@' . $since ) )->setTimezone( $tz )->format( 'Y-m-d' ), 'to' => $today, 'hours' => $hours );
	}
	$from = $in['from'] ?? $today;
	$to   = $in['to'] ?? $from;
	if ( ! snt_analytics_is_ymd( $from ) || ! snt_analytics_is_ymd( $to ) || $from > $to ) {
		return $bad( 'from and to must be days (YYYY-MM-DD, the site\'s time zone), from no later than to; or give hours.' );
	}
	$to    = min( $to, $today );
	$floor = snt_analytics_sessions_floor( $now );
	if ( $from < $floor ) {
		return $bad( sprintf( 'sessions are read from Analytics Engine, which keeps about %d days: the window must start on %s or later.', SNT_AROWS_EVENTS_DAYS, $floor ) );
	}
	list( $lo, $hi ) = snt_arows_utc_bounds( $from, $to );
	return array( 'lo' => $lo, 'hi' => $hi, 'since' => 0, 'from' => $from, 'to' => $to, 'hours' => null );
}

/**
 * Execute: validate, read, page.
 *
 * @param mixed $input The ability input.
 * @return array|WP_Error
 */
function snt_ability_analytics_sessions( $input = null ) {
	$in    = is_array( $input ) ? $input : array();
	$class = $in['class'] ?? 'human';
	$page  = $in['page'] ?? 1;
	$per   = $in['per_page'] ?? SN_ANALYTICS_SESSIONS_PER_PAGE;
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) || ! is_int( $page ) || $page < 1 || $page > 100000 || ! is_int( $per ) || $per < 1 || $per > SN_ANALYTICS_SESSIONS_MAX_PAGE ) {
		return new WP_Error( 'ability_invalid_input', 'class must be human, suspect or bot; page an integer from 1 to 100000; per_page an integer from 1 to ' . SN_ANALYTICS_SESSIONS_MAX_PAGE . '.', array( 'status' => 400 ) );
	}
	$w = snt_analytics_sessions_window( $in, time() );
	if ( is_wp_error( $w ) ) {
		return $w;
	}
	$read = sn_analytics_sessions_read( $w['lo'], $w['hi'], $class );
	if ( null === $read ) {
		return new WP_Error( 'sn_analytics_read_failed', 'The Analytics Engine read failed; this is not an empty answer.', array( 'status' => 500 ) );
	}
	$total = count( $read['rows'] );
	return array(
		'sessions' => array_slice( $read['rows'], ( $page - 1 ) * $per, $per ),
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per,
		'pages'    => (int) ceil( $total / $per ),
		'from'     => $w['from'],
		'to'       => $w['to'],
		'hours'    => $w['hours'],
		'class'    => $class,
		'capped'   => $read['capped'],
	);
}

/** The output schema, shared by both registrations (the remote contract hashes it). PURE. */
function snt_analytics_sessions_output_schema() {
	$s    = array( 'type' => 'string' );
	$i    = array( 'type' => 'integer' );
	$ni   = array( 'type' => array( 'integer', 'null' ) );
	$b    = array( 'type' => 'boolean' );
	$page = array( 'type' => 'object', 'properties' => array( 'path' => $s, 'time_ms' => $ni, 'scroll_pct' => $ni ) );
	$row  = array( 'start' => $s, 'end' => $s, 'duration_s' => $i, 'pageviews' => $i, 'entry' => $s, 'exit' => $s, 'pages' => array( 'type' => 'array', 'items' => $page ), 'source' => $s, 'country' => $s, 'region' => $s, 'city' => $s, 'device' => $s, 'class' => $s, 'returning' => $b, 'link_code' => $s, 'engaged' => $b, 'goals' => array( 'type' => 'array', 'items' => $s ) );
	return array(
		'type'       => 'object',
		'properties' => array( 'sessions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $row ) ), 'total' => $i, 'page' => $i, 'per_page' => $i, 'pages' => $i, 'from' => $s, 'to' => $s, 'hours' => $ni, 'class' => $s, 'capped' => $b ),
	);
}

/** The remote twin's gate: the remote door, for its own slug. */
function snt_ability_perm_remote_analytics_sessions() {
	return sn_remote_analytics_allows( 'signal-noise/remote-analytics-sessions' );
}

/** The description, shared. */
function snt_analytics_sessions_description() {
	return 'Sessions one by one, the unit of the S&N Analytics Sessions tab: a visit within one visitor-day (a UTC day), split at the idle gap, with at least one page view. The sessions that START in the window, newest first, paged; each is read whole, so one still going at the window\'s end is complete, and one that began before it is not listed. '
		. 'Each: start and end (ISO 8601, site time), duration_s, pageviews, entry and exit, pages in order with time_ms and scroll_pct on each (null when not measured), source (the dashboard\'s source label of the first page view\'s referrer), country, region, city, device, class, returning (a second or later session of the same visitor-day, counting sessions before the window too; no visitor is recognized on another day), link_code (a coded résumé link, or ""), engaged and goals (custom event names; anything that is not a short printable name reads (invalid)). Place and device read (unknown) when not sent, as in the rows read. '
		. 'Arguments: from and to (YYYY-MM-DD, site time; default today) or hours (1 to ' . SN_ANALYTICS_SESSIONS_MAX_HOURS . ', the last N hours), class human|suspect|bot (default human), page (default 1), per_page (1 to ' . SN_ANALYTICS_SESSIONS_MAX_PAGE . ', default ' . SN_ANALYTICS_SESSIONS_PER_PAGE . '). Read from Analytics Engine: the last 90 days. capped: true means the raw read hit its cap, so the list is a floor. '
		. 'Paths are the site\'s own content or (unmatched). No visitor code, IP address or user agent is returned. Read-only.';
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$base = array(
		'category'         => 'analytics',
		'execute_callback' => 'snt_ability_analytics_sessions',
		'output_schema'    => snt_analytics_sessions_output_schema(),
		'description'      => snt_analytics_sessions_description(),
	);
	$props = array( 'from' => array( 'type' => 'string' ), 'to' => array( 'type' => 'string' ), 'hours' => array( 'type' => 'integer' ), 'class' => array( 'type' => 'string', 'default' => 'human' ), 'page' => array( 'type' => 'integer', 'default' => 1 ), 'per_page' => array( 'type' => 'integer', 'default' => SN_ANALYTICS_SESSIONS_PER_PAGE ) );
	wp_register_ability( 'signal-noise/analytics-sessions', $base + array(
		'label'               => 'Analytics sessions',
		// Written out in both registrations for the static readers (tests/abilities-categories.php).
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => $props, 'additionalProperties' => false ),
		'permission_callback' => 'snt_ability_perm_manage_options',
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
	wp_register_ability( 'signal-noise/remote-analytics-sessions', $base + array(
		'label'               => 'Analytics sessions (remote)',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => $props, 'additionalProperties' => false ),
		'permission_callback' => 'snt_ability_perm_remote_analytics_sessions',
		'meta'                => array( 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
} );
