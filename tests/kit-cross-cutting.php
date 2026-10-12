<?php
/**
 * The kit's cross-cutting defaults (2026-10-12 audit, cross-cutting report).
 *
 * #3: a kit form fills its leaf and pairs its fields. #10, #11: status is
 * never by color alone, and a box's sub-heading has a face. A list row's status dot was
 * aria-hidden and only green, red or gray: producers already speak `warn`,
 * which painted green, so leaves put a red dot beside an amber value. A stat's
 * warning swatch is a color in the shell's shadow with no word. And
 * `<h4 class="snt-h">` had a rule only in the Signal & Noise files window, so
 * in S&N Home it fell to wp-admin's 13px h4 with 1.33em margins.
 *
 * Run: php tests/kit-cross-cutting.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require_once __DIR__ . '/lib/os-leaf-harness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$css  = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/assets/os-app.css' ) );
$rule = static function ( $sel ) use ( $css ) {
	$at = strpos( $css, $sel . ' {' );
	return false !== $at ? substr( $css, $at, (int) strpos( $css, '}', $at ) - $at ) : '';
};

// #11: every dot says its state in words, and each state has its own shape.
$words = array( 'ok' => 'OK', 'warn' => 'Needs a look', 'err' => 'Problem', 'unknown' => 'Not read' );
foreach ( $words as $dot => $word ) {
	$html = snt_kit_list( array( array( 'label' => 'Token', 'value' => 'x', 'dot' => $dot ) ) );
	ok( false !== strpos( $html, 'class="snt-dot snt-dot--' . $dot . '"' ) && false !== strpos( $html, 'role="img"' ) && false !== strpos( $html, 'aria-label="' . $word . '"' ) && false === strpos( $html, 'aria-hidden' ), "a $dot dot carries its word for a screen reader: $word" );
}
ok( false !== strpos( snt_kit_list( array( array( 'label' => 'Token', 'value' => 'x', 'dot' => 'warning' ) ) ), 'snt-dot--warn"' ), 'a producer that says "warning" gets the warn dot, not a green one' );
$shapes = array();
foreach ( array( 'warn', 'err', 'unknown' ) as $dot ) {
	$r = $rule( '.snt-dot--' . $dot );
	ok( '' !== $r && false !== strpos( $r, 'background' ), "the $dot dot has its own rule" );
	$shapes[ $dot ] = preg_replace( '/\s*background[^;]*;/', '', $r );
}
ok( false !== strpos( $shapes['warn'], 'clip-path' ) && false !== strpos( $shapes['err'], 'border-radius' ) && false !== strpos( $shapes['unknown'], 'box-shadow' ), 'a warn dot is a triangle, an err dot a square and an unknown dot a ring, so the states differ without color' );

// A toned stat with no caption says its tone in words.
$warn = snt_kit_stat( '83', '5xx at the edge', '', 'warn' );
$err  = snt_kit_stat( '3', 'Tools failing', '', 'err' );
ok( false !== strpos( $warn, 'caption="needs a look"' ), 'a warning stat with no caption says "needs a look"' );
ok( false !== strpos( $err, 'caption="a problem"' ), 'a danger stat with no caption says "a problem"' );
ok( false !== strpos( snt_kit_stat( '1', 'Broken path', 'one hit; needs a look', 'warn' ), 'caption="one hit; needs a look"' ), 'a stat that already says why keeps its caption' );
ok( false === strpos( snt_kit_stat( '0', 'Threats' ), 'caption=' ), 'an untoned stat gets no caption' );
// The classic Edge, 7 days card says the same words on its 5xx tile.
if ( ! function_exists( 'sn_cf_monitor_read' ) ) {
	function sn_cf_monitor_read() { return $GLOBALS['__cf_record']; }
}
if ( ! function_exists( 'size_format' ) ) { function size_format( $b ) { return $b . ' B'; } }
require_once SNT_PATH . 'inc/admin-glance.php';
require_once SNT_PATH . 'inc/cloudflare-readings-admin.php';
foreach ( array( 83 => true, 0 => false ) as $n => $want ) {
	$GLOBALS['__cf_record'] = array( 'configured' => true, 'fetched_at' => 0, 'zone' => array( 'available' => true, 'totals' => array( 'requests' => 10, 'cache_share' => null, 'bytes' => 1, 'threats' => 0, 'status_5xx' => $n ) ) );
	ob_start();
	sn_cf_edge_card_render();
	$card = (string) ob_get_clean();
	ok( $want === ( false !== strpos( $card, '<span class="sn-pill sn-pill--warn">needs a look</span>' ) ) && false !== strpos( $card, 'not read' ), "the classic 5xx tile at $n " . ( $want ? 'says needs a look, as the native tile does' : 'says nothing more' ) . ', and an unread cache share says not read' );
}

// #10: the sub-heading inside a box.
$h = $rule( ':is( .snt-leaf, .snt-view ) .snt-h' );
ok( '' !== $h && false !== strpos( $h, 'margin: 0' ) && false !== strpos( $h, 'font-size: 11px' ) && false !== strpos( $h, 'text-transform: uppercase' ) && false !== strpos( $h, 'color: var( --os-ui-fg-muted' ), 'h4.snt-h in either S&N window has the sub-heading face, not wp-admin\'s h4' );

// #3: a kit form pairs its fields unless the leaf says otherwise.
ok( false !== strpos( snt_kit_form( 'save_identity', '' ), 'columns="auto"' ), 'a kit form defaults to auto columns (two-up at a 480px form), not one column' );
ok( false !== strpos( snt_kit_form( 'save_identity', '', array( 'columns' => '1' ) ), 'columns="1"' ), 'a leaf that asks for one column still gets it' );

// #8: the leaf bar says how wide its pills need the window to be.
$connections = array( 'credentials' => 'Credentials', 'cloudflare' => 'Cloudflare', 'cloudways' => 'Cloudways', 'webhooks' => 'Webhooks', 'indexnow' => 'IndexNow', 'zenodo' => 'Zenodo', 'music' => 'Discography', 'cron' => 'Cron', 'scheduled-content' => 'Scheduled' );
ok( false !== strpos( snt_kit_tabs( 'cron', $connections, 'sub', 'Sections' ), 'data-snt-fit="920"' ), 'nine Connections pills (819px measured) ask for the select twin under a 920px window' );
ok( false === strpos( snt_kit_tabs( 'login', array( 'login' => 'Login URL', 'login-defense' => 'Login defense', 'firewall' => 'Firewall' ), 'sub', 'Sections' ), 'data-snt-fit' ), 'three short pills fit even a 360px window and keep the segmented control' );
$site = array( 'identity-and-seo' => 'Identity & SEO', 'front-end' => 'Front-End', 'performance' => 'Performance', 'redirects' => 'Redirects', 'broken-links' => 'Broken links' );
ok( false !== strpos( snt_kit_tabs( 'front-end', $site, 'sub', 'Sections' ), 'data-snt-fit="600"' ), 'Site\'s five pills (about 545px) switch under a 600px window: a short bar is not exempt (review on #2023)' );

// #15: an empty table or list says what none means, or the kit says who forgot.
$log = tempnam( sys_get_temp_dir(), 'snt-kit-empty' );
ini_set( 'error_log', $log );
$said  = snt_kit_list( array(), array( 'empty' => 'No custom rules.' ) );
$quiet = (string) file_get_contents( $log );
$bare  = snt_kit_list( array() );
$table = snt_kit_table( array( 'a' ), array() );
$after = (string) file_get_contents( $log );
ini_restore( 'error_log' );
@unlink( $log );
ok( false !== strpos( $said, 'No custom rules.' ) && '' === $quiet, 'a list that says what none means paints it, and logs nothing' );
ok( false !== strpos( $bare, '>Nothing to show.</p>' ), 'a list with no sentence of its own paints the kit\'s, never a blank paragraph' );
ok( 2 === substr_count( $after, 'without `empty`' ) && false !== strpos( $after, 'kit-cross-cutting.php' ), 'an empty table or list painted without its own sentence logs the caller to fix' );
ok( false !== strpos( $table, 'empty="Nothing to show."' ), 'the table keeps the kit sentence as its fallback' );
$rows = snt_kit_list( array( array( 'label' => 'a', 'value' => 'b' ) ) );
ok( '' === (string) @file_get_contents( $log ), 'a list with rows needs no empty sentence and logs nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
