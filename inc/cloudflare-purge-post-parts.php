<?php
/**
 * Signal & Noise: Cloudflare cache purge, the purge on save (the post's URL list, the archive pages, the machine URLs, the sitemaps, the wp_after_insert_post hook).
 *
 * Split from inc/cloudflare-purge.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-purge: when a published post is saved, purge that post's URL
 * plus the index URLs that may list/link it (homepage, /notes/,
 * /provenance/, RSS feed). Skips revisions, autosaves, and non-
 * published statuses — only "publish" transitions trigger purges.
 *
 * Filterable: `sn_cf_purge_urls_for_post` lets future code add or
 * remove URLs from the purge list (e.g., taxonomy archives).
 */
/**
 * Every URL a post save invalidates.
 *
 * Extracted from the wp_after_insert_post closure so a test can drive it; the
 * closure could not be called without a WordPress boot, which is why the set it
 * built was never checked against what the edge actually caches.
 *
 * WHAT WAS MISSING (#1008). The list was five hardcoded URLs. Measured against
 * the live edge, three CACHED surfaces were absent from it:
 *
 *   /notes/page/2/ (also 3, 4)     cf-cache-status: HIT
 *   /wp-sitemap.xml                cf-cache-status: HIT
 *   /wp-sitemap-posts-post-1.xml   cf-cache-status: HIT
 *
 * Pagination matters more than it looks: publishing shifts every item across
 * page boundaries, so the pages most likely to be wrong were exactly the ones
 * never purged. And a stale sitemap delays crawler and AI-reader discovery of a
 * new note, which on this site is the point rather than a detail.
 *
 * PAGE COUNT IS DERIVED, NOT LISTED. A hard-coded page count is correct the day
 * it is written and wrong the first time the corpus grows past it - the same rot
 * that put a hardcoded five-URL list here in the first place. Capped, because an
 * unbounded set turns one save into an unbounded number of API calls.
 *
 * NOT ADDED, checked first: `/notes/tags/` is `cf-cache-status: BYPASS` with
 * `no-store, private`, so it cannot go stale; `/feed/` revalidates on every
 * request. Purging either would be motion without effect.
 *
 * @since 13.96.2
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return string[] Absolute URLs, filtered through `sn_cf_purge_urls_for_post`.
 */
function sn_cf_post_purge_urls( $post_id, $post ) {
	// The permalink of the post OBJECT handed in, not a fresh read by id
	// (#1180): on unpublish/trash the caller passes the pre-change post, and a
	// read by id would answer the plain ?p= form the edge never cached.
	$urls = array(
		get_permalink( $post ),
		home_url( '/' ),
		home_url( '/notes/' ),
		home_url( '/provenance/' ),
		home_url( '/notes/feed/' ),
	);

	// If the saved post is a child page (e.g., /provenance/over-detection/),
	// also purge the parent so its referring listings refresh.
	$parent_id = (int) $post->post_parent;
	if ( $parent_id ) {
		$urls[] = get_permalink( $parent_id );
	}

	foreach ( sn_cf_archive_page_urls( home_url( '/notes/' ), $post->post_type ) as $paged ) {
		$urls[] = $paged;
	}
	foreach ( sn_cf_sitemap_urls( $post->post_type ) as $sitemap ) {
		$urls[] = $sitemap;
	}
	foreach ( sn_cf_machine_urls() as $machine ) {
		$urls[] = $machine;
	}

	/** This filter's contract is unchanged: it still sees the final set. */
	return (array) apply_filters( 'sn_cf_purge_urls_for_post', $urls, $post_id, $post );
}

/** Hard ceiling on paginated archive URLs added per save. */
const SN_CF_MAX_ARCHIVE_PAGES = 20;

/**
 * Paginated pages of an archive, page 2 upward.
 *
 * Page 1 is the archive URL itself and is already in the set; adding
 * `/page/1/` would purge a URL nothing links to.
 *
 * @param string $base      Archive URL, trailing-slashed.
 * @param string $post_type Post type whose count drives the page count.
 * @return string[]
 */
function sn_cf_archive_page_urls( $base, $post_type ) {
	if ( ! function_exists( 'wp_count_posts' ) || ! function_exists( 'get_option' ) ) {
		return array();
	}
	$counts = wp_count_posts( $post_type );
	$published = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	$per_page  = (int) get_option( 'posts_per_page', 10 );
	if ( $published < 1 || $per_page < 1 ) {
		return array();
	}

	$pages = (int) ceil( $published / $per_page );
	if ( $pages > SN_CF_MAX_ARCHIVE_PAGES ) {
		$pages = SN_CF_MAX_ARCHIVE_PAGES;
	}

	$out = array();
	for ( $n = 2; $n <= $pages; $n++ ) {
		$out[] = trailingslashit( $base ) . 'page/' . $n . '/';
	}

	return $out;
}

/**
 * The feeds and the two llms files: what a reader or an agent polls for a
 * new note.
 *
 * The earlier note above ("/feed/ revalidates on every request") stopped being
 * true when the theme gave these five minutes at the edge with stale serving:
 * the edge now holds them, so a save has to purge them.
 *
 * THE THEME'S LIST, NOT A COPY OF IT. The theme caches a closed set of feed
 * addresses (sn_edge_cache_feed_paths(): every core format plus JSON at both
 * bases) and leaves every other feed on no-cache; this purges exactly that
 * set, so a format the theme adds is purged without an edit here. A theme
 * without the function caches none of this, and there is nothing to purge.
 *
 * NOT ADDED, checked first: /.well-known/agents.json and /opensearch.xml carry
 * nothing a post save changes, so purging them would be motion without effect.
 *
 * @return string[]
 */
