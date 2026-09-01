<?php
/**
 * Signal & Noise — link isolation (ML pipeline #8).
 *
 * The editorial question nothing here could answer: WHICH PUBLISHED NOTES DOES
 * NOTHING LINK TO? A note with no inbound link is reachable only by archive or
 * search — it exists, but it is not part of the corpus's own fabric.
 *
 * Not to be confused with orphaned MEDIA (inc/health-check-orphaned-media.php).
 * Every prior use of "orphan" in this plugin means an unreferenced attachment;
 * this is the note-level link graph, deliberately named apart so the two never
 * blur together in a findings list.
 *
 * Related but different neighbours, so nobody rebuilds one of these by accident:
 *   - link-candidates  suggests links to ADD from one post (outbound, per-post).
 *   - unlinked_mentions finds one note naming another without linking it (pairs).
 *   - this            diagnoses reachability across the WHOLE graph.
 *
 * No new model, no network, nothing on a reader's request. Same corpus walk the
 * rest of the deterministic layer uses, asked a question about topology rather
 * than similarity.
 *
 * @package SignalNoiseTools
 * @since 10.83.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard cap on reported rows, so one badly-connected corpus cannot flood a surface. */
const SNT_ML_ISOLATION_MAX = 200;

/**
 * The note slug an href points at, or '' when it points at no note of ours.
 *
 * THE WHOLE CORRECTNESS STORY LIVES HERE. `/notes/foo/`, `/notes/foo`,
 * `https://juanlentino.com/notes/foo/?utm=x#s` and the protocol-relative form
 * are one target. Too strict and every note reads as isolated; too loose and
 * none does. Both failures are silent, which is why this is a separate, pure,
 * directly-tested function rather than a regex inline in the walk.
 *
 * Matching is by final path SEGMENT rather than by full permalink, because the
 * permalink base is a site setting: hardcoding `/notes/` here would make the
 * whole measure wrong the day that changes, and quietly.
 *
 * @param string $href     Raw href attribute value.
 * @param string $our_host Site host; defaults to home_url()'s.
 * @return string Lowercased slug, or '' for external / non-note / unusable hrefs.
 */
function snt_ml_link_target_slug( $href, $our_host = null ) {
	$href = trim( (string) $href );
	if ( '' === $href ) {
		return '';
	}
	// A bare fragment is a same-page jump, never a link to another note.
	if ( '#' === $href[0] ) {
		return '';
	}
	// Non-navigational schemes: mailto:, tel:, javascript:, data: …
	if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) && ! preg_match( '#^https?://#i', $href ) ) {
		return '';
	}

	if ( null === $our_host ) {
		$our_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
	$our_host = strtolower( ltrim( (string) $our_host, '.' ) );

	// Protocol-relative (//host/path) — give parse_url a scheme to work with.
	$candidate = ( 0 === strpos( $href, '//' ) ) ? 'https:' . $href : $href;

	if ( preg_match( '#^https?://#i', $candidate ) ) {
		$host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		if ( '' === $host || $host !== $our_host ) {
			return ''; // A different host is not our corpus, whatever its path says.
		}
		$path = (string) wp_parse_url( $candidate, PHP_URL_PATH );
	} else {
		// Relative. Strip query/fragment by hand: parse_url on a bare path is
		// fine, but this keeps the two branches obviously identical.
		$path = (string) preg_replace( '/[?#].*$/', '', $candidate );
	}

	$path = trim( (string) $path, '/' );
	if ( '' === $path ) {
		return ''; // The site root is not a note.
	}
	$parts = explode( '/', $path );
	$slug  = strtolower( urldecode( (string) end( $parts ) ) );

	return $slug;
}

/**
 * Every internal note slug an href set points at, from one body.
 *
 * @param string $content Post content.
 * @param string $our_host Site host.
 * @return string[] Slugs, in document order, duplicates preserved.
 */
function snt_ml_outbound_slugs( $content, $our_host = null ) {
	$content = (string) $content;
	if ( '' === trim( $content ) || false === stripos( $content, '<a' ) ) {
		return array();
	}
	if ( ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*["\']([^"\']*)["\']#i', $content, $m ) ) {
		return array();
	}
	$out = array();
	foreach ( $m[1] as $href ) {
		$slug = snt_ml_link_target_slug( $href, $our_host );
		if ( '' !== $slug ) {
			$out[] = $slug;
		}
	}
	return $out;
}

/**
 * Published notes that nothing in the corpus links to.
 *
 * Subjects and sources are both PUBLISHED only, deliberately. A draft linking
 * to a note does not make that note reachable today, and a draft is not itself
 * publicly reachable — counting either would report a fabric that does not
 * exist yet. (This is the one place this pipeline diverges from its siblings,
 * which walk all five statuses for pre-publish collision checking. Different
 * question, different corpus.)
 *
 * @param int $limit Maximum rows returned; clamped to SNT_ML_ISOLATION_MAX.
 * @return array{ok:bool,isolated:array,isolated_count:int,isolated_total:int,posts_scanned:int,truncated:bool,scanned_at:int}
 */
