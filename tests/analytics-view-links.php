<?php
/**
 * Tests: the Links view (inc/analytics-view-links.php), on the analytics
 * vocabulary the dark window maps: which coded résumé link was opened, per
 * code and per visitor-day, with class as a column. 2026-10-31.
 *
 * Run: php tests/analytics-view-links.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
$GLOBALS['codes']  = array( array( 'code' => 'hera', 'first_seen' => '2026-10-17', 'last_seen' => '2026-10-29', 'views' => 5, 'human' => 3, 'suspect' => 1, 'bot' => 0 ) );
$GLOBALS['visits'] = array(
	array( 'code' => 'hera', 'day' => '2026-10-29', 'city' => 'New York', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'views' => 2, 'also' => array( '/about', '/notes/a', '/notes/b', '/notes/c', '/notes/d', '/notes/e', '/notes/f' ) ),
	array( 'code' => 'hera', 'day' => '2026-10-29', 'city' => '', 'country' => 'US', 'device' => 'desktop', 'class' => 'likely automated', 'views' => 1, 'also' => array() ),
);
function sn_link_codes_summary_read( $f, $t ) { $GLOBALS['range'] = array( $f, $t ); return $GLOBALS['codes']; }
function sn_link_codes_visits_read() { return $GLOBALS['visits']; }
require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-view-links.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$render = static function () { ob_start(); snt_analytics_render_view_links( '2026-10-01', '2026-10-31' ); return (string) ob_get_clean(); };

$html = $render();
ok( array( '2026-10-01', '2026-10-31' ) === $GLOBALS['range'], 'the per-code table reads the chosen range' );
ok( false !== strpos( $html, '>Links<' ) && false !== strpos( $html, '>Opened, last 90 days<' ), 'two panels: per code, per visitor-day' );
foreach ( array( 'Code', 'First seen', 'Last seen', 'Views', 'Human', 'Suspect', 'Bot', 'Day', 'Place', 'Device', 'Class', 'Also read' ) as $col ) {
	ok( false !== strpos( $html, '>' . $col . '</th>' ), "column: $col" );
}
ok( false !== strpos( $html, '<strong>hera</strong>' ) && false !== strpos( $html, '>2026-10-17<' ) && false !== strpos( $html, '>2026-10-29<' ), 'a code with its first and last day' );
ok( false !== strpos( $html, '>New York, US<' ) && false !== strpos( $html, 'data-colname="Place">US<' ), 'place: city and country, or the country alone' );
ok( false !== strpos( $html, '>likely automated<' ), 'a scanner-like visitor-day says so in its class' );
ok( false !== strpos( $html, '/about, /notes/a, /notes/b, /notes/c, /notes/d +2 more' ), 'the rest of the visitor-day: five named, the rest counted' );
ok( false !== strpos( $html, 'data-colname="First seen"' ) && false !== strpos( $html, 'data-colname="Also read"' ), 'phone cards: every cell of both tables carries its column name' );
ok( false !== strpos( $html, '<td class="num" data-colname="Views">' ) && false !== strpos( $html, '<td data-colname="Place">' ) && false !== strpos( $html, '<td data-colname="Also read">' ), 'counts align right, text (place, class, also read) aligns left' );
ok( false === strpos( $html, 'class="notice' ) && false === strpos( $html, 'sn-fieldset' ), 'no light-surface classes the dark window never maps' );
ok( false === strpos( $html, "\u{2014}" ), 'no em dash' );

$GLOBALS['codes'] = null; $GLOBALS['visits'] = null;
$html = $render();
ok( 2 === substr_count( $html, 'could not be read (read failure: not an empty window)' ), 'a failed read says so in both panels, never an empty table' );
$GLOBALS['codes'] = array(); $GLOBALS['visits'] = array();
$html = $render();
ok( false !== strpos( $html, 'No coded link was opened in this range' ) && false !== strpos( $html, 'No coded link was opened in the last 90 days' ), 'nothing opened: the empty notes' );

$src = (string) file_get_contents( __DIR__ . '/../inc/analytics-admin-resolve-parts.php' );
ok( 1 === preg_match( "/'campaigns'\s*=> 'Campaigns',\s*(?:\/\/[^\n]*\n\s*)*'links'\s*=> 'Links',/", $src ), 'the Links tab, beside Campaigns' );
$painter = (string) file_get_contents( __DIR__ . '/../apps/sn-analytics/parts/painters/view-links.php' );
ok( false !== strpos( $painter, "\$painters['view/links']" ), 'a native painter is registered (frame.php falls back to .snt-classic without one)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
