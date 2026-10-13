<?php
/**
 * Which edge purge a note save needs (2026-10-13).
 *
 * Since #1850 every save of a published post purged the theme's whole cache
 * tag, and one tag purge empties every tagged page on the site. The week to
 * 2026-10-13: 40 save purges, about 5.7 a day, in bursts of 3 to 6 within
 * seconds. Pages stayed fresh; the cost was the edge hit rate.
 *
 * Other pages carry a note only through what the theme LISTS: the site-wide
 * notes index on every page (title, link, date order), index and tag rows
 * (title, date, excerpt, reading time, tags), related notes, link previews
 * (title, a summary, date, reading time) and cited-by (which note links to
 * which). A save that changed none of that changed the note's own page and
 * the feeds that carry its body, so it purges two tags in one call: the
 * note's own (every copy of its page, whatever the query string, in every
 * tier) and the lists' (home, /notes/, archives, /provenance/, feeds,
 * llms.txt). Core's sitemaps carry no tag and go by URL, as before. The
 * post-save probe checks the note's page afterward.
 *
 * Not URLs: Cloudflare keys a cached copy by its query string, so a share or
 * feed link (?utm_source=...) is its own copy a URL purge never names, and a
 * URL purge has missed under Tiered Cache before (inc/cloudflare-purge-verify.php).
 *
 * Everything else keeps the whole tag: a theme without per-note tags, a
 * change to any listed field, a first publish, leaving publish, a page, or a
 * save whose "before" this request never captured (a scheduled post going
 * live skips pre_post_update). When in doubt, the whole tag.
 *
 * NOT DEBOUNCED, on purpose. The bursts are separate HTTP requests (the block
 * editor's save, then its meta-box save). Coalescing across requests means
 * either skipping a purge ("one ran seconds ago"), which cannot tell a repeat
 * from a change made since, or deferring it to WP-Cron, which has no
 * seconds-level timing and can drop it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Words of the opening compared: more than the index excerpt (55) and the
 * link preview (28) show, so a change either of them could show is seen.
 */
const SN_CF_LISTED_WORDS = 60;

/**
 * Does the theme tag single notes and the lists apart (the theme after 2026.10.1)?
 * Without both, a save keeps the whole tag.
 *
 * @return bool
 */
function sn_cf_note_tags_supported() {
	return sn_cf_tagged() && defined( 'SN_EDGE_CACHE_LIST_TAG' ) && '' !== (string) constant( 'SN_EDGE_CACHE_LIST_TAG' ) && function_exists( 'sn_edge_cache_note_tag' );
}

/**
 * What other pages show of a post. PURE over the post and what was read
 * beside it. Never the raw body or post_modified: those change on every save.
 *
 * @param object $post  Post object.
 * @param array  $extra Terms, thumbnail and sticky, read by the caller.
 * @return array
 */
function sn_cf_listed_fields( $post, array $extra ) {
	$content = (string) ( $post->post_content ?? '' );
	// The theme's cited-by boundary (sn_cited_by_link_pattern): the slug ends
	// at a slash, a quote, a query, a fragment or the end, so /notes/a.md is
	// not a link to /notes/a/.
	preg_match_all( '~/notes/([a-z0-9-]+)(?=[/"\'?#]|$)~i', $content, $m );
	$links = array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	sort( $links );
	// The link preview strips the body as is; the index excerpt drops blocks
	// an excerpt never shows and shortcodes first. Both openings are compared.
	$for_excerpt = function_exists( 'excerpt_remove_blocks' ) ? excerpt_remove_blocks( $content ) : $content;
	$for_excerpt = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $for_excerpt ) : $for_excerpt;
	return array(
		'type'      => (string) ( $post->post_type ?? '' ),
		'status'    => (string) ( $post->post_status ?? '' ),
		'title'     => (string) ( $post->post_title ?? '' ),
		'slug'      => (string) ( $post->post_name ?? '' ),
		'date'      => (string) ( $post->post_date ?? '' ),
		'password'  => (string) ( $post->post_password ?? '' ),
		// Untrimmed: '' and ' ' differ (an excerpt of spaces still replaces the body's opening).
		'excerpt'   => (string) ( $post->post_excerpt ?? '' ),
		'opening'   => sn_cf_listed_opening( $content ),
		'opening_x' => sn_cf_listed_opening( $for_excerpt ),
		'minutes'   => function_exists( 'sn_calculate_reading_time' ) ? (int) sn_calculate_reading_time( $post ) : str_word_count( strip_tags( $content ) ),
		'links'     => $links,
	) + $extra;
}

/**
 * The first SN_CF_LISTED_WORDS words of a body, stripped as WordPress strips
 * it (script and style bodies go too).
 *
 * @param string $html Body.
 * @return string
 */
