<?php
/**
 * Tests for inc/colophon-page.php — [sn_colophon], the CMS-owned colophon.
 * Pins: content parity items, the maturity loop-closer resolved from the
 * page (linked when resolvable, plain text when not — never a dead link),
 * live version footer, escaping, and the filter seam.
 * Run: php tests/colophon-page.php
 * @since plugin v10.13.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '10.13.0-test' );
function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
// The maturity resolver, stubbed with a switch so both branches are testable.
$GLOBALS['__maturity_url'] = 'https://example.com/maturity/';
function sn_maturity_index_resolve_url( $slug ) { return 'maturity' === $slug ? $GLOBALS['__maturity_url'] : ''; }
function wp_get_theme() {
	return new class() {
		public function get( $k ) { return 'Version' === $k ? '11.1.10-test' : ''; }
	};
}

require __DIR__ . '/../inc/colophon-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + content parity\n";
ok( isset( $GLOBALS['__shortcodes']['sn_colophon'] ), 'shortcode registered on load' );
$html = sn_colophon_shortcode();
ok( false !== strpos( $html, 'custom WordPress block theme' ), 'intro paragraph present' );
foreach ( array( 'platform', 'type', 'build', 'hosting', 'tooling', 'ai', 'trust' ) as $slug ) {
	ok( false !== strpos( $html, 'sn-colophon-item--' . $slug ), "item present: $slug" );
}
ok( false !== strpos( $html, 'pair-programmer' ), 'the AI-assistance credit is kept verbatim' );

echo "\nGroup: the maturity loop-closer\n";
ok( false !== strpos( $html, 'href="https://example.com/maturity/"' ), 'trust line links the maturity index when it resolves' );
$GLOBALS['__maturity_url'] = '';
$plain = sn_colophon_shortcode();
ok( false === strpos( $plain, '<a href' ) && false !== strpos( $plain, 'maturity index' ), 'unresolvable index → plain text, never a dead link' );
$GLOBALS['__maturity_url'] = 'https://example.com/maturity/';

echo "\nGroup: versions + escaping + seam\n";
ok( false !== strpos( $html, 'Theme v11.1.10-test' ) && false !== strpos( $html, 'plugin v10.13.0-test' ), 'live version footer carries both versions' );
add_filter( 'sn_colophon_items', function ( $items ) {
	$items['evil'] = array( '<script>x</script>', 'text with <b>markup</b>' );
	return $items;
} );
$f = sn_colophon_shortcode();
ok( false === strpos( $f, '<script>' ) && false === strpos( $f, '<b>' ), 'filtered items are escaped at build — markup never survives' );
ok( false !== strpos( $f, 'sn-colophon-item--evil' ), 'filter seam adds a line without markup changes' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
