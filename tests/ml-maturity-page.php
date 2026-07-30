<?php
/**
 * Tests for inc/ml-maturity-page.php — the [sn_ml_maturity] static explainer
 * (the ML-kernel member of the maturity family). Mirrors the AI-page fixture:
 * registration, format whitelist, scope statuses + filter seam, PLUS the
 * SECURITY CONTRACT sweep (the page describes the MODEL, never the LEVERS —
 * no meta keys, tool slugs, file names, hook names, or threshold numbers),
 * PLUS the family's one hard extra: the THREE NEVER badges must render
 * (provenance verdicts / reader profiling / models in the reader's browser).
 * Run: php tests/ml-maturity-page.php
 * @since plugin v10.18.0
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

require __DIR__ . '/../inc/ml-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + contract\n";
ok( isset( $GLOBALS['__shortcodes']['sn_ml_maturity'] ) && 'sn_ml_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_ml_maturity'], 'shortcode registered on load' );
ok( array() === $GLOBALS['__enq'], 'loading the file enqueues nothing — the stylesheet rides the render, not the pageload' );
ok( array( 'corpus', 'model', 'compute', 'surface', 'draft', 'decide' ) === array_keys( sn_ml_maturity_layers() ), 'layer slugs in walk order: corpus, model, compute, surface, draft, decide' );
ok( 8 === count( sn_ml_maturity_principles() ), 'eight principles, matching the family' );
ok( in_array( 'never', SN_ML_MATURITY_STATUSES, true ), "the scope whitelist carries 'never' — inherited from the AI page" );

echo "\nGroup: formats\n";
$full = sn_ml_maturity_shortcode( array() );
ok( false !== strpos( $full, 'sn-ml-maturity--full' ), 'bare shortcode renders full' );
ok( false !== strpos( $full, 'sn-ml-maturity-table' ) && false !== strpos( $full, 'sn-ml-maturity-principles' ) && false !== strpos( $full, 'sn-ml-maturity-scope' ), 'full carries table + principles + scope' );
$table = sn_ml_maturity_shortcode( array( 'format' => 'table' ) );
ok( false !== strpos( $table, 'sn-ml-maturity-table' ) && false === strpos( $table, 'sn-ml-maturity-principles' ), 'table format is table only' );
$compact = sn_ml_maturity_shortcode( array( 'format' => 'compact' ) );
ok( 6 === substr_count( $compact, 'sn-ml-maturity-badge ' ), 'compact strip carries one badge per layer (6)' );
$bogus = sn_ml_maturity_shortcode( array( 'format' => '"><script>alert(1)</script>' ) );
ok( false !== strpos( $bogus, 'sn-ml-maturity--full' ) && false === strpos( $bogus, '<script' ), 'unknown format falls back to full; raw attribute never reaches the class' );
ok( array() !== $GLOBALS['__enq'] && 'sn-ml-maturity-front' === $GLOBALS['__enq'][0][0], 'rendering enqueues the front stylesheet by handle' );

echo "\nGroup: the THREE NEVERS render as commitments\n";
$scope_html = sn_ml_maturity_shortcode( array( 'format' => 'scope' ) );
ok( 3 === substr_count( $scope_html, 'sn-ml-maturity-scope-badge--never' ), 'exactly three never badges' );
ok( false !== strpos( $scope_html, 'Provenance verdicts' ), 'never #1 present by name: provenance verdicts' );
ok( false !== strpos( $scope_html, 'Reader profiling' ), 'never #2 present by name: reader profiling' );
ok( false !== strpos( $scope_html, 'reader&#039;s browser' ) || false !== strpos( $scope_html, 'reader\'s browser' ), "never #3 present by name: models in the reader's browser" );
ok( 5 === substr_count( $scope_html, 'sn-ml-maturity-scope-badge--live' ), 'five live consumers: related, cousins, keywords, links, ranked search (theme v11.2.0)' );
ok( 2 === substr_count( $scope_html, 'sn-ml-maturity-scope-badge--planned' ), 'two planned: analytics joins, ops cadence' );

echo "\nGroup: scope filter seam\n";
add_filter( 'sn_ml_maturity_scope', function ( $scope ) {
	$scope['ordering'] = array( 'Reading-path ordering', 'evil-raw-status' );
	return $scope;
} );
$filtered = sn_ml_maturity_shortcode( array( 'format' => 'scope' ) );
ok( false !== strpos( $filtered, 'Reading-path ordering' ), 'filter seam adds a surface without markup changes' );
ok( false === strpos( $filtered, 'evil-raw-status' ), 'a status outside the whitelist NEVER reaches the class attribute raw' );
ok( false !== strpos( $filtered, 'sn-ml-maturity-scope-badge--planned"><strong>Reading-path ordering' ), 'unknown status renders as planned' );
remove_all_filters( 'sn_ml_maturity_scope' );

echo "\nGroup: SECURITY CONTRACT — model, never levers, in any rendered format\n";
$all_output = '';
foreach ( array( 'full', 'table', 'principles', 'scope', 'compact' ) as $f ) {
	$all_output .= sn_ml_maturity_shortcode( array( 'format' => $f ) );
}
$forbidden = array(
	// meta keys + hook/function prefixes
	'_snt_ml', 'snt_', 'sn_ml_pipelines',
	// ability/tool slugs (the read door's names stay internal)
	'near-duplicate-scan', 'keyword-candidates', 'link-candidates',
	// implementation file names
	'ml-kernel', 'ml-pipelines', 'ml-artifacts', 'ml-related-render',
	// tuned numbers (similarity threshold, BM25 free parameters, test pins)
	'0.6', '1.2', '0.75', '4.4723',
	// transport internals shared with the family sweep
	'wp-json', 'signal-noise/v1', '/mcp',
);
$leaks = array();
$low   = mb_strtolower( $all_output );
foreach ( $forbidden as $token ) {
	if ( false !== mb_strpos( $low, mb_strtolower( $token ) ) ) {
		$leaks[] = $token;
	}
}
ok( array() === $leaks, 'no sensitive token appears in ANY rendered format' . ( $leaks ? ' — LEAKED: ' . implode( ', ', $leaks ) : '' ) );
ok( false !== mb_strpos( $low, 'tf-idf' ) && false !== mb_strpos( $low, 'bm25' ), 'sanity: the MATH is named (model-level, deliberate) even though the levers are not' );
ok( false !== mb_strpos( $low, 'the kernel computes' ), 'sanity: the program motto is on the page' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
