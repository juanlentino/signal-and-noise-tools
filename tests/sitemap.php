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

// ─── wp_sitemaps_taxonomies ───────────────────────────────────────────
echo "\nwp_sitemaps_taxonomies: drop post_tag + category, keep custom\n";
$taxes  = array(
	'post_tag' => 'PT',
	'category' => 'CAT',
	'custom'   => 'CUSTOM',
);
$result = apply_filters( 'wp_sitemaps_taxonomies', $taxes );
sm_true( ! isset( $result['post_tag'] ), 'post_tag removed from taxonomies' );
sm_true( ! isset( $result['category'] ), 'category removed from taxonomies' );
sm_true( isset( $result['custom'] ) && 'CUSTOM' === $result['custom'], 'custom taxonomy preserved' );
sm_eq( 1, count( $result ), 'exactly one taxonomy left (the custom one)' );


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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
