<?php
/**
 * No position words in the native leaves (2026-10-12 audit, batch 7; the
 * coordinator's scope add for Discography, Search Console, Webhooks and the
 * Health advisories).
 *
 * Owner rule: UI text names the box or the button ("under Credentials",
 * "press Sync now"), never a position ("above", "below", "left", "right",
 * "rail", "beside", "main column"): boxes move between rows and widths, and a
 * sentence that points at a place points at nothing once they do. This reads
 * every string literal in every native leaf, the classic twins of this
 * batch's tabs and the keyring's row text (comments are not read; variable
 * names are not strings), and fails on any of those words.
 *
 * PR #2025 review: the scan globs every leaf instead of a fixed list, so a new
 * leaf is read without editing this file, and "main column" is a position too.
 *
 * Run: php tests/positional-words-batch7.php
 *
 * @package SignalNoiseTools
 */

$root  = dirname( __DIR__ );
$files = array_merge(
	array_map( static fn( $f ) => substr( $f, strlen( $root ) + 1 ), (array) glob( $root . '/apps/sn-dashboard/parts/leaves/*.php' ) ),
	array(
		'inc/keyring.php',
		'inc/admin-forms/music.php',
		'inc/search-console-admin.php',
		'inc/webhooks-admin.php',
		'inc/health-checks-admin.php',
		'inc/health-render-findings.php',
		'inc/health-check-stale-posts.php',
		'inc/analytics-render-settings.php',
		'inc/machine-readers-render.php',
		'inc/north-star-settings.php',
	)
);
// Positional sentences in OTHER batches' leaves, listed in PR #2025 for their
// owners and left as they are: file => a fragment of the one string. Any other
// positional string anywhere in the scan fails.
$elsewhere = array(
	'apps/sn-dashboard/parts/leaves/ai-agent-tools-adapter.php' => 'at the address below',
	'apps/sn-dashboard/parts/leaves/ai-agent-tools-doors.php'   => 'the tool list below',
	'apps/sn-dashboard/parts/leaves/ai-models-budget-parts.php' => 'The figure above',
	'apps/sn-dashboard/parts/leaves/site-redirects-rules.php'   => 'add the first one below',
	'apps/sn-dashboard/parts/leaves/tools-provenance.php'       => 'the commitment above',
);
// Idioms that are not places, stripped before the scan so the same string can
// still fail on a positional word: time ("right now"), SQL, what remains ("is
// left", "days left", "left off"), a measure against a threshold ("below the
// floor", "below AA", "above its 7-day mean"), "a right tag" (a correct one),
// and the dashicons class name.
$idioms = '/\bright (now|away)\b|\bLEFT JOIN\b|\bbelow (the floor|its model|body-text AA|AA|%\d\$s:1)|\b(is|are|days) left\b|\bleft (off|unverifiable|afterwards)\b|\babove its 7-day mean\b|\ba right tag\b|arrow-right/';
$words  = '/\b(above|below|left|right|rail|beside)\b|\bmain column\b/i';

$pass = 0;
$fail = 0;
function pw_ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
	} else {
		++$fail;
		echo "FAIL: $m\n";
	}
}

$hits = array();
foreach ( $files as $rel ) {
	$src = file_get_contents( $root . '/' . $rel );
	pw_ok( false !== $src, "read $rel" );
	foreach ( token_get_all( (string) $src ) as $tok ) {
		if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) ) {
			continue;
		}
		if ( isset( $elsewhere[ $rel ] ) && false !== strpos( $tok[1], $elsewhere[ $rel ] ) ) {
			continue;
		}
		if ( preg_match( $words, (string) preg_replace( $idioms, '', $tok[1] ) ) ) {
			$hits[] = $rel . ':' . $tok[2] . ' ' . substr( $tok[1], 0, 90 );
		}
	}
}
pw_ok( count( $files ) > 100, 'the glob reads every leaf (' . count( $files ) . ' files)' );
pw_ok( array() === $hits, 'no painter string points at a position: ' . implode( ' | ', $hits ) );

// The guard can fail: positional sentences the audits found, through the same scan.
foreach ( array( 'Enabled: deliveries fire on the events below', 'Set them in the main column', 'Nothing is left; the box on the left' ) as $sentence ) {
	pw_ok( 1 === preg_match( $words, (string) preg_replace( $idioms, '', $sentence ) ), "the scan catches a positional sentence (it is not vacuous): $sentence" );
}
foreach ( array( 'Run it right now', '%5$d days left', 'below body-text AA', 'where you left off' ) as $idiom ) {
	pw_ok( 0 === preg_match( $words, (string) preg_replace( $idioms, '', $idiom ) ), "an idiom is not a place: $idiom" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
