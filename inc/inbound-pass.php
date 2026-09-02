<?php
/**
 * Signal & Noise Tools — the inbound pass. A note with no inbound link is
 * invisible to the crawler's link graph, and nothing said so until a
 * coverage reading a fortnight later.
 *
 * Measured 2026-09-01: every one of the 13 not-indexed notes had zero
 * inbound links from an indexed page, and every scheduled note (21) will be
 * born the same way — the link_candidates artifact and the pair-suggest
 * gate both require a PUBLISHED target, so nothing can pre-link a future
 * post. The lever is a link FROM an older, indexed note.
 *
 * Three tenses, one pass (owner rule 2026-09-01: "past, present and future"):
 *
 *  PAST     every published post with zero inbound links in the link graph,
 *           however old — not only the recent ones. Top related PUBLISHED
 *           notes (the ML kernel's stored artifact) are the candidate
 *           sources, at most SN_INBOUND_PASS_MAX_PER_NOTE per note, and the
 *           pair-suggest judgment runs older → new. The verdict lands in the
 *           durable verdict store, so a `link` with a valid anchor appears on
 *           the link_opportunities worklist with the anchor nominated; the
 *           stored report lists the same anchors. A per-run pair budget
 *           bounds model calls; what it defers is reported as deferred and
 *           picked up next run (judged pairs are memoized on both modified
 *           stamps, so a re-run re-bills nothing).
 *  PRESENT  a transition INTO publish schedules a single run a few minutes
 *           later (after the ML rebuild the same transition coalesces), so
 *           the morning-after gap is minutes, not a day.
 *  FUTURE   scheduled posts cannot be judged (no published target), so the
 *           report lists them with their OUTBOUND note-link count — a
 *           scheduled note carrying no outbound links is the case fixed by
 *           hand today, and it is fixable before it publishes.
 *
 * FAIL-CLOSED: an unavailable AI provider is `state: unavailable`, never a
 * report with zero pairs — zero pairs means "every note is linked". An
 * unbuilt artifact is reported per note (`artifact: unbuilt`), not silently
 * read as "no related notes".
 *
 * @package SignalNoiseTools
 * @since 13.68.0 (all three tenses 13.69.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_INBOUND_PASS_HOOK          = 'sn_inbound_pass_daily';
const SN_INBOUND_PASS_PUBLISH_HOOK  = 'sn_inbound_pass_after_publish';
const SN_INBOUND_PASS_PUBLISH_DELAY = 5 * MINUTE_IN_SECONDS;
const SN_INBOUND_PASS_STATUS        = 'sn_inbound_pass_last';
const SN_INBOUND_PASS_MAX_PER_NOTE  = 3;
const SN_INBOUND_PASS_MAX_PAIRS     = 30;
const SN_INBOUND_PASS_RELATED       = 10;

/**
 * PURE selector: which published notes need sources, and which to judge.
 *
 * @param array<int,array{id:int,key:string}>              $published Published posts, newest first.
 * @param array<string,array{inbound:int}>                 $inbound   Link-graph counts keyed by join key.
 * @param array<int,array<int,array{post_id:int}>|null>    $related   id => related rows (published only), null = artifact unbuilt.
 * @param int                                              $budget    Max pairs this run may judge.
 * @return array{notes:array,deferred:int}
 */
function sn_inbound_pass_select( $published, $inbound, $related, $budget = SN_INBOUND_PASS_MAX_PAIRS ) {
	$out      = array();
	$pairs    = 0;
	$deferred = 0;
	foreach ( (array) $published as $p ) {
		$id  = (int) ( $p['id'] ?? 0 );
		$key = (string) ( $p['key'] ?? '' );
		if ( $id <= 0 || '' === $key ) {
			continue;
		}
		$count = (int) ( $inbound[ $key ]['inbound'] ?? 0 );
		if ( $count > 0 ) {
			continue; // Already reachable: nothing to propose.
		}
		$rows    = array_key_exists( $id, (array) $related ) ? $related[ $id ] : null;
		$sources = array();
		$wanted  = 0; // Pairs this note would judge under the per-note cap; the budget defers the tail.
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$sid = (int) ( $r['post_id'] ?? 0 );
				if ( $sid <= 0 || $sid === $id || in_array( $sid, $sources, true ) ) {
					continue;
				}
				if ( $wanted >= SN_INBOUND_PASS_MAX_PER_NOTE ) {
					break;
				}
				$wanted++;
				if ( $pairs >= $budget ) {
					$deferred++;
					continue;
				}
				$sources[] = $sid;
				$pairs++;
			}
		}
		$out[] = array(
			'target_id' => $id,
			'key'       => $key,
			'inbound'   => $count,
			'artifact'  => is_array( $rows ) ? 'built' : 'unbuilt',
			'sources'   => $sources,
		);
	}
	return array( 'notes' => $out, 'deferred' => $deferred );
}

