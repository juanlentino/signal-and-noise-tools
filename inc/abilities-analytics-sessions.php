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

require_once __DIR__ . '/analytics-pairing.php'; // 2026-11-17: paired, a (direct) session near an automated hit.
require_once __DIR__ . '/analytics-contact-direct.php'; // 2026-11-17: contact_direct, direct to contact, nothing sent.
require_once __DIR__ . '/share-echoes-scan.php'; // 2026-11-17: link_scans, an app checking a link it was just sent.

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
	if ( $from > $today ) {
		return $bad( 'from is after today (' . $today . ', the site\'s time zone): no day in the window has happened yet.' );
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
 * The checked arguments, or why they are refused. sn-metrics asks before its dispatch, which
 * would fold a refusal into "unavailable".
 *
 * @param array $in The ability input.
 * @return array|WP_Error array( class, page, per_page, window ).
 */
function snt_analytics_sessions_check( array $in ) {
	$class = $in['class'] ?? 'human';
	$page  = $in['page'] ?? 1;
	$per   = $in['per_page'] ?? SN_ANALYTICS_SESSIONS_PER_PAGE;
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) || ! is_int( $page ) || $page < 1 || $page > 100000 || ! is_int( $per ) || $per < 1 || $per > SN_ANALYTICS_SESSIONS_MAX_PAGE ) {
		return new WP_Error( 'ability_invalid_input', 'class must be human, suspect or bot; page an integer from 1 to 100000; per_page an integer from 1 to ' . SN_ANALYTICS_SESSIONS_MAX_PAGE . '.', array( 'status' => 400 ) );
	}
	$w = snt_analytics_sessions_window( $in, sn_analytics_clock() ); // pinnable, so a test never straddles a day.
	return is_wp_error( $w ) ? $w : array( $class, $page, $per, $w );
}

/**
 * Execute: validate, read, page.
 *
 * @param mixed $input The ability input.
 * @return array|WP_Error
 */
function snt_ability_analytics_sessions( $input = null ) {
	$args = snt_analytics_sessions_check( is_array( $input ) ? $input : array() );
	if ( is_wp_error( $args ) ) {
		return $args;
	}
	list( $class, $page, $per, $w ) = $args;
	$read = sn_analytics_sessions_read( $w['lo'], $w['hi'], $class );
	if ( null === $read ) {
		return new WP_Error( 'sn_analytics_read_failed', 'The Analytics Engine read failed; this is not an empty answer.', array( 'status' => 500 ) );
	}
	// 2026-11-17: an app checking a coded link it was just sent is not a suspect visit (inc/share-echoes-scan.php).
	$scan         = sn_share_echo_scans_on_sessions( $read['rows'], $class );
	$read['rows'] = $scan['rows'];
	$total        = count( $read['rows'] );
	// 2026-11-17: the observe-only flags, paired and contact_direct, over the whole window for the per-day count.
	$flags = sn_analytics_session_flags( $read['rows'], $class, ( $page - 1 ) * $per, $per );
	return array(
		// 2026-11-14: each human session says which share it followed, if any (inc/share-echoes.php).
		'sessions' => array_map(
			static fn( $r, $p ) => $r + array( 'paired' => $p ),
			function_exists( 'sn_share_echoes_on_sessions' ) ? sn_share_echoes_on_sessions( $flags['slice'], $class ) : array_map( static fn( $r ) => $r + array( 'share' => null ), $flags['slice'] ),
			$flags['paired']
		),
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per,
		'pages'    => (int) ceil( $total / $per ),
		'from'     => $w['from'],
		'to'       => $w['to'],
		'hours'    => $w['hours'],
		'class'    => $class,
		'capped'   => $read['capped'],
		'flags_by_day' => $flags['by_day'],
		'link_scans'   => $scan['scans'],
	);
}

/** The output schema, shared by both registrations (the remote contract hashes it). PURE. */
function snt_analytics_sessions_output_schema() {
	$s    = array( 'type' => 'string' );
	$i    = array( 'type' => 'integer' );
	$ni   = array( 'type' => array( 'integer', 'null' ) );
	$b    = array( 'type' => 'boolean' );
	$page = array( 'type' => 'object', 'properties' => array( 'path' => $s, 'time_ms' => $ni, 'scroll_pct' => $ni, 'read' => $b ) );
	$share = array( 'type' => array( 'object', 'null' ), 'properties' => array( 'app' => $s, 'family' => $s, 'gap_s' => $i, 'read' => $b ) );
	$nb   = array( 'type' => array( 'boolean', 'null' ) );
	$day  = array( 'type' => 'object', 'properties' => array( 'day' => $s, 'sessions' => $i, 'direct' => $i, 'paired' => $ni, 'contact_direct' => $ni ) );
	$row  = array( 'start' => $s, 'end' => $s, 'duration_s' => $i, 'pageviews' => $i, 'pageviews_stored' => $i, 'pageviews_est' => $i, 'entry' => $s, 'exit' => $s, 'pages' => array( 'type' => 'array', 'items' => $page ), 'source' => $s, 'country' => $s, 'region' => $s, 'city' => $s, 'device' => $s, 'class' => $s, 'returning' => $b, 'link_code' => $s, 'engaged' => $b, 'goals' => array( 'type' => 'array', 'items' => $s ), 'contact_direct' => $nb, 'share' => $share, 'paired' => $nb );
	return array(
		'type'       => 'object',
		'properties' => array( 'sessions' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $row ) ), 'total' => $i, 'page' => $i, 'per_page' => $i, 'pages' => $i, 'from' => $s, 'to' => $s, 'hours' => $ni, 'class' => $s, 'capped' => $b, 'flags_by_day' => array( 'type' => array( 'array', 'null' ), 'items' => $day ), 'link_scans' => $ni ),
	);
}

