<?php
/**
 * Tests for inc/maturity-roadmap-shortcode.php — [sn_maturity_roadmap],
 * the HUB-WIDE roadmap (done/planned/considering across every maturity
 * family). Mirrors the maturity-sibling fixture, PLUS the family's
 * SECURITY CONTRACT sweep: the rendered page must never leak option
 * names, endpoint paths, tool/change-type slugs, or meta keys.
 * Run: php tests/maturity-roadmap-shortcode.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function remove_all_filters( $tag ) { unset( $GLOBALS['__filters'][ $tag ] ); }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__enq'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( $handle, (string) $src );
	return true;
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' );
}

require __DIR__ . '/../inc/maturity-roadmap-shortcode.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "[sn_maturity_roadmap] — the hub-wide roadmap\n\n";

// Registration + statuses whitelist.
ok( isset( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] ), 'shortcode registered' );
ok( array( 'done', 'planned', 'considering' ) === SN_MATURITY_ROADMAP_STATUSES, 'exactly the three roadmap statuses, in walk order' );

// Default render: wrapper, heading, three groups + badges, own stylesheet.
$html = call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] );
ok( false !== strpos( $html, '<div class="sn-maturity-roadmap">' ), 'renders the scoped wrapper' );
ok( false !== strpos( $html, 'Roadmap' ), 'renders the Roadmap heading' );
foreach ( array( 'done', 'planned', 'considering' ) as $status ) {
	ok( false !== strpos( $html, 'sn-maturity-roadmap-group--' . $status ), "renders the '$status' group" );
	ok( false !== strpos( $html, 'sn-maturity-roadmap-badge--' . $status ), "renders the '$status' badge" );
}
ok( ! empty( $GLOBALS['__enq'] ) && 'sn-maturity-roadmap-front' === $GLOBALS['__enq'][0][0], 'enqueues its own front stylesheet' );

// HUB-WIDE coverage: every maturity family appears as an area kicker.
foreach ( array( 'Analytics', 'Provenance', 'AI', 'Machine layer', 'Accessibility', 'Operations' ) as $area ) {
	ok( false !== strpos( $html, '<span class="sn-maturity-roadmap-area">' . $area . '</span>' ), "the '$area' family appears with an area kicker" );
}
ok( false !== strpos( $html, 'sentence-scale change' ), 'the staged-edit done item is present in prose' );
ok( false !== strpos( $html, 'once that runner is stable' ), 'the agents migration is PLANNED with its gate named' );

// SECURITY CONTRACT: no option names, endpoint paths, tool/change-type
// slugs, or meta keys on the public page — the family's leak-proof sweep.
foreach ( array( 'sn_mcp', 'snt_', '_sn_', 'wp-json', 'sn_apply', 'sn-apply', 'sentence_replace', 'restore_revision', 'openstation', 'desktop_mode', 'MCP' ) as $token ) {
	ok( false === strpos( $html, $token ), "leak sweep: '$token' never reaches the page" );
}

// Filter seam: items are owner-editable; unknown statuses never render;
// content is escaped at build; emptied statuses are omitted, not hollow.
add_filter( 'sn_maturity_roadmap_items', function ( $items ) {
	return array(
		'done'  => array( array( 'Area <b>x</b>', 'Custom <script>alert(1)</script> item' ) ),
		'bogus' => array( array( 'X', 'Never rendered' ) ),
	);
} );
$html2 = call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] );
ok( false !== strpos( $html2, 'Custom &lt;script&gt;' ) && false === strpos( $html2, '<script>' ), 'filtered items render escaped — markup never survives' );
ok( false !== strpos( $html2, 'Area &lt;b&gt;' ), 'the area kicker is escaped too' );
ok( false === strpos( $html2, 'Never rendered' ) && false === strpos( $html2, 'bogus' ), 'a status outside the whitelist never renders' );
ok( false === strpos( $html2, 'sn-maturity-roadmap-group--planned' ), 'a status the filter emptied is omitted, not rendered hollow' );
remove_all_filters( 'sn_maturity_roadmap_items' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