function sn_cf_machine_urls() {
	if ( ! function_exists( 'home_url' ) || ! function_exists( 'sn_edge_cache_feed_paths' ) ) {
		return array();
	}
	$paths = array_merge( (array) sn_edge_cache_feed_paths(), array( '/llms.txt', '/llms-full.txt' ) );
	return array_map( 'home_url', array_values( array_filter( $paths, 'is_string' ) ) );
}

/** WordPress core lists at most this many URLs per sitemap page. */
const SN_CF_SITEMAP_MAX_URLS = 2000;

/**
 * The sitemap index plus the sub-sitemap pages listing this post type.
 *
 * The index is where a crawler starts; the sub-sitemap is where the new note
 * actually appears. Both are edge-cached and neither was purged.
 *
 * @param string $post_type Post type.
 * @return string[]
 */
function sn_cf_sitemap_urls( $post_type ) {
	if ( ! function_exists( 'home_url' ) ) {
		return array();
	}
	$urls = array( home_url( '/wp-sitemap.xml' ) );

	$published = 0;
	if ( function_exists( 'wp_count_posts' ) ) {
		$counts    = wp_count_posts( $post_type );
		$published = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
	// At least one page always exists once anything is published; core paginates
	// at SN_CF_SITEMAP_MAX_URLS, so the count is derived rather than assumed to
	// be 1 - it is 1 today and silently would not be at 2001 notes.
	$pages = $published > 0 ? (int) ceil( $published / SN_CF_SITEMAP_MAX_URLS ) : 0;
	for ( $n = 1; $n <= $pages; $n++ ) {
		$urls[] = home_url( '/wp-sitemap-posts-' . $post_type . '-' . $n . '.xml' );
	}

	return $urls;
}

add_action( 'wp_after_insert_post', function( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	$is_published  = 'publish' === $post->post_status;
	$was_published = ( $post_before instanceof WP_Post ) && 'publish' === $post_before->post_status;
	if ( ! $is_published && ! $was_published ) {
		return; // never on the edge: draft -> draft, draft -> trash, and so on.
	}
	if ( ! sn_cf_is_configured() ) {
		return;
	}

	// #1180: LEAVING publish (unpublish, trash, private) purges the same set
	// an edit does, computed from the PRE-CHANGE post -- the permalink the
	// edge actually holds. $post already carries the new status, and its
	// permalink would be ?p=ID (trash also renames post_name to *__trashed).
	// 21.0.0: with a theme that tags its cached responses (15.2.0+), one tag
	// purge refreshes every page, feed and machine file; seen clearing the live
	// edge on 2026-10-03 (a no-change save turned unrelated pages from HIT to
	// MISS). The sitemaps are core's and carry no tag, so they go by URL. The
	// URL list, the first-publish zone purge and the post-save probe remain
	// only as the path for an older theme.
	// 2026-10-13: a note save that changed nothing another page lists purges
	// that note's tag and the lists' tag instead (inc/cloudflare-purge-save.php).
	if ( sn_cf_tagged() ) {
		if ( sn_cf_save_is_narrow( $post_id, $post, $post_before ) ) {
			sn_cf_purge_note( $post_id, $post );
			return;
		}
		sn_cf_purge_tag();
		sn_cf_purge_urls( sn_cf_sitemap_urls( $is_published ? $post->post_type : $post_before->post_type ) );
		return;
	}

	if ( ! $is_published ) {
		sn_cf_purge_urls( sn_cf_post_purge_urls( $post_id, $post_before ) );
		return;
	}

	// v10.52.6: a Note entering publish for the FIRST time changes every page on
	// the site, not just its own. Each rendered page embeds the site-wide notes
	// index (the `{"t":…,"u":…}` list that feeds search and related-notes), so a
	// new publication makes every cached page's copy of that index incomplete.
	// The URL list below cannot express that, and the freshness probe cannot see
	// it either — it checks /, /notes/ and /provenance/, never an individual
	// Note. Measured 2026-08-05: the cached copy of a Note was 104,516 bytes
	// against 115,431 from origin, and its embedded index was missing an entry.
	//
	// So a first publication purges the zone; an ORDINARY EDIT keeps the narrow
	// list, because an edit changes that Note's page and its listings, not the
	// index every other page carries. New publications run about one a week
	// here, so this is a rare full purge, not a routine one.
	//
	// The test is a transition INTO publish, not $update: $update is true for a
	// draft that already existed as a row, which is exactly the case that adds a
	// new entry to the index.
	if ( ! $was_published ) {
		/**
		 * Filters whether a first publication purges the whole zone.
		 *
		 * @since 10.52.6
		 *
		 * @param bool    $purge_all Whether to purge everything.
		 * @param int     $post_id   Post ID.
		 * @param WP_Post $post      Post object.
		 */
		if ( apply_filters( 'sn_cf_purge_all_on_first_publish', true, $post_id, $post ) ) {
			sn_cf_purge_everything();
			return;
		}
	}

	$urls = sn_cf_post_purge_urls( $post_id, $post );
	sn_cf_purge_urls( $urls );

	// v11.10.0: the purge above is fire-and-forget and CANNOT report whether it
	// worked. On 2026-08-15 three of them ran against one Note and the edge kept
	// serving a 27-hour-old render for fifty minutes; the public provenance
	// ledger went red for it three times while this code path looked healthy.
	// Schedule a probe that checks the URL a reader would actually get, and
	// escalates once to a zone purge if it is still stale. Rescheduling is
	// deliberate: three saves in ten minutes should probe once, after the last.
	sn_cf_schedule_probe( $post_id );
}, 30, 4 );

