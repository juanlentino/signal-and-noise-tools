<?php
/**
 * Tests for inc/maturity-layout.php + assets/maturity-layout-front.css — the
 * maturity pages on the page track as a spec sheet (2026-10-06).
 * Run: php tests/maturity-layout.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function has_shortcode( $c, $t ) { return false !== strpos( $c, '[' . $t ); }
require dirname( __DIR__ ) . '/inc/maturity-layout.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( sn_maturity_layout_wanted( 'x [sn_maturity_index] y' ), 'the hub takes the layout' );
ok( sn_maturity_layout_wanted( '[sn_a11y_maturity format="full"]' ), 'a system page takes the layout' );
ok( ! sn_maturity_layout_wanted( '[sn_maturity_roadmap]' ), 'the roadmap keeps its own wide layout' );
ok( ! sn_maturity_layout_wanted( 'plain prose' ), 'a page without a maturity shortcode does not load the sheet' );

$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/maturity-layout-front.css' );
ok( false !== strpos( $css, 'var(--wp--custom--page-track,1320px)' ), 'the page, title and rule take the shared page track' );
$roots = array( 'sn-maturity', 'sn-a11y-maturity', 'sn-ai-maturity', 'sn-ops-maturity', 'sn-ml-maturity', 'sn-machine-maturity', 'sn-prov-maturity' );
$missing = array();
foreach ( $roots as $r ) {
	if ( false === strpos( $css, '.' . $r . '--full' ) || false === strpos( $css, '.' . $r . '-principles' ) ) { $missing[] = $r; }
}
ok( array() === $missing, 'all seven system pages are in the band and principles selectors' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) );
ok( 1 === preg_match( '/>h3\{grid-column:1;/', $css ) && 1 === preg_match( '/>h3\+\*\{grid-column:2;/', $css ), 'each later heading takes the left column and its block the right: the band' );
ok( 1 === preg_match( '/@media \(max-width:900px\)\{[^@]*display:block/', $css ) && 1 === preg_match( '/@media \(max-width:640px\)\{[^@]*display:block/', $css ), 'bands stack under 900px and lists go to one column under 640px' );
ok( false === strpos( $css, 'opacity' ), 'no text is faded' );
echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
