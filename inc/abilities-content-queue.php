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

/** How many upcoming rows the payload carries: the headline plus three. */
const SN_CONTENT_QUEUE_NEXT = 4;

/** How many just-published rows: about twelve days at the site's cadence. */
const SN_CONTENT_QUEUE_PUBLISHED = 3;

/**
 * Label a scheduled time relative to now, in the given timezone. PURE.
 *
 * "Today 09:38", "Tomorrow 09:38", "Sat 11:38" within the week, then
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
		return 'Today ' . $at->format( 'H:i' );
	}
	if ( 1 === $days ) {
		return 'Tomorrow ' . $at->format( 'H:i' );
	}
	if ( $days < 7 ) {
		return $at->format( 'D H:i' );
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
 * The first hole in the schedule, or null. PURE.
 *
 * Reuses the publishing-cadence flag's own definition (inc/ml-cadence.php):
 * snt_ml_cadence_deviation() over the published history gives the expected
 * gap (EWMA) and its spread, and a gap is late at SNT_ML_CADENCE_Z_FLAG
 * sigmas. That flag scores only the current quiet spell; here the same test
 * runs on each pair of consecutive scheduled notes, so the line says where
 * the cadence flag WOULD fire if nothing new is scheduled. Honest unknowns
 * carry over: thin history or a zero-spread metronome answers null, never a
 * guessed threshold. Ceiling: an irregular history widens the spread and
 * nothing flags; that is the cadence check's posture, kept on purpose.
 *
 * The empty stretch is the whole days strictly between the two notes, in
 * the site timezone; a late pair with no whole empty day between them is
 * not a hole anyone can schedule into, so the walk moves on.
 *
 * @since 23.8.0
 * @param int[]        $scheduled Scheduled Unix times (any order).
 * @param int[]        $history   Published Unix times (any order).
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @param string[]     $planned   Gaps the owner marked planned, as "from|to"
 *                                (Y-m-d): skipped, so the next one shows.
 * @return array{from:string,to:string,from_label:string,to_label:string}|null
 */
