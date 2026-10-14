<?php
/**
 * Signal & Noise Tools — signal-noise/content-queue (readonly ability).
 *
 * One call answers "is the queue fed, and what goes out next": the next few
 * scheduled posts in date order, how deep the schedule runs and until when,
 * and the last few published. This is the reading behind the SN Queue desktop
 * widget (15.8.0). Core's Activity box frames the same data as "activity",
 * built for busy multi-author blogs; this site lives on a scheduled queue of
 * notes, one every few days, so the number that matters is the depth.
 *
 * The shaper is PURE (rows + counts + a clock + a timezone in, the payload
 * out) so every label the widget paints is testable without WordPress.
 * Times are labelled here, in the SITE timezone, never in the browser's: a
 * reader's laptop clock is not the clock the post is scheduled on.
 *
 * @package SignalNoiseTools
 * @since 15.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abilities-content-queue-gaps.php';
require_once __DIR__ . '/abilities-content-queue-cadence.php';
require_once __DIR__ . '/abilities-content-queue-slot.php';
require_once __DIR__ . '/abilities-content-queue-runway.php';
require_once __DIR__ . '/abilities-content-queue-register.php';

/** How many upcoming rows the payload carries: the headline plus three. */
const SN_CONTENT_QUEUE_NEXT = 4;

/** How many just-published rows: about twelve days at the site's cadence. */
const SN_CONTENT_QUEUE_PUBLISHED = 3;

/** How many drafts the card lists by title (the count covers the rest). */
const SN_CONTENT_QUEUE_DRAFTS = 3;

/**
 * Label a scheduled time relative to now, in the given timezone. PURE.
 *
 * "Today 9:38 AM", "Tomorrow 9:38 AM", "Sat 11:38 AM" within the week (the
 * 12-hour clock every other card uses, since 2026-10-11), then
 * "Sep 20" and, past the year, "Dec 27, 2027". A past time (a scheduled post
 * whose cron has not fired yet) labels as "Overdue" so the widget says what
 * the Posts screen would.
 *
 * @since 15.8.0
 * @param int          $ts  Unix time of the event.
 * @param int          $now Unix time now.
 * @param DateTimeZone $tz  Site timezone.
 * @return string
 */
function sn_content_queue_when( $ts, $now, DateTimeZone $tz ) {
	$ts  = (int) $ts;
	$now = (int) $now;
	if ( $ts < $now ) {
		return 'Overdue';
	}
	$at    = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
	$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$days  = (int) $today->diff( $at->setTime( 0, 0, 0 ) )->format( '%r%a' );
	if ( 0 === $days ) {
		return 'Today ' . $at->format( 'g:i A' );
	}
	if ( 1 === $days ) {
		return 'Tomorrow ' . $at->format( 'g:i A' );
	}
	if ( $days < 7 ) {
		return $at->format( 'D g:i A' );
	}
	return sn_content_queue_day( $at, $today );
}

/**
 * A calendar-day label: "Sep 20", or "Dec 27, 2027" past the current year.
 * PURE. The tail of sn_content_queue_when(), shared with the gap line.
 *
 * @since 23.8.0
 * @param DateTimeImmutable $at    The day, in the site timezone.
 * @param DateTimeImmutable $today Today, in the site timezone.
 * @return string
 */
function sn_content_queue_day( DateTimeImmutable $at, DateTimeImmutable $today ) {
	return $at->format( 'Y' ) === $today->format( 'Y' ) ? $at->format( 'M j' ) : $at->format( 'M j, Y' );
}

/**
 * Whole calendar days between two days, unsigned. PURE.
 *
 * Read from their Y-m-d dates, never from the instants: where a DST switch
 * happens at midnight (Santiago, Havana, Beirut, Cairo, the Azores) the day
 * starts at 01:00, so setTime( 0, 0, 0 ) lands an hour late and an instant
 * diff came up a day short (review on #2058).
 *
 * @since Unreleased (runway)
 * @param DateTimeInterface $a One day, in the site timezone.
 * @param DateTimeInterface $b The other.
 * @return int
 */
