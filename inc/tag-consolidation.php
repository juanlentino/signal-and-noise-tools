<?php
/**
 * Notes tag consolidation: detect near-duplicate post_tag terms, merge a cluster
 * (or any two tags) into one canonical term, and record merge history. Pure logic
 * + the merge engine; no output. Admin UI lives in inc/tag-consolidation-admin.php,
 * the 301 map in inc/tag-consolidation-redirects.php.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_TAG_MERGE_HISTORY_OPT = 'sn_tag_merge_history';
const SN_TAG_MERGE_HISTORY_MAX = 50;

/**
 * Normalize a tag name to a comparison key: lowercase, diacritics folded to ASCII,
 * hyphen/underscore/whitespace/punctuation collapsed to single spaces. So
 * "AI-Generated Music" and "AI Generated Music" map to the same key.
 *
 * @param string $name Tag name.
 * @return string Normalized comparison key.
 */
function sn_tag_normalize_key( $name ) {
	$s = (string) $name;
	if ( function_exists( 'mb_strtolower' ) ) {
		$s = mb_strtolower( $s, 'UTF-8' );
	} else {
		$s = strtolower( $s );
	}
	// Fold diacritics to ASCII. remove_accents() (WP core) is deterministic and is what
	// sanitize_title uses; iconv //TRANSLIT is platform-dependent (unreliable on macOS).
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	$s = preg_replace( '/[^a-z0-9]+/i', ' ', $s ); // punctuation/hyphen/space -> single space
	$s = trim( preg_replace( '/\s+/', ' ', $s ) );
	return $s;
}

/**
 * Group post_tag terms into clusters of likely duplicates. Exact-normalized keys
 * cluster together; then a conservative Levenshtein pass merges keys that differ by
 * a typo (<=1 edit for len 4-5, <=2 for len >=6; no fuzzy under len 4). Returns only
 * clusters with >=2 terms, ordered by total count desc. Each cluster:
 *   { key, terms:[{term_id,name,slug,count}], suggested:term_id }
 * suggested = highest-count term (tie -> lowest term_id). Suggest only; never auto-merge.
 *
 * @return array List of cluster arrays.
 */
function sn_tag_find_duplicate_clusters() {
	$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	if ( ! is_array( $terms ) || ! $terms ) {
		return array();
	}
	// Bucket by exact normalized key.
	$buckets = array(); // key => [term-row,...]
	foreach ( $terms as $t ) {
		$row = array( 'term_id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug, 'count' => (int) $t->count );
		$buckets[ sn_tag_normalize_key( $t->name ) ][] = $row;
	}
	// Fuzzy-merge bucket keys that are typos of each other.
	$keys = array_keys( $buckets );
	$seen = array();
	$groups = array(); // list of key-lists
	foreach ( $keys as $i => $ka ) {
		if ( isset( $seen[ $ka ] ) ) {
			continue;
		}
		$group       = array( $ka );
		$seen[ $ka ] = true;
		foreach ( $keys as $j => $kb ) {
			if ( $j <= $i || isset( $seen[ $kb ] ) ) {
				continue;
			}
			if ( sn_tag_keys_are_typos( $ka, $kb ) ) {
				$group[]     = $kb;
				$seen[ $kb ] = true;
			}
		}
		$groups[] = $group;
	}
	// Build clusters.
	$clusters = array();
	foreach ( $groups as $group ) {
		$rows = array();
		foreach ( $group as $k ) {
			foreach ( $buckets[ $k ] as $r ) {
				$rows[] = $r;
			}
		}
		if ( count( $rows ) < 2 ) {
			continue;
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return ( $b['count'] <=> $a['count'] ) ?: ( $a['term_id'] <=> $b['term_id'] );
			}
		);
		$total = 0;
		foreach ( $rows as $r ) {
			$total += $r['count'];
		}
		$clusters[] = array( 'key' => $group[0], 'terms' => $rows, 'suggested' => $rows[0]['term_id'], '_total' => $total );
	}
	usort(
		$clusters,
		function ( $a, $b ) {
			return $b['_total'] <=> $a['_total'];
		}
	);
	foreach ( $clusters as &$c ) {
		unset( $c['_total'] );
	}
	unset( $c );
	return $clusters;
}

