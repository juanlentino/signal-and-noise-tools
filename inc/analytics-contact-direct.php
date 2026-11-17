<?php
/**
 * Direct to contact, nothing sent: observe only (owner brief 2026-11-17).
 * A human session whose source is (direct) and whose entry page is a contact
 * page (/contact or /contact/...), or whose second page is one after an entry
 * view of under 10 seconds, and in which no contact form was submitted, gets
 * `contact_direct: true`. Real case: Niigata, 2026-11-16 22:45, entered / for
 * 3.1 seconds, then /contact/personal, nothing sent.
 *
 * The contact form sends no beacon event (its theme goals, contact-<alias>,
 * are clicks on an email link, not a submission), so a submission is an
 * AllTerrain Forms entry, in the inbox or in spam, created in the session's
 * time: joined by time only, never by visitor code or the entry's content.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_CONTACT_ENTRY_SEC = 10;  // an entry view shorter than this leads straight on.
const SN_ANALYTICS_CONTACT_SLACK     = 60;  // a submission's entry can land a little after the last beacon.
const SN_ANALYTICS_CONTACT_MAX       = 500; // entries read per window; a full read is not read.

/**
 * Whether a session went straight to a contact page. PURE.
 *
 * @param array  $views  Its page views in order, each array{0:string canonical path, 1:int unix time}.
 * @param string $source Its canonical source.
 * @param ?int   $entry_ms The entry page's measured time on page, ms; null when not measured.
 * @return bool
 */
function sn_analytics_contact_direct_candidate( array $views, $source, $entry_ms = null ) {
	$is = static fn( $p ) => 1 === preg_match( '#^/contact(?:/|$)#', (string) $p );
	if ( '(direct)' !== (string) $source || array() === $views ) {
		return false;
	}
	// Short by its measured time on page, or by the gap to the next page view:
	// a gap counts load and idle time (Niigata 2026-11-16: 3.1 s on /, a longer
	// gap), so a measured time decides when there is one.
	$short = null !== $entry_ms ? (int) $entry_ms < SN_ANALYTICS_CONTACT_ENTRY_SEC * 1000
		: ( isset( $views[1] ) && (int) $views[1][1] - (int) $views[0][1] < SN_ANALYTICS_CONTACT_ENTRY_SEC );
	return $is( $views[0][0] ) || ( isset( $views[1] ) && $is( $views[1][0] ) && $short );
}

/**
 * Sessions with `contact_direct` settled against the submissions. PURE. The
 * row's own `contact_direct` is the candidate (inc/analytics-sessions-list.php);
 * a candidate becomes null when the submissions were not read.
 *
 * @param array      $sessions Session rows.
 * @param int[]|null $submits  Unix times of form entries.
 * @return array
 */
function sn_analytics_contact_flag( array $sessions, $submits ) {
	foreach ( $sessions as $i => $s ) {
		if ( true !== ( $s['contact_direct'] ?? null ) ) {
			$sessions[ $i ]['contact_direct'] = false;
			continue;
		}
		$lo = (int) strtotime( (string) $s['start'] );
		$hi = (int) strtotime( (string) $s['end'] ) + SN_ANALYTICS_CONTACT_SLACK;
		$sessions[ $i ]['contact_direct'] = is_array( $submits ) ? array() === array_filter( $submits, static fn( $t ) => $t >= $lo && $t <= $hi ) : null;
	}
	return $sessions;
}

/**
 * Times of AllTerrain Forms entries created in [$t_lo, $t_hi] (inbox, read,
 * spam or archived; a partial save was not sent). Null when the plugin is not
 * active or the read filled its cap.
 *
 * @param int $t_lo Unix time.
 * @param int $t_hi Unix time.
 * @return int[]|null
 */
function sn_analytics_contact_submits( $t_lo, $t_hi ) {
	if ( ! defined( 'ALLTFO_ENTRY_TYPE' ) || ! function_exists( 'get_posts' ) ) {
		return null;
	}
	$statuses = array_filter( array( 'ALLTFO_STATUS_UNREAD', 'ALLTFO_STATUS_READ', 'ALLTFO_STATUS_SPAM', 'ALLTFO_STATUS_ARCHIVED' ), 'defined' );
	$posts    = get_posts( array(
		'post_type'        => ALLTFO_ENTRY_TYPE,
		'post_status'      => array_map( 'constant', array_values( $statuses ) ),
		'date_query'       => array( array( 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', (int) $t_lo ), 'before' => gmdate( 'Y-m-d H:i:s', (int) $t_hi ), 'inclusive' => true ) ),
		'numberposts'      => SN_ANALYTICS_CONTACT_MAX,
		'orderby'          => 'date',
		'order'            => 'ASC',
		'suppress_filters' => true,
	) );
	if ( ! is_array( $posts ) || count( $posts ) >= SN_ANALYTICS_CONTACT_MAX ) {
		return null;
	}
	return array_map( static fn( $p ) => (int) strtotime( (string) $p->post_date_gmt . ' UTC' ), $posts );
}

/**
 * Human sessions settled against the entries around them; other classes null.
 *
 * @param array  $sessions Session rows.
 * @param string $class    Traffic class.
 * @return array
 */
function sn_analytics_contact_on_sessions( array $sessions, $class ) {
	if ( 'human' !== $class ) {
		return array_map( static fn( $s ) => array_replace( $s, array( 'contact_direct' => null ) ), $sessions );
	}
	$cands = array_filter( $sessions, static fn( $s ) => true === ( $s['contact_direct'] ?? null ) );
	if ( array() === $cands ) {
		return sn_analytics_contact_flag( $sessions, array() );
	}
	$lo = min( array_map( static fn( $s ) => (int) strtotime( (string) $s['start'] ), $cands ) );
	$hi = max( array_map( static fn( $s ) => (int) strtotime( (string) $s['end'] ), $cands ) ) + SN_ANALYTICS_CONTACT_SLACK;
	return sn_analytics_contact_flag( $sessions, sn_analytics_contact_submits( $lo, $hi ) );
}

/**
 * The sessions read's flags: settled over the whole window for the per-day
 * count, and the page's pairing read again on its own span when the window's
 * read failed or capped (a wide window can), so only the summary goes null.
 *
 * @param array  $rows  Every session in the window.
 * @param string $class Traffic class.
 * @param int    $off   The page's first row.
 * @param int    $per   Rows per page.
 * @return array{slice:array,paired:array,by_day:?array} The page's rows (contact_direct settled), its paired values, the per-day counts.
 */
function sn_analytics_session_flags( array $rows, $class, $off, $per ) {
	$all   = sn_analytics_pairing_on_sessions( sn_analytics_contact_on_sessions( $rows, $class ), $class );
	$slice = array_slice( $all, (int) $off, (int) $per );
	if ( 'human' === $class && in_array( null, array_column( $slice, 'paired' ), true ) ) {
		$slice = sn_analytics_pairing_on_sessions( array_map( static fn( $r ) => array_diff_key( $r, array( 'paired' => 1 ) ), $slice ), $class );
	}
	return array(
		'slice'  => array_map( static fn( $r ) => array_diff_key( $r, array( 'paired' => 1 ) ), $slice ),
		'paired' => array_column( $slice, 'paired' ),
		'by_day' => 'human' === $class ? sn_analytics_pairing_by_day( $all ) : null,
	);
}
