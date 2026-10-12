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

// #10: the sub-heading inside a box.
$h = $rule( ':is( .snt-leaf, .snt-view ) .snt-h' );
ok( '' !== $h && false !== strpos( $h, 'margin: 0' ) && false !== strpos( $h, 'font-size: 11px' ) && false !== strpos( $h, 'text-transform: uppercase' ) && false !== strpos( $h, 'color: var( --os-ui-fg-muted' ), 'h4.snt-h in either S&N window has the sub-heading face, not wp-admin\'s h4' );

// #3: a kit form pairs its fields unless the leaf says otherwise.
ok( false !== strpos( snt_kit_form( 'save_identity', '' ), 'columns="auto"' ), 'a kit form defaults to auto columns (two-up at a 480px form), not one column' );
ok( false !== strpos( snt_kit_form( 'save_identity', '', array( 'columns' => '1' ) ), 'columns="1"' ), 'a leaf that asks for one column still gets it' );

// #8: the leaf bar says how wide its pills need the window to be.
$connections = array( 'credentials' => 'Credentials', 'cloudflare' => 'Cloudflare', 'cloudways' => 'Cloudways', 'webhooks' => 'Webhooks', 'indexnow' => 'IndexNow', 'zenodo' => 'Zenodo', 'music' => 'Discography', 'cron' => 'Cron', 'scheduled-content' => 'Scheduled' );
ok( false !== strpos( snt_kit_tabs( 'cron', $connections, 'sub', 'Sections' ), 'data-snt-fit="920"' ), 'nine Connections pills (819px measured) ask for the select twin under a 920px window' );
ok( false === strpos( snt_kit_tabs( 'login', array( 'login' => 'Login URL', 'login-defense' => 'Login defense', 'firewall' => 'Firewall' ), 'sub', 'Sections' ), 'data-snt-fit' ), 'three short pills fit any desk window and keep the segmented control' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
