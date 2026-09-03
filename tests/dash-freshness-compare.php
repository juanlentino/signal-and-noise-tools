<?php
/**
 * The "Last purge" compare line — v13.71.1.
 *
 * THREE PHRASINGS SHIPPED BEFORE THIS ONE, all the same mistake:
 *   v13.70.0  "9 still stale"          — a retained-log tally as a live count
 *   v13.70.0  "9 of 20 probes stale"   — the same tally, beside a "fresh" headline
 *   v13.70.1  "9 of 20 earlier probes" — a tense marker taped onto the seam
 *
 * Owner ruling 2026-09-02: "If it's fresh, it is fresh. If it isn't, it
 * shouldn't say." The cell is labelled "Last purge" and answers ONE question
 * about ONE event. The history moved to the Cloudflare tab, which renders the
 * rows, so the count is drillable instead of decorative.
 *
 * The clock is INJECTED. A helper reading time() internally cannot be tested
 * for the "future timestamp" branch at all, and would race the second boundary
 * on the others.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function add_action( ...$a ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function human_time_diff( $a, $b = 0 ) { $d = abs( (int) $b - (int) $a ); return $d < 3600 ? intdiv( $d, 60 ) . ' mins' : intdiv( $d, 3600 ) . ' hours'; }

require_once __DIR__ . '/../inc/dash-widgets-render.php';

echo "Last-purge compare line — v13.71.1\n\n";

$NOW = 1_800_000_000;

// ─── one event, one question ────────────────────────────────────────────────
ok( 'verified 4 mins ago' === snt_dash_freshness_compare( 'fresh', $NOW - 240, $NOW ), 'fresh: says WHEN it was verified — a true statement about the event the cell is labelled for' );
// v13.86.0 — WAS 'still stale after 4 mins'. There is no recheck: the probe
// records one verdict, escalates once to a zone purge, and stops. So "still"
// asserted a PRESENT state nothing had measured, on the strength of a probe
// taken immediately BEFORE the purge most likely to have fixed it — and it got
// worse with age, reading "still stale after 1 day" the next morning about an
// edge nobody had looked at since. The desktop widget already said "Edge
// served a stale render", past tense, which is why the two surfaces disagreed
// in tone about one row.
ok( 'last verdict 4 mins ago' === snt_dash_freshness_compare( 'stale', $NOW - 240, $NOW ), 'stale: reports WHEN the verdict was taken, and claims nothing about now' );
ok( false === strpos( snt_dash_freshness_compare( 'stale', $NOW - 86400, $NOW ), 'still' ), 'a day-old stale verdict does not claim the edge is STILL stale — nothing rechecked it' );
ok( 'unread 4 mins ago' === snt_dash_freshness_compare( 'unknown', $NOW - 240, $NOW ), 'unknown is NOT fresh: the probe ran and could not read an answer' );

// ─── THE OWNER RULING: no history in this cell ──────────────────────────────
foreach ( array( 'fresh', 'stale', 'unknown' ) as $verdict ) {
	$line = snt_dash_freshness_compare( $verdict, $NOW - 240, $NOW );
	ok( 0 === preg_match( '/\bof\s+\d+\b|probes|earlier|still stale after \d+ probes/', str_replace( 'last verdict 4 mins ago', '', $line ) ),
		"[$verdict] carries no tally over other probes — \"if it's fresh, it is fresh\"" );
}

// ─── a missing or impossible timestamp is not an age ────────────────────────
ok( 'no timing recorded' === snt_dash_freshness_compare( 'fresh', 0, $NOW ), 'no timestamp: says so rather than inventing an age' );
ok( 'no timing recorded' === snt_dash_freshness_compare( 'fresh', $NOW + 60, $NOW ), 'a FUTURE stamp is a broken clock, never a very fresh purge' );

// ─── REGRESSION: the three retired phrasings ────────────────────────────────
$src    = (string) file_get_contents( __DIR__ . '/../inc/dash-widgets-render.php' );
$src_nc = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
ok( false !== strpos( $src, 'still stale' ) && false !== strpos( $src, 'earlier' ), 'VACUITY: the retired phrasings ARE quoted in the file (in comments), so a comment-stripped scan is doing real work' );
foreach ( array( '%d still stale', 'earlier probes stale', 'of %2$d probes stale' ) as $retired ) {
	ok( false === strpos( $src_nc, $retired ), 'REGRESSION: "' . $retired . '" survives only as an explanation, never as a string the widget can print' );
}
ok( 1 === preg_match( '/\$fresh\[.last_time.\]/', $src ), 'the cell reads last_time — the field that was in the summary all along and went unused while the cell reported a tally instead' );

// ─── the history has somewhere to live ──────────────────────────────────────
$cf = (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' );
ok( false !== strpos( $cf, 'Post-purge probes' ), 'the Cloudflare tab renders the individual rows, so "why were 9 stale?" is answerable' );
ok( false !== strpos( $cf, 'retired detector' ), 'and a row from the pre-v11.29.1 detector is LABELLED as such — it could only ever say stale, so mixing it in silently would re-tell that lie' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
