<?php
/**
 * The post-purge probe panel — v13.71.2.
 *
 * v13.71.1 shipped this table into the RAIL using three class names
 * (.sn-table, .sn-rail-h, .sn-rail-note) that exist in no stylesheet, so it
 * rendered as an unstyled browser-default table, twenty full URLs wide, in the
 * column the shell sizes at 1fr against the main column's 1.7fr. Owner-reported:
 * "no format at all", and the rail "a bit crammed".
 *
 * A class name is a CLAIM that a component exists. Nothing checked the claim,
 * and the failure is invisible from PHP — the markup is valid, the page renders,
 * and only a human looking at it can tell. So this suite checks the claim.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$src = (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' );
$css = '';
foreach ( (array) glob( __DIR__ . '/../assets/*.css' ) as $f ) { $css .= (string) file_get_contents( $f ); }

echo "Post-purge probe panel — v13.71.2\n\n";

ok( '' !== $src && '' !== $css, 'the tab source and the admin stylesheets are readable' );

// ─── every sn- class this tab claims must actually exist ───────────────────
preg_match_all( '/class="([^"]*)"/', $src, $m );
$claimed = array();
foreach ( $m[1] as $attr ) {
	foreach ( preg_split( '/\s+/', $attr ) as $c ) {
		// STATIC tokens only. A class attribute built by concatenation
		// (class="sn-pill sn-pill--' . $v . '") leaves fragments like
		// "sn-pill--'" in the raw source, and scoring those manufactures
		// failures for classes that are fine. What survives the filter is every
		// literal name — which is all three of the invented ones were.
		if ( 1 === preg_match( '/^sn-[a-z0-9-]+$/', $c ) ) { $claimed[ $c ] = true; }
	}
}
$claimed = array_keys( $claimed );
sort( $claimed );
ok( count( $claimed ) >= 6, 'VACUITY: the scan found this tab\'s sn- classes (' . count( $claimed ) . ') — a rotted regex must fail here, never report a clean sweep over nothing' );
$missing = array();
foreach ( $claimed as $c ) {
	if ( 1 !== preg_match( '/\.' . preg_quote( $c, '/' ) . '\b/', $css ) ) { $missing[] = $c; }
}
ok( array() === $missing, 'every sn- class this tab uses is DECLARED in a stylesheet — missing: ' . ( $missing ? implode( ', ', $missing ) : 'none' ) );

// The three that shipped unstyled, named so the regression is specific.
foreach ( array( 'sn-table', 'sn-rail-h', 'sn-rail-note' ) as $invented ) {
	ok( false === strpos( $src, 'class="' . $invented . '"' ), "REGRESSION: .$invented is gone — it was invented for a surface that already had a component" );
}

// ─── it renders with wp-admin's own table, not a new one ───────────────────
ok( 1 === preg_match( '/<table class="wp-list-table widefat striped">/', $src ), 'the probe table uses core widefat/striped — real formatting, no new CSS, and it matches the analytics tables already in this plugin' );
ok( 1 === preg_match( '/<th scope="col">When<\/th>/', $src ), 'headers carry scope="col"' );

// ─── main column, not the rail ─────────────────────────────────────────────
$panel_at = strpos( $src, 'Post-purge probes' );
$rail_at  = strpos( $src, "sn_admin_shell_rail(" );
ok( false !== $panel_at && false !== $rail_at && $panel_at < $rail_at, 'the panel is emitted BEFORE the rail opens — a twenty-row data grid belongs in the main column, not the summary one' );

// ─── the host is stripped ──────────────────────────────────────────────────
ok( false !== strpos( $src, "wp_parse_url( \$r_url, PHP_URL_PATH )" ), 'rows show the PATH: twenty identical origins is twenty times the width and none of the information' );

// ─── the retired detector is still labelled ────────────────────────────────
ok( false !== strpos( $src, 'retired detector' ), 'a pre-v11.29.1 row says so: that detector compared whole documents and could only ever return stale (11 of 11), so counting it silently would re-tell that lie' );
ok( false !== strpos( $src, 'SN_CF_PROBE_ALGO' ), 'and the label is driven by the stamped algorithm, never by a date guess' );

// ─── FOLDED, and open only when it is the task (v13.72.1) ──────────────────
ok( 1 === preg_match( '/<details class="sn-fieldset sn-disclosure"/', $src ), 'the panel is a <details> using the SHARED .sn-disclosure caret — not a new component, and not the browser triangle' );
ok( 1 === preg_match( "/'stale' === \\\$probe_newest \\? ' open' : ''/", $src ), 'it opens only when the NEWEST probe is stale: that is a live condition, while a stale count under a fresh newest is history' );
ok( false !== strpos( $src, 'retained, %2$d stale' ), 'the summary carries the counts, so a COLLAPSED fold still says what it is hiding' );
ok( 1 === preg_match( '/<\/details>/', $src ) && 0 === preg_match( '/<h2 class="sn-fieldset-h">Post-purge probes<\/h2>/', $src ), 'the old always-expanded heading is gone' );
$open_at  = strpos( $src, "' open' : ''" );
$table_at = strpos( $src, 'wp-list-table widefat striped' );
ok( false !== $open_at && false !== $table_at && $open_at < $table_at, 'the open decision is made before the table renders, from the log itself' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