/**
 * Two normalized keys are typos of each other: conservative Levenshtein.
 * No fuzzy under length 4; <=1 edit for 4-5; <=2 for >=6. Equal keys are NOT typos
 * (they already share a bucket).
 *
 * @param string $a Normalized key.
 * @param string $b Normalized key.
 * @return bool
 */
function sn_tag_keys_are_typos( $a, $b ) {
	if ( $a === $b ) {
		return false;
	}
	$la  = strlen( $a );
	$lb  = strlen( $b );
	$min = min( $la, $lb );
	if ( $min < 4 ) {
		return false;
	}
	$tol = ( $min >= 6 ) ? 2 : 1;
	if ( abs( $la - $lb ) > $tol ) {
		return false;
	}
	// Optimal String Alignment (restricted Damerau-Levenshtein) distance: counts an
	// adjacent transposition as 1 edit, so "music" <-> "muisc" is distance 1, not 2.
	// Transpositions are the typo class plain levenshtein() under-counts.
	return sn_tag_osa_distance( $a, $b ) <= $tol;
}

/**
 * Optimal String Alignment distance (Damerau-Levenshtein restricted to adjacent
 * transpositions). Like edit distance but a swap of two neighbouring chars costs 1.
 *
 * @param string $a First string.
 * @param string $b Second string.
 * @return int
 */
function sn_tag_osa_distance( $a, $b ) {
	$la = strlen( $a );
	$lb = strlen( $b );
	if ( 0 === $la ) {
		return $lb;
	}
	if ( 0 === $lb ) {
		return $la;
	}
	$d = array();
	for ( $i = 0; $i <= $la; $i++ ) {
		$d[ $i ][0] = $i;
	}
	for ( $j = 0; $j <= $lb; $j++ ) {
		$d[0][ $j ] = $j;
	}
	for ( $i = 1; $i <= $la; $i++ ) {
		for ( $j = 1; $j <= $lb; $j++ ) {
			$cost      = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
			$d[ $i ][ $j ] = min(
				$d[ $i - 1 ][ $j ] + 1,        // deletion
				$d[ $i ][ $j - 1 ] + 1,        // insertion
				$d[ $i - 1 ][ $j - 1 ] + $cost // substitution
			);
			if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
				$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 ); // transposition
			}
		}
	}
	return (int) $d[ $la ][ $lb ];
}

/**
 * Dry-run preview of a merge: validate ids, count distinct affected posts. No mutation.
 *
 * @param array $from_ids Source term ids.
 * @param int   $into_id  Canonical term id.
 * @return array|WP_Error { from:[{id,name,slug,count}], into:{id,name,slug}, posts_affected:int }
 */
function sn_tag_merge_preview( array $from_ids, $into_id ) {
	$into_id  = (int) $into_id;
	$from_ids = array_values( array_unique( array_map( 'intval', $from_ids ) ) );
	$err      = sn_tag_validate_merge( $from_ids, $into_id );
	if ( is_wp_error( $err ) ) {
		return $err;
	}
	$into  = get_term( $into_id, 'post_tag' );
	$from  = array();
	$posts = array();
	foreach ( $from_ids as $id ) {
		$t      = get_term( $id, 'post_tag' );
		$from[] = array( 'id' => $id, 'name' => (string) $t->name, 'slug' => (string) $t->slug, 'count' => (int) $t->count );
		foreach ( (array) get_objects_in_term( $id, 'post_tag' ) as $p ) {
			$posts[ (int) $p ] = true;
		}
	}
	return array(
		'from'           => $from,
		'into'           => array( 'id' => $into_id, 'name' => (string) $into->name, 'slug' => (string) $into->slug ),
		'posts_affected' => count( $posts ),
	);
}

