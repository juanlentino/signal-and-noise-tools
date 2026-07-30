<?php
/**
 * Signal & Noise Tools — Corpus inspection impls (read-only).
 *
 * Three pure-read helpers backing the corpus abilities in
 * inc/abilities-corpus.php:
 *   - snt_corpus_duplicate_scan()    — group posts by exact body hash
 *   - snt_corpus_list_posts()        — metadata-only corpus listing
 *   - snt_corpus_get_post_content()  — full bodies for a bounded ID set
 *
 * The corpus walk spans ALL non-trash statuses (publish, future, draft,
 * pending, private) because the whole point is pre-publish collision
 * checking — a scheduled post that duplicates a published one must be
 * findable BEFORE it goes live. Exposure of non-public statuses is gated
 * by the abilities' manage_options permission_callback plus the MCP
 * read door's own auth; nothing here is reachable unauthenticated.
 *
 * Content hash = md5( trim( post_content ) ). Raw (no block-markup
 * normalization) is deliberate: the target failure is duplicate-to-seed
 * posts whose body was never replaced, which are byte-identical. The
 * trim() only absorbs trailing-whitespace drift from imports. Posts whose
 * content is empty after trim get hash '' and are NEVER grouped — two
 * blank drafts are not duplicates of each other.
 *
 * Unlike the sibling scans (block-migrations, pattern-adoption) these do
 * NOT cache in a transient: they hash strings instead of parse_blocks()-ing
 * everything, and the workflow is fix-then-rescan-to-confirm — a stale
 * cached "duplicates found" after the fix would be a false alarm.
 *
 * @package SignalNoiseTools
 * @since 10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_CORPUS_STATUSES        = array( 'publish', 'future', 'draft', 'pending', 'private' );
const SNT_CORPUS_MAX_CONTENT_IDS = 20;
const SNT_CORPUS_MAX_LIST        = 2000;

/**
 * Post-type gate: registered AND public. Internal types (revision,
 * nav_menu_item, oembed cache, …) are never walkable through these tools,
 * even by an authenticated admin — they are implementation detail, not
 * corpus, and revisions would multiply every body into false duplicates.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function snt_corpus_post_type_allowed( $post_type ) {
	if ( ! post_type_exists( $post_type ) ) {
		return false;
	}
	$obj = get_post_type_object( $post_type );
	return is_object( $obj ) && ! empty( $obj->public );
}

/**
 * Shared WP_Error for a post type that fails snt_corpus_post_type_allowed().
 *
 * @return WP_Error
 */
function snt_corpus_post_type_error() {
	return new WP_Error(
		'snt_corpus_unknown_post_type',
		__( 'Unknown or non-public post type.', 'signal-and-noise-tools' ),
		array( 'status' => 422 )
	);
}

/**
 * Exact-duplicate content hash: md5 of the trimmed body, or '' when the
 * body is empty/whitespace-only (empty bodies must never group together).
 *
 * @param string $content Raw post_content.
 * @return string 32-char md5, or '' for empty content.
 */
function snt_corpus_content_hash( $content ) {
	$trimmed = trim( (string) $content );
	return '' === $trimmed ? '' : md5( $trimmed );
}

/**
 * Fetch the corpus for a status/type filter. Central query so both the
 * duplicate scan and the listing walk identical post sets.
 *
 * @param string $status 'any' (all five SNT_CORPUS_STATUSES) or one of them.
 * @param string $post_type Registered post type slug.
 * @return WP_Post[]
 */