/**
 * The link graph over published notes. PURE over post objects (v13.65.0):
 * slug => {post_id, title, slug, status, inbound, outbound, linked_from}.
 *
 * A target is counted ONCE per source (two links from one note are one
 * edge), self-links are not reachability, and a link to anything that is
 * not a published note is not an edge. Extracted from the isolation walk so
 * the same graph can answer "how many notes link to THIS one" for the
 * coverage and disagreement readers without a second parser.
 *
 * @param array  $posts WP_Post-like objects (ID, post_name, post_title, post_status, post_content).
 * @param string $host  The site host.
 * @return array<string,array>
 */
function snt_ml_link_graph( $posts, $host ) {
	$rows   = array();
	$bodies = array();
	foreach ( (array) $posts as $post ) {
		$slug = strtolower( (string) ( $post->post_name ?? '' ) );
		if ( '' === $slug ) {
			continue; // No slug, no address: it cannot be a link target.
		}
		$rows[ $slug ] = array(
			'post_id'     => (int) $post->ID,
			'title'       => (string) ( $post->post_title ?? '' ),
			'slug'        => $slug,
			'status'      => (string) ( $post->post_status ?? '' ),
			'inbound'     => 0,
			'outbound'    => 0,
			'linked_from' => array(),
		);
		$bodies[ $slug ] = (string) ( $post->post_content ?? '' );
	}
	foreach ( $bodies as $source_slug => $content ) {
		$seen = array();
		foreach ( snt_ml_outbound_slugs( $content, $host ) as $target ) {
			if ( $target === $source_slug || ! isset( $rows[ $target ] ) || isset( $seen[ $target ] ) ) {
				continue;
			}
			$seen[ $target ] = true;
			++$rows[ $target ]['inbound'];
			++$rows[ $source_slug ]['outbound'];
			$rows[ $target ]['linked_from'][] = $rows[ $source_slug ]['post_id'];
		}
	}
	return $rows;
}

/**
 * Inbound counts keyed by the WEAVE join key (v13.65.0), so the coverage map
 * and the disagreement scan join without a third spelling. {inbound, linked_from}.
 *
 * @return array<string,array{inbound:int,linked_from:int[]}>
 */
function snt_ml_inbound_by_path() {
	if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
		return array();
	}
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$graph = snt_ml_link_graph( snt_corpus_fetch_posts( 'publish', 'post' ), $host );
	$out   = array();
	foreach ( $graph as $slug => $row ) {
		$url = function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $row['post_id'] ) : '';
		$key = '' !== $url && function_exists( 'sn_path_join_key' ) ? sn_path_join_key( $url ) : '/' . $slug;
		if ( '' === $key ) {
			continue;
		}
		$out[ $key ] = array( 'inbound' => (int) $row['inbound'], 'linked_from' => $row['linked_from'] );
	}
	return $out;
}

function snt_ml_link_isolation( $limit = 50 ) {
	$limit = max( 1, min( SNT_ML_ISOLATION_MAX, (int) $limit ) );
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	$posts = snt_corpus_fetch_posts( 'publish', 'post' );
	$graph = snt_ml_link_graph( $posts, $host );
	$rows  = array();
	$inbound = array();
	$outbound = array();
	foreach ( $graph as $slug => $g ) {
		$rows[ $slug ]     = array( 'post_id' => $g['post_id'], 'title' => $g['title'], 'slug' => $slug, 'status' => $g['status'], 'outbound_count' => 0 );
		$inbound[ $slug ]  = $g['inbound'];
		$outbound[ $slug ] = $g['outbound'];
	}

	$isolated = array();
	foreach ( $rows as $slug => $row ) {
		if ( 0 !== $inbound[ $slug ] ) {
			continue;
		}
		$row['outbound_count'] = (int) $outbound[ $slug ];
		$isolated[]            = $row;
	}

	// Worst first: a note isolated in BOTH directions (nothing points at it and
	// it points nowhere) is more stranded than a dead end that links out.
	usort( $isolated, static function ( $a, $b ) {
		$by_out = $a['outbound_count'] <=> $b['outbound_count'];
		return 0 !== $by_out ? $by_out : $a['post_id'] <=> $b['post_id'];
	} );

	$total    = count( $isolated );
	$isolated = array_slice( $isolated, 0, $limit );

	return array(
		'ok'             => true,
		'isolated'       => $isolated,
		'isolated_count' => count( $isolated ),
		// The TRUE total, always — so a cap can never read as "that is all there is".
		'isolated_total' => $total,
		'posts_scanned'  => count( $rows ),
		'truncated'      => $total > count( $isolated ),
		'scanned_at'     => time(),
	);
}