/**
 * Validate a merge request: every id a real post_tag term, into not among from, >=1 from.
 *
 * @param array $from_ids Source term ids.
 * @param int   $into_id  Canonical term id.
 * @return true|WP_Error
 */
function sn_tag_validate_merge( array $from_ids, $into_id ) {
	if ( ! $from_ids ) {
		return new WP_Error( 'sn_tag_empty', __( 'No source tags selected.', 'signal-and-noise-tools' ) );
	}
	if ( in_array( (int) $into_id, $from_ids, true ) ) {
		return new WP_Error( 'sn_tag_into_in_from', __( 'The canonical tag cannot also be a source tag.', 'signal-and-noise-tools' ) );
	}
	foreach ( array_merge( $from_ids, array( (int) $into_id ) ) as $id ) {
		$t = get_term( (int) $id, 'post_tag' );
		if ( ! $t || is_wp_error( $t ) || ( isset( $t->taxonomy ) && 'post_tag' !== $t->taxonomy ) ) {
			return new WP_Error( 'sn_tag_bad_id', __( 'One or more selected tags no longer exist.', 'signal-and-noise-tools' ) );
		}
	}
	return true;
}

/**
 * Merge source tags into a canonical tag: append the canonical to every post that
 * carries any source tag (so no post is left tagless), then delete the source terms
 * (wp_delete_term detaches them from all posts). Records the old-slug -> canonical
 * redirect map and a history entry. Validates everything first; a bad id aborts with
 * zero mutation.
 *
 * @param array $from_ids Source term ids.
 * @param int   $into_id  Canonical term id.
 * @return array|WP_Error { merged:[old_slugs], into_slug, posts_moved:int }
 */
function sn_tag_merge( array $from_ids, $into_id ) {
	$into_id  = (int) $into_id;
	$from_ids = array_values( array_unique( array_map( 'intval', $from_ids ) ) );
	$err      = sn_tag_validate_merge( $from_ids, $into_id );
	if ( is_wp_error( $err ) ) {
		return $err;
	}

	$into        = get_term( $into_id, 'post_tag' );
	$into_slug   = (string) $into->slug;
	$old_slugs   = array();
	$moved_posts = array();

	foreach ( $from_ids as $id ) {
		$t           = get_term( $id, 'post_tag' );
		$old_slugs[] = (string) $t->slug;
		foreach ( (array) get_objects_in_term( $id, 'post_tag' ) as $post_id ) {
			wp_set_object_terms( (int) $post_id, array( $into_id ), 'post_tag', true );
			$moved_posts[ (int) $post_id ] = true;
		}
		wp_delete_term( $id, 'post_tag' );
	}

	sn_tag_redirects_record( $old_slugs, $into_slug );
	sn_tag_merge_history_record( $old_slugs, $into_slug, count( $moved_posts ) );

	return array( 'merged' => $old_slugs, 'into_slug' => $into_slug, 'posts_moved' => count( $moved_posts ) );
}

/**
 * Append a merge to the capped-FIFO history option (the domain-appropriate "audit").
 *
 * @param array  $old_slugs Merged-away slugs.
 * @param string $into_slug Canonical slug.
 * @param int    $posts     Posts moved.
 * @return void
 */
function sn_tag_merge_history_record( array $old_slugs, $into_slug, $posts ) {
	$hist = get_option( SN_TAG_MERGE_HISTORY_OPT, array() );
	if ( ! is_array( $hist ) ) {
		$hist = array();
	}
	array_unshift(
		$hist,
		array(
			'from'  => array_values( array_map( 'strval', $old_slugs ) ),
			'into'  => (string) $into_slug,
			'posts' => (int) $posts,
			'user'  => (int) get_current_user_id(),
			'ts'    => time(),
		)
	);
	if ( count( $hist ) > SN_TAG_MERGE_HISTORY_MAX ) {
		$hist = array_slice( $hist, 0, SN_TAG_MERGE_HISTORY_MAX );
	}
	update_option( SN_TAG_MERGE_HISTORY_OPT, $hist );
}