/** The remote twin's gate: the remote door, for its own slug. */
function snt_ability_perm_remote_analytics_sessions() {
	return sn_remote_analytics_allows( 'signal-noise/remote-analytics-sessions' );
}

/** The description, shared. */
function snt_analytics_sessions_description() {
	return 'Sessions one by one, the unit of the S&N Analytics Sessions tab: a visit within one visitor-day (a day in America/New_York, where the visitor code rotates), split at the idle gap, with at least one page view. The sessions that START in the window, newest first, paged; each is read whole, so one still going at the window\'s end is complete, and one that began before it is not listed. '
		. 'Each: start and end (ISO 8601, site time), duration_s, pageviews, entry and exit, pages in order with time_ms and scroll_pct on each (null when not measured), source (the dashboard\'s source label of the first page view\'s referrer), country, region, city, device, class, returning (a second or later session of the same visitor-day, counting sessions before the window too; no visitor is recognized on another day), link_code (a coded résumé link, or ""), engaged and goals (custom event names; anything that is not an identifier-shaped name, letters, digits and _ . : -, reads (invalid)); the source is (direct), (internal), a known source label or a clean hostname, else (invalid). Place and device read (unknown) when not sent, as in the rows read. '
		. 'Arguments: from and to (YYYY-MM-DD, site time; default today) or hours (1 to ' . SN_ANALYTICS_SESSIONS_MAX_HOURS . ', the last N hours), class human|suspect|bot (default human), page (default 1), per_page (1 to ' . SN_ANALYTICS_SESSIONS_MAX_PAGE . ', default ' . SN_ANALYTICS_SESSIONS_PER_PAGE . '). Read from Analytics Engine: the last 90 days. capped: true means the raw read hit its cap, so the list is a floor. pageviews and pageviews_stored are the page-view rows Analytics Engine kept; pageviews_est is the views they stand for (the sum of its sample interval). When pageviews_est is above pageviews_stored the visitor-day was sampled: read it as "about pageviews_est, from pageviews_stored stored", and pages lists the kept views only. '
		. 'Each page view carries read (' . ( defined( 'SN_ANALYTICS_READS_DEFINITION' ) ? SN_ANALYTICS_READS_DEFINITION : 'proven reading' ) . '). Each human session carries share: the most recent link-preview fetch of its entry page (for /r/CODE, the same code) in the 72 hours before it, through the app its source names when it names one (Instagram, Facebook, Messenger and Threads are Meta), else through any app; joined by page, time and app only, never by a visitor identifier: app (the preview service: Meta, WhatsApp, LinkedIn, Slack, Discord, Telegram, X, Apple Messages or another app), family, gap_s (seconds from the share to the visit) and read (whether the entry view was a read); null when no share preceded it, for other classes, or when the share read failed. A preview fires when anyone shares the link, so this is after a share, never whose share, and it names no person. '
		. 'Each human session carries paired (observe only, nothing is subtracted): true when its source is (direct) and it overlaps in time with, or starts or ends within 60 seconds of, any bot- or suspect-class page view (an automated or data-center read), joined by time only, never by visitor code, place or network; false otherwise; null for other classes or when that read failed or hit its cap. Each human session also carries contact_direct (observe only): true when its source is (direct), its entry page is a contact page (/contact or /contact/...) or its second page is one after an entry view of under 10 seconds, and no contact form entry (AllTerrain Forms, inbox or spam) was created between its start and 60 seconds after its end, joined by time only; false otherwise; null for other classes, or for a candidate when the entries could not be read. flags_by_day counts, per site day of the session\'s start in the window, { day, sessions, direct, paired, contact_direct } over every human session in it, not only this page; a flag that could not be read is null on every day (null for other classes); ask from six days ago to today for the last 7 days. '
		. 'link_scans (class suspect only, null otherwise or when the shares could not be read): suspect sessions taken out of this list because an app was checking a coded link it had just been sent: one page view on /r/CODE starting within 60 seconds after that app\'s verified preview fetch of the same code (LinkedIn, 2026-11-17), joined by code and time only. The share itself is in the shares list; class totals elsewhere still count the visit. '
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
		'input_schema'        => array( 'type' => 'object', 'default' => array(), 'properties' => $props, 'additionalProperties' => false ),
		'permission_callback' => 'snt_ability_perm_manage_options',
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
	wp_register_ability( 'signal-noise/remote-analytics-sessions', $base + array(
		'label'               => 'Analytics sessions (remote)',
		'input_schema'        => array( 'type' => 'object', 'default' => array(), 'properties' => $props, 'additionalProperties' => false ),
		'permission_callback' => 'snt_ability_perm_remote_analytics_sessions',
		'meta'                => array( 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
} );
