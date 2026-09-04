<?php
/**
 * Tests: a post save purges every CACHED surface it invalidates (issue #1008).
 *
 * The set was five hardcoded URLs. Measured against the live edge, three cached
 * surfaces were missing — /notes/page/2..4/, /wp-sitemap.xml and
 * /wp-sitemap-posts-post-1.xml, all `cf-cache-status: HIT`. Publishing shifts
 * every item across page boundaries, so the pages most likely to be wrong were
 * exactly the ones never purged.
 *
 * Nothing could catch it: the list lived inside a `wp_after_insert_post`
 * closure that no test can call without booting WordPress, and the freshness
 * probe checks only get_permalink() — a subset of the producer's own action,
 * so it cannot fail for an omission however bad the omission gets.
 *
 * Run: php tests/cloudflare-purge-coverage.php
 * @since 13.96.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── stubs ────────────────────────────────────────────────────────────────
$GLOBALS['__published'] = 39;   // notes on the site when this was written
$GLOBALS['__per_page']  = 10;
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_permalink( $id ) { return 'https://example.test/notes/post-' . (int) $id . '/'; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function get_option( $k, $d = false ) { return 'posts_per_page' === $k ? $GLOBALS['__per_page'] : $d; }
function wp_count_posts( $type = 'post' ) { return (object) array( 'publish' => $GLOBALS['__published'] ); }
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

require_once __DIR__ . '/../inc/cloudflare-purge.php';

$post = (object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => 0 );
$urls = sn_cf_post_purge_urls( 7, $post );

echo "cloudflare-purge-coverage — plugin v13.96.2\n\nGroup 1: the surfaces that were missing\n";

// 39 published / 10 per page = 4 pages -> /page/2/, /page/3/, /page/4/
ok( in_array( 'https://example.test/notes/page/2/', $urls, true ), 'the archive\'s page 2 is purged' );
ok( in_array( 'https://example.test/notes/page/4/', $urls, true ), 'the LAST page is purged (39 posts / 10 per page = 4)' );
ok( ! in_array( 'https://example.test/notes/page/1/', $urls, true ),
	'page/1/ is NOT purged — it is the archive URL itself, and nothing links to /page/1/' );
ok( ! in_array( 'https://example.test/notes/page/5/', $urls, true ), 'no page beyond the real count' );
ok( in_array( 'https://example.test/wp-sitemap.xml', $urls, true ), 'the sitemap index is purged' );
ok( in_array( 'https://example.test/wp-sitemap-posts-post-1.xml', $urls, true ),
	'the sub-sitemap listing this post type is purged — that is where a new note appears to a crawler' );

echo "\nGroup 2: what it already did, unchanged\n";
foreach ( array( '/notes/post-7/', '/', '/notes/', '/provenance/', '/notes/feed/' ) as $keep ) {
	ok( in_array( 'https://example.test' . $keep, $urls, true ), "still purges $keep" );
}

echo "\nGroup 3: the page count is DERIVED, not written down\n";
// A hardcoded count is right the day it is written and wrong the first time the
// corpus grows. Moving the corpus must move the set.
$GLOBALS['__published'] = 95;
$grown = sn_cf_post_purge_urls( 7, $post );
ok( in_array( 'https://example.test/notes/page/10/', $grown, true ), '95 posts / 10 per page -> page 10 appears' );
ok( ! in_array( 'https://example.test/notes/page/11/', $grown, true ), '...and page 11 does not' );

$GLOBALS['__published'] = 5;
$small = sn_cf_post_purge_urls( 7, $post );
ok( ! in_array( 'https://example.test/notes/page/2/', $small, true ), 'a single-page archive adds no paginated URL at all' );

// Unbounded growth would turn one save into unbounded API calls.
$GLOBALS['__published'] = 100000;
$huge = sn_cf_post_purge_urls( 7, $post );
$paged = array_values( array_filter( $huge, function ( $u ) { return false !== strpos( $u, '/page/' ); } ) );
ok( count( $paged ) === SN_CF_MAX_ARCHIVE_PAGES - 1,
	'the paginated set is CAPPED (' . count( $paged ) . ' pages) — one save must not become unbounded API calls' );

// Sitemap pagination is derived too: core splits at 2000 URLs.
$GLOBALS['__published'] = 2001;
$two = sn_cf_sitemap_urls( 'post' );
ok( in_array( 'https://example.test/wp-sitemap-posts-post-2.xml', $two, true ),
	'past 2000 posts the SECOND sub-sitemap page is purged — assuming one page would silently stop being true' );

$GLOBALS['__published'] = 39;
echo "\nGroup 4: the freshness verdict says what it covered\n";
$abil = (string) file_get_contents( __DIR__ . '/../inc/abilities-cache-freshness.php' );
ok( false !== strpos( $abil, "'probe_scope' => 'permalink'" ),
	'cache-freshness reports probe_scope — "Edge fresh" alone reads as a claim about the edge, and it is a claim about one URL' );
ok( 2 === substr_count( $abil, "'probe_scope' => 'permalink'" ),
	'both the unknown and the recorded path carry it — a field present on only one is worse than none' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
