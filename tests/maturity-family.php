<?php
/**
 * Tests for the v10.11.0 maturity-family expansion: [sn_machine_maturity],
 * [sn_ops_maturity], [sn_a11y_maturity], and the [sn_maturity_index] hub.
 * One consolidated fixture: registration, format contracts, filter seams,
 * and the SECURITY CONTRACT sweep (model, never levers) across every
 * rendered format of every new page.
 * Run: php tests/maturity-family.php
 * @since plugin v10.11.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
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
// Page store models the LIVE hierarchy: every family page is a CHILD of
// /maturity/ (the drift that motivated slug resolution in v10.11.2).
$GLOBALS['__pages'] = array( 'analytics', 'proof-of-origin', 'ai-maturity', 'machine-readability', 'ops-maturity', 'a11y-maturity' );
function get_posts( $args ) {
	$name = isset( $args['name'] ) ? (string) $args['name'] : '';
	return in_array( $name, $GLOBALS['__pages'], true ) ? array( (object) array( 'post_name' => $name ) ) : array();
}
function get_permalink( $post ) {
	return 'https://example.com/maturity/' . $post->post_name . '/';
}

require __DIR__ . '/../inc/machine-maturity-page.php';
require __DIR__ . '/../inc/ops-maturity-page.php';
require __DIR__ . '/../inc/a11y-maturity-page.php';
require __DIR__ . '/../inc/maturity-index-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration\n";
foreach ( array( 'sn_machine_maturity', 'sn_ops_maturity', 'sn_a11y_maturity', 'sn_maturity_index' ) as $tag ) {
	ok( isset( $GLOBALS['__shortcodes'][ $tag ] ), "$tag registered on load" );
}
ok( array() === $GLOBALS['__enq'], 'loading the files enqueues nothing — stylesheets ride the render' );
ok( 5 === count( sn_machine_maturity_layers() ) && 5 === count( sn_ops_maturity_layers() ) && 5 === count( sn_a11y_maturity_layers() ), 'each new page walks five layers' );
ok( 8 === count( sn_machine_maturity_principles() ) && 8 === count( sn_ops_maturity_principles() ) && 8 === count( sn_a11y_maturity_principles() ), 'eight principles per page, matching the family' );
ok( 6 === count( sn_maturity_index_items() ), 'the index lists all six family members' );

echo "\nGroup: format contract (spot: each page's format whitelist behaves)\n";
foreach ( array( 'machine' => 'sn_machine_maturity_shortcode', 'ops' => 'sn_ops_maturity_shortcode', 'a11y' => 'sn_a11y_maturity_shortcode' ) as $n => $fn ) {
	$full = $fn( array() );
	ok( false !== strpos( $full, "sn-$n-maturity--full" ) && false !== strpos( $full, "sn-$n-maturity-table" ), "$n: bare renders full with the table" );
	$bogus = $fn( array( 'format' => '"><script>x</script>' ) );
	ok( false !== strpos( $bogus, "sn-$n-maturity--full" ) && false === strpos( $bogus, '<script' ), "$n: unknown format falls back; raw attr never reaches the class" );
}

echo "\nGroup: index cards + filter seam\n";
$idx = sn_maturity_index_shortcode();
ok( 6 === substr_count( $idx, '<a class="sn-maturity-index-card' ), 'all six default cards are linked (every default path is set)' );
ok( false !== strpos( $idx, 'https://example.com/maturity/ai-maturity/' ) && false !== strpos( $idx, 'https://example.com/maturity/machine-readability/' ) && false !== strpos( $idx, 'https://example.com/maturity/analytics/' ), 'v10.11.2: links resolve from the PAGES (get_permalink) — hierarchy-proof, child-of-/maturity/ paths come out right' );
ok( 'https://example.com/legacy/' === sn_maturity_index_resolve_url( '/legacy/' ), 'explicit path targets stay supported (filter escape hatch)' );
ok( 'https://ext.example/x' === sn_maturity_index_resolve_url( 'https://ext.example/x' ), 'absolute URL targets pass through' );
ok( '' === sn_maturity_index_resolve_url( 'no-such-page' ), 'unresolvable slug returns empty — the card renders unlinked, never dead' );
add_filter( 'sn_maturity_index_items', function ( $items ) {
	$items['future'] = array( 'Future moat', 'What next?', 'A page that does not exist yet.', '' );
	return $items;
} );
$idx2 = sn_maturity_index_shortcode();
ok( false !== strpos( $idx2, 'sn-maturity-index-card--unlinked' ) && false !== strpos( $idx2, 'Future moat' ), 'an empty path renders an UNLINKED card — never a dead link' );
remove_all_filters( 'sn_maturity_index_items' );

echo "\nGroup: SECURITY CONTRACT — no lever leaks across every new rendered surface\n";
$all = sn_maturity_index_shortcode();
foreach ( array( 'sn_machine_maturity_shortcode', 'sn_ops_maturity_shortcode', 'sn_a11y_maturity_shortcode' ) as $fn ) {
	foreach ( array( 'full', 'table', 'principles', 'scope', 'compact' ) as $f ) {
		$all .= $fn( array( 'format' => $f ) );
	}
}
$forbidden = array(
	// options, constants, credentials
	'sn_mcp_read_enabled', 'sn_mcp_rw_enabled', 'SN_MCP_READ_DISABLED', 'SN_MCP_RW_DISABLED', 'application password',
	// endpoint paths + protocol internals
	'wp-json', 'signal-noise/v1', '/mcp', 'mcp-rw',
	// ability/tool slugs + meta keys
	'update-post-surfaces', 'duplicate-body-scan', '_sn_focus_keyword', '_sn_autogen',
	// hook/file/vendor internals
	'snt_', 'sn_cf_', 'deploy.yml', 'github', 'cloudways', 'cloudflare', 'workers.dev', 'opentimestamps',
	// operational numbers that identify levers
	'5 writes', '10 minutes',
);
$leaks = array();
$low   = mb_strtolower( $all );
foreach ( $forbidden as $token ) {
	if ( false !== mb_strpos( $low, mb_strtolower( $token ) ) ) {
		$leaks[] = $token;
	}
}
ok( array() === $leaks, 'no sensitive token in any rendered format of any new page' . ( $leaks ? ' — LEAKED: ' . implode( ', ', $leaks ) : '' ) );
ok( false !== mb_strpos( $low, 'wcag 2.1 aa' ), 'sanity: a11y still names its standard (public claim, kept)' );
ok( false !== mb_strpos( $low, 'never confuses' ) || false !== mb_strpos( $low, 'never confused' ), 'sanity: ops carries the zero-vs-unknown principle' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
