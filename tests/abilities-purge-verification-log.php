<?php
/**
 * The purge-verification trail, as data.
 *
 * The defect this closes is not a missing surface — inc/cloudflare-purge.php
 * has rendered these rows in the Cloudflare admin tab since v11.10.0. It is
 * that no MACHINE could read them, while the two glance widgets carry only the
 * five aggregate numbers. Asked on 2026-09-02 why the stale count was climbing,
 * the honest answer was "I can see the summary and not the rows" — and the
 * summary is exactly the thing that misleads.
 *
 * WHY IT MISLEADS, which is what most of these assertions defend: the log is a
 * bounded buffer. `total` pins at SN_CF_PROBE_LOG_CAP once full, so it is a
 * WINDOW SIZE, not a lifetime count. Read as cumulative, a rising `stale` says
 * "a tally is accumulating, this can only go up". Read correctly, the same
 * movement says fresh rows are being EVICTED by stale ones and the recent
 * failure RATE is climbing. Opposite conclusions, same five numbers.
 *
 * @since 13.86.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

/** Collected so the registration itself can be asserted, not assumed. */
$GLOBALS['__hooks'] = array();
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['__hooks'][] = $hook;
}
/** The option under test, swapped per case. */
function get_option( $name, $default = false ) {
	return $GLOBALS['__option'] ?? $default;
}

// Required, not redeclared: cap and algo must be the REAL constants. A test
// that hardcoded 20 would keep passing after someone changed the buffer, which
// is the specific failure mode this ability exists to make visible.
require __DIR__ . '/../inc/cloudflare-purge-verify.php';
require __DIR__ . '/../inc/abilities-purge-verification-log.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "purge-verification-log ability (v13.86.0)\n\n";

ok( in_array( 'wp_abilities_api_init', $GLOBALS['__hooks'], true ), 'registers on wp_abilities_api_init' );

// --- an empty log is NOT a clean edge ------------------------------------
$GLOBALS['__option'] = array();
$r = snt_ability_purge_verification_log( null );
ok( 'never_probed' === $r['state'], 'an empty log reports never_probed' );
ok( 0 === $r['counts']['stale'] && null === $r['counts']['stale_pct'], 'never_probed carries no rate — nothing to divide' );
ok( array() === $r['rows'], 'and no rows' );
// The whole point: absence of evidence must not read as evidence of freshness.
ok( 'fresh' !== $r['state'] && ( $r['counts']['fresh'] ?? 0 ) === 0, 'an unprobed edge is NEVER reported as fresh' );

// --- the cap is REPORTED, so a reader can tell total is a window ----------
$NOW = 1788400000;
// Expectations are TALLIED HERE, from the same loop that builds the fixture.
// They were literals (11 / 9 / 55.0 / 3.2) and a positive control caught it:
// changing SN_CF_PROBE_LOG_CAP from 20 to 15 reddened three assertions that
// had nothing to do with the cap. They were reading today's value of the
// constant back as a fact about the ability.
$full        = array();
$exp_stale   = 0;
$exp_fresh   = 0;
$exp_esc     = 0;
$step        = 600;
for ( $i = 0; $i < SN_CF_PROBE_LOG_CAP; $i++ ) {
	$is_stale = ( 0 === $i % 2 );
	$full[] = array(
		'time'      => $NOW - $i * $step,
		'post_id'   => 100 + $i,
		'url'       => 'https://example.test/n' . $i . '/',
		'result'    => $is_stale ? 'stale' : 'fresh',
		'escalated' => $is_stale,
		'algo'      => SN_CF_PROBE_ALGO,
	);
	if ( $is_stale ) { $exp_stale++; $exp_esc++; } else { $exp_fresh++; }
}
$exp_pct  = round( ( $exp_stale / SN_CF_PROBE_LOG_CAP ) * 100, 1 );
$exp_span = round( ( ( SN_CF_PROBE_LOG_CAP - 1 ) * $step ) / HOUR_IN_SECONDS, 1 );
$GLOBALS['__option'] = $full;
$r = snt_ability_purge_verification_log( null );

ok( SN_CF_PROBE_LOG_CAP === $r['cap'], 'cap is reported from the constant, so total can be recognised as bounded' );
ok( $r['counts']['total'] === SN_CF_PROBE_LOG_CAP, 'a full buffer reports total == cap' );
ok( $r['counts']['total'] <= $r['cap'], 'total can never exceed cap — the property that makes it a window, not a tally' );
ok( $exp_stale === $r['counts']['stale'] && $exp_fresh === $r['counts']['fresh'], 'stale and fresh are counted separately' );
ok( $exp_pct === $r['counts']['stale_pct'], 'stale_pct turns the count into a RATE — the reading the bare count invited getting wrong' );
ok( $exp_esc === $r['counts']['escalated'], 'escalations are counted' );

// --- the window the counts describe --------------------------------------
ok( null !== $r['window']['oldest'] && null !== $r['window']['newest'], 'the window is stated in ISO, not left to the reader' );
ok( $exp_span === $r['window']['span_hours'], 'span_hours says how long a period those counts cover' );
ok( gmdate( 'c', $NOW ) === $r['rows'][0]['time_iso'] && '' !== (string) $r['rows'][0]['time_iso'],
	'each row carries ISO time, for correlating against deploys' );