function sn_content_queue_gap( $scheduled, $history, $now, DateTimeZone $tz, array $planned = array() ) {
	if ( ! function_exists( 'snt_ml_cadence_deviation' ) || ! defined( 'SNT_ML_CADENCE_Z_FLAG' ) ) {
		return null;
	}
	$dev = snt_ml_cadence_deviation( (array) $history, (int) $now );
	if ( ! is_array( $dev ) || ! ( $dev['std'] > 0.0 ) ) {
		return null;
	}
	$ts = array_map( 'intval', (array) $scheduled );
	sort( $ts );
	$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$count = count( $ts );
	for ( $i = 1; $i < $count; $i++ ) {
		if ( ( $ts[ $i ] - $ts[ $i - 1 ] - $dev['ewma'] ) / $dev['std'] < SNT_ML_CADENCE_Z_FLAG ) {
			continue;
		}
		$from = ( new DateTimeImmutable( '@' . $ts[ $i - 1 ] ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '+1 day' );
		$to   = ( new DateTimeImmutable( '@' . $ts[ $i ] ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '-1 day' );
		if ( $from > $to || in_array( $from->format( 'Y-m-d' ) . '|' . $to->format( 'Y-m-d' ), $planned, true ) ) {
			continue;
		}
		return array(
			'from'       => $from->format( 'Y-m-d' ),
			'to'         => $to->format( 'Y-m-d' ),
			'from_label' => sn_content_queue_day( $from, $today ),
			'to_label'   => sn_content_queue_day( $to, $today ),
		);
	}
	return null;
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
 * @param array        $future    Scheduled rows, soonest first (the whole queue; the last is runs_to).
 * @param int          $total     Scheduled count site-wide.
 * @param array        $published Published rows, newest first (the cadence history; three are painted).
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @param int|null     $drafts    Draft count, or null when it could not be read (never a guessed 0).
 * @param string[]     $planned   Gaps marked planned ("from|to"), skipped by the gap line.
 * @return array{next:array,scheduled_total:int,runs_to:?array,published:array,drafts:?int,gap:?array}
 */
function sn_content_queue_shape( $future, $total, $published, $now, DateTimeZone $tz, $drafts = null, array $planned = array() ) {
	$future    = is_array( $future ) ? array_values( $future ) : array();
	$published = is_array( $published ) ? array_values( $published ) : array();
	$now       = (int) $now;

	$row = static function ( $r, $label ) {
		if ( ! is_array( $r ) ) {
			return null;
		}
		$title = trim( (string) ( $r['title'] ?? '' ) );
		return array(
			'id'       => (int) ( $r['id'] ?? 0 ),
			'title'    => '' !== $title ? $title : '(untitled)',
			'date'     => gmdate( 'c', (int) ( $r['ts'] ?? 0 ) ),
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

	return array(
		'next'            => $next,
		'scheduled_total' => max( 0, (int) $total ),
		'runs_to'         => $runs_to,
		'published'       => $recent,
		'drafts'          => null === $drafts ? null : max( 0, (int) $drafts ),
		'gap'             => sn_content_queue_gap( $stamps( $future ), $stamps( $published ), $now, $tz, $planned ),
	);
}

/**
 * Register the ability.
 *
 * @since 15.8.0
 */
function snt_abilities_content_queue_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$row_schema = array(
		'type'       => 'object',
		'properties' => array(
			'id'       => array( 'type' => 'integer' ),
			'title'    => array( 'type' => 'string' ),
			'date'     => array( 'type' => 'string' ),
			'when'     => array( 'type' => 'string' ),
			'edit_url' => array( 'type' => 'string' ),
		),
	);
	wp_register_ability( 'signal-noise/content-queue', array(
		'label'               => 'Content queue',
		'description'         => 'The scheduled queue at a glance: the next four scheduled posts in date order with a site-timezone label ("Tomorrow 09:38", "Sat 11:38", "Dec 27"), the scheduled count and the date the queue runs to, the draft count (null when unreadable), the first hole in the schedule by the publishing-cadence flag\'s own threshold (null when none or when the history is too thin to say), and the last three published posts with a relative age. Read-only; the reading behind the SN Queue desktop widget.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_edit_posts',
		'execute_callback'    => 'snt_ability_content_queue',
		'input_schema'        => array(
			// The [object,null] union: readonly ⇒ GET run-path ⇒ an omitted
			// ?input= delivers NULL, and a plain 'object' rejects every such call.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'next'            => array( 'type' => 'array', 'items' => $row_schema ),
				'scheduled_total' => array( 'type' => 'integer' ),
				'runs_to'         => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'date'  => array( 'type' => 'string' ),
						'label' => array( 'type' => 'string' ),
					),
				),
				'published'       => array( 'type' => 'array', 'items' => $row_schema ),
				'drafts'          => array( 'type' => array( 'integer', 'null' ) ),
				'gap'             => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'from'       => array( 'type' => 'string' ),
						'to'         => array( 'type' => 'string' ),
						'from_label' => array( 'type' => 'string' ),
						'to_label'   => array( 'type' => 'string' ),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_content_queue_register' );

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
		'edit_url' => (string) get_edit_post_link( $post, 'raw' ),
	);
}

/**
 * Ability execute callback: signal-noise/content-queue.
 *
 * Two reads: the whole scheduled queue (asc; the shaper paints the first
 * four, labels the last as runs_to and walks all of it for the gap) and the
 * last SNT_ML_CADENCE_PUBLISH_DEPTH published (the cadence history the gap
 * is measured against; three are painted). The totals ride wp_count_posts(),
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

	$future = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $queue ) ? $queue : array() ) ) );

	$counts = wp_count_posts( 'post' );
	$total  = is_object( $counts ) && isset( $counts->future ) ? (int) $counts->future : count( $future );
	$drafts = is_object( $counts ) && isset( $counts->draft ) ? (int) $counts->draft : null;

	return sn_content_queue_shape(
		$future,
		$total,
		array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $done ) ? $done : array() ) ) ),
		time(),
		wp_timezone(),
		$drafts,
		sn_content_queue_planned_gaps()
	);
}

/** Gaps the owner marked planned (2026-10-10: the holidays are on purpose). */
const SN_CONTENT_QUEUE_PLANNED_OPT = 'sn_content_queue_planned_gaps';

/**
 * The planned gaps, as "from|to" (Y-m-d), newest last, at most 20.
 *
 * @return string[]
 */
function sn_content_queue_planned_gaps() {
	$v = get_option( SN_CONTENT_QUEUE_PLANNED_OPT, array() );
	return array_values( array_filter( (array) $v, static fn( $k ) => is_string( $k ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\|\d{4}-\d{2}-\d{2}$/', $k ) ) );
}

/**
 * Marks one gap planned.
 *
 * @param string $from First empty day, Y-m-d.
 * @param string $to   Last empty day, Y-m-d.
 * @return bool False on a malformed range.
 */
function sn_content_queue_mark_planned( $from, $to ) {
	$from = (string) $from;
	$to   = (string) $to;
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $from > $to ) {
		return false;
	}
	$keep   = array_values( array_diff( sn_content_queue_planned_gaps(), array( $from . '|' . $to ) ) );
	$keep[] = $from . '|' . $to;
	update_option( SN_CONTENT_QUEUE_PLANNED_OPT, array_slice( $keep, -20 ), false );
	return true;
}

add_action( 'rest_api_init', static function () {
	register_rest_route( 'signal-noise/v1', '/desktop/queue-gap-planned', array(
		'methods'             => 'POST',
		'callback'            => static function ( WP_REST_Request $req ) {
			if ( ! sn_content_queue_mark_planned( $req->get_param( 'from' ), $req->get_param( 'to' ) ) ) {
				return new WP_Error( 'rest_invalid_param', 'from and to must be Y-m-d days, from first.', array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'ok' => true ) );
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'from' => array( 'type' => 'string', 'required' => true ),
			'to'   => array( 'type' => 'string', 'required' => true ),
		),
	) );
} );
