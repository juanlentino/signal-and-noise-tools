<?php
/**
 * Tests for inc/a11y-maturity-page.php — the [sn_a11y_maturity] static
 * explainer (sixth maturity sibling). Mirrors tests/ai-maturity-page.php,
 * PLUS an OVERCLAIM CONTRACT: the AI page's own contract asserts that no
 * internal lever leaks, because leaking one is that page's characteristic
 * harm. This page has no levers to leak. Its characteristic harm is the
 * opposite direction — claiming more accessibility than has been earned — so
 * the contract here asserts that every rendered format keeps the WCAG claim
 * qualified and never reaches for certification, audit, or completeness
 * language the site cannot back.
 *
 * WHY A PAGE SUITE AT ALL. a11y was covered only by tests/maturity-family.php,
 * the shared fixture that walks all six siblings. That file pins what the
 * family has in COMMON — counts, registration, the shortcode contract — so
 * every word specific to this page could be rewritten or dropped with the
 * sweep still green. The AI page grew its own suite for the same reason.
 *
 * Run: php tests/a11y-maturity-page.php
 * @since plugin v10.11.0 (the module); this suite backfilled 2026-08-22 —
 *        tests ship nothing, so no version bump rides with it.
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

require __DIR__ . '/../inc/a11y-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + contract\n";
ok( isset( $GLOBALS['__shortcodes']['sn_a11y_maturity'] ) && 'sn_a11y_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_a11y_maturity'], 'shortcode registered on load' );
ok( array() === $GLOBALS['__enq'], 'loading the file enqueues nothing — the stylesheet rides the render, not the pageload' );
ok( array( 'reach', 'drive', 'calm', 'read', 'admit' ) === array_keys( sn_a11y_maturity_layers() ), 'layer slugs in walk order: reach, drive, calm, read, admit — structure, then operation, then preference, then legibility, then the confession' );
ok( 'admit' === array_key_last( sn_a11y_maturity_layers() ), 'ADMIT IS LAST ON PURPOSE: the page ends on what is still broken, not on a win' );
ok( 12 === count( sn_a11y_maturity_principles() ), 'twelve principles — the ninth graduated off the roadmap board at v12.6.3, the tenth (per-palette contrast) at v13.8.2, and the eleventh and twelfth (alt-text coverage and quality) together at v13.18.0 when the done column filled a third time' );
// The alt-text pair is pinned by SUBSTANCE in tests/maturity-family.php; the
// count here is the cheap guard that catches a principle being dropped
// wholesale, which a substance pin on the OTHER two would still pass.
ok( array( 'live', 'planned', 'never' ) === SN_A11Y_MATURITY_STATUSES, 'the status whitelist is the sibling three; a11y takes no divergence' );

echo "\nGroup: formats\n";
$full = sn_a11y_maturity_shortcode( array() );
ok( false !== strpos( $full, 'sn-a11y-maturity--full' ), 'bare shortcode renders full' );
ok( false !== strpos( $full, 'sn-a11y-maturity-table' ) && false !== strpos( $full, 'sn-a11y-maturity-principles' ) && false !== strpos( $full, 'sn-a11y-maturity-scope' ), 'full carries table + principles + scope' );
$table = sn_a11y_maturity_shortcode( array( 'format' => 'table' ) );
ok( false !== strpos( $table, 'sn-a11y-maturity-table' ) && false === strpos( $table, 'sn-a11y-maturity-principles' ), 'table format is table only' );
$compact = sn_a11y_maturity_shortcode( array( 'format' => 'compact' ) );
ok( 5 === substr_count( $compact, 'sn-a11y-maturity-badge ' ), 'compact strip carries one badge per layer (5)' );
$bogus = sn_a11y_maturity_shortcode( array( 'format' => '"><script>alert(1)</script>' ) );
ok( false !== strpos( $bogus, 'sn-a11y-maturity--full' ) && false === strpos( $bogus, '<script' ), 'unknown format falls back to full; raw attribute never reaches the class' );
ok( array() !== $GLOBALS['__enq'] && 'sn-a11y-maturity-front' === $GLOBALS['__enq'][0][0], 'rendering enqueues the front stylesheet by handle' );

echo "\nGroup: the ADMIT layer is the page's whole argument\n";
ok( false !== strpos( $table, 'still broken' ), 'ADMIT: the row asks what is still broken, in those words' );
ok( false !== strpos( $table, 'published next to the wins' ), 'ADMIT: and says the gaps sit NEXT TO the wins — segregating them onto a page nobody reads is the failure this layer was written against' );
ok( false !== strpos( $table, 'report' ), 'ADMIT: and it names a way to report what the self-assessment missed' );
$principles_html = sn_a11y_maturity_principles_html();
ok( false !== strpos( $principles_html, 'outranks the self-assessment' ), "a reader's report OUTRANKS the self-assessment — the page subordinates its own verdict to the person actually blocked" );

echo "\nGroup: the self-assessment is never dressed up as an audit\n";
ok( false !== strpos( $table, 'self-assessed' ), 'the READ row names WCAG 2.1 AA as SELF-ASSESSED, in the same breath as the standard' );
ok( false !== strpos( $table, 'assessor named as the site itself' ), 'and it names WHO assessed — an unattributed conformance claim is the thing this row refuses to be' );
ok( false !== strpos( $principles_html, 'the standard is named, and so is who checked' ), 'the principle restates it: naming the standard without naming the checker is half a claim' );

echo "\nGroup: graduation — the ninth principle arrived by demotion, not authoring\n";
// Pinned on the RENDERED html, not on sn_a11y_maturity_principles(), for the
// reason the AI suite gives: a claim sitting in an array that no format emits
// is the mechanism-without-surface shape this project keeps re-learning. The
// array is where it lives; the page is where it counts. Substance in halves so
// a rewrite may reword freely and still cannot quietly drop the mechanism.
ok( false !== strpos( $principles_html, 'heading order' ), 'GRADUATION: the structural-scan claim renders here — it retired off the roadmap board, it did not vanish' );
ok( false !== strpos( $principles_html, 'fingerprint-bound' ), 'GRADUATION: and it keeps the fingerprint-bound half — a repair that cannot prove where it is landing is the failure the row was written against' );
ok( false !== strpos( $principles_html, 'does not land' ), 'GRADUATION: and it keeps the REFUSAL — the mechanism is that an unprovable repair is declined, not merely flagged' );

echo "\nGroup: scope statuses + filter seam\n";
$scope_html = sn_a11y_maturity_shortcode( array( 'format' => 'scope' ) );
ok( 3 === substr_count( $scope_html, 'sn-a11y-maturity-scope-badge ' ), 'three coverage rows render' );
ok( 0 === substr_count( $scope_html, 'sn-a11y-maturity-scope-badge--planned' ), 'and NONE of them is planned: the per-page scope shows what acts TODAY, the hub roadmap owns all future tense' );
ok( false !== strpos( $scope_html, 'Keyboard navigation' ) && false !== strpos( $scope_html, 'Reduced motion' ) && false !== strpos( $scope_html, 'Forced colors' ), 'the three live rows are keyboard, reduced motion, forced colors' );
add_filter( 'sn_a11y_maturity_scope', function ( $scope ) {
	$scope['evil'] = array( 'SVG alt text', 'evil-raw-status' );
	return $scope;
} );
$filtered = sn_a11y_maturity_shortcode( array( 'format' => 'scope' ) );
ok( false !== strpos( $filtered, 'SVG alt text' ), 'filter seam adds a surface without markup changes' );
ok( false === strpos( $filtered, 'evil-raw-status' ), 'a status outside the whitelist NEVER reaches the class attribute raw' );
ok( false !== strpos( $filtered, 'sn-a11y-maturity-scope-badge--planned"><strong>SVG alt text' ), 'unknown status renders as planned — the seam still accepts a future row for the release that flips one live' );
remove_all_filters( 'sn_a11y_maturity_scope' );

echo "\nGroup: OVERCLAIM CONTRACT — no rendered format promises more than is earned\n";
$all_output = '';
foreach ( array( 'full', 'table', 'principles', 'scope', 'compact' ) as $f ) {
	$all_output .= sn_a11y_maturity_shortcode( array( 'format' => $f ) );
}
$low = mb_strtolower( $all_output );
// The AI page forbids leaked levers because leaking one is its characteristic
// harm. This page's characteristic harm runs the other way: an accessibility
// statement that claims certification, third-party audit, or completeness is
// both false here and, in several jurisdictions, a statement with legal
// weight. Every token below is a promise the site cannot currently back.
$forbidden = array(
	// certification + third-party attestation the site has never obtained
	'certified', 'certification', 'accredited', 'audited by', 'third-party audit',
	'independent audit', 'vpat', 'conformance report',
	// completeness language no self-assessment can support
	'fully accessible', 'fully compliant', 'fully conformant', 'wcag 2.1 aaa', 'wcag 2.2 aa',
	'meets all', 'no known issues', 'no accessibility barriers', 'barrier-free',
	// legal-standard claims the page does not assess against
	'section 508 compliant', 'ada compliant', 'en 301 549',
);
$leaks = array();
foreach ( $forbidden as $token ) {
	if ( false !== mb_strpos( $low, mb_strtolower( $token ) ) ) {
		$leaks[] = $token;
	}
}
ok( array() === $leaks, 'no overclaim appears in ANY rendered format' . ( $leaks ? ' — CLAIMED: ' . implode( ', ', $leaks ) : '' ) );
// The contract must not be satisfiable by saying nothing. A page that dropped
// its standard entirely would pass every assertion above, so the floor is
// pinned too: the claim is REQUIRED to be present, and required to be hedged.
ok( false !== mb_strpos( $low, 'wcag 2.1 aa' ), 'sanity: the page still NAMES its standard — a contract satisfiable by silence would pass a page that claimed nothing at all' );
ok( false !== mb_strpos( $low, 'self-assessed' ), 'sanity: and still hedges it — the standard and the hedge must travel together or the pin above is decorative' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
