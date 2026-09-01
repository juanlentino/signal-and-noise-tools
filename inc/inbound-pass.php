<?php
/**
 * Signal & Noise Tools — the inbound pass: a freshly published note has
 * zero inbound links by construction, and nothing said so until the next
 * coverage reading a fortnight later.
 *
 * Measured 2026-09-01: every one of the 13 not-indexed notes had zero
 * inbound links from an indexed page, and every scheduled note (21) will be
 * born the same way — the link_candidates artifact and the pair-suggest
 * gate both require a PUBLISHED target, so nothing can pre-link a future
 * post. The lever is a link FROM an older, indexed note, and the day after
 * publication is when it is cheapest to add.
 *
 * What this does, daily: for each post published inside the window with
 * zero inbound links in the link graph, take its top related PUBLISHED
 * notes (the ML kernel's stored artifact) as candidate sources and run the
 * pair-suggest judgment (older note → new note). The verdict lands in the
 * durable verdict store, so a `link` with a valid anchor appears on the
 * link_opportunities worklist with the anchor already nominated — Apply is
 * one click. The stored report lists the same anchors, so the reader does
 * not need the worklist to see them.
 *
 * FAIL-CLOSED: an unavailable AI provider is `state: unavailable`, never a
 * report with zero pairs — zero pairs means "every new note is linked".
 * The artifact being unbuilt is reported per note (`artifact: unbuilt`), not
 * silently read as "no related notes".
 *
 * Costs one model call per pair, at most SN_INBOUND_PASS_MAX_PER_NOTE per
 * new note, and the verdict store memoizes on both modified stamps — a
 * second run over the same pair re-bills nothing.
 *
 * @package SignalNoiseTools
 * @since 13.68.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_INBOUND_PASS_HOOK         = 'sn_inbound_pass_daily';
const SN_INBOUND_PASS_STATUS       = 'sn_inbound_pass_last';
const SN_INBOUND_PASS_WINDOW_SECS  = 2 * DAY_IN_SECONDS;
const SN_INBOUND_PASS_MAX_PER_NOTE = 3;
const SN_INBOUND_PASS_RELATED      = 10;

/**
 * PURE selector: which new notes need sources, and which sources to judge.
 *
 * @param array<int,array{id:int,key:string}>         $recent  Posts published inside the window.
 * @param array<string,array{inbound:int}>            $inbound Link-graph counts keyed by join key.
 * @param array<int,array<int,array{post_id:int}>|null> $related id => related rows (published only), null = artifact unbuilt.
 * @return array<int,array{target_id:int,key:string,inbound:int,artifact:string,sources:int[]}>
 */
function sn_inbound_pass_select( $recent, $inbound, $related ) {
	$out = array();
	foreach ( (array) $recent as $p ) {
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
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$sid = (int) ( $r['post_id'] ?? 0 );
				if ( $sid > 0 && $sid !== $id && ! in_array( $sid, $sources, true ) ) {
					$sources[] = $sid;
				}
				if ( count( $sources ) >= SN_INBOUND_PASS_MAX_PER_NOTE ) {
					break;
				}
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
	return $out;
}

/**
 * PURE judge loop: one row per (source → target) pair, from a suggest callable.
 *
 * @param array    $selected Output of sn_inbound_pass_select().
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

/** The cron body: gather, judge, store. */
function sn_inbound_pass_run() {
	$now = time();
	if ( ! function_exists( 'snt_ai_pair_suggest_impl' ) || ! function_exists( 'snt_ml_related_for_post' ) || ! function_exists( 'snt_ml_inbound_by_path' ) || ! function_exists( 'sn_path_join_key' ) ) {
		update_option( SN_INBOUND_PASS_STATUS, array( 'ran_at' => $now, 'state' => 'unavailable', 'reason' => 'module_missing', 'notes' => array(), 'counts' => array() ), false );
		return;
	}
	$ids    = (array) get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'date_query'     => array( array( 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $now - SN_INBOUND_PASS_WINDOW_SECS ) ) ),
	) );
	$recent = array();
	$rel    = array();
	foreach ( $ids as $id ) {
		$recent[]          = array( 'id' => (int) $id, 'key' => sn_path_join_key( (string) get_permalink( (int) $id ) ) );
		$rel[ (int) $id ] = snt_ml_related_for_post( (int) $id, SN_INBOUND_PASS_RELATED );
	}
	$selected = sn_inbound_pass_select( $recent, snt_ml_inbound_by_path(), $rel );
	$judged   = sn_inbound_pass_judge( $selected, 'snt_ai_pair_suggest_impl' );
	update_option( SN_INBOUND_PASS_STATUS, array(
		'ran_at'      => $now,
		'window_secs' => SN_INBOUND_PASS_WINDOW_SECS,
		'state'       => $judged['unavailable'] ? 'unavailable' : 'ok',
		'reason'      => $judged['unavailable'] ? 'snt_ai_unavailable' : '',
		'published'   => count( $recent ),
		'notes'       => $judged['notes'],
		'counts'      => $judged['counts'],
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
		return array( 'status' => 'recommended', 'summary' => sprintf( 'The inbound pass could not judge (%s); new notes may be unlinked with nothing saying so.', (string) ( $r['reason'] ?? 'unknown' ) ) );
	}
	$c = (array) ( $r['counts'] ?? array() );
	if ( (int) ( $c['ready'] ?? 0 ) > 0 ) {
		return array( 'status' => 'recommended', 'summary' => sprintf( '%d anchor(s) ready to apply for %d new note(s) with no inbound links.', (int) $c['ready'], (int) ( $c['notes'] ?? 0 ) ) );
	}
	return array( 'status' => 'good', 'summary' => sprintf( '%d note(s) published in the window; %d with no inbound links; nothing left to apply.', (int) ( $r['published'] ?? 0 ), (int) ( $c['notes'] ?? 0 ) ) );
}

add_action( 'init', function () {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_INBOUND_PASS_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_INBOUND_PASS_HOOK );
	}
} );
add_action( SN_INBOUND_PASS_HOOK, 'sn_inbound_pass_run' );
