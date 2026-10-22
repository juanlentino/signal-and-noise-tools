<?php
/**
 * Tests for inc/sitemap.php index trimming (v4.8.1, T7).
 *
 * Verifies the two new filters that lean out WP core's sitemap index:
 *   - wp_sitemaps_add_provider: drop the 'users' provider (single author).
 *   - wp_sitemaps_taxonomies:   drop 'post_tag' + 'category' (thin/dupe-y).
 *
 * Pure-PHP CLI harness using a real filter simulator so the closures
 * registered by sitemap.php run exactly as WP would invoke them.
 *
 * @since plugin v4.8.1
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Filter simulator ─────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__filters'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		usort(
			$GLOBALS['__filters'][ $hook ],
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $entry ) {
			$cb_args = array_merge( array( $value ), $args );
			$cb_args = array_slice( $cb_args, 0, $entry['accepted_args'] );
			$value   = call_user_func_array( $entry['callback'], $cb_args );
		}
		return $value;
	}
}

// Minimal WP_Sitemaps_Provider stub for the fake providers.
if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
	class WP_Sitemaps_Provider {}
}

require_once __DIR__ . '/../inc/sitemap.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function sm_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function sm_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "sitemap.php index-trimming suite — plugin v4.8.1\n";

$fake_provider = new WP_Sitemaps_Provider();

// ─── wp_sitemaps_add_provider ─────────────────────────────────────────
echo "\nwp_sitemaps_add_provider: drop the 'users' provider\n";
sm_eq( false, apply_filters( 'wp_sitemaps_add_provider', $fake_provider, 'users' ), "'users' provider dropped (returns false)" );
sm_eq( $fake_provider, apply_filters( 'wp_sitemaps_add_provider', $fake_provider, 'posts' ), "'posts' provider kept (unchanged)" );
sm_eq( $fake_provider, apply_filters( 'wp_sitemaps_add_provider', $fake_provider, 'taxonomies' ), "'taxonomies' provider kept (unchanged)" );

// wp_sitemaps_post_types: MailPoet's utility page type leaves the index.
echo "\nwp_sitemaps_post_types: drop mailpoet_page, keep the rest\n";
$types = apply_filters( 'wp_sitemaps_post_types', array( 'post' => 'POST', 'page' => 'PAGE', 'mailpoet_page' => 'MP' ) );
sm_eq( array( 'post' => 'POST', 'page' => 'PAGE' ), $types, 'mailpoet_page removed; post and page untouched' );

// ─── wp_sitemaps_taxonomies (15.10.0: tags STAY, category goes) ──────
echo "\nwp_sitemaps_taxonomies: keep post_tag (the hubs), drop category, keep custom\n";
$taxes  = array(
	'post_tag' => 'PT',
	'category' => 'CAT',
	'custom'   => 'CUSTOM',
);
$result = apply_filters( 'wp_sitemaps_taxonomies', $taxes );
sm_true( isset( $result['post_tag'] ), '15.10.0: post_tag KEPT — every tag has an owner-written description and a hub tag is a real page' );
sm_true( ! isset( $result['category'] ), 'category removed from taxonomies (one category, a duplicate of /notes/)' );
sm_true( isset( $result['custom'] ) && 'CUSTOM' === $result['custom'], 'custom taxonomy preserved' );
sm_eq( 2, count( $result ), 'exactly two taxonomies left' );

echo "\n15.10.0: the hub line and the thin-tag exclusion\n";
sm_true( true === sn_sitemap_tag_is_hub( 3 ) && true === sn_sitemap_tag_is_hub( 13 ), 'three or more notes make a hub' );
sm_true( false === sn_sitemap_tag_is_hub( 2 ) && false === sn_sitemap_tag_is_hub( 0 ), 'two or fewer do not' );
sm_eq( 3, SN_SITEMAP_TAG_MIN_NOTES, 'the line is three (the 2026-09-17 vocabulary: 21 tags at or above it, 4 below)' );
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args ) {
		return array(
			(object) array( 'term_id' => 11, 'count' => 13 ),
			(object) array( 'term_id' => 12, 'count' => 3 ),
			(object) array( 'term_id' => 13, 'count' => 2 ),
			(object) array( 'term_id' => 14, 'count' => 1 ),
			'junk',
		);
	}
}
sm_eq( array( 13, 14 ), sn_sitemap_thin_tag_ids(), 'the thin ids are exactly the tags under the line; junk rows are dropped' );
$qa = apply_filters( 'wp_sitemaps_taxonomies_query_args', array( 'exclude' => array( 99 ) ), 'post_tag' );
sm_eq( array( 99, 13, 14 ), $qa['exclude'], 'the taxonomy provider excludes the thin tags, keeping any exclude it already had' );
$qa = apply_filters( 'wp_sitemaps_taxonomies_query_args', array( 'x' => 1 ), 'custom' );
sm_eq( array( 'x' => 1 ), $qa, 'another taxonomy\'s args are untouched' );


// ─── v13.66.0: per-URL lastmod ───
echo "\nGroup: v13.66.0 lastmod\n";
sm_eq( '2026-08-29T20:36:28+00:00', sn_sitemap_lastmod( '2026-08-29 20:36:28' ), 'a real GMT modified time → ISO 8601 with +00:00' );
sm_eq( null, sn_sitemap_lastmod( '0000-00-00 00:00:00' ), 'the zero date → null (nothing emitted)' );
sm_eq( null, sn_sitemap_lastmod( '' ), 'empty → null' );
sm_eq( null, sn_sitemap_lastmod( '2026-02-30 12:00:00' ), 'an impossible date → null, never a normalised guess' );
sm_eq( null, sn_sitemap_lastmod( 'yesterday' ), 'garbage → null' );
$sm_post = (object) array( 'ID' => 7, 'post_modified_gmt' => '2026-08-29 20:36:28' );
$sm_entry = apply_filters( 'wp_sitemaps_posts_entry', array( 'loc' => 'https://juanlentino.com/notes/x/' ), $sm_post, 'post' );
sm_eq( array( 'loc' => 'https://juanlentino.com/notes/x/', 'lastmod' => '2026-08-29T20:36:28+00:00' ), $sm_entry, 'the entry filter adds lastmod beside loc' );
$sm_bad = apply_filters( 'wp_sitemaps_posts_entry', array( 'loc' => 'https://juanlentino.com/notes/y/' ), (object) array( 'ID' => 8, 'post_modified_gmt' => '0000-00-00 00:00:00' ), 'post' );
sm_true( ! array_key_exists( 'lastmod', $sm_bad ), 'a post with no real modified time gets NO lastmod key — a fabricated date is the inconsistency Google ignores the field for' );
sm_true( isset( $GLOBALS['__filters']['wp_sitemaps_posts_entry'] ), 'the wp_sitemaps_posts_entry filter is registered' );

// ─── 2026-10-22: the posts provider asks the ONE indexable predicate ───
echo "\nGroup: the sitemap excludes exactly what sn_post_is_indexable() refuses\n";
$GLOBALS['sm_posts'] = array(
	21 => (object) array( 'ID' => 21, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://juanlentino.com/verify/' ),
	22 => (object) array( 'ID' => 22, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://juanlentino.com/about/' ),
	23 => (object) array( 'ID' => 23, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://juanlentino.com/call/' ),
	24 => (object) array( 'ID' => 24, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://juanlentino.com/notes/x/' ),
);
$GLOBALS['sm_meta'] = array( 23 => array( '_sn_noindex' => '1' ), 7 => array( '_sn_prov_last_commit_gmt' => '2026-09-14 05:10:06' ) );
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['sm_posts'] as $id => $p ) {
		if ( $p->post_type === $args['post_type'] ) {
			$out[] = $id;
		}
	}
	return $out;
}
function get_post( $id ) { return $GLOBALS['sm_posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['sm_meta'][ (int) $id ][ $k ] ?? ''; }
function get_permalink( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? $p->url : ''; }
function get_post_modified_time( $f, $gmt, $post ) {
	$m = (string) ( $post->post_modified_gmt ?? '' );
	return ( '' === $m || 0 === strpos( $m, '0000' ) ) ? false : gmdate( 'c', strtotime( $m . ' UTC' ) );
}
require_once __DIR__ . '/../inc/seo-schema-identity-parts.php';
$qa = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post__not_in' => array( 5 ) ), 'page' );
sm_eq( array( 5, 21, 23 ), $qa['post__not_in'], '/verify (noindex by the virtual route) and /call (_sn_noindex) leave the page sitemap; an existing exclusion stays' );
sm_true( ! isset( $qa['meta_query'] ), 'no second rule beside the predicate: the meta_query is gone' );
$qa = apply_filters( 'wp_sitemaps_posts_query_args', array(), 'post' );
sm_true( ! isset( $qa['post__not_in'] ), 'nothing excluded when every note is indexable' );

echo "\nGroup: lastmod is the content-change clock the JSON-LD dateModified reads\n";
$sm_c = apply_filters( 'wp_sitemaps_posts_entry', array( 'loc' => 'https://juanlentino.com/provenance/as-substrate/' ), (object) array( 'ID' => 7, 'post_modified_gmt' => '2026-09-24 20:35:11' ), 'page' );
sm_eq( '2026-09-14T05:10:06+00:00', $sm_c['lastmod'] ?? null, 'a provenance commit is the lastmod, as it is the dateModified (not the row\'s later save)' );
$sm_bad = apply_filters( 'wp_sitemaps_posts_entry', array( 'loc' => 'https://juanlentino.com/notes/y/' ), (object) array( 'ID' => 8, 'post_modified_gmt' => '0000-00-00 00:00:00' ), 'post' );
sm_true( ! array_key_exists( 'lastmod', $sm_bad ), 'still no lastmod for a post with no real modified time and no commit' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