/**
 * PURE judge loop: one row per (source → target) pair, from a suggest callable.
 *
 * @param array    $selected notes[] from sn_inbound_pass_select().
 * @param callable $suggest  fn( $source_id, $target_id ): array|WP_Error — the pair-suggest impl.
 * @return array{notes:array,counts:array,unavailable:bool}
 */
function sn_inbound_pass_judge( $selected, $suggest ) {
	$counts = array( 'notes' => count( $selected ), 'pairs' => 0, 'ready' => 0, 'linked' => 0, 'declined' => 0, 'errors' => 0 );
	$notes  = array();
	foreach ( $selected as $note ) {
		$pairs = array();
		foreach ( $note['sources'] as $sid ) {
			$counts['pairs']++;
			$r = $suggest( $sid, (int) $note['target_id'] );
			if ( is_wp_error( $r ) ) {
				$code = (string) $r->get_error_code();
				if ( 'snt_ai_unavailable' === $code ) {
					return array( 'notes' => $notes, 'counts' => $counts, 'unavailable' => true );
				}
				$linked = ( 'snt_ai_link_already_linked' === $code );
				$counts[ $linked ? 'linked' : 'errors' ]++;
				$pairs[] = array( 'source_id' => $sid, 'outcome' => $linked ? 'linked' : 'error', 'code' => $code );
				continue;
			}
			$ready = ( 'link' === ( $r['verdict'] ?? '' ) && ! empty( $r['can_apply'] ) );
			$counts[ $ready ? 'ready' : 'declined' ]++;
			$pairs[] = array(
				'source_id' => $sid,
				'outcome'   => $ready ? 'ready' : 'declined',
				'verdict'   => (string) ( $r['verdict'] ?? '' ),
				'anchor'    => $ready ? (string) $r['anchor'] : '',
			);
		}
		$notes[] = array_merge( $note, array( 'pairs' => $pairs ) );
	}
	return array( 'notes' => $notes, 'counts' => $counts, 'unavailable' => false );
}

/**
 * PURE future half: scheduled posts with their outbound note-link count.
 *
 * @param array<int,array{id:int,slug:string,date:string,content:string}> $future
 * @return array<int,array{post_id:int,slug:string,date_gmt:string,outbound:int}>
 */
function sn_inbound_pass_scheduled( $future ) {
	$out = array();
	foreach ( (array) $future as $p ) {
		$n     = preg_match_all( '#href=["\'][^"\']*/notes/[^"\']+#i', (string) ( $p['content'] ?? '' ) );
		$out[] = array(
			'post_id'  => (int) ( $p['id'] ?? 0 ),
			'slug'     => (string) ( $p['slug'] ?? '' ),
			'date_gmt' => (string) ( $p['date'] ?? '' ),
			'outbound' => (int) $n,
		);
	}
	return $out;
}

