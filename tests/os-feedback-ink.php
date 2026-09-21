<?php
/**
 * Suggest / Apply feedback inside a kit leaf paints on the shell's tokens
 * (#1616).
 *
 * The status line `window.sntSetStatus` writes, the verdict headline and the
 * after-Apply cell states, and the Caches badge on the systems wall all gave
 * their colour in light-admin hex chosen on a white page: 1.9:1 to 3.2:1 on
 * the station's #1a1721 surface. Three pieces, three pins:
 *
 *  - snt-status.js picks a token map when the node is inside `.snt-app` (the
 *    same surface test health-suggest-actions.js uses for os-button and
 *    os-modal) and the hex map otherwise: the chromeless iframe paints white
 *    and the block editor sidebars carry no admin.css override, so a bare
 *    token swap would put mint on white there.
 *  - os-app.css paints the verdict / cell family on the tokens (comments
 *    stripped first, so the rule's own comment cannot keep the pin green).
 *  - freshness-dot.js sets the tone names os-badge defines (success /
 *    warning), the names snt_kit_tone() emits for the same card server-side;
 *    `ok` / `warn` matched no tone rule and the badge painted toneless.
 *
 * Run: php tests/os-feedback-ink.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

// 1. The shared status writer.
// The branch is pinned by its literal, direction included: the presence of
// both maps and of the surface test stays green with the ternary inverted
// (the kit app on hex, the classic tab on tokens), which is the defect again.
$js = (string) file_get_contents( $root . '/assets/snt-status.js' );
ok( '' !== $js, 'snt-status.js is readable' );
ok( false === strpos( $js, "node.style.color = '#" ), 'no branch writes a hex literal straight onto node.style.color' );
ok( false !== strpos( $js, "node.closest( '.snt-app' ) ? KIT_INK : CLASSIC_INK" ), 'the ink is picked by surface, in that direction: a node inside .snt-app takes the kit map, anything else the classic one' );
ok( false !== strpos( $js, "'var(--os-ui-success-fg)'" ) && false !== strpos( $js, "'var(--os-ui-warning-fg)'" ) && false !== strpos( $js, "'var(--os-ui-danger)'" ) && false !== strpos( $js, "'var(--os-ui-fg-muted)'" ), 'the kit map names the four shell tokens' );
ok( false !== strpos( $js, "'#0a5a1a'" ) && false !== strpos( $js, "'#8b1a1a'" ), 'the classic map keeps the light-admin hex for the classic tab, the chromeless iframe and the editor' );

// 2. The verdict headline and the after-Apply cell states.
$css      = (string) file_get_contents( $root . '/assets/os-app.css' );
$stripped = preg_replace( '#/\*.*?\*/#s', '', $css );
ok( $stripped !== $css, 'os-app.css comments were actually stripped' );
function snt_ink_rule( $stripped, $selector ) {
	$q = preg_quote( $selector, '/' );
	return preg_match( '/(?:^|\})\s*([^{}]*' . $q . '[^{}]*)\{([^}]*)\}/', $stripped, $m ) ? $m[2] : null;
}
$err = snt_ink_rule( $stripped, '.snt-app .snt-verdict-headline--err' );
$ok  = snt_ink_rule( $stripped, '.snt-app .snt-verdict-headline--ok' );
$wn  = snt_ink_rule( $stripped, '.snt-app .snt-verdict-headline--warn' );
ok( null !== $err && false !== strpos( $err, '--os-ui-danger' ), '.snt-app .snt-verdict-headline--err paints on --os-ui-danger' );
ok( null !== $ok && false !== strpos( $ok, '--os-ui-success-fg' ), '.snt-app .snt-verdict-headline--ok paints on --os-ui-success-fg' );
ok( null !== $wn && false !== strpos( $wn, '--os-ui-warning-fg' ), '.snt-app .snt-verdict-headline--warn paints on --os-ui-warning-fg' );
foreach ( array( '.snt-app .snt-cell-error' => '--os-ui-danger', '.snt-app .snt-suggest-inline-err' => '--os-ui-danger', '.snt-app .snt-cell-applied' => '--os-ui-success-fg' ) as $sel => $token ) {
	$rule = snt_ink_rule( $stripped, $sel );
	ok( null !== $rule && false !== strpos( $rule, $token ), $sel . ' paints on ' . $token );
}

// 3. The Caches badge on the systems wall.
$fd = (string) file_get_contents( $root . '/assets/freshness-dot.js' );
ok( false !== strpos( $fd, "'warning' : 'success'" ), 'freshness-dot.js sets the tone names os-badge defines (warning / success)' );
ok( false === strpos( $fd, "'warn' : 'ok'" ), '...and never the plugin\'s pill kinds, which os-badge has no rule for' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
