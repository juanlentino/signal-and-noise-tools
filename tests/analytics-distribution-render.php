<?php
/**
 * Tests for snt_analytics_render_distribution()'s optional custom empty-state.
 * The bot-confidence panel needs a bespoke "needs Cloudflare Bot Management"
 * message instead of the generic "No <title> data" copy.
 * Run: php tests/analytics-distribution-render.php
 * @since plugin v6.6.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } } // v9.68.1: the read-failure fold copy
require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: empty distribution omits the panel + notes the title (v8.5.2, why-carrying since D4 §4)\n";
// Empty rows now omit the panel entirely and register the title for the fold line
// (was: a full 'no data' card with the custom/default message inside — the
// compact-&-pack redesign folds empties instead of drawing cards).
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_analytics_render_distribution( 'Bot confidence', array(), 'Needs Cloudflare Bot Management enabled.' ); $e = ob_get_clean();
ok( '' === trim( $e ), 'empty distribution renders no panel markup' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Bot confidence' === $noted[0]['title'], 'empty distribution notes its title for the fold' );
ok( 'Needs Cloudflare Bot Management enabled.' === $noted[0]['why'], 'empty distribution carries its $empty_msg as the fold why (D4 §4)' );
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_analytics_render_distribution( 'Scroll depth', array() ); $d = ob_get_clean();
ok( '' === trim( $d ), 'no-rows distribution renders no panel (2-arg back-compat)' );

echo "\nGroup: distribution renders bands when data present\n";
ob_start(); snt_analytics_render_distribution( 'Bot confidence', array( array( 'label' => '61–99', 'views' => 9 ) ), 'x' ); $h = ob_get_clean();
ok( strpos( $h, '61–99' ) !== false && strpos( $h, 'sn-an-dist-bar' ) !== false, 'renders bands when data present' );
ok( strpos( $h, 'sn-an-dist--wide' ) === false, 'no wide-label class by default' );
// $wide_labels adds the ellipsis-label modifier (launch velocity opts in).
ob_start(); snt_analytics_render_distribution( 'Launch velocity', array( array( 'label' => 'A very long post title', 'views' => 3 ) ), '', true ); $w = ob_get_clean();
ok( strpos( $w, 'sn-an-dist--wide' ) !== false, '$wide_labels adds the .sn-an-dist--wide class' );

echo "\nGroup: v9.68.1 — referrer categories: NULL cats (failed dims read) fold with the read-failure copy\n";
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_analytics_render_referrer_categories( null ); $rcf = ob_get_clean();
ok( '' === trim( $rcf ), 'refcats: null cats → folds, no panel, no fatal' );
$noted_rc = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_rc ) && 'Referrer categories' === $noted_rc[0]['title'], 'refcats: null cats → title noted for the fold' );
ok( 'Referrer categories could not be read (read failure — not an empty window).' === ( $noted_rc[0]['why'] ?? '' ),
	'refcats: null cats → the shared read-failure sentence, never the "No referrer data" empty copy' );
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_analytics_render_referrer_categories( array() ); $rce = ob_get_clean();
$noted_rce = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( '' === trim( $rce ) && 'No referrer data in this range yet.' === ( $noted_rce[0]['why'] ?? '' ),
	'refcats: [] keeps the honest empty-window copy (both directions pinned)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
