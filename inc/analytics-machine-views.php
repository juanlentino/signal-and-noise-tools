<?php
/**
 * Signal & Noise Tools: the two machine views of S&N Analytics, Machine
 * readers (who reads, and for what) and Reader evidence (how each count is
 * known). The window, the reads, and the section order, shared by the native
 * painters (apps/sn-analytics/parts/painters/view-*.php) and the classic
 * bodies (inc/analytics-view-machine-readers.php, -reader-evidence.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The URL of one machine view in S&N Analytics.
 *
 * @param string $view machine-readers|reader-evidence.
 * @return string
 */
function snt_mr_analytics_view_url( $view ) {
	$page = function_exists( 'snt_analytics_page_url' ) ? snt_analytics_page_url() : admin_url( 'admin.php?page=sn-analytics' );
	return add_query_arg( 'sn_view', (string) $view, $page );
}

/** The windows the machine views offer; the sensor reads 1 to 90 days. */
const SN_MR_VIEW_WINDOWS = array( 7, 14, 30, 90 );

/**
 * The window for the page's shared range: itself when the sensor can read it,
 * else 90 days, said in one line.
 *
 * @param string $range The page's range token.
 * @return array{0:int,1:string} Days, and the note ('' when none).
 */
function snt_mr_view_window( $range ) {
	$days = (int) $range;
	if ( in_array( $days, SN_MR_VIEW_WINDOWS, true ) && (string) $days === (string) $range ) {
		return array( $days, '' );
	}
	return array( 90, __( 'The edge sensor keeps 90 days, so this view opens at 90 days for the page’s longer range.', 'signal-and-noise-tools' ) );
}

/**
 * Every read the two views paint, through the cached sensor fetch.
 *
 * @param int  $days     Window.
 * @param bool $evidence True for the Reader evidence reads (unnamed, anomalies).
 * @return array
 */
function snt_mr_view_data( $days, $evidence = false ) {
	$read = function_exists( 'snt_mr_fetch' ) ? snt_mr_fetch( $days ) : array( 'ok' => false, 'error' => 'unavailable' );
	$rows = ! empty( $read['ok'] ) && is_array( $read['rows'] ?? null ) ? $read['rows'] : array();
	$d    = array( 'days' => (int) $days, 'ok' => ! empty( $read['ok'] ), 'error' => (string) ( $read['error'] ?? '' ), 'rows' => $rows, 'truncated' => ! empty( $read['truncated'] ) );
	if ( ! $d['ok'] ) {
		return $d;
	}
	if ( $evidence ) {
		$unknown      = snt_mr_fetch( $days, 'unknown-network' );
		$d['unnamed'] = ! empty( $unknown['ok'] ) ? snt_mr_unnamed_fold( $unknown, PHP_INT_MAX ) : null;
		// A floor, not a count: the read was truncated, or an older worker answered only its top 50.
		$d['unnamed_floor'] = ! empty( $unknown['truncated'] ) || 'network' !== (string) ( $unknown['detail'] ?? '' );
		$d['report']   = function_exists( 'snt_ml_reader_anomalies' ) ? snt_ml_reader_anomalies() : null;
		return $d;
	}
	$feed         = function_exists( 'sn_rss_tracker_window_stats_multi' ) ? (array) sn_rss_tracker_window_stats_multi( array( 7, 30 ) ) : array();
	$rights       = snt_mr_fetch( $days, 'rights' );
	$win          = function_exists( 'snt_mr_split_windows' ) ? snt_mr_split_windows( $rows, max( 1, intdiv( $days, 2 ) ), gmdate( 'Y-m-d' ) ) : null;
	$d['feed']    = $feed;
	$d['rights']  = ! empty( $rights['ok'] ) ? (array) $rights['rows'] : null;
	$d['cards']   = null !== $win && function_exists( 'snt_mr_family_delta_cards' ) ? snt_mr_family_delta_cards( $win['current'] ?? array(), $win['prior'] ?? array(), max( 1, intdiv( $days, 2 ) ) ) : array();
	$d['hero']    = snt_mr_hero_figures( $rows, $days, isset( $feed['windows'][30]['total'] ) ? (int) $feed['windows'][30]['total'] : null, isset( $read['webmcp']['calls'] ) ? (int) $read['webmcp']['calls'] : null );
	return $d;
}

