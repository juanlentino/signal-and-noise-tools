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
	if ( $at->format( 'Y' ) === $today->format( 'Y' ) ) {
		return $at->format( 'M j' );
	}
	return $at->format( 'M j, Y' );
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
 * @param array        $future    Scheduled rows, soonest first (may be longer than the cap).
 * @param int          $total     Scheduled count site-wide.
 * @param array        $published Published rows, newest first.
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @return array{next:array,scheduled_total:int,runs_to:?array,published:array}
 */
function sn_content_queue_shape( $future, $total, $published, $now, DateTimeZone $tz ) {
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

	return array(
		'next'            => $next,
		'scheduled_total' => max( 0, (int) $total ),
		'runs_to'         => $runs_to,
		'published'       => $recent,
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
		'description'         => 'The scheduled queue at a glance: the next four scheduled posts in date order with a site-timezone label ("Tomorrow 09:38", "Sat 11:38", "Dec 27"), the scheduled count and the date the queue runs to, and the last three published posts with a relative age. Read-only; the reading behind the SN Queue desktop widget.',
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
 * Three cheap reads: the next few scheduled (asc), the furthest scheduled
 * (desc, one row) for the run-to date, the last few published; the total
 * rides wp_count_posts(), which is cached.
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
	);

	$soon = get_posts( $base + array( 'post_status' => 'future', 'order' => 'ASC', 'numberposts' => SN_CONTENT_QUEUE_NEXT ) );
	$far  = get_posts( $base + array( 'post_status' => 'future', 'order' => 'DESC', 'numberposts' => 1 ) );
	$done = get_posts( $base + array( 'post_status' => 'publish', 'order' => 'DESC', 'numberposts' => SN_CONTENT_QUEUE_PUBLISHED ) );

	$future = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $soon ) ? $soon : array() ) ) );
	// The shaper reads runs_to from the LAST future row; append the furthest
	// one so the label is the real end of the queue, not the fourth row.
	$far_row = is_array( $far ) && isset( $far[0] ) ? sn_content_queue_row( $far[0] ) : null;
	if ( $far_row ) {
		$future[] = $far_row;
	}

	$counts = wp_count_posts( 'post' );
	$total  = is_object( $counts ) && isset( $counts->future ) ? (int) $counts->future : count( $future );

	return sn_content_queue_shape(
		$future,
		$total,
		array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $done ) ? $done : array() ) ) ),
		time(),
		wp_timezone()
	);
}