// --- a retired detector is excluded from counts but still visible ---------
// Counting it would put a broken instrument in numerator AND denominator;
// HIDING it would lose the forensics. Both, separately labelled.
$mixed = array(
	array( 'time' => $NOW, 'post_id' => 1, 'url' => 'https://example.test/a/', 'result' => 'fresh', 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' => $NOW - 60, 'post_id' => 2, 'url' => 'https://example.test/b/', 'result' => 'stale', 'algo' => 1 ),
	array( 'time' => $NOW - 120, 'post_id' => 3, 'url' => 'https://example.test/c/', 'result' => 'stale' ),
);
$GLOBALS['__option'] = $mixed;
$r = snt_ability_purge_verification_log( null );
ok( 1 === $r['counts']['total'], 'counts cover current-detector rows ONLY' );
ok( 2 === $r['counts_excluded_rows'], 'excluded rows are reported, never silently dropped from the denominator' );
ok( 3 === count( $r['rows'] ), 'but every row is still returned, for forensics' );
ok( 1 === $r['rows'][2]['algo'], 'a row with no algo stamp reads as the pre-fix detector, not as current' );

// --- TWO WRITERS, TWO MEANINGS -------------------------------------------
// The fixture mirrors the real log on 2026-09-03: a post-save probe that found
// a genuinely stale edge, beside two rows the purge-all-caches ability wrote
// while racing per-colo propagation after its own zone purge. Mixed into one
// rate they are unreadable — which is exactly how a counter climbing per
// button-press got read as a failing edge.
$GLOBALS['__option'] = array(
	array( 'time' => $NOW, 'post_id' => 0, 'url' => 'https://example.test/', 'result' => 'stale', 'source' => 'manual_zone_purge', 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' => $NOW - 22, 'post_id' => 0, 'url' => 'https://example.test/', 'result' => 'stale', 'source' => 'manual_zone_purge', 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' => $NOW - 300, 'post_id' => 7, 'url' => 'https://example.test/n/', 'result' => 'stale', 'escalated' => true, 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' => $NOW - 600, 'post_id' => 8, 'url' => 'https://example.test/m/', 'result' => 'fresh', 'algo' => SN_CF_PROBE_ALGO ),
	// A retired-detector row, which could ONLY ever say stale. by_source has
	// its own copy of the algo filter, and without this row that copy could
	// be deleted with every assertion still green.
	array( 'time' => $NOW - 900, 'post_id' => 9, 'url' => 'https://example.test/old/', 'result' => 'stale', 'algo' => 1 ),
);
$r = snt_ability_purge_verification_log( null );

ok( 'manual_zone_purge' === $r['rows'][0]['source'], 'a manual-purge row is labelled as one' );
// The post-save writer has never set `source`; defaulting it is what makes the
// split total rather than partial.
ok( 'post_save_probe' === $r['rows'][2]['source'], 'a row with no source reads as the post-save probe, not as unknown' );

/** Bucket accessor that reports a MISSING bucket instead of fataling on it. */
function bucket( $r, $src, $key ) {
	return $r['counts']['by_source'][ $src ][ $key ] ?? -1;
}
ok( isset( $r['counts']['by_source']['manual_zone_purge'] ), 'by_source names the manual-purge population' );
ok( 2 === bucket( $r, 'manual_zone_purge', 'stale' ), 'both button-press rows land in the manual bucket' );
ok( 1 === bucket( $r, 'post_save_probe', 'stale' ), 'and exactly one stale row is attributed to a real purge failure' );
ok( 2 === bucket( $r, 'post_save_probe', 'total' ), 'the post-save denominator excludes manual rows' );
ok( ! isset( $r['counts']['by_source']['__retired__'] ) && $r['counts']['total'] === array_sum( array_column( $r['counts']['by_source'], 'total' ) ),
	'by_source totals EXCLUDE the retired-detector row — it has its own copy of that filter' );

// THE POINT. The headline says 75% stale; the actionable population says 50%.
// Reporting only the headline is what sent a human looking for a broken edge.
ok( 75.0 === $r['counts']['stale_pct'], 'the headline rate still mixes both, as the widgets do' );
ok( 50.0 === round( ( bucket( $r, 'post_save_probe', 'stale' ) / max( 1, bucket( $r, 'post_save_probe', 'total' ) ) ) * 100, 1 ),
	'but the actionable rate is recoverable, and differs from the headline' );

// --- shape hygiene --------------------------------------------------------
$GLOBALS['__option'] = array( array( 'time' => $NOW, 'result' => 'weird', 'algo' => SN_CF_PROBE_ALGO ) );
$r = snt_ability_purge_verification_log( null );
ok( 'unknown' === $r['rows'][0]['result'], 'an unrecognised result is unknown, never silently fresh' );
ok( 0 === $r['counts']['fresh'] && 0 === $r['counts']['stale'], 'and it lands in neither bucket' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
