<?php
/**
 * Tests for inc/analytics-maturity-page.php — the [sn_analytics_maturity]
 * static explainer (maturity I6; refreshed + formatted v9.70.0).
 * Run: php tests/analytics-maturity-page.php
 * @since plugin v9.35.0
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
// Models core: only default keys survive; missing keys take the default.
function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
$GLOBALS['__enq'] = array(); // recorded [handle, src] pairs.
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( $handle, (string) $src );
	return true;
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' );
}

require __DIR__ . '/../inc/analytics-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + contract\n";
ok( isset( $GLOBALS['__shortcodes']['sn_analytics_maturity'] ) && 'sn_analytics_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_analytics_maturity'], 'shortcode registered on load' );
ok( array() === $GLOBALS['__enq'], 'loading the file enqueues nothing — the stylesheet rides the render, not the pageload' );
ok( array( 'descriptive', 'diagnostic', 'predictive', 'prescriptive' ) === array_keys( sn_analytics_maturity_tiers() ), 'tier slugs mirror the snt_analytics_tier_badge whitelist, in order' );

echo "\nGroup: full format (the default)\n";
ob_start();
$html = sn_analytics_maturity_shortcode();
$echoed = ob_get_clean();
ok( '' === $echoed, 'returns, never echoes (shortcode contract)' );
ok( is_string( $html ) && 0 === strpos( $html, '<div class="sn-maturity sn-maturity--full">' ), 'root div carries sn-maturity--full' );
ok( false !== strpos( $html, 'sn-maturity-table' ), 'renders the tier table' );
ok( false !== strpos( $html, '<h2>' ) && false !== strpos( $html, 'sn-maturity-principles' ), 'full = intro + table + principles' );
foreach ( array( 'Descriptive', 'Diagnostic', 'Predictive', 'Prescriptive' ) as $tier ) {
	ok( false !== strpos( $html, '>' . $tier . '<' ), "names the $tier tier" );
}
ok( 1 === count( $GLOBALS['__enq'] ), 'render enqueues the stylesheet exactly once' );
ok( 'sn-maturity-front' === $GLOBALS['__enq'][0][0], 'enqueue handle is sn-maturity-front (the provenance-front idiom)' );
ok( 'assets/maturity-front.css' === substr( $GLOBALS['__enq'][0][1], -strlen( 'assets/maturity-front.css' ) ), 'enqueue src points at assets/maturity-front.css' );
ok( file_exists( SNT_PATH . 'assets/maturity-front.css' ), 'the stylesheet exists on disk' );

echo "\nGroup: refreshed copy — the 2026-07 integrity arc (verified claims only)\n";
// Descriptive: the honest vocabulary + durable history (v9.63.0-v9.66.0).
ok( false !== strpos( $html, 'pageview-gated visits vs unique visitor-days' ), 'descriptive names the gated-vs-visitor-day split' );
ok( false !== strpos( $html, 'viewless days counted' ), 'descriptive names the viewless count' );
ok( false !== strpos( $html, 'exact from a stated date' ), 'descriptive names the exact-metrics discontinuity date' );
ok( false !== strpos( $html, '90-day retention' ), 'descriptive names the durable history beyond edge retention' );
// Diagnostic: the two contracts (v9.64.1 input, v9.64.2 voice).
ok( false !== strpos( $html, 'input contract' ) && false !== strpos( $html, 'voice contract' ), 'diagnostic names both AI contracts' );
ok( false !== strpos( $html, 'can never be narrated as an anomaly' ), 'diagnostic states the structural-not-anomaly rule' );
ok( false !== strpos( $html, 'plain prose, no jargon' ), 'diagnostic states the voice contract terms' );
// Predictive: unchanged engines.
ok( false !== strpos( $html, 'Transparent statistics: robust median/MAD anomalies, Theil-Sen trends, backtested Holt forecasts' ), 'predictive engine wording unchanged' );
// Prescriptive: the triaging Overview (v9.69.0) + the invariant.
ok( false !== strpos( $html, 'sentiment-aware' ), 'prescriptive names the sentiment-aware attention flags' );
ok( false !== strpos( $html, 'percentage bar and an absolute floor' ), 'prescriptive names both attention thresholds' );
ok( false !== strpos( $html, 'can never invent an action' ), 'prescriptive keeps the no-invented-actions invariant' );
// Intro: the contracts join the fallback clause.
ok( false !== strpos( $html, 'governed by explicit input and voice contracts' ), 'intro names the AI contracts' );
ok( false !== strpos( $html, 'every surface renders with AI off' ), 'intro keeps the deterministic-fallback claim' );

echo "\nGroup: honesty principles — kept six + earned six\n";
ok( false !== strpos( $html, 'backtest' ) && false !== strpos( $html, 'per-person' ), 'kept principles still name the measured calibration + the cookieless boundary' );
ok( false !== strpos( $html, 'a single wild day cannot fake a trend' ), 'kept: robust statistics' );
ok( false !== strpos( $html, 'suppressed, not guessed' ), 'kept: minimum-sample floors' );
ok( false !== strpos( $html, 'monitored alarm, never a silent clamp' ), 'earned: never-invert is an alarm, not a clamp (v9.63.0)' );
ok( false !== strpos( $html, 'never impersonates a quiet week' ), 'earned: failed reads render as failures (v9.68.1)' );
ok( false !== strpos( $html, 'Zero, null, and absent are three different answers' ), 'earned: the three-answers rule' );
ok( false !== strpos( $html, 'never share a label' ), 'earned: every unit named (v9.65.0)' );
ok( false !== strpos( $html, 'forward-secret' ), 'earned: forward-secret visitor identity (worker salt rotation)' );
ok( false !== strpos( $html, 'never painted green' ), 'earned: honest chart colors (v9.68.0 sentiment badge)' );
// v13.19.0: THIRTEEN. The extra one graduated off the roadmap board when the
// Analytics done column hit the ceiling. These two count pins are rendered-<li>
// counts, which is why a grep for sn_analytics_maturity_principles() in tests/
// finds nothing and wrongly reads as unpinned -- they guard the OUTPUT.
ok( 13 === substr_count( $html, '<li>' ), 'exactly 13 principles — twelve kept, one graduated (v13.19.0)' );
ok( false !== strpos( $html, 'AI-sent reader is a different signal' ), 'GRADUATION: the AI-referral claim renders here — it retired off the board, it did not vanish, and the feature it describes is untouched' );
ok( false !== strpos( $html, 'keep them apart' ), 'GRADUATION: and it keeps the mechanism half — the rollups hold the segment separate, which is the whole claim' );

echo "\nGroup: escaping pins on the new strings\n";
ok( false !== strpos( $html, '&quot;could not be read&quot;' ), 'double quotes in the failed-read principle are escaped' );
ok( false !== strpos( $html, 'yesterday&#039;s visitors are unrecoverable' ), 'apostrophes in the forward-secrecy principle are escaped' );
ok( false !== strpos( $html, 'edge store&#039;s 90-day retention' ), 'apostrophe in the descriptive engine is escaped' );
ok( false === strpos( $html, '<script' ), 'no script tags anywhere' );

echo "\nGroup: static by design\n";
ok( 1 !== preg_match( '/\b\d{2,}(,\d{3})*\s+(views|visits)\b/', $html ), 'no live metrics baked into a public page' );

echo "\nGroup: format=table\n";
$t = sn_analytics_maturity_shortcode( array( 'format' => 'table' ) );
ok( 0 === strpos( $t, '<div class="sn-maturity sn-maturity--table">' ), 'root div carries sn-maturity--table' );
ok( false !== strpos( $t, '<table class="sn-maturity-table">' ), 'table variant renders the table' );
ok( false === strpos( $t, '<h2>' ) && false === strpos( $t, 'sn-maturity-principles' ) && false === strpos( $t, 'sn-maturity-strip' ), 'table variant renders ONLY the table' );

echo "\nGroup: format=principles\n";
$p = sn_analytics_maturity_shortcode( array( 'format' => 'principles' ) );
ok( 0 === strpos( $p, '<div class="sn-maturity sn-maturity--principles">' ), 'root div carries sn-maturity--principles' );
ok( false !== strpos( $p, 'sn-maturity-principles' ) && 13 === substr_count( $p, '<li>' ), 'principles variant renders the 13-item list' );
ok( false !== strpos( $p, '<h3>Honest by construction</h3>' ), 'principles variant keeps its heading' );
ok( false === strpos( $p, 'sn-maturity-table' ) && false === strpos( $p, '<h2>' ), 'principles variant renders ONLY the principles section' );

echo "\nGroup: format=compact (value-pinned whole)\n";
$c = sn_analytics_maturity_shortcode( array( 'format' => 'compact' ) );
$expected_compact = '<div class="sn-maturity sn-maturity--compact">'
	. '<p class="sn-maturity-compact-intro">Four analytics maturity tiers - deterministic SQL and statistics at the base, contract-governed AI narration on top, honest by construction.</p>'
	. '<div class="sn-maturity-strip">'
	. '<span class="sn-maturity-badge sn-maturity-badge--descriptive">Descriptive</span>'
	. '<span class="sn-maturity-badge sn-maturity-badge--diagnostic">Diagnostic</span>'
	. '<span class="sn-maturity-badge sn-maturity-badge--predictive">Predictive</span>'
	. '<span class="sn-maturity-badge sn-maturity-badge--prescriptive">Prescriptive</span>'
	. '</div></div>';
ok( $expected_compact === $c, 'compact variant is byte-identical to the pinned shape (one sentence + badge strip)' );
// The intro margin must WIN its cascade fight: the generic `.sn-maturity p` rule
// is 0,1,1 and a bare class selector is only 0,1,0 — permanently overridden, a
// dead rule. The override needs the element in its selector (0,2,1). Pin the
// winning form in, pin the dead form out, and keep the generic rule for full.
$css = (string) file_get_contents( SNT_PATH . 'assets/maturity-front.css' );
ok( false !== strpos( $css, '.sn-maturity p.sn-maturity-compact-intro{margin:0 0 .75rem}' ), 'stylesheet carries the compact-intro margin at winning specificity (0,2,1 beats the generic p rule)' );
ok( 0 === preg_match( '/(?<!p)\.sn-maturity-compact-intro\s*\{/', $css ), 'the dead 0,1,0 form (.sn-maturity-compact-intro as a bare class selector) is gone' );
ok( false !== strpos( $css, '.sn-maturity p{margin:0 0 1.25rem}' ), 'the generic paragraph rule stays intact for the full format' );

echo "\nGroup: whitelist fallback (pinned)\n";
$bogus = sn_analytics_maturity_shortcode( array( 'format' => 'bogus' ) );
ok( $bogus === $html, 'unknown format falls back byte-identically to full' );
$inject = sn_analytics_maturity_shortcode( array( 'format' => '"><script>alert(1)</script>' ) );
ok( $inject === $html && false === strpos( $inject, 'script>' ), 'a hostile format value hits the whitelist, never the class attribute' );
$empty = sn_analytics_maturity_shortcode( '' );
ok( $empty === $html, 'the bare no-atts form (core passes an empty string) renders full' );
$upper = sn_analytics_maturity_shortcode( array( 'format' => 'TABLE' ) );
ok( $upper === $html, 'the whitelist is exact-match (case-sensitive, pinned) — TABLE falls back to full' );

echo "\nGroup: every render enqueues (idempotence is core's job)\n";
ok( count( $GLOBALS['__enq'] ) >= 5, 'each render call passed through the enqueue gate' );
$handles = array_unique( array_map( function ( $e ) { return $e[0]; }, $GLOBALS['__enq'] ) );
ok( array( 'sn-maturity-front' ) === array_values( $handles ), 'only the sn-maturity-front handle is ever enqueued' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
