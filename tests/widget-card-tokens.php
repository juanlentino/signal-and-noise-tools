<?php
/**
 * The docked cards read the widget card's token contract and the four timer
 * widgets follow Recipe 2 (#1603).
 *
 * OpenStation 1.1.5 declared five on-dark names for a widget stylesheet
 * (`assets/css/variables.css`: --os-ui-color-surface, -text, -text-subtle,
 * -border, -accent; documented under "Theme tokens" in
 * docs/examples/register-widget.md, Stable with the widget API). The eleven
 * `assets/desktop-mode-widget*.js` files read none of them at 17.4.4: muted
 * text was `opacity`, hairlines were a private rgba, and the 13 link sites
 * borrowed `--os-window-link-*`, the overlay lines drawn between windows, which
 * a worn theme pins to its own blue. (The contract's accent follows the picker
 * only with no theme worn; Legacy at 1.1.10 pins `--os-ui-color-accent` and
 * `--os-ui-color-border` to its own literals, an upstream matter these pins
 * do not claim to fix.) Recipe 2 in the same doc stops a poll while the tab is hidden; the
 * deploy, uptime, RSS and queue cards polled regardless (43,049 first-party
 * calls in the 50-day reading) and the cache card refreshed on every reveal.
 *
 * Pins, on source with comments stripped so prose cannot keep them green:
 *  1. no widget file reads `--os-window-link-`; every link site and the
 *     views sparkline read `--os-ui-color-accent`;
 *  2. no bare `rgba(255,255,255,0.12)` / `0.14` hairline outside a
 *     `var(--os-ui-color-border, ...)` fallback;
 *  3. no `opacity:.N;` muting in a style string; muted text reads
 *     `--os-ui-color-text-subtle`;
 *  4. every `--os-ui-color-*` read carries an on-dark fallback (no
 *     light-theme grey);
 *  5. the four pollers listen on `visibilitychange`, stop while hidden and
 *     catch up on reveal only when stale; the cache card gates its reveal
 *     refresh on the last run's age;
 *  6. the status colours stay literal (the negative control: the contract
 *     has no name for them).
 *
 * Run: php tests/widget-card-tokens.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function strip_js( $js ) {
	$js = preg_replace( '#/\*.*?\*/#s', '', $js );
	return preg_replace( '#^\s*//.*$#m', '', $js );
}

$root  = dirname( __DIR__ );
$files = glob( $root . '/assets/desktop-mode-widget*.js' );
ok( count( $files ) >= 11, 'the widget scan found every widget script (' . count( $files ) . ', floor 11)' );

$code = array();
foreach ( $files as $path ) {
	$name = basename( $path );
	$js   = strip_js( (string) file_get_contents( $path ) );
	$code[ $name ] = $js;

	// 1. The link scope.
	ok( false === strpos( $js, 'color:#4a9eff' ), "$name hardcodes no bare link blue (the bridge suite's pin, carried over)" );
	ok( false === strpos( $js, '--os-window-link-' ), "$name borrows no --os-window-link-* overlay token" );

	// 2. Hairlines ride the border token: a bare 0.12 / 0.14 white is only
	//    legal as the var() fallback.
	$bare = preg_replace( '/var\(--os-ui-color-border,\s*rgba\(255,255,255,0\.1[24]\)\)/', '', $js );
	ok( 0 === preg_match( '/rgba\(255,255,255,0\.1[24]\)/', $bare ), "$name draws no hairline outside a var(--os-ui-color-border, ...) fallback" );

	// 3. Muting is a colour, not an opacity, in every style string.
	ok( 0 === preg_match( '/opacity:\s*\.\d+;/', $js ), "$name mutes no text with opacity in a style string" );

	// 4. Every token read carries an on-dark fallback.
	preg_match_all( '/var\(\s*(--os-ui-color-[a-z-]+)\s*,\s*([^)]*\)?)\s*\)/', $js, $m, PREG_SET_ORDER );
	$light = array();
	foreach ( $m as $hit ) {
		if ( preg_match( '/#(6b7280|e5e7eb|1d2327|646970|2271b1|50575e)|rgba\(\s*0\s*,\s*0\s*,\s*0/i', $hit[2] ) ) { $light[] = $hit[1] . ' -> ' . $hit[2]; }
	}
	ok( empty( $light ), "$name gives every --os-ui-color-* read an on-dark fallback" . ( $light ? ' [' . implode( ', ', $light ) . ']' : '' ) );
}

