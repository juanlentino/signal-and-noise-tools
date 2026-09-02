<?php
/**
 * The "Last purge" compare line — v13.70.1.
 *
 * v13.70.0 fixed "9 still stale" (a tally over the retained probe log, phrased
 * as a present state) and shipped a second reading of the same kind in its
 * place: the cell rendered "fresh · 9 of 20 probes stale", which a reader parses
 * as one sentence contradicting itself. Owner-reported on the release that
 * shipped it.
 *
 * The two numbers were never in conflict — `last` is the NEWEST probe's verdict,
 * `stale` counts every stale entry still retained — but a glance cell has no
 * room to explain that, so the WORDS have to carry the tense.
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

require_once __DIR__ . '/../inc/dash-widgets-render.php';

echo "Last-purge compare line — v13.70.1\n\n";

// ─── the clean case ────────────────────────────────────────────────────────
ok( '20 verified' === snt_dash_freshness_compare( 'fresh', 0, 20 ), 'no stale entries: the line counts what was verified' );
ok( '0 verified' === snt_dash_freshness_compare( 'unknown', 0, 0 ), 'an empty log says 0 verified — never a fabricated all-clear' );

// ─── THE DEFECT: headline and tally in different tenses ─────────────────────
$mixed = snt_dash_freshness_compare( 'fresh', 9, 20 );
ok( false !== strpos( $mixed, 'earlier' ), 'fresh headline + stale history: the line says EARLIER, so "fresh · 9 of 20 probes stale" stops reading as a contradiction' );
ok( false !== strpos( $mixed, '9 of 20' ), 'and still names both numbers — the history is information, not noise' );

// ─── same tense: no marker, because it would be wrong ───────────────────────
$now = snt_dash_freshness_compare( 'stale', 9, 20 );
ok( false === strpos( $now, 'earlier' ), 'a STALE headline drops the marker: the newest probe is stale right now, and calling it earlier would be false' );
ok( '9 of 20 probes stale' === $now, 'and reads as a plain present-tense tally' );

// ─── an unknown verdict is not a fresh one ─────────────────────────────────
ok( false !== strpos( snt_dash_freshness_compare( 'unknown', 3, 5 ), 'earlier' ), 'an UNKNOWN headline takes the marker too — only a currently-stale probe earns the present tense' );

// ─── REGRESSION: the two phrasings this cell has already shipped ───────────
$src = (string) file_get_contents( __DIR__ . '/../inc/dash-widgets-render.php' );
// COMMENT-STRIPPED, like every sibling source scan here: the helper's own
// docblock quotes the retired phrasing to explain it, and a scan that cannot
// tell an explanation from a declaration reports the fix as the defect.
$src_nc = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
ok( false !== strpos( $src, 'still stale' ), 'VACUITY: the retired phrasing IS quoted in the file (in a comment) — so a scan that ignores comments is doing real work here' );
ok( false === strpos( $src_nc, 'still stale' ), 'REGRESSION (v13.70.0): "still stale" survives only as an explanation, never as a string the widget can print' );
ok( 1 === preg_match( '/\'compare\' => snt_dash_freshness_compare\(/', $src ), 'the widget calls the pure helper, so this line is testable without rendering a widget' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
