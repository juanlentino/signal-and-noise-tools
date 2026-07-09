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

/** Placeholder — implemented in Task 3. @return array|null */
function sn_analytics_rec_unlinked() {
	return null;
}

/** Placeholder — implemented in Task 4. @return array|null */
function sn_analytics_rec_seo_meta() {
	return null;
}