function sn_content_queue_days_apart( DateTimeInterface $a, DateTimeInterface $b ) {
	$utc = new DateTimeZone( 'UTC' );
	return (int) ( new DateTimeImmutable( $a->format( 'Y-m-d' ), $utc ) )->diff( new DateTimeImmutable( $b->format( 'Y-m-d' ), $utc ) )->format( '%a' );
}

/**
 * Label a past time as "N ago", coarse. PURE.
 *
 * @since 15.8.0
 * @param int $ts  Unix time of the event.
 * @param int $now Unix time now.
 * @return string
 */
function sn_content_queue_ago( $ts, $now ) {
	$diff = max( 0, (int) $now - (int) $ts );
	if ( $diff < 3600 ) {
		$n = max( 1, (int) floor( $diff / 60 ) );
		return $n . ( 1 === $n ? ' minute ago' : ' minutes ago' );
	}
	if ( $diff < 86400 ) {
		$n = (int) floor( $diff / 3600 );
		return $n . ( 1 === $n ? ' hour ago' : ' hours ago' );
	}
	$n = (int) floor( $diff / 86400 );
	return $n . ( 1 === $n ? ' day ago' : ' days ago' );
}

/**
 * Shape the payload from plain rows. PURE.
 *
 * A row is {id, title, ts, edit_url}; anything else is tolerated and dropped.
 * A title that is empty paints as "(untitled)" so a row never vanishes.
 *
 * @since 15.8.0
 * @since 23.8.0 $drafts and the gap: every scheduled row and the published
 *               history ride in so the gap is computed here, purely.
 * @since Unreleased (the queue calendar): every scheduled row (`scheduled`), today and
 *               the Monday of this week, the planned gaps as ranges, and the
 *               drafts by title (`draft_list`); every row gains `day` and `time`.
 * @param array        $future    Scheduled rows, soonest first (the whole queue; the last is runs_to).
 * @param int          $total     Scheduled count site-wide.
 * @param array        $published Published rows, newest first (the cadence history; three are painted).
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @param int|null     $drafts    Draft count, or null when it could not be read (never a guessed 0).
 * @param string[]     $planned   Gaps marked planned ("from|to"), skipped by the gap line.
 * @param array        $draft_list Draft rows (titles and edit links only), newest edit first.
 * @param int|null     $draft_more Drafts left off the list, or null when not read for this user.
 * @since Unreleased (cadence warnings): `cadence`, every cluster and unplanned gap.
 * @since Unreleased (runway): `runway`, how far ahead the schedule covers, in one sentence.
 * @return array{next:array,scheduled_total:int,runs_to:?array,published:array,drafts:?int,gap:?array,scheduled:array,today:string,week_start:string,planned:array,draft_list:array,draft_more:?int,cadence:array,runway:array}
 */
