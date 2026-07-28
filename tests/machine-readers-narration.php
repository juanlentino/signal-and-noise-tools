<?php
/**
 * Tests: the one machine-readership sentence (Session 3 lane R2).
 *
 * The narrator is strict about denominators: a crawler read is a READ, never a
 * "visit" and never a "visitor", and it is never summed with a human beacon.
 * These assertions pin that vocabulary, and pin silence over a fabricated
 * claim whenever the sensor is unconfigured, unreachable, or simply quiet.
 *
 * SCAFFOLD-RED: written against the shell on purpose; the implementation turns
 * it green.
 *
 * Run: php tests/machine-readers-narration.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $count, $d = null ) { return 1 === (int) $count ? $single : $plural; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }

require __DIR__ . '/../inc/machine-readers-render.php';   // snt_mr_sum_hits_by + the AI-training map.
require __DIR__ . '/../inc/machine-readers-narration.php';

// The tests/machine-readers-render.php fixture rows: 60 machine reads total,
// of which openai (12+3) + anthropic (5) = 20 are declared AI-training.
$rows = array(
	array( 'family' => 'openai',    'surface' => 'llms',   'day' => '2026-07-27', 'hits' => 12 ),
	array( 'family' => 'openai',    'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'anthropic', 'surface' => 'html',   'day' => '2026-07-28', 'hits' => 5 ),
	array( 'family' => 'uptime',    'surface' => 'html',   'day' => '2026-07-28', 'hits' => 40 ),
);
$ok_result = array( 'ok' => true, 'rows' => $rows, 'error' => null );

echo "Group: the sentence states both honest numbers\n";
$s = snt_mr_narration_sentence( $ok_result, 30 );
ok( '' !== $s, 'a configured sensor with reads produces a sentence' );
ok( false !== strpos( $s, '60' ), 'total machine reads stated (12+3+5+40)' );
ok( false !== strpos( $s, '20' ), 'declared AI-training reads stated (openai 15 + anthropic 5)' );
ok( false !== strpos( $s, '30' ), 'the window is named, so the count has a denominator' );
ok( 1 === substr_count( $s, '.' ), 'exactly one sentence, one full stop' );
ok( false === strpos( $s, '<' ), 'plain prose, no markup for a sink to swallow' );

echo "\nGroup: honest denominators, the narrator's hard vocabulary rule\n";
ok( false === stripos( $s, 'visit' ), 'a crawler read is never called a visit (nor a visitor)' );
ok( false === stripos( $s, 'human' ), 'never mentions humans: these counts are not comparable to beacons' );
ok( false === stripos( $s, 'traffic' ), 'no "traffic", the word that invites summing machines with people' );
ok( false !== stripos( $s, 'read' ), 'the counted thing is named a read' );
ok( false !== stripos( $s, 'declared' ), 'the AI-training class is named as declared, never as proven' );
ok( false === stripos( $s, 'verified' ), 'never claims verified identity (user agents are self-reported)' );

echo "\nGroup: silence beats a fabricated claim\n";
ok( '' === snt_mr_narration_sentence( array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ), 30 ), 'unconfigured sensor says nothing' );
ok( '' === snt_mr_narration_sentence( array( 'ok' => false, 'rows' => array(), 'error' => 'network' ), 30 ), 'unreachable sensor says nothing' );
ok( '' === snt_mr_narration_sentence( array( 'ok' => false, 'rows' => $rows, 'error' => 'http_500' ), 30 ), 'a failed read says nothing even when stale rows ride along' );
ok( '' === snt_mr_narration_sentence( array( 'ok' => true, 'rows' => array(), 'error' => null ), 30 ), 'a configured but empty window says nothing' );
ok( '' === snt_mr_narration_sentence( array( 'ok' => true, 'rows' => array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-07-28', 'hits' => 0 ) ), 'error' => null ), 30 ), 'rows that sum to zero reads say nothing' );
ok( '' === snt_mr_narration_sentence( null, 30 ), 'a null payload says nothing, never a fatal' );
ok( '' === snt_mr_narration_sentence( 'nope', 30 ), 'a non-array payload says nothing, never a fatal' );

echo "\nGroup: the zero-AI window is stated, never implied by omission\n";
$quiet = snt_mr_narration_sentence( array( 'ok' => true, 'rows' => array( array( 'family' => 'uptime', 'surface' => 'html', 'day' => '2026-07-28', 'hits' => 40 ) ), 'error' => null ), 7 );
ok( '' !== $quiet && false !== strpos( $quiet, '40' ), 'machine reads without AI-training reads still produce a sentence' );
ok( false !== stripos( $quiet, 'none' ), 'a zero AI-training window says none, plainly' );
ok( 1 === substr_count( $quiet, '.' ), 'the zero-AI branch is still exactly one sentence' );

echo "\nGroup: grammar and purity\n";
$one = snt_mr_narration_sentence( array( 'ok' => true, 'rows' => array( array( 'family' => 'openai', 'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 1 ) ), 'error' => null ), 1 );
ok( false === strpos( $one, '1 times' ), 'a single read reads as "1 time", not "1 times"' );
$before = $ok_result;
snt_mr_narration_sentence( $ok_result, 30 );
ok( $before === $ok_result, 'pure: the payload is never mutated' );
ok( snt_mr_narration_sentence( $ok_result, 30 ) === $s, 'pure: same input, same sentence' );

echo "\nGroup: the window is the caller's, clamped like the fetch it describes\n";
ok( false !== strpos( snt_mr_narration_sentence( $ok_result, 7 ), '7-day' ), 'a 7-day window is narrated as 7 days' );
// v10.2.0 (verifier finding): the old form asserted strpos(..., '90') on a
// 900-day input — and "900" CONTAINS "90", so it passed with the clamp
// deleted. Assert the rendered token, and negatively assert the unclamped one.
$mr_hi = snt_mr_narration_sentence( $ok_result, 900 );
ok( false !== strpos( $mr_hi, '90-day' ), 'an out-of-range window clamps to the sensor maximum' );
ok( false === strpos( $mr_hi, '900' ), 'and the unclamped number never reaches the prose (the vacuous-assertion trap)' );
$mr_lo = snt_mr_narration_sentence( $ok_result, -5 );
ok( false === strpos( $mr_lo, '-5' ), 'a negative window cannot be narrated' );
ok( false !== strpos( $mr_lo, '1-day' ), 'it clamps to the sensor minimum instead' );

echo "\nGroup: the honesty vocabulary holds on BOTH prose branches\n";
// v10.2.0 (verifier finding): the six vocabulary pins only ran against the
// ai>0 branch. The zero-AI branch is prose too and can drift the same way.
$mr_zero = $quiet;
foreach ( array( 'visit', 'human', 'traffic', 'verified' ) as $banned ) {
	ok( false === stripos( $mr_zero, $banned ), "zero-AI branch avoids the dishonest word: $banned" );
}
ok( false !== stripos( $mr_zero, 'read' ), 'zero-AI branch still says reads' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
