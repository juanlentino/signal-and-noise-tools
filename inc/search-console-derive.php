<?php
/**
 * Signal & Noise Tools — derived Search Console signals (wiring items 2 + 3).
 *
 * The R6b close recorded three crossings in value order; v13.10.0 shipped
 * item 1 (seen-but-never-clicked). This file ships the other two, each
 * shaped by a caution recorded WITH the plan:
 *
 * ITEM 2 — POSITION DRIFT (the early decay signal). Google's ranking moves
 * before views do, and it is the only signal at all for pages that never
 * had traffic. Computed from the bounded history the store now keeps —
 * and deliberately NOT fed into the lifecycle producer: the recorded
 * caution is that this window is lagged 3 days and aggregate while the
 * lifecycle's inputs are current and per-post; mixing introduces
 * time-skew. Drift stands beside view-decay as its own instrument.
 *
 * ITEM 3 — SEARCH INTEREST BY TOPIC. The recorded caution: queries are
 * Google's language about a page, not the author's — mixing them into a
 * corpus-derived model conflates two sources. So NO QUERY TEXT enters any
 * model here. The join is by PAGE: the window's page metrics aggregate up
 * to the topic partition the corpus already owns (cluster members → their
 * paths → summed impressions/clicks, impression-weighted position). The
 * ML kernel is read, never written.
 *
 * Thresholds are written down before the query (the Defense-numbers
 * idiom) and pinned by tests.
 *
 * @package SignalNoiseTools
 * @since 13.11.0
 */

defined( 'ABSPATH' ) || exit;

// Pre-committed: a page must worsen by at least this many positions ...
const SNT_GSC_DRIFT_FLOOR = 5.0;
// ... across at least this span, while still being shown this much.
const SNT_GSC_DRIFT_MIN_SPAN_DAYS   = 7;
const SNT_GSC_DRIFT_MIN_IMPRESSIONS = 10;

/**
 * Pages whose average position worsened materially across the history span.
 *
 * NULL when the history cannot answer yet (fewer than two snapshots at
 * least SNT_GSC_DRIFT_MIN_SPAN_DAYS apart) — "accruing" is not "no drift".
 * [] when the history CAN answer and nothing drifts — a real, good zero.
 * Otherwise path => {from, to, drift, impressions}, worst first. Drift is
 * positive when WORSE (position grows downward the rankings).
 *
 * @return array<string,array{from:float,to:float,drift:float,impressions:int}>|null
 */
function snt_gsc_position_drift() {
	$history = function_exists( 'snt_gsc_history' ) ? snt_gsc_history() : array();
	if ( count( $history ) < 2 ) {
		return null;
	}
	$entries = array_values( $history ); // chronological (store ksorts).
	$newest  = end( $entries );
	$oldest  = null;
	foreach ( $entries as $e ) { // first entry far enough back wins: longest span.
		$span = ( strtotime( $newest['end'] ) - strtotime( $e['end'] ) ) / DAY_IN_SECONDS;
		if ( $span >= SNT_GSC_DRIFT_MIN_SPAN_DAYS ) {
			$oldest = $e;
			break;
		}
	}
	if ( null === $oldest ) {
		return null;
	}
	$out = array();
	foreach ( $newest['pages'] as $path => $now ) {
		if ( ! isset( $oldest['pages'][ $path ] ) ) {
			continue; // a page Google only just started showing has no drift yet.
		}
		if ( (int) $now['impressions'] < SNT_GSC_DRIFT_MIN_IMPRESSIONS ) {
			continue; // barely shown: movement is noise, not a signal.
		}
		$from  = (float) $oldest['pages'][ $path ]['position'];
		$to    = (float) $now['position'];
		$drift = $to - $from;
		if ( $drift >= SNT_GSC_DRIFT_FLOOR ) {
			$out[ $path ] = array(
				'from'        => $from,
				'to'          => $to,
				'drift'       => round( $drift, 1 ),
				'impressions' => (int) $now['impressions'],
			);
		}
	}
	uasort( $out, static function ( $a, $b ) { return $b['drift'] <=> $a['drift']; } );
	return $out;
}

/**
 * The stored window's page metrics aggregated up to the topic partition.
 *
 * NULL when either instrument is missing (no window, or the topics artifact
 * was never built) — unknown, never a fabricated empty. Otherwise clusters
 * worst... no: MOST-SHOWN first, each {label, members, impressions, clicks,
 * position (impression-weighted), paths_matched}, plus an 'outside' residual
 * for window pages no cluster claims (site pages, unclustered notes) — the
 * remainder is stated, not dropped (a scan is its exclusions).
 *
 * @return array{clusters:array<int,array>,outside:array{impressions:int,clicks:int,paths:int}}|null
 */
function snt_gsc_topic_interest() {
	if ( ! function_exists( 'snt_gsc_data' ) || ! function_exists( 'snt_ml_topics_get' ) ) {
		return null;
	}
	$data     = snt_gsc_data();
	$clusters = snt_ml_topics_get();
	if ( null === $data || null === $clusters || empty( $data['pages'] ) ) {
		return null;
	}
	$pages   = (array) $data['pages'];
	$claimed = array();
	$rows    = array();
	foreach ( $clusters as $c ) {
		$imp = 0; $clicks = 0; $pos_weighted = 0.0; $matched = 0;
		foreach ( (array) ( $c['members'] ?? array() ) as $post_id ) {
			$path = snt_gsc_url_to_path( (string) get_permalink( (int) $post_id ) );
			if ( '' === $path || ! isset( $pages[ $path ] ) ) {
				continue;
			}
			$m             = $pages[ $path ];
			$imp          += (int) $m['impressions'];
			$clicks       += (int) $m['clicks'];
			$pos_weighted += (float) $m['position'] * (int) $m['impressions'];
			$matched++;
			$claimed[ $path ] = true;
		}
		$rows[] = array(
			'label'         => (string) ( $c['label'] ?? '' ),
			'members'       => count( (array) ( $c['members'] ?? array() ) ),
			'impressions'   => $imp,
			'clicks'        => $clicks,
			// Impression-weighted, the store's own merging rule; 0 impressions
			// yields position 0, rendered as an em dash by the view, because a
			// cluster Google never showed HAS no average position.
			'position'      => $imp > 0 ? round( $pos_weighted / $imp, 1 ) : 0.0,
			'paths_matched' => $matched,
		);
	}
	usort( $rows, static function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
	$out_imp = 0; $out_clicks = 0; $out_paths = 0;
	foreach ( $pages as $path => $m ) {
		if ( isset( $claimed[ $path ] ) ) {
			continue;
		}
		$out_imp    += (int) $m['impressions'];
		$out_clicks += (int) $m['clicks'];
		$out_paths++;
	}
	return array(
		'clusters' => $rows,
		'outside'  => array( 'impressions' => $out_imp, 'clicks' => $out_clicks, 'paths' => $out_paths ),
	);
}
