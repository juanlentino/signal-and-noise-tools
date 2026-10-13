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
 * nothing else, so it purges by URL: the note, the lists and feeds, the
 * sitemaps, its tag and category archives and /notes/tags/.
 *
 * Everything else keeps the tag purge: a change to any listed field, a first
 * publish, leaving publish, a page, or a save whose "before" this request
 * never captured (a scheduled post going live skips pre_post_update). When in
 * doubt, the tag.
 *
 * NOT DEBOUNCED, on purpose. The bursts are separate HTTP requests (the block
 * editor's save, then its meta-box save). Coalescing across requests means
 * either skipping a purge ("one ran seconds ago"), which cannot tell a repeat
 * from a change made since, or deferring it to WP-Cron, which has no
 * seconds-level timing and can drop it. The fingerprint already makes the
 * repeats cheap: an unchanged save is a URL purge, not a tag purge.
 *
 * The .md copy of a note is not purged: the Worker stores no .md object at
 * the edge; it converts /notes/<slug>/, which this purges (theme
 * inc/note-markdown.php).
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
 * What other pages show of a post. PURE over the post and what was read
 * beside it. Never the raw body or post_modified: those change on every save.
 *
 * @param object $post  Post object.
 * @param array  $extra Terms, thumbnail and sticky, read by the caller.
 * @return array
 */
function sn_cf_listed_fields( $post, array $extra ) {
	$content = (string) ( $post->post_content ?? '' );
	preg_match_all( '#/notes/([a-z0-9-]+)#i', $content, $m );
	$links = array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	sort( $links );
	// The index excerpt skips blocks an excerpt never shows; the link preview
	// reads the stripped body as is. Both openings are compared.
	$for_excerpt = function_exists( 'excerpt_remove_blocks' ) ? excerpt_remove_blocks( $content ) : $content;
	return array(
		'type'      => (string) ( $post->post_type ?? '' ),
		'status'    => (string) ( $post->post_status ?? '' ),
		'title'     => (string) ( $post->post_title ?? '' ),
		'slug'      => (string) ( $post->post_name ?? '' ),
		'date'      => (string) ( $post->post_date ?? '' ),
		'password'  => (string) ( $post->post_password ?? '' ),
		'parent'    => (int) ( $post->post_parent ?? 0 ),
		'excerpt'   => trim( (string) ( $post->post_excerpt ?? '' ) ),
		'opening'   => sn_cf_listed_opening( $content ),
		'opening_x' => sn_cf_listed_opening( $for_excerpt ),
		'minutes'   => function_exists( 'sn_calculate_reading_time' ) ? (int) sn_calculate_reading_time( $post ) : str_word_count( strip_tags( $content ) ),
		'links'     => $links,
	) + $extra;
}

/**
 * The first SN_CF_LISTED_WORDS words of a body, tags stripped. PURE.
 *
 * @param string $html Body.
 * @return string
 */
function sn_cf_listed_opening( $html ) {
	$words = preg_split( '/\s+/u', trim( strip_tags( (string) $html ) ), SN_CF_LISTED_WORDS + 1, PREG_SPLIT_NO_EMPTY );
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
	// Only a published note on a tagging, configured install can take the
	// narrow path; anything else would read terms for nothing.
	if ( is_object( $post ) && 'post' === $post->post_type && 'publish' === $post->post_status
		&& sn_cf_tagged() && sn_cf_is_configured() && ! isset( $GLOBALS['sn_cf_listed_before'][ $post_id ] ) ) {
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
	if ( null === $before || ! is_object( $post_before ) || 'post' !== $post->post_type
		|| 'publish' !== $post->post_status || 'publish' !== $post_before->post_status ) {
		return false;
	}
	return $before === sn_cf_listed_snapshot( $post );
}

/**
 * Purge one note by URL and write its ledger row (scope 'note'). Within the
 * single-file limit: Cloudflare takes 100 URLs a call, sn_cf_purge_urls()
 * sends 30.
 *
 * @param int    $post_id Post ID.
 * @param object $post    The post as saved.
 * @return bool Handled (confirmed or queued to retry).
 */
function sn_cf_purge_note( $post_id, $post ) {
	$urls  = sn_cf_post_purge_urls( $post_id, $post );
	$terms = wp_get_object_terms( (int) $post_id, array( 'post_tag', 'category' ) );
	foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $term ) {
		$link = get_term_link( $term );
		if ( is_string( $link ) ) {
			$urls[] = $link;
		}
	}
	$urls[]  = home_url( '/notes/tags/' );
	$handled = sn_cf_purge_urls( $urls );
	if ( $handled && function_exists( 'snt_purge_ledger_add' ) ) {
		snt_purge_ledger_add( array( 'trigger' => 'save', 'scope' => 'note', 'redis' => false, 'pages' => false, 'edge' => 'ok' === sn_cf_send_last(), 'cloudways' => 'not run' ) );
	}
	return $handled;
}