function sn_cf_listed_opening( $html ) {
	$text  = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $html ) : strip_tags( (string) $html );
	$words = preg_split( '/\s+/u', trim( $text ), SN_CF_LISTED_WORDS + 1, PREG_SPLIT_NO_EMPTY );
	return implode( ' ', array_slice( (array) $words, 0, SN_CF_LISTED_WORDS ) );
}

/**
 * The listed fields of a post as stored now, or null when they cannot be read.
 *
 * @param object $post Post object.
 * @return array|null
 */
function sn_cf_listed_snapshot( $post ) {
	if ( ! is_object( $post ) || ! function_exists( 'wp_get_object_terms' ) || ! function_exists( 'get_post_meta' ) ) {
		return null;
	}
	$terms = wp_get_object_terms( (int) $post->ID, array( 'post_tag', 'category' ), array( 'fields' => 'ids' ) );
	if ( is_wp_error( $terms ) ) {
		return null;
	}
	$terms = array_map( 'intval', (array) $terms );
	sort( $terms );
	return sn_cf_listed_fields(
		$post,
		array(
			'terms'     => $terms,
			'thumbnail' => (int) get_post_meta( (int) $post->ID, '_thumbnail_id', true ),
			'sticky'    => function_exists( 'is_sticky' ) && is_sticky( (int) $post->ID ),
		)
	);
}

/**
 * Before the row changes (pre_post_update): keep what other pages show now.
 * Terms, the thumbnail and REST meta are written after the row and before
 * wp_after_insert_post, so this is the only moment the old values exist.
 * The first capture in a request wins: a nested update compares against the
 * state before all of them, which can only widen the purge.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function sn_cf_listed_capture( $post_id ) {
	$post_id = (int) $post_id;
	$post    = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
	// Only a published note on a configured install whose theme tags notes can take the
	// narrow path; anything else would read terms for nothing.
	if ( is_object( $post ) && 'post' === $post->post_type && 'publish' === $post->post_status
		&& sn_cf_note_tags_supported() && sn_cf_is_configured() && ! isset( $GLOBALS['sn_cf_listed_before'][ $post_id ] ) ) {
		$GLOBALS['sn_cf_listed_before'][ $post_id ] = sn_cf_listed_snapshot( $post );
	}
}
add_action( 'pre_post_update', 'sn_cf_listed_capture', 10, 1 );

/**
 * Whether this save may purge by URL instead of the tag. Consumes the capture.
 *
 * @param int    $post_id     Post ID.
 * @param object $post        The post as saved.
 * @param mixed  $post_before The post before the save (core columns only).
 * @return bool
 */
function sn_cf_save_is_narrow( $post_id, $post, $post_before ) {
	$before = $GLOBALS['sn_cf_listed_before'][ (int) $post_id ] ?? null;
	unset( $GLOBALS['sn_cf_listed_before'][ (int) $post_id ] );
	if ( null === $before || ! sn_cf_note_tags_supported() || ! is_object( $post_before ) || 'post' !== $post->post_type
		|| 'publish' !== $post->post_status || 'publish' !== $post_before->post_status ) {
		return false;
	}
	return $before === sn_cf_listed_snapshot( $post );
}

/**
 * Purge one note's tag and the lists' tag in one call, its sitemaps by URL,
 * and schedule the post-save probe. A call Cloudflare refused outright falls
 * back to the whole tag.
 *
 * @param int    $post_id Post ID.
 * @param object $post    The post as saved.
 * @return bool Handled (confirmed or queued to retry).
 */
function sn_cf_purge_note( $post_id, $post ) {
	// The theme owns the tag names; PHPStan reads a function_exists guard only
	// in the function that holds it, so this one carries its own.
	if ( ! function_exists( 'sn_edge_cache_note_tag' ) ) {
		return sn_cf_purge_tag();
	}
	$tags      = array( (string) sn_edge_cache_note_tag( (int) $post_id ), (string) constant( 'SN_EDGE_CACHE_LIST_TAG' ) );
	$confirmed = sn_cf_api_send( 'purge_cache', array( 'tags' => $tags ) );
	$handled   = $confirmed || 'queued' === sn_cf_send_last();
	if ( ! $handled ) {
		$handled = sn_cf_purge_tag();
	} elseif ( function_exists( 'snt_purge_ledger_add' ) ) {
		snt_purge_ledger_add( array( 'trigger' => 'save', 'scope' => 'note', 'redis' => false, 'pages' => false, 'edge' => $confirmed, 'cloudways' => 'not run' ) );
	}
	sn_cf_purge_urls( sn_cf_sitemap_urls( (string) $post->post_type ) );
	sn_cf_schedule_probe( $post_id );
	return $handled;
}