/** The one line a view paints when the sensor did not answer, with the way to its configuration. */
function snt_mr_view_unread( array $d, $surface, $config_link ) {
	/* translators: %s: the sensor's error code. */
	return snt_ev_lead( $surface, sprintf( __( 'The edge sensor did not answer (%s), so machine reads are not measured for this window.', 'signal-and-noise-tools' ), '' !== $d['error'] ? $d['error'] : 'unknown' ) ) . $config_link;
}

/**
 * Machine readers: who reads, and for what.
 *
 * @param array  $d       snt_mr_view_data( $days ).
 * @param string $surface kit|classic.
 * @param array  $links   evidence (to Reader evidence) and config (to Monitoring), painted with visible text.
 * @return string
 */
function snt_mr_view_machine_readers_html( array $d, $surface, array $links ) {
	if ( ! $d['ok'] ) {
		return snt_mr_view_unread( $d, $surface, $links['config'] ?? '' );
	}
	$since = defined( 'SN_RIGHTS_EVIDENCE_VERIFIED_SINCE' ) ? SN_RIGHTS_EVIDENCE_VERIFIED_SINCE : null;
	$cap   = $d['truncated'] ? snt_ev_verdict( $surface, esc_html__( 'The edge capped this read at its row limit, so every figure here is a floor, not a count. Narrow the window to get a complete one.', 'signal-and-noise-tools' ) ) : '';
	$hero  = '<div class="snt-ev-hero snt-ev-hero--band"><div class="snt-ev-mesh" aria-hidden="true"></div>' . $cap . snt_evidence_legend( $since )
		. snt_ev_paint_hero_tiles( $d['hero'], $surface ) . snt_ev_paint_finding( snt_mr_ai_finding( $d['rows'] ), $links['evidence'] ?? '', $surface ) . '</div>';
	return $hero
		. snt_ev_paint_delta_cards( $d['cards'], $surface )
		. snt_ev_pair( $surface, snt_ev_paint_who_reads( snt_mr_who_reads( $d['rows'] ), $surface ), snt_ev_paint_what_for( snt_mr_what_for( $d['rows'] ), $surface ) )
		. snt_ev_pair( $surface, snt_ev_paint_rights( $d['rights'], $surface ), snt_ev_paint_feeds( $d['feed'], $surface ) )
		. snt_ev_paint_reference( $d['rows'], $surface );
}

/**
 * Reader evidence: how each count is known.
 *
 * @param array  $d       snt_mr_view_data( $days, true ).
 * @param string $surface kit|classic.
 * @param array  $links   config (to Monitoring), painted with visible text.
 * @return string
 */
function snt_mr_view_reader_evidence_html( array $d, $surface, array $links ) {
	if ( ! $d['ok'] ) {
		return snt_mr_view_unread( $d, $surface, $links['config'] ?? '' );
	}
	$since = defined( 'SN_RIGHTS_EVIDENCE_VERIFIED_SINCE' ) ? SN_RIGHTS_EVIDENCE_VERIFIED_SINCE : null;
	$t     = snt_mr_claim_proof( $d['rows'] );
	$who   = snt_mr_who_reads( $d['rows'] );
	$disp  = snt_mr_basis_display( $d['rows'] );
	$from  = gmdate( 'Y-m-d', time() - $d['days'] * DAY_IN_SECONDS );
	return snt_ev_paint_claim_proof( $t, $since, $surface )
		. snt_ev_paint_proofs( snt_mr_identity_breakdown( $d['rows'] ), $t, $since, $surface )
		. snt_ev_paint_family_split( $who, $surface )
		. snt_ev_paint_purpose_split( $disp['purposes'], $surface )
		. snt_ev_paint_control_tokens( $disp['impersonations'], $surface )
		. snt_ev_paint_agents( snt_mr_agent_exceptions( $d['rows'] ), $surface )
		. snt_ev_paint_unnamed( $d['unnamed'], $surface, ! empty( $d['unnamed_floor'] ) )
		. snt_ev_paint_anomalies( snt_mr_verification_daily( $d['rows'], $from, gmdate( 'Y-m-d' ) ), $d['report'], $surface );
}
