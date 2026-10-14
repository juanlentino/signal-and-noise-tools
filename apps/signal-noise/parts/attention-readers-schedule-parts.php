<?php
/**
 * Signal & Noise app, the Attention queue's readers: the schedule, pending, health and watches readers.
 *
 * A pure move from parts/attention-readers.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Fragments and posts with a transition inside the next twenty-four hours.
 *
 * sn_admin_schedule_ordered_rows() already merges the two sources into one
 * list ordered by the soonest FUTURE boundary, with "nothing pending" (ts 0)
 * sorted last — so the window is a filter over its output and never a second
 * ordering rule that could disagree with the admin leaf's.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_schedule() {
	if ( ! function_exists( 'sn_admin_schedule_ordered_rows' ) || ! function_exists( 'sn_schedule_all' ) || ! function_exists( 'sn_schedule_future_posts' ) ) {
		return attention_read();
	}
	try {
		$ordered = \sn_admin_schedule_ordered_rows( \sn_schedule_all(), \sn_schedule_future_posts() );
		if ( ! is_array( $ordered ) ) {
			return attention_unreadable();
		}
		$now   = attention_now();
		$stamp = attention_stamp( $now );
		$door  = attention_door( 'sn-connections', 'scheduled-content' );
		$label = __( 'Open Scheduled in S&N Dashboard', 'signal-and-noise-tools' );
		$rows  = array();
		foreach ( $ordered as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$ts = (int) ( $entry['ts'] ?? 0 );
			if ( $ts <= 0 || $ts > $now + ATTENTION_SOON ) {
				continue;
			}
			$row  = isset( $entry['row'] ) && is_array( $entry['row'] ) ? $entry['row'] : array();
			$when = attention_local( attention_stamp( $ts ) );
			if ( 'post' === (string) ( $entry['kind'] ?? '' ) ) {
				$pid    = (int) ( $row['id'] ?? 0 );
				$rows[] = attention_row( array(
					'kind'       => 'schedule',
					'key'        => 'post-' . $pid,
					'title'      => '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : attention_post_title( $pid ),
					/* translators: %s: a timestamp in the site timezone with its zone. */
					'subtitle'   => sprintf( __( 'publishes at %s', 'signal-and-noise-tools' ), $when ),
					'tone'       => 'neutral',
					'stamp'      => $stamp,
					'source'     => __( 'The scheduled-content queue', 'signal-and-noise-tools' ),
					'door'       => $door,
					'door_label' => $label,
					'post_id'    => $pid,
				) );
				continue;
			}
			// A fragment: the boundary that fires is whichever of the two the
			// ordered row resolved to, so the verb is read back off the row
			// rather than assumed to be the start.
			$opens  = function_exists( 'sn_admin_schedule_next_transition_ts' )
				? (int) \sn_admin_schedule_next_transition_ts( $row['starts_at'] ?? null, null ) === $ts
				: false;
			$ref    = (int) ( $row['target_ref'] ?? 0 );
			$rows[] = attention_row( array(
				'kind'       => 'schedule',
				'key'        => 'fragment-' . (int) ( $row['id'] ?? 0 ),
				'title'      => $ref > 0 ? attention_post_title( $ref, __( '(unlinked fragment)', 'signal-and-noise-tools' ) ) : __( '(unlinked fragment)', 'signal-and-noise-tools' ),
				'subtitle'   => $opens
					/* translators: %s: a timestamp in the site timezone with its zone. */
					? sprintf( __( 'opens at %s', 'signal-and-noise-tools' ), $when )
					/* translators: %s: a timestamp in the site timezone with its zone. */
					: sprintf( __( 'closes at %s', 'signal-and-noise-tools' ), $when ),
				'tone'       => 'neutral',
				'stamp'      => $stamp,
				'source'     => __( 'The scheduled-content queue', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => $label,
				'post_id'    => $ref,
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Posts and pages waiting for review.
 *
 * wp_count_posts() is a SITE-WIDE count: it does not carry the author scope
 * the post sections put on their own unpublished half, and WordPress offers
 * no `perm` that would (`readable` guards `private` and leaves draft, pending
 * and scheduled unrestricted). So the row belongs only to a reader who may
 * edit others' posts of that type — for anyone else the honest surface is the
 * section's own Pending pill, which IS scoped.
 *
 * THE GATE MOVED, THE REASON DID NOT. The row is composed for EVERYONE and
 * carries `requires` (the type's own edit_others capability, read off the type
 * object and never a literal — `edit_others_posts` is the POST cap). The
 * capability is checked when the queue is READ, in attention_visible_rows().
 * It has to be: the composition is one sixty-second transient shared by every
 * administrator, so deciding here would let whoever filled the cache decide
 * for whoever reads it.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_pending() {
	if ( ! function_exists( 'wp_count_posts' ) || ! function_exists( 'get_post_type_object' ) ) {
		return attention_read();
	}
	try {
		$stamp = attention_stamp( attention_now() );
		$rows  = array();
		foreach ( array( 'post' => 'notes', 'page' => 'pages' ) as $type => $section ) {
			$object = get_post_type_object( $type );
			$others = is_object( $object ) && isset( $object->cap->edit_others_posts ) ? (string) $object->cap->edit_others_posts : '';
			if ( '' === $others ) {
				// A type whose object names no capability is a right nobody
				// can be shown to hold: no row, rather than an ungated one.
				continue;
			}
			$counts = wp_count_posts( $type );
			if ( ! is_object( $counts ) ) {
				return attention_unreadable();
			}
			$n = (int) ( $counts->pending ?? 0 );
			if ( $n <= 0 ) {
				continue;
			}
			$rows[] = attention_row( array(
				'kind'       => 'pending',
				'key'        => $type,
				'title'      => 'page' === $type
					? __( 'Pages waiting for review', 'signal-and-noise-tools' )
					: __( 'Posts waiting for review', 'signal-and-noise-tools' ),
				'subtitle'   => 'page' === $type
					/* translators: %d: a count of pages. */
					? sprintf( _n( '%d page is pending review', '%d pages are pending review', $n, 'signal-and-noise-tools' ), $n )
					/* translators: %d: a count of posts. */
					: sprintf( _n( '%d post is pending review', '%d posts are pending review', $n, 'signal-and-noise-tools' ), $n ),
				'tone'       => 'neutral',
				'stamp'      => $stamp,
				'source'     => __( 'The post counts, read just now', 'signal-and-noise-tools' ),
				'section'    => $section,
				'requires'   => $others,
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * The last health scan's failing checks.
 *
 * READS the cached scan; it never runs one. sn_health_flagged_checks() is the
 * same pure accessor the desktop badge counts, so it is already narrowed to
 * the `health` surface and already excludes the advisory tier — which is also
 * why the integrity rows above are not double-counted here (they live on the
 * `integrity` surface, outside this tally).
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_health() {
	if ( ! function_exists( 'sn_health_last_scan' ) || ! function_exists( 'sn_health_flagged_checks' ) ) {
		return attention_read();
	}
	try {
		$scan = \sn_health_last_scan();
		if ( ! is_array( $scan ) ) {
			// Never scanned: the reader exists and has nothing to read.
			return attention_unreadable();
		}
		$stamp   = attention_stamp( $scan['scanned_at'] ?? '' );
		$door    = attention_health_door();
		$rows    = array();
		$flagged = (array) \sn_health_flagged_checks( $scan );
		foreach ( $flagged as $key => $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$n    = (int) ( $check['count'] ?? 0 );
			$hint = trim( (string) ( $check['fix_hint'] ?? '' ) );
			$rows[] = attention_row( array(
				'kind'       => 'health',
				'key'        => (string) $key,
				'title'      => '' !== (string) ( $check['label'] ?? '' ) ? (string) $check['label'] : (string) $key,
				'subtitle'   => '' !== $hint
					/* translators: 1: a count of findings. 2: the check's own fix hint. */
					? sprintf( _n( '%1$d finding. %2$s', '%1$d findings. %2$s', $n, 'signal-and-noise-tools' ), $n, $hint )
					/* translators: %d: a count of findings. */
					: sprintf( _n( '%d finding', '%d findings', $n, 'signal-and-noise-tools' ), $n ),
				'tone'       => 'danger',
				'stamp'      => $stamp,
				'source'     => __( 'The last health scan', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Health in S&N Dashboard', 'signal-and-noise-tools' ),
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * The watches that are ripe.
 *
 * A watch is SILENT until its state ripens, which is the same argument this
 * whole queue is built on, so a ripe watch belongs here and a pending one
 * does not. The row's own `read` string is its door — there is no leaf.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_watches() {
	if ( ! function_exists( 'snt_watches_ripe' ) ) {
		return attention_read();
	}
	try {
		$now  = attention_now();
		$ripe = \snt_watches_ripe( $now );
		if ( ! is_array( $ripe ) ) {
			return attention_unreadable();
		}
		$stamp = attention_stamp( $now );
		$rows  = array();
		foreach ( $ripe as $watch ) {
			if ( ! is_array( $watch ) ) {
				continue;
			}
			$note = trim( (string) ( $watch['note'] ?? '' ) );
			$rows[] = attention_row( array(
				'kind'     => 'watches',
				'key'      => (string) ( $watch['id'] ?? '' ),
				'title'    => (string) ( $watch['label'] ?? ( $watch['id'] ?? '' ) ),
				'subtitle' => '' !== $note ? $note : __( 'ripe', 'signal-and-noise-tools' ),
				'tone'     => 'warning',
				'stamp'    => $stamp,
				/* translators: %s: where to read the watch, e.g. "sn-status{watches}". */
				'source'   => sprintf( __( 'A ripe watch; read it at %s', 'signal-and-noise-tools' ), (string) ( $watch['read'] ?? '' ) ),
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