function sn_content_queue_shape( $future, $total, $published, $now, DateTimeZone $tz, $drafts = null, array $planned = array(), $draft_list = array(), $draft_more = null ) {
	$future    = is_array( $future ) ? array_values( $future ) : array();
	$published = is_array( $published ) ? array_values( $published ) : array();
	$now       = (int) $now;

	// 2026-10-12 (the calendar): day and time ride every row, in the SITE
	// timezone, so the card never asks the browser's clock which day a note
	// falls on.
	$row = static function ( $r, $label ) use ( $tz ) {
		if ( ! is_array( $r ) ) {
			return null;
		}
		$title = trim( (string) ( $r['title'] ?? '' ) );
		$at    = ( new DateTimeImmutable( '@' . (int) ( $r['ts'] ?? 0 ) ) )->setTimezone( $tz );
		return array(
			'id'       => (int) ( $r['id'] ?? 0 ),
			'title'    => '' !== $title ? $title : '(untitled)',
			'date'     => gmdate( 'c', (int) ( $r['ts'] ?? 0 ) ),
			'day'      => $at->format( 'Y-m-d' ),
			'time'     => $at->format( 'g:i A' ),
			'when'     => $label,
			'edit_url' => (string) ( $r['edit_url'] ?? '' ),
		);
	};

	$next = array();
	foreach ( array_slice( $future, 0, SN_CONTENT_QUEUE_NEXT ) as $r ) {
		$shaped = is_array( $r ) ? $row( $r, sn_content_queue_when( (int) ( $r['ts'] ?? 0 ), $now, $tz ) ) : null;
		if ( $shaped ) {
			$next[] = $shaped;
		}
	}

	$last    = array() !== $future ? end( $future ) : null;
	$runs_to = null;
	if ( is_array( $last ) && isset( $last['ts'] ) ) {
		$runs_to = array(
			'date'  => gmdate( 'c', (int) $last['ts'] ),
			'label' => sn_content_queue_when( (int) $last['ts'], $now, $tz ),
		);
	}

	$recent = array();
	foreach ( array_slice( $published, 0, SN_CONTENT_QUEUE_PUBLISHED ) as $r ) {
		$shaped = is_array( $r ) ? $row( $r, sn_content_queue_ago( (int) ( $r['ts'] ?? 0 ), $now ) ) : null;
		if ( $shaped ) {
			$recent[] = $shaped;
		}
	}

	$stamps = static function ( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( is_array( $r ) && isset( $r['ts'] ) ) {
				$out[] = (int) $r['ts'];
			}
		}
		return $out;
	};

	// The calendar's rows: EVERY scheduled note, not the four of `next`
	// (the 2026-10-12 sketch plotted only those and left real notes off).
	$all = array();
	foreach ( $future as $r ) {
		$shaped = is_array( $r ) ? $row( $r, sn_content_queue_when( (int) ( $r['ts'] ?? 0 ), $now, $tz ) ) : null;
		if ( $shaped ) {
			$all[] = $shaped;
		}
	}
	$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	// Monday of this week by ISO weekday ('N', 1 to 7); "monday this week"
	// reads differently on a Sunday across PHP versions.
	$monday = $today->modify( '-' . ( (int) $today->format( 'N' ) - 1 ) . ' days' );

	$draft_rows = array();
	foreach ( is_array( $draft_list ) ? $draft_list : array() as $r ) {
		$shaped = is_array( $r ) ? $row( $r, '' ) : null;
		if ( $shaped ) {
			$draft_rows[] = array_intersect_key( $shaped, array_flip( array( 'id', 'title', 'edit_url' ) ) );
		}
	}
	// Phase 2, item 2 (2026-10-13): a suggested slot per draft, never
	// scheduled; the same sentence the Scheduled twins print.
	$newest = $stamps( array_slice( $published, 0, 1 ) )[0] ?? null;
	foreach ( sn_content_queue_slots( $draft_rows, $stamps( $future ), $newest, $now, $tz, $planned ) as $i => $s ) {
		$draft_rows[ $i ]['slot'] = array_intersect_key( $s, array_flip( array( 'day', 'time', 'label', 'reason', 'text' ) ) );
	}

	return array(
		'next'            => $next,
		'scheduled_total' => max( 0, (int) $total ),
		'runs_to'         => $runs_to,
		'published'       => $recent,
		'drafts'          => null === $drafts ? null : max( 0, (int) $drafts ),
		'gap'             => sn_content_queue_gap( $stamps( $future ), $stamps( $published ), $now, $tz, $planned ),
		'scheduled'       => $all,
		'today'           => $today->format( 'Y-m-d' ),
		'week_start'      => $monday->format( 'Y-m-d' ),
		'planned'         => array_map(
			static function ( $k ) {
				$p = explode( '|', $k );
				return array( 'from' => $p[0], 'to' => $p[1] );
			},
			array_values( array_filter( $planned, static fn( $k ) => is_string( $k ) && 1 === substr_count( $k, '|' ) ) )
		),
		'draft_list'      => $draft_rows,
		'draft_more'      => null === $draft_more ? null : max( 0, (int) $draft_more ),
		// Phase 2 (2026-10-12): every cluster and gap by the owner's rule
		// (under 2 days, over 7), planned gaps left out, one sentence each.
		'cadence'         => sn_content_queue_cadence( $stamps( $future ), $newest, $now, $tz, $planned ),
		// Phase 2, item 6 (2026-10-14): "Covered through Jan 25, 2027 (103
		// days)", the same sentence the Scheduled twins print.
		'runway'          => sn_content_queue_runway( $stamps( $future ), $now, $tz ),
	);
}


