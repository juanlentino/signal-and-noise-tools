<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
$GLOBALS['sn_esc_html_calls'] = array();
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { $GLOBALS['sn_esc_html_calls'][] = $s; return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require __DIR__ . '/../inc/dash-briefing.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard briefing\n\n";

$calm = array( 'needy' => 0, 'views' => 103, 'views_delta' => 39, 'anchored' => 33, 'citations' => 0, 'warming' => 1 );
$s = sn_dash_briefing_sentence( $calm );
ok( false !== stripos( $s, 'holding' ) || false !== stripos( $s, 'fine' ), 'a calm site opens by saying so' );
ok( false !== strpos( $s, '103' ), 'the headline figure is in the sentence' );
ok( false !== strpos( $s, '39' ), 'and its change' );
ok( false !== stripos( $s, 'warming' ), 'a warming worker is mentioned, not hidden' );

// THE BACKSTOP PROPERTY. With every box hidden this sentence is the only thing
// left on the page, so it must never open with "everything is fine" when it is not.
$bad = array( 'needy' => 2, 'views' => 103, 'views_delta' => -4, 'anchored' => 33, 'citations' => 0, 'warming' => 0 );
$s = sn_dash_briefing_sentence( $bad );
ok( false === stripos( $s, 'holding' ), 'A SITE WITH FINDINGS NEVER OPENS WITH "HOLDING"' );
ok( false !== strpos( $s, '2' ), 'it leads with the count that needs attention' );
ok( false !== stripos( $s, 'attention' ), 'and names what the count is' );

// One finding reads as one, not "1 checks".
$one = array( 'needy' => 1, 'views' => 0, 'views_delta' => 0, 'anchored' => 0, 'citations' => 0, 'warming' => 0 );
ok( false === strpos( sn_dash_briefing_sentence( $one ), 'checks need' ), 'one finding is singular' );

// A negative delta is not dressed up as growth.
$down = array( 'needy' => 0, 'views' => 90, 'views_delta' => -13, 'anchored' => 1, 'citations' => 0, 'warming' => 0 );
ok( false === stripos( sn_dash_briefing_sentence( $down ), 'up 13' ), 'A FALL IS NOT REPORTED AS A RISE' );
ok( false !== stripos( sn_dash_briefing_sentence( $down ), 'down' ), 'it says down' );

// Missing data shortens the sentence rather than inventing a figure.
$thin = array( 'needy' => 0 );
$s = sn_dash_briefing_sentence( $thin );
ok( '' !== $s, 'a sentence is still produced with almost no data' );
ok( false === strpos( $s, '0 views' ), 'and it does not claim a figure it never received' );

// Escaping: the sentence is echoed, so the renderer must escape it.
ob_start(); sn_dash_render_briefing( $calm ); $h = ob_get_clean();
ok( false !== strpos( $h, 'sn-dash-briefing' ), 'the band renders its wrapper' );
ok( false === strpos( $h, '<script>' ), 'the band escapes its content' );
ok( in_array( sn_dash_briefing_sentence( $calm ), $GLOBALS['sn_esc_html_calls'], true ), 'the renderer actually passes the sentence through esc_html()' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
