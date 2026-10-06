<?php
/**
 * Guard: text is dimmed by a token (rust), never by opacity. Opacity fades the
 * words with the frame; a sitewide computed audit (2026-10-06) measured the
 * note panel's block link at 2.5:1 and the roadmap legend sub at 2.86:1.
 * Run: php tests/front-end-text-not-faded.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$dir  = dirname( __DIR__ ) . '/assets/';
$prov = (string) file_get_contents( $dir . 'provenance-front.css' );
$road = (string) file_get_contents( $dir . 'maturity-roadmap-front.css' );
$rule = static function ( $css, $sel ) {
	return preg_match( '/' . preg_quote( $sel, '/' ) . '\{([^}]*)\}/', $css, $m ) ? $m[1] : null;
};
foreach ( array( '.sn-prov-meta', '.sn-prov-onchain-host', '.sn-prov-caveat' ) as $sel ) {
	$body = $rule( $prov, $sel );
	ok( null !== $body && false === strpos( $body, 'opacity' ) && false !== strpos( $body, 'color--rust' ), "$sel is dimmed by rust, not opacity" );
}
$sub = $rule( $road, '.sn-maturity-roadmap-legend__sub' );
ok( null !== $sub && false === strpos( $sub, 'opacity' ) && false !== strpos( $sub, 'max(.68rem,11px)' ), 'the legend sub carries no opacity and holds the 11px floor' );
ok( 0 === preg_match( '/\.sn-maturity-roadmap-legend__cell(--[a-z]+)?(:hover)?\{[^}]*opacity/', $road ), 'no legend cell fades as a whole (it faded every word in it)' );
// 2026-10-06: the whole maturity family. Every sheet faded a column, a
// dash, a planned badge or a board cell with opacity; all now use rust.
foreach ( glob( $dir . '*maturity*.css' ) as $f ) {
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $f ) );
	ok( 0 === preg_match( '/(?<![-\w])opacity\s*:/', $css ), basename( $f ) . ' dims nothing with opacity' );
}
echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
