<?php
/**
 * Tests for inc/sitemap-redirect.php: the old sitemap addresses that 301 to
 * core's /wp-sitemap.xml. 2026-10: Search Console reported /sitemap/ as a 404;
 * it joins /sitemap.xml and /sitemap_index.xml on the same map.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__actions'] = array();
function add_action( $tag, $cb, $prio = 10 ) { $GLOBALS['__actions'][ $tag ][] = array( $cb, $prio ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require __DIR__ . '/../inc/sitemap-redirect.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function matches( $uri ) { return function_exists( 'sn_sitemap_legacy_redirect_matches' ) && sn_sitemap_legacy_redirect_matches( $uri ); }

echo "Group: the legacy sitemap routes\n";
ok( matches( '/sitemap.xml' ), '/sitemap.xml redirects (unchanged)' );
ok( matches( '/sitemap_index.xml' ), '/sitemap_index.xml redirects (unchanged)' );
ok( matches( '/sitemap.xsl' ), '/sitemap.xsl redirects (unchanged)' );
ok( matches( '/sitemap/' ), '/sitemap/ redirects (the Search Console 404)' );
ok( matches( '/sitemap' ), '/sitemap without the slash redirects too' );
ok( matches( '/sitemap/?x=1' ), 'a query string does not defeat the match' );

echo "\nGroup: everything else is left alone\n";
ok( ! matches( '/wp-sitemap.xml' ), 'the target itself never redirects (no loop)' );
ok( ! matches( '/sitemaps/' ), 'a near miss is not a match' );
ok( ! matches( '/notes/sitemap/' ), 'a deeper path is not a match' );
ok( ! matches( '/' ), 'the home page is not a match' );

ok( isset( $GLOBALS['__actions']['init'] ), 'the redirect still runs at init' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
