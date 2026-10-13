<?php
/**
 * After a related-notes rebuild, purge the notes whose list changed (2026-10-13).
 *
 * The rebuild runs about 30 seconds after a save (inc/ml-artifacts.php), and
 * the theme's "More on this" on OTHER notes reads what it writes. Since #2044
 * a save that changed no list purges only that note's tags, so those other
 * notes kept their old "More on this" until the 2-hour edge TTL. Nothing
 * purged after the rebuild.
 *
 * Now the rebuild's related lists are read before and after it runs, and only
 * the notes whose list changed have their tags purged: one call per rebuild
 * (the Free plan allows 5 tag purges a minute), at most 100 tags in it. Over
 * 100, or with a theme that does not tag notes one by one, the whole tag goes
 * instead. A rebuild that changed no list purges nothing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cloudflare takes at most this many tags in one purge call. */
const SNT_ML_RELATED_PURGE_MAX_TAGS = 100;

/**
 * Each published note's stored related list, as post IDs in order.
 *
 * @return array<int,array<int,int>>
 */
function snt_ml_related_lists() {
	$ids   = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
	$lists = array();
	foreach ( (array) $ids as $id ) {
		$rows               = get_post_meta( (int) $id, SNT_ML_RELATED_META, true );
		$lists[ (int) $id ] = is_array( $rows ) ? array_values( array_map( static fn( $r ) => (int) ( $r['post_id'] ?? 0 ), array_filter( $rows, 'is_array' ) ) ) : array();
	}
	return $lists;
}

/**
 * The notes whose related list differs between two readings. PURE.
 *
 * @param array<int,array<int,int>> $before Before the rebuild.
 * @param array<int,array<int,int>> $after  After it.
 * @return int[]
 */
function snt_ml_related_changed( array $before, array $after ) {
	$changed = array();
	foreach ( $after as $id => $list ) {
		if ( ( $before[ $id ] ?? array() ) !== $list ) {
			$changed[] = (int) $id;
		}
	}
	return $changed;
}

/**
 * Before the rebuild (priority 5): keep the lists as they are, only when a
 * purge could follow (a configured zone and a theme that tags its pages).
 *
 * @return void
 */
function snt_ml_related_purge_before() {
	unset( $GLOBALS['snt_ml_related_before'] );
	if ( function_exists( 'sn_cf_is_configured' ) && function_exists( 'sn_cf_tagged' ) && sn_cf_is_configured() && sn_cf_tagged() ) {
		$GLOBALS['snt_ml_related_before'] = snt_ml_related_lists();
	}
}

/**
 * After the rebuild (priority 20): purge the tags of the notes whose list
 * changed, in one call, and record it in the purge ledger.
 *
 * @return void
 */
function snt_ml_related_purge_after() {
	$before = $GLOBALS['snt_ml_related_before'] ?? null;
	unset( $GLOBALS['snt_ml_related_before'] );
	if ( ! is_array( $before ) || ! function_exists( 'sn_cf_api_send' ) || ! function_exists( 'sn_cf_send_last' ) ) {
		return;
	}
	$changed = snt_ml_related_changed( $before, snt_ml_related_lists() );
	if ( array() === $changed ) {
		return;
	}
	// The theme owns the tag names; PHPStan reads a function_exists guard only
	// in the function that holds it, so this one carries its own.
	$per_note  = function_exists( 'sn_edge_cache_note_tag' ) && count( $changed ) <= SNT_ML_RELATED_PURGE_MAX_TAGS;
	$tags      = $per_note
		? array_map( static fn( $id ) => (string) sn_edge_cache_note_tag( $id ), $changed )
		: array( (string) constant( 'SN_EDGE_CACHE_TAG' ) );
	$confirmed = sn_cf_api_send( 'purge_cache', array( 'tags' => $tags ) );
	if ( ( $confirmed || 'queued' === sn_cf_send_last() ) && function_exists( 'snt_purge_ledger_add' ) ) {
		snt_purge_ledger_add( array( 'trigger' => 'related', 'scope' => $per_note ? 'note' : 'tag', 'redis' => false, 'pages' => false, 'edge' => $confirmed, 'cloudways' => 'not run' ) );
	}
}

foreach ( array( SNT_ML_REBUILD_HOOK, SNT_ML_REBUILD_ASYNC_HOOK ) as $snt_ml_hook ) {
	add_action( $snt_ml_hook, 'snt_ml_related_purge_before', 5 );
	add_action( $snt_ml_hook, 'snt_ml_related_purge_after', 20 );
}
unset( $snt_ml_hook );
