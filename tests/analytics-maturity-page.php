<?php
/**
 * Tests for inc/analytics-maturity-page.php — the [sn_analytics_maturity]
 * static explainer (maturity I6). Run: php tests/analytics-maturity-page.php
 * @since plugin v9.35.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }

require __DIR__ . '/../inc/analytics-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: [sn_analytics_maturity] explainer\n";
ok( isset( $GLOBALS['__shortcodes']['sn_analytics_maturity'] ) && 'sn_analytics_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_analytics_maturity'], 'shortcode registered on load' );
$html = sn_analytics_maturity_shortcode();
ok( is_string( $html ) && false !== strpos( $html, 'sn-maturity-table' ), 'renders the tier table (returns, never echoes — shortcode contract)' );
foreach ( array( 'Descriptive', 'Diagnostic', 'Predictive', 'Prescriptive' ) as $tier ) {
	ok( false !== strpos( $html, '>' . $tier . '<' ), "names the $tier tier" );
}
ok( false !== strpos( $html, 'backtest' ) && false !== strpos( $html, 'per-person' ), 'principles name the measured calibration + the cookieless boundary' );
ok( 1 !== preg_match( '/\b\d{2,}(,\d{3})*\s+(views|visits)\b/', $html ), 'static by design: no live metrics baked into a public page' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
