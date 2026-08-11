<?php
/**
 * Tests: the rights-read count at render (R3 gate 3B, the planned half).
 *
 * The count is a claim the site makes about itself on a public page, so the
 * assertions here are mostly about what it must REFUSE to say: never a
 * confident 0 from an unmeasured sensor, never a bare number when the
 * measurement is days old, never a fetch at render.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $s, $d = null ) { return (string) $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? (string) $single : (string) $plural; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { return true; }
function get_option( $k, $d = false ) { return $d; }

// The snapshot module is loaded for real, not stubbed: it OWNS the staleness
// threshold the sentence consults, and a hand-written stub of that boundary
// would let the page and the record drift apart silently — which is the whole
// reason the sentence delegates instead of re-deriving.
require __DIR__ . '/../inc/machine-readers-snapshot.php';
require __DIR__ . '/../inc/machine-readers-rights-reads.php';

/** Build a snapshot record without going near the option layer. */
function snap( $captured_age = 0, $by_surface = array(), $total = null ) {
	$sum = 0;
	foreach ( $by_surface as $v ) { $sum += $v; }
	return array(
		'captured_at' => time() - $captured_age,
		'days'        => 30,
		'total'       => null === $total ? $sum : $total,
		'by_family'   => array(),
		'by_surface'  => $by_surface,
	);
}

echo "Group: the rights surfaces are the rights-signal set, not every surface\n";
$surfaces = snt_mr_rights_surfaces();
ok( in_array( 'robots', $surfaces, true ), 'robots is a rights surface' );
ok( in_array( 'rights', $surfaces, true ), 'rights is a rights surface' );
ok( in_array( 'llms', $surfaces, true ), 'llms is a rights surface' );
ok( in_array( 'agents-manifest', $surfaces, true ), 'agents-manifest is a rights surface' );
ok( in_array( 'well-known', $surfaces, true ), 'well-known is a rights surface' );
ok( ! in_array( 'html', $surfaces, true ), 'html is NOT a rights read — reading an article is not reading the terms' );
ok( ! in_array( 'asset', $surfaces, true ), 'asset is not a rights read' );
ok( ! in_array( 'sitemap', $surfaces, true ), 'sitemap is discovery, not terms' );
// Every value must be a real surface the sensor can actually emit, or the count
// silently sums a key that will never appear.
$valid = array( 'robots', 'rights', 'llms', 'agents-manifest', 'well-known', 'feed', 'wp-json', 'sitemap', 'asset', 'html' );
ok( array() === array_diff( $surfaces, $valid ), 'every rights surface is a value the sensor enum can emit' );

echo "\nGroup: the count is three-valued, exactly like the snapshot under it\n";
ok( null === snt_mr_rights_reads( null ), 'no snapshot → NULL, never 0' );
ok( null === snt_mr_rights_reads( array( 'captured_at' => null, 'last_error' => 'http_503' ) ), 'a failed-attempt record → NULL, never 0' );
ok( 0 === snt_mr_rights_reads( snap( 60, array( 'html' => 400 ) ) ), 'measured, but only article reads → a real 0' );
ok( 9 === snt_mr_rights_reads( snap( 60, array( 'robots' => 4, 'llms' => 5, 'html' => 900 ) ) ), 'sums ONLY the rights surfaces (4+5), never the article reads' );
ok( 15 === snt_mr_rights_reads( snap( 60, array( 'robots' => 4, 'llms' => 5, 'rights' => 3, 'well-known' => 2, 'agents-manifest' => 1 ) ) ), 'sums all five rights surfaces' );

echo "\nGroup: the sentence never publishes a number it cannot stand behind\n";
$s = snt_mr_rights_reads_sentence( null );
ok( false !== strpos( $s, 'not been measured' ), 'unmeasured says so in words' );
ok( false === strpos( $s, ' 0 ' ) && false === strpos( $s, 'No machine' ), 'unmeasured NEVER renders as zero or as "no machine read them"' );

$s = snt_mr_rights_reads_sentence( snap( 60, array( 'html' => 400 ) ) );
ok( false !== strpos( $s, 'No machine' ), 'a MEASURED zero states the zero plainly' );
ok( false === strpos( $s, 'not been measured' ), 'a measured zero is not hedged as unmeasured' );

$s = snt_mr_rights_reads_sentence( snap( 60, array( 'robots' => 4, 'llms' => 5 ) ) );
ok( false !== strpos( $s, '9' ), 'a fresh count states the number' );
ok( false !== strpos( $s, '30 days' ), 'and names the window it covers' );
ok( false === strpos( $s, 'Last measured' ), 'a FRESH count does not clutter itself with an age clause' );

echo "\nGroup: a stale count carries its own age — the number alone would be a lie\n";
$s = snt_mr_rights_reads_sentence( snap( 3 * DAY_IN_SECONDS, array( 'robots' => 4, 'llms' => 5 ) ) );
ok( false !== strpos( $s, '9' ), 'the stale count is still reported' );
ok( false !== strpos( $s, 'Last measured' ), 'a stale count states WHEN it was true' );
ok( false !== strpos( $s, '3 days ago' ), 'the age is in the reader\'s units, not a timestamp' );
$s = snt_mr_rights_reads_sentence( snap( 9 * HOUR_IN_SECONDS, array( 'robots' => 1 ) ) );
ok( false !== strpos( $s, '9 hours ago' ), 'sub-day staleness reads in hours' );
$s = snt_mr_rights_reads_sentence( snap( 25 * HOUR_IN_SECONDS, array( 'robots' => 1 ) ) );
ok( false !== strpos( $s, '1 day ago' ), 'singular day, not "1 days ago"' );

echo "\nGroup: the window comes from the record, never from a hardcoded assumption\n";
$seven          = snap( 60, array( 'robots' => 2 ) );
$seven['days']  = 7;
$s              = snt_mr_rights_reads_sentence( $seven );
ok( false !== strpos( $s, '7 days' ), 'the sentence names the window the snapshot actually captured' );
ok( false === strpos( $s, '30 days' ), 'and does not assert the default window over the real one' );

echo "\nGroup: the sentence leaks no levers (the maturity pages\' standing contract)\n";
$all = snt_mr_rights_reads_sentence( null )
	. snt_mr_rights_reads_sentence( snap( 60, array( 'robots' => 4 ) ) )
	. snt_mr_rights_reads_sentence( snap( 3 * DAY_IN_SECONDS, array( 'robots' => 4 ) ) );
$low = strtolower( $all );
$leaks = array();
foreach ( array( 'snt_', 'sn_mr', 'wp-json', 'transient', 'option', 'cron', 'worker', 'cloudflare', '_sn/' ) as $t ) {
	if ( false !== strpos( $low, $t ) ) { $leaks[] = $t; }
}
ok( array() === $leaks, 'no internal token in any sentence variant' . ( $leaks ? ' — LEAKED: ' . implode( ', ', $leaks ) : '' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