function snt_corpus_fetch_posts( $status = 'any', $post_type = 'post' ) {
	$statuses = ( 'any' === $status ) ? SNT_CORPUS_STATUSES : array( $status );

	// SNT_CORPUS_MAX_LIST is a memory guard, never a silent cap: callers
	// compare count() against it and report `truncated` when it binds.
	return get_posts( array(
		'post_type'      => $post_type,
		'post_status'    => $statuses,
		'posts_per_page' => SNT_CORPUS_MAX_LIST,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
}

/**
 * Excerpt for a post: the manual excerpt when set, else a 55-word trim of
 * the content with block comments and HTML stripped. Never the full body.
 *
 * @param object $post WP_Post-shaped object.
 * @return string
 */
function snt_corpus_excerpt( $post ) {
	$manual = trim( (string) ( $post->post_excerpt ?? '' ) );
	if ( '' !== $manual ) {
		return $manual;
	}
	$text = (string) ( $post->post_content ?? '' );
	$text = preg_replace( '/<!--.*?-->/s', ' ', $text ); // Block comment delimiters.
	$text = wp_strip_all_tags( $text );
	return wp_trim_words( $text, 55, '…' );
}

/**
 * Unicode-safe word count of the visible text (tags + block comments stripped).
 *
 * @param string $content Raw post_content.
 * @return int
 */
function snt_corpus_word_count( $content ) {
	$text = preg_replace( '/<!--.*?-->/s', ' ', (string) $content );
	$text = trim( wp_strip_all_tags( $text ) );
	if ( '' === $text ) {
		return 0;
	}
	$words = preg_split( '/\s+/u', $text );
	return is_array( $words ) ? count( $words ) : 0;
}

/**
 * Term names for a post, empty array when the taxonomy errors or is absent.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy 'category' or 'post_tag'.
 * @return string[]
 */
function snt_corpus_term_names( $post_id, $taxonomy ) {
	$terms = wp_get_post_terms( (int) $post_id, $taxonomy, array( 'fields' => 'names' ) );
	return is_wp_error( $terms ) ? array() : array_values( array_map( 'strval', (array) $terms ) );
}

/**
 * Metadata row for one post — everything the listing returns per post.
 * No body: excerpt + hash stand in for it (that is the token-economy point).
 *
 * @param object $post WP_Post-shaped object.
 * @return array<string,mixed>
 */
function snt_corpus_post_row( $post ) {
	return array(
		'post_id'       => (int) $post->ID,
		'title'         => (string) ( $post->post_title ?? '' ),
		'slug'          => (string) ( $post->post_name ?? '' ),
		'status'        => (string) ( $post->post_status ?? '' ),
		'post_type'     => (string) ( $post->post_type ?? '' ),
		'post_date'     => (string) ( $post->post_date ?? '' ),
		'post_modified' => (string) ( $post->post_modified ?? '' ),
		'categories'    => snt_corpus_term_names( (int) $post->ID, 'category' ),
		'tags'          => snt_corpus_term_names( (int) $post->ID, 'post_tag' ),
		'word_count'    => snt_corpus_word_count( (string) ( $post->post_content ?? '' ) ),
		'content_hash'  => snt_corpus_content_hash( (string) ( $post->post_content ?? '' ) ),
		'excerpt'       => snt_corpus_excerpt( $post ),
	);
}

/**
 * Duplicate body scan: hash every post across all five statuses, return
 * groups where the same non-empty hash appears more than once.
 *
 * @param string $post_type Post type to scan.
 * @return array{ok:bool,groups:array,group_count:int,posts_scanned:int,scanned_at:int}
 */
function snt_corpus_duplicate_scan( $post_type = 'post' ) {
	if ( ! snt_corpus_post_type_allowed( $post_type ) ) {
		return snt_corpus_post_type_error();
	}

	$posts   = snt_corpus_fetch_posts( 'any', $post_type );
	$by_hash = array();

	foreach ( $posts as $post ) {
		$hash = snt_corpus_content_hash( (string) ( $post->post_content ?? '' ) );
		if ( '' === $hash ) {
			continue; // Empty bodies never group.
		}
		$by_hash[ $hash ][] = array(
			'post_id'   => (int) $post->ID,
			'title'     => (string) ( $post->post_title ?? '' ),
			'slug'      => (string) ( $post->post_name ?? '' ),
			'status'    => (string) ( $post->post_status ?? '' ),
			'post_date' => (string) ( $post->post_date ?? '' ),
		);
	}

	$groups = array();
	foreach ( $by_hash as $hash => $members ) {
		if ( count( $members ) > 1 ) {
			$groups[] = array(
				'content_hash' => (string) $hash,
				'posts'        => $members,
			);
		}
	}

	return array(
		'ok'            => true,
		'groups'        => $groups,
		'group_count'   => count( $groups ),
		'posts_scanned' => count( $posts ),
		'truncated'     => count( $posts ) >= SNT_CORPUS_MAX_LIST,
		'scanned_at'    => time(),
	);
}

/**
 * Corpus listing: one metadata row per post, optional status/type filter.
 *
 * @param string $status    'any' or one of SNT_CORPUS_STATUSES.
 * @param string $post_type Registered post type slug.
 * @return array{ok:bool,posts:array,count:int}|WP_Error
 */
function snt_corpus_list_posts( $status = 'any', $post_type = 'post' ) {
	if ( ! snt_corpus_post_type_allowed( $post_type ) ) {
		return snt_corpus_post_type_error();
	}

	if ( 'any' !== $status && ! in_array( $status, SNT_CORPUS_STATUSES, true ) ) {
		return new WP_Error(
			'snt_corpus_bad_status',
			__( 'Status must be "any" or one of: publish, future, draft, pending, private.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$rows = array_map( 'snt_corpus_post_row', snt_corpus_fetch_posts( $status, $post_type ) );

	return array(
		'ok'        => true,
		'posts'     => $rows,
		'count'     => count( $rows ),
		'truncated' => count( $rows ) >= SNT_CORPUS_MAX_LIST,
	);
}

/**
 * Full bodies for a bounded set of post IDs, each with its metadata row.
 * Unknown/trashed IDs are reported in `missing` rather than silently
 * dropped — an absent post is an answer, not a gap.
 *
 * @param int[] $post_ids 1..SNT_CORPUS_MAX_CONTENT_IDS post IDs.
 * @return array{ok:bool,posts:array,missing:array}|WP_Error
 */
function snt_corpus_get_post_content( $post_ids ) {
	$post_ids = array_values( array_unique( array_map( 'intval', (array) $post_ids ) ) );

	if ( empty( $post_ids ) || count( $post_ids ) > SNT_CORPUS_MAX_CONTENT_IDS ) {
		return new WP_Error(
			'snt_corpus_bad_id_set',
			sprintf(
				/* translators: %d: maximum number of post IDs per call. */
				__( 'Provide between 1 and %d post IDs.', 'signal-and-noise-tools' ),
				SNT_CORPUS_MAX_CONTENT_IDS
			),
			array( 'status' => 422 )
		);
	}

	$rows    = array();
	$missing = array();

	foreach ( $post_ids as $id ) {
		$post = get_post( $id );
		// Trash, revisions, internal CPTs, and unknown IDs are all "missing":
		// nothing outside the corpus statuses/public types is fetchable by ID.
		if ( ! $post
			|| ! in_array( (string) ( $post->post_status ?? '' ), SNT_CORPUS_STATUSES, true )
			|| ! snt_corpus_post_type_allowed( (string) ( $post->post_type ?? '' ) ) ) {
			$missing[] = $id;
			continue;
		}
		$row            = snt_corpus_post_row( $post );
		$row['content'] = (string) ( $post->post_content ?? '' );
		$rows[]         = $row;
	}

	return array(
		'ok'      => true,
		'posts'   => $rows,
		'missing' => $missing,
	);
}
