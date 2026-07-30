<?php
/**
 * Tests for inc/ai-maturity-page.php — the [sn_ai_maturity] static explainer
 * (third sibling of analytics/provenance maturity). Mirrors the sibling
 * fixture, PLUS the SECURITY CONTRACT block: every rendered format is
 * asserted free of the sensitive tokens the page must never leak (option
 * names, constants, endpoint paths, tool slugs, meta keys, throttle numbers).
 * Run: php tests/ai-maturity-page.php
 * @since plugin v10.10.0
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

require __DIR__ . '/../inc/ai-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + contract\n";
ok( isset( $GLOBALS['__shortcodes']['sn_ai_maturity'] ) && 'sn_ai_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_ai_maturity'], 'shortcode registered on load' );
ok( array() === $GLOBALS['__enq'], 'loading the file enqueues nothing — the stylesheet rides the render, not the pageload' );
ok( array( 'spec', 'generate', 'check', 'review', 'mark', 'bound' ) === array_keys( sn_ai_maturity_layers() ), 'layer slugs in walk order: spec, generate, check, review, mark, bound' );
ok( 8 === count( sn_ai_maturity_principles() ), 'eight honesty principles, matching the sibling pages' );
ok( in_array( 'never', SN_AI_MATURITY_STATUSES, true ), "the scope whitelist carries 'never' — the one deliberate divergence from the siblings" );

echo "\nGroup: formats\n";
$full = sn_ai_maturity_shortcode( array() );
ok( false !== strpos( $full, 'sn-ai-maturity--full' ), 'bare shortcode renders full' );
ok( false !== strpos( $full, 'sn-ai-maturity-table' ) && false !== strpos( $full, 'sn-ai-maturity-principles' ) && false !== strpos( $full, 'sn-ai-maturity-scope' ), 'full carries table + principles + scope' );
$table = sn_ai_maturity_shortcode( array( 'format' => 'table' ) );
ok( false !== strpos( $table, 'sn-ai-maturity-table' ) && false === strpos( $table, 'sn-ai-maturity-principles' ), 'table format is table only' );
$compact = sn_ai_maturity_shortcode( array( 'format' => 'compact' ) );
ok( 6 === substr_count( $compact, 'sn-ai-maturity-badge ' ), 'compact strip carries one badge per layer (6)' );
$bogus = sn_ai_maturity_shortcode( array( 'format' => '"><script>alert(1)</script>' ) );
ok( false !== strpos( $bogus, 'sn-ai-maturity--full' ) && false === strpos( $bogus, '<script' ), 'unknown format falls back to full; raw attribute never reaches the class' );
ok( array() !== $GLOBALS['__enq'] && 'sn-ai-maturity-front' === $GLOBALS['__enq'][0][0], 'rendering enqueues the front stylesheet by handle' );

echo "\nGroup: scope statuses + filter seam\n";
$scope_html = sn_ai_maturity_shortcode( array( 'format' => 'scope' ) );
ok( false !== strpos( $scope_html, 'sn-ai-maturity-scope-badge--never' ), "Note bodies render with the 'never' badge" );
ok( false !== strpos( $scope_html, 'Note bodies' ), 'the bodies commitment is present by name' );
ok( false !== strpos( $scope_html, 'sn-ai-maturity-scope-badge--never"><strong>Relevance ranking' ), "v10.18.0: 'Relevance ranking &amp; similarity' renders as a never — the deterministic tier below AI is named, so 'where AI stops' has a floor" );
ok( 2 === substr_count( $scope_html, 'sn-ai-maturity-scope-badge--never' ), 'exactly two never badges on the AI page: bodies + ranking' );
add_filter( 'sn_ai_maturity_scope', function ( $scope ) {
	$scope['voice'] = array( 'Voice cloning', 'evil-raw-status' );
	return $scope;
} );
$filtered = sn_ai_maturity_shortcode( array( 'format' => 'scope' ) );
ok( false !== strpos( $filtered, 'Voice cloning' ), 'filter seam adds a surface without markup changes' );
ok( false === strpos( $filtered, 'evil-raw-status' ), 'a status outside the whitelist NEVER reaches the class attribute raw' );
ok( false !== strpos( $filtered, 'sn-ai-maturity-scope-badge--planned"><strong>Voice cloning' ), 'unknown status renders as planned' );
remove_all_filters( 'sn_ai_maturity_scope' );

echo "\nGroup: SECURITY CONTRACT — no lever leaks in any rendered format\n";
$all_output = '';
foreach ( array( 'full', 'table', 'principles', 'scope', 'compact' ) as $f ) {
	$all_output .= sn_ai_maturity_shortcode( array( 'format' => $f ) );
}
$forbidden = array(
	// options + constants (kill switches, credential binding)
	'sn_mcp_read_enabled', 'sn_mcp_rw_enabled', 'SN_MCP_READ_DISABLED', 'SN_MCP_RW_DISABLED',
	'app_password', 'application password',
	// endpoint paths + namespaces
	'wp-json', 'signal-noise/v1', '/mcp', 'mcp-rw',
	// ability/tool slugs
	'update-post-surfaces', 'duplicate-body-scan', 'get-post-content', 'list-posts',
	// meta keys + sentinels by name
	'_sn_focus_keyword', '_sn_autogen', '_sn_meta_description', '_sn_og_card_title',
	// operational numbers (throttle, allowlist sizes)
	'5 writes', '10 minutes', '10-minute', '28 ', ' 35 ',
	// internal file/function names
	'mcp-read-guard', 'mcp-rw-guard', 'snt_', 'sn_mcp_',
);
$leaks = array();
$low   = mb_strtolower( $all_output );
foreach ( $forbidden as $token ) {
	if ( false !== mb_strpos( $low, mb_strtolower( $token ) ) ) {
		$leaks[] = $token;
	}
}
ok( array() === $leaks, 'no sensitive token appears in ANY rendered format' . ( $leaks ? ' — LEAKED: ' . implode( ', ', $leaks ) : '' ) );
ok( false !== strpos( $all_output, 'Model Context Protocol' ), 'sanity: the page still says something real (protocol named at the design level is deliberate)' );
ok( false !== strpos( $all_output, 'kill switch' ), 'sanity: kill-switch EXISTENCE is published (deterrent); its levers are not' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
