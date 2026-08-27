<?php
/**
 * Standalone tests for the widget TOKEN BRIDGE — the one survivor of the
 * v13.7.x desktop-theme arc (dropped whole in v13.8.0, owner decision).
 *
 * Our desktop widget views color their links and the Site Views spark
 * line through var(--os-window-link-*) with the plugin's own blue as the
 * fallback:
 *
 *     color: var(--os-window-link-accent, #4a9eff)   // links
 *     color: var(--os-window-link-color,  #4a9eff)   // spark line
 *
 * Why this outlives the theme: before v13.7.2 four anchors hardcoded
 * #4a9eff while five more inherited core wp-admin's link blue BY
 * ACCIDENT — two blues, nobody chose two. The bridge unifies the
 * fallback and lets any future shell theme (or the user's accent, where
 * upstream routes these tokens through it) recolor our widgets without
 * us shipping a theme at all.
 *
 * Pins: zero bare literals, exactly 11 bridge sites. The 11 matters —
 * the glob caught an anchor in desktop-mode-widget.js that a
 * hand-enumerated edit missed (v13.7.2), which is why this counts files
 * by pattern and not by list.
 *
 * Run: php tests/desktop-widget-token-bridge.php
 *
 * @since plugin v13.8.0 (pins carried over from v13.7.2)
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$files = glob( __DIR__ . '/../assets/desktop-mode-widget*.js' );
ok( count( $files ) >= 8, 'the widget-view glob finds the module set (' . count( $files ) . ' files)' );

$bare = 0; $accent = 0; $color = 0;
foreach ( $files as $f ) {
	$js = (string) file_get_contents( $f );
	$bare   += substr_count( $js, 'color:#4a9eff' );
	$accent += substr_count( $js, 'var(--os-window-link-accent, #4a9eff)' );
	$color  += substr_count( $js, 'var(--os-window-link-color, #4a9eff)' );
}
ok( 0 === $bare, 'no widget view hardcodes the link blue (bare color:#4a9eff count is ' . $bare . ')' );
ok( 10 === $accent, 'exactly 10 link bridges ride -accent (found ' . $accent . ')' );
ok( 1 === $color, 'exactly 1 spark-line bridge rides -color (found ' . $color . ')' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
