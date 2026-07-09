<?php
/**
 * Signal & Noise Tools — Analytics → Intelligence recommendations engine (slice b).
 *
 * Pure rules over already-cached signals: each rule reads a durable/transient-
 * cached source (never triggers an expensive live scan) and returns at most one
 * actionable card deep-linked to the tool that acts on it. Cookieless: rules read
 * aggregate counts + published-content metadata only, never per-person data.
 *
 * @package SignalNoiseTools
 * @since 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the ordered recommendation card list. Each rule returns one card or null;
 * nulls are filtered. Empty result is first-class ("nothing needs attention").
 *
 * @return array<int,array{id:string,title:string,detail:string,count:int,action_url:string,action_label:string}>
 */
function sn_analytics_recommendations() {
	$cards = array(
		sn_analytics_rec_refresh(),
		sn_analytics_rec_unlinked(),
		sn_analytics_rec_seo_meta(),
	);
	return array_values( array_filter( $cards ) );
}

/**
 * Refresh rule: cooling, non-evergreen posts worth a refresh, from the transient-
 * cached lifecycle bundle. Deep-links to the Posts view (which renders the refresh
 * queue). Null when there is no bundle or zero candidates.
 *
 * @return array|null
 */
function sn_analytics_rec_refresh() {
	$bundle = function_exists( 'sn_analytics_posts_lifecycle' ) ? sn_analytics_posts_lifecycle() : null;
	$n      = is_array( $bundle ) ? (int) ( $bundle['summary']['refresh_candidates'] ?? 0 ) : 0;
	if ( $n < 1 ) {
		return null;
	}
	return array(
		'id'           => 'refresh',
		'title'        => sprintf( _n( '%d cooling post worth a refresh', '%d cooling posts worth a refresh', $n, 'signal-and-noise-tools' ), $n ),
		'detail'       => 'These posts are past their peak and not marked evergreen — a refresh can revive them.',
		'count'        => $n,
		'action_url'   => admin_url( 'index.php?page=sn-analytics&sn_view=posts' ),
		'action_label' => 'Open the refresh queue',
	);
}

/**
 * Unlinked-mentions rule: notes that title-mention another note without linking
 * it, read from the DURABLE cached Health scan (sn_health_last_scan() — a
 * get_option, never a live re-scan; the check itself is an O(n^2) TF-IDF pass).
 * Deep-links to the Health tab where Suggest/Apply lives. Null when no scan or
 * zero mentions.
 *
 * @return array|null
 */
function sn_analytics_rec_unlinked() {
	$scan = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	$n    = is_array( $scan ) ? (int) ( $scan['checks']['unlinked_mentions']['count'] ?? 0 ) : 0;
	if ( $n < 1 ) {
		return null;
	}
	return array(
		'id'           => 'unlinked',
		'title'        => sprintf( _n( '%d unlinked mention between notes', '%d unlinked mentions between notes', $n, 'signal-and-noise-tools' ), $n ),
		'detail'       => 'One note names another without linking it. Health → Suggest proposes an anchor already in your prose.',
		'count'        => $n,
		'action_url'   => admin_url( 'admin.php?page=sn-theme-options&tab=health' ),
		'action_label' => 'Review link suggestions',
	);
}

/** Placeholder — implemented in Task 4. @return array|null */
function sn_analytics_rec_seo_meta() {
	return null;
}
