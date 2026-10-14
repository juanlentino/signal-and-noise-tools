<?php
/**
 * Signal & Noise app, the Attention composition: the clock, the stamps and the kinds.
 *
 * A pure move from parts/attention.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Now, in unix seconds.
 *
 * `$GLOBALS['__now']` is honoured so a fixture can hold the clock still: the
 * cache's own 60-second window is the thing under test, and a suite that has
 * to sleep for a minute to reach the second branch is a suite nobody runs.
 * Nothing in the plugin ever writes it.
 *
 * @return int
 */
function attention_now() {
	if ( isset( $GLOBALS['__now'] ) ) {
		return (int) $GLOBALS['__now'];
	}
	return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
}

/**
 * Any stored instant as one stamp: UTC 'Y-m-d H:i:s', or '' when there is none.
 *
 * The estate stamps in three shapes — unix ints (the sweep, the scan, the
 * probe log, the snapshot), MySQL UTC strings (the citations table, the
 * schedule queue) and ISO 8601 (a commit's `committed_at`). A queue that
 * printed each in its own shape would make two rows look like two different
 * kinds of fact. A bare MySQL string is read as UTC, which is what every
 * producer here writes.
 *
 * THIS IS A KEY, NOT A LABEL. Acknowledgements are held against it, rows
 * sort by it, and the composer runs it over readers' own output, so it must
 * be idempotent: UTC in, the same UTC out. 14.7.4 tried to make it print in
 * the site timezone and every re-application shifted the instant another
 * four hours (three passes read midnight). The reading lives in
 * attention_local() at the print sites; storage and identity stay UTC.
 *
 * @param mixed $when A unix int, a MySQL UTC string, or an ISO 8601 string.
 * @return string 'Y-m-d H:i:s' in UTC, or '' when unreadable or absent.
 */
function attention_stamp( $when ) {
	$ts = attention_stamp_ts( $when );
	return $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
}

/**
 * The unix instant behind a stored stamp, or 0.
 *
 * @param mixed $when
 * @return int
 */
function attention_stamp_ts( $when ) {
	if ( is_int( $when ) || is_float( $when ) || ( is_string( $when ) && ctype_digit( $when ) ) ) {
		return max( 0, (int) $when );
	}
	$when = trim( (string) $when );
	if ( '' === $when ) {
		return 0;
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $when ) ) {
		$when .= ' UTC';
	}
	$ts = strtotime( $when );
	return ( false === $ts || $ts <= 0 ) ? 0 : (int) $ts;
}

/**
 * A stamp as the SITE reads it: 'Y-m-d H:i:s' in the timezone from
 * Settings › General, followed by the zone's abbreviation ("EDT"), or ''
 * for an empty stamp.
 *
 * 14.7.4: the owner reads the queue in Eastern time; a stamp that said
 * "08:00 UTC" beside a clock reading 4:00 AM made every reading look four
 * hours off. Storage stays UTC (attention_stamp()); every print site goes
 * through here. Without WordPress the reading is UTC and says so.
 *
 * @param string $stamp attention_stamp() output.
 * @return string
 */
function attention_local( $stamp ) {
	$ts = attention_stamp_ts( $stamp );
	if ( $ts <= 0 ) {
		return '';
	}
	if ( ! function_exists( 'wp_date' ) ) {
		return gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC';
	}
	$abbr = (string) wp_date( 'T', $ts ); // resolved for THAT instant, so DST is right
	return (string) wp_date( 'Y-m-d H:i:s', $ts ) . ' ' . ( '' !== $abbr ? $abbr : 'UTC' );
}

/**
 * A stamp as a date label. An unstamped row SAYS it is unstamped.
 *
 * @param string $stamp attention_stamp() output.
 * @return string
 */
function attention_asof( $stamp ) {
	$stamp = (string) $stamp;
	if ( '' === $stamp ) {
		return __( 'not stamped', 'signal-and-noise-tools' );
	}
	/* translators: %s: a timestamp in the site timezone with its zone, e.g. "2026-09-14 04:00:28 EDT". */
	return sprintf( __( 'as of %s', 'signal-and-noise-tools' ), attention_local( $stamp ) );
}

/**
 * The kinds, in the order the pills offer them. A kind is a row's `status`,
 * so the section's pills and a row's statusLabel read from ONE list.
 *
 * @return array<string,string> kind => label.
 */
function attention_kinds() {
	return array(
		'integrity' => __( 'Integrity', 'signal-and-noise-tools' ),
		'anchors'   => __( 'Anchors', 'signal-and-noise-tools' ),
		'edge'      => __( 'Edge', 'signal-and-noise-tools' ),
		'citations' => __( 'Citations', 'signal-and-noise-tools' ),
		'schedule'  => __( 'Scheduled', 'signal-and-noise-tools' ),
		'pending'   => __( 'Pending review', 'signal-and-noise-tools' ),
		'health'    => __( 'Health', 'signal-and-noise-tools' ),
		'watches'   => __( 'Watches', 'signal-and-noise-tools' ),
		'readers'   => __( 'Machine readers', 'signal-and-noise-tools' ),
		'search'    => __( 'Search', 'signal-and-noise-tools' ),
		'live'      => __( 'Live traffic', 'signal-and-noise-tools' ),
	);
}

/**
 * One kind's label; an unknown kind reads as itself rather than as blank.
 *
 * @param string $kind A key of attention_kinds().
 * @return string
 */
function attention_kind_label( $kind ) {
	$kinds = attention_kinds();
	return isset( $kinds[ (string) $kind ] ) ? $kinds[ (string) $kind ] : (string) $kind;
}

/**
 * One kind's tile icon.
 *
 * @param string $kind A key of attention_kinds().
 * @return string Dashicons class.
 */
function attention_kind_icon( $kind ) {
	$icons = array(
		'integrity' => 'dashicons-shield',
		'anchors'   => 'dashicons-shield-alt',
		'edge'      => 'dashicons-cloud',
		'citations' => 'dashicons-admin-links',
		'schedule'  => 'dashicons-clock',
		'pending'   => 'dashicons-edit-page',
		'health'    => 'dashicons-heart',
		'watches'   => 'dashicons-visibility',
		'readers'   => 'dashicons-rest-api',
		'search'    => 'dashicons-search',
		'live'      => 'dashicons-chart-line',
	);
	return isset( $icons[ (string) $kind ] ) ? $icons[ (string) $kind ] : 'dashicons-flag';
}