// Link sites: each file that paints a card link reads the accent token.
$link_files = array( 'desktop-mode-widget-anchors.js', 'desktop-mode-widget-health.js', 'desktop-mode-widget-cache.js', 'desktop-mode-widget-uptime.js', 'desktop-mode-widget-machine-readers.js', 'desktop-mode-widget-queue.js', 'desktop-mode-widget-cron.js', 'desktop-mode-widget-views.js', 'desktop-mode-widget.js', 'desktop-mode-widget-rss.js' );
foreach ( $link_files as $name ) {
	ok( isset( $code[ $name ] ) && false !== strpos( $code[ $name ], 'color:var(--os-ui-color-accent, #4a9eff)' ), "$name paints its link on --os-ui-color-accent with the plugin blue as fallback" );
}
$dimmed = array();
foreach ( $code as $name => $js ) { if ( false !== strpos( $js, 'color:var(--os-ui-color-accent, #4a9eff);text-decoration:none;opacity' ) ) { $dimmed[] = $name; } }
ok( empty( $dimmed ), 'no link on the accent carries an opacity that would dim the pick' . ( $dimmed ? ' [' . implode( ', ', $dimmed ) . ']' : '' ) );
ok( preg_match( '/color:var\(--os-ui-color-accent, #4a9eff\);margin:4px 0 6px;/', $code['desktop-mode-widget-views.js'] ) === 1, 'the views sparkline rides --os-ui-color-accent, not the window-links overlay colour' );
$subtle = 0;
foreach ( $code as $js ) { $subtle += substr_count( $js, 'var(--os-ui-color-text-subtle, rgba(255,255,255,.' ); }
ok( $subtle >= 50, "muted text reads --os-ui-color-text-subtle across the set ($subtle sites, floor 50)" );

// 5. Recipe 2 on the four pollers.
foreach ( array( 'desktop-mode-widget.js', 'desktop-mode-widget-uptime.js' ) as $name ) {
	$js = $code[ $name ];
	ok( false !== strpos( $js, "document.addEventListener( 'visibilitychange', onVisibilityChange )" ) && false !== strpos( $js, "document.removeEventListener( 'visibilitychange', onVisibilityChange )" ), "$name listens on visibilitychange and drops the listener at teardown" );
	ok( false !== strpos( $js, 'if ( torn || pending || document.hidden ) { return; }' ), "$name arms no timer while the tab is hidden" );
	ok( false !== strpos( $js, 'Math.max( 0, nextAt - Date.now() )' ), "$name re-arms on reveal for what is left of the wait, zero when stale" );
	ok( false === strpos( $js, 'timer = window.setTimeout( refresh, delay )' ), "$name no longer re-arms bare after every poll" );
}
foreach ( array( 'desktop-mode-widget-rss.js', 'desktop-mode-widget-queue.js' ) as $name ) {
	$js = $code[ $name ];
	ok( false !== strpos( $js, "document.addEventListener( 'visibilitychange', onVisibilityChange )" ) && false !== strpos( $js, "document.removeEventListener( 'visibilitychange', onVisibilityChange )" ), "$name listens on visibilitychange and drops the listener at teardown" );
	ok( false !== strpos( $js, 'if ( document.hidden ) { stopPolling(); return; }' ), "$name stops its interval when the tab hides" );
	ok( false !== strpos( $js, 'if ( Date.now() - lastRunMs >= REFRESH_MS ) { poll(); }' ), "$name catches up on reveal only when the data went stale" );
	ok( false === strpos( $js, 'window.setInterval( refresh, REFRESH_MS )' ), "$name runs no bare interval" );
}
ok( false === strpos( $code['desktop-mode-widget-queue.js'], "'focus'" ), 'the queue card no longer refreshes on every window focus (reveal-when-stale covers it)' );
$cache = $code['desktop-mode-widget-cache.js'];
ok( false === strpos( $cache, "document.addEventListener( 'visibilitychange', refresh )" ), 'the cache card no longer refreshes unconditionally on every visibilitychange' );
ok( false !== strpos( $cache, 'if ( document.hidden || Date.now() - lastRunMs < 60000 ) { return; }' ), 'the cache card refreshes on reveal only when the last run is older than the poll' );
ok( false !== strpos( $cache, 'lastRunMs = Date.now();' ), 'the cache card stamps the last run when it calls the ability' );

// 6. Negative control: the status colours have no widget token and stay literal.
ok( false !== strpos( $code['desktop-mode-widget.js'], "'#3fb950'" ) && false !== strpos( $code['desktop-mode-widget.js'], "'#d29922'" ) && false !== strpos( $code['desktop-mode-widget.js'], "'#ff9d94'" ), 'the deploy card keeps its green, amber and red status glyphs literal' );
ok( false !== strpos( $code['desktop-mode-widget-actions.js'], "'#3fb950'" ), 'Quick Actions keeps its success colour literal' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
