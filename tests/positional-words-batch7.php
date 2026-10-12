<?php
/**
 * No position words in the Connections and Measurement painters (2026-10-12
 * audit, batch 7; the coordinator's scope add for Discography, Search
 * Console, Webhooks and the Health advisories).
 *
 * Owner rule: UI text names the box or the button ("under Credentials",
 * "press Sync now"), never a position ("above", "below", "left", "right",
 * "rail", "beside"): boxes move between rows and widths, and a sentence that
 * points at a place points at nothing once they do. This reads every string
 * literal in the painters of those tabs, on both surfaces (comments are not
 * read; variable names are not strings), and fails on any of the six words.
 * A word used for something other than a place is listed by its exact
 * string, so a new positional sentence cannot hide behind it.
 *
 * Run: php tests/positional-words-batch7.php
 *
 * @package SignalNoiseTools
 */

$root  = dirname( __DIR__ );
$files = array(
	// Discography.
	'apps/sn-dashboard/parts/leaves/connections-music.php',
	'apps/sn-dashboard/parts/leaves/connections-music-parts.php',
	'inc/admin-forms/music.php',
	// Search Console.
	'apps/sn-dashboard/parts/leaves/monitoring-search-console.php',
	'inc/search-console-admin.php',
	// Webhooks.
	'apps/sn-dashboard/parts/leaves/connections-webhooks.php',
	'apps/sn-dashboard/parts/leaves/connections-webhooks-parts.php',
	'apps/sn-dashboard/parts/leaves/connections-webhooks-log.php',
	'inc/webhooks-admin.php',
	// Health, with its advisories.
	'apps/sn-dashboard/parts/leaves/monitoring-health.php',
	'apps/sn-dashboard/parts/leaves/monitoring-health-parts.php',
	'apps/sn-dashboard/parts/leaves/monitoring-health-rows.php',
	'apps/sn-dashboard/parts/leaves/monitoring-health-sections.php',
	'inc/health-checks-admin.php',
	'inc/health-render-findings.php',
	'inc/health-check-stale-posts.php',
	// The rest of the batch's leaves, where the audit found the same words.
	'apps/sn-dashboard/parts/leaves/connections-keyring.php',
	'apps/sn-dashboard/parts/leaves/connections-indexnow.php',
	'apps/sn-dashboard/parts/leaves/monitoring-analytics.php',
	'apps/sn-dashboard/parts/leaves/monitoring-analytics-parts.php',
	'apps/sn-dashboard/parts/leaves/monitoring-analytics-settings.php',
	'apps/sn-dashboard/parts/leaves/monitoring-analytics-tuning.php',
	'apps/sn-dashboard/parts/leaves/monitoring-machine-readers.php',
	'apps/sn-dashboard/parts/leaves/monitoring-machine-readers-parts.php',
	'apps/sn-dashboard/parts/leaves/monitoring-insights.php',
	'apps/sn-dashboard/parts/leaves/monitoring-insights-parts.php',
	'apps/sn-dashboard/parts/leaves/monitoring-rss-parts.php',
	'inc/analytics-render-settings.php',
	'inc/machine-readers-render.php',
	'inc/north-star-settings.php',
);
// Exact strings that use one of the words for something other than a place
// (none today).
$allowed = array();
// Idioms that are not places: time ("right now"), SQL, and a measure under a
// threshold ("below the floor", "below its model's minimum"). Stripped before
// the scan, so the same string can still fail on a positional word.
$idioms = '/\bright (now|away)\b|\bLEFT JOIN\b|\bbelow (the floor|its model)/';

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
		if ( in_array( $tok[1], $allowed, true ) ) {
			continue;
		}
		if ( preg_match( '/\b(above|below|left|right|rail|beside)\b/i', (string) preg_replace( $idioms, '', $tok[1] ) ) ) {
			$hits[] = $rel . ':' . $tok[2] . ' ' . substr( $tok[1], 0, 90 );
		}
	}
}
pw_ok( array() === $hits, 'no painter string in these tabs points at a position: ' . implode( ' | ', $hits ) );

// The guard can fail: the sentence the audit found on Webhooks, fed through the same scan.
$probe = array_filter( token_get_all( "<?php echo 'Enabled: deliveries fire on the events below';" ), static fn( $t ) => is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && preg_match( '/\b(above|below|left|right|rail|beside)\b/i', $t[1] ) );
pw_ok( 1 === count( $probe ), 'the scan catches a positional sentence (it is not vacuous)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