/** The cron body: gather, judge, store. */
function sn_inbound_pass_run() {
	$now = time();
	if ( ! function_exists( 'snt_ai_pair_suggest_impl' ) || ! function_exists( 'snt_ml_related_for_post' ) || ! function_exists( 'snt_ml_inbound_by_path' ) || ! function_exists( 'sn_path_join_key' ) ) {
		update_option( SN_INBOUND_PASS_STATUS, array( 'ran_at' => $now, 'state' => 'unavailable', 'reason' => 'module_missing', 'notes' => array(), 'counts' => array(), 'scheduled' => array() ), false );
		return;
	}
	$published = array();
	$rel       = array();
	foreach ( (array) get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
		$published[]      = array( 'id' => (int) $id, 'key' => sn_path_join_key( (string) get_permalink( (int) $id ) ) );
		$rel[ (int) $id ] = snt_ml_related_for_post( (int) $id, SN_INBOUND_PASS_RELATED );
	}
	$future = array();
	foreach ( (array) get_posts( array( 'post_type' => 'post', 'post_status' => 'future', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC' ) ) as $post ) {
		$future[] = array( 'id' => (int) $post->ID, 'slug' => (string) $post->post_name, 'date' => (string) $post->post_date_gmt, 'content' => (string) $post->post_content );
	}
	$sel    = sn_inbound_pass_select( $published, snt_ml_inbound_by_path(), $rel );
	$judged = sn_inbound_pass_judge( $sel['notes'], 'snt_ai_pair_suggest_impl' );
	update_option( SN_INBOUND_PASS_STATUS, array(
		'ran_at'    => $now,
		'state'     => $judged['unavailable'] ? 'unavailable' : 'ok',
		'reason'    => $judged['unavailable'] ? 'snt_ai_unavailable' : '',
		'published' => count( $published ),
		'deferred'  => $sel['deferred'],
		'notes'     => $judged['notes'],
		'counts'    => $judged['counts'],
		'scheduled' => sn_inbound_pass_scheduled( $future ),
	), false );
}

/** @return array|null The stored report, or null when the pass has never run. */
function sn_inbound_pass_report() {
	$r = get_option( SN_INBOUND_PASS_STATUS, null );
	return is_array( $r ) ? $r : null;
}

/** Verdict over the stored report. Never `critical`: this is an advisory. */
function sn_inbound_pass_health( $r ) {
	if ( ! is_array( $r ) ) {
		return array( 'status' => 'good', 'summary' => 'The inbound pass has not run yet.' );
	}
	if ( 'ok' !== ( $r['state'] ?? '' ) ) {
		return array( 'status' => 'recommended', 'summary' => sprintf( 'The inbound pass could not judge (%s); unlinked notes may exist with nothing saying so.', (string) ( $r['reason'] ?? 'unknown' ) ) );
	}
	$c        = (array) ( $r['counts'] ?? array() );
	$unlinked = (int) ( $c['notes'] ?? 0 );
	$no_out   = count( array_filter( (array) ( $r['scheduled'] ?? array() ), static fn( $s ) => 0 === (int) ( $s['outbound'] ?? 0 ) ) );
	$parts    = array();
	if ( (int) ( $c['ready'] ?? 0 ) > 0 ) {
		$parts[] = sprintf( '%d anchor(s) ready to apply for %d published note(s) with no inbound links', (int) $c['ready'], $unlinked );
	}
	if ( $no_out > 0 ) {
		$parts[] = sprintf( '%d scheduled note(s) carry no outbound note links', $no_out );
	}
	if ( array() !== $parts ) {
		return array( 'status' => 'recommended', 'summary' => implode( '; ', $parts ) . '.' );
	}
	return array( 'status' => 'good', 'summary' => sprintf( '%d published, %d with no inbound links, %d scheduled; nothing left to apply.', (int) ( $r['published'] ?? 0 ), $unlinked, count( (array) ( $r['scheduled'] ?? array() ) ) ) );
}

/** PRESENT: a transition into publish schedules one run after the ML rebuild. */
function sn_inbound_pass_on_transition( $new_status, $old_status, $post ) {
	if ( 'publish' !== (string) $new_status || 'publish' === (string) $old_status || 'post' !== (string) ( $post->post_type ?? '' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( SN_INBOUND_PASS_PUBLISH_HOOK ) ) {
		wp_schedule_single_event( time() + SN_INBOUND_PASS_PUBLISH_DELAY, SN_INBOUND_PASS_PUBLISH_HOOK );
	}
}

add_action( 'init', function () {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_INBOUND_PASS_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_INBOUND_PASS_HOOK );
	}
} );
add_action( SN_INBOUND_PASS_HOOK, 'sn_inbound_pass_run' );
add_action( SN_INBOUND_PASS_PUBLISH_HOOK, 'sn_inbound_pass_run' );
add_action( 'transition_post_status', 'sn_inbound_pass_on_transition', 10, 3 );