/**
 * Permission: anyone who can edit posts can read the queue (the Posts screen
 * shows the same rows to the same people).
 *
 * @since 15.8.0
 * @return bool
 */
function snt_ability_perm_edit_posts() {
	return current_user_can( 'edit_posts' );
}

/**
 * Turn a WP_Post into a plain row for the shaper.
 *
 * @since 15.8.0
 * @param mixed $post WP_Post (anything else yields null).
 * @return array|null
 */
function sn_content_queue_row( $post ) {
	if ( ! $post instanceof WP_Post || empty( $post->ID ) ) {
		return null;
	}
	$ts = strtotime( (string) $post->post_date_gmt . ' UTC' );
	if ( false === $ts || $ts <= 0 ) {
		// A scheduled post's post_date_gmt can be 0000-00-00 on older rows;
		// fall back to the local date read in the site timezone.
		$ts = (int) get_post_time( 'U', true, $post );
	}
	return array(
		'id'       => (int) $post->ID,
		'title'    => (string) get_the_title( $post ),
		'ts'       => (int) $ts,
		// Escaped for a URL here, where the row is made (review on #2031):
		// a link a filter bent to another scheme never reaches the card.
		'edit_url' => esc_url_raw( (string) get_edit_post_link( $post, 'raw' ) ),
	);
}

/**
 * Ability execute callback: signal-noise/content-queue.
 *
 * Three reads: the whole scheduled queue (asc; the shaper paints the first
 * four, labels the last as runs_to, walks all of it for the gap and hands
 * all of it to the calendar), the last SNT_ML_CADENCE_PUBLISH_DEPTH
 * published (the cadence history the gap is measured against; three are
 * painted) and the newest SN_CONTENT_QUEUE_DRAFTS drafts (titles and edit
 * links only; no post body leaves the server). The totals ride wp_count_posts(),
 * which is cached.
 *
 * @since 15.8.0
 * @param array|null $input Unused.
 * @return array
 */
function snt_ability_content_queue( $input = null ) {
	unset( $input );
	$base = array(
		'post_type'        => 'post',
		'suppress_filters' => false,
		'orderby'          => 'date',
		// The whole future queue is read for the gap check: titles and dates
		// only, so no meta or term caches are primed for every scheduled note.
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	$queue = get_posts( $base + array( 'post_status' => 'future', 'order' => 'ASC', 'numberposts' => -1 ) );
	$done  = get_posts( $base + array( 'post_status' => 'publish', 'order' => 'DESC', 'numberposts' => defined( 'SNT_ML_CADENCE_PUBLISH_DEPTH' ) ? SNT_ML_CADENCE_PUBLISH_DEPTH : 20 ) );
	// The drafts by title for the card's list (2026-10-12), only those this
	// user can edit, read once for the card and the Scheduled twins.
	$draft = sn_content_queue_draft_read();

	$future = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $queue ) ? $queue : array() ) ) );

	$counts = wp_count_posts( 'post' );
	$total  = is_object( $counts ) && isset( $counts->future ) ? (int) $counts->future : count( $future );

	return sn_content_queue_shape(
		$future,
		$total,
		array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $done ) ? $done : array() ) ) ),
		time(),
		wp_timezone(),
		$draft['count'],
		sn_content_queue_planned_gaps(),
		$draft['rows'],
		$draft['more']
	);
}
