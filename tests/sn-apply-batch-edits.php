<?php
/**
 * Batch body edits: N candidates in ONE post must produce ONE write.
 *
 * WHY THIS EXISTS. The public provenance ledger records a note, "Two kinds of
 * provenance", at v1 -> v2 -> v3. Both increments are halves of a SINGLE edit:
 * converting one dashed aside into parentheses.
 *
 *   v1->v2:  'SLSA provenance — were designed'  ->  'SLSA provenance) were designed'
 *   v2->v3:  'supply chain — code signing'      ->  'supply chain (code signing'
 *
 * v2 is therefore a permanently anchored state in which the sentence had an
 * opening parenthesis and a closing em-dash — a state nobody ever intended to
 * publish. The scanner's own pairing rule reasons about the pair as ONE edit
 * (a PAIR in one run -> parentheses) but emits it as TWO candidates, and
 * sn_apply splices ONE occurrence per call, each doing its own
 * wp_update_post(), and every publish re-anchors.
 *
 * sn_apply's existing `target` array batches across POSTS ("per-post writes are
 * atomic, across posts they are independent"). It cannot batch WITHIN a post.
 * That is the gap this closes.
 *
 * THE ALGORITHM, and why the obvious one is wrong. Looping the existing impls
 * and writing at the end fails twice over: each impl re-reads
 * get_post()->post_content, so edit 2 would splice the ORIGINAL string and
 * clobber edit 1; and drift/em-dash fingerprints are md5(phrase|window) over an
 * 80-char window (SNT_AI_DRIFT_FINGERPRINT_WINDOW), so an edit inside a
 * neighbour's window invalidates that neighbour's fingerprint. Hence: validate
 * and locate EVERY edit against the ORIGINAL content, then splice in DESCENDING
 * position order so no splice shifts a later offset, then write once.
 *
 * The planner under test is PURE (content in, content out, no DB, no writes) so
 * gate 1 can use it read-only for the diff and the executor can use it for the
 * write — one implementation, two callers.
 *
 * @since plugin v10.66.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }

function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function __( $s, $d = null ) { return $s; }
// Models REAL WordPress: wp_strip_all_tags() ends with `return trim( $text );`.
// Omitting that trim is what made an earlier suite green while production
// refused every whitespace-carrying replacement (v10.65.3).
function wp_strip_all_tags( $t, $remove_breaks = false ) { return trim( strip_tags( (string) $t ) ); }
function strip_shortcodes( $t ) { return $t; }

require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require_once __DIR__ . '/../inc/sn-apply-sentence-replace.php';
require_once __DIR__ . '/../inc/sn-apply-batch-edits.php';

/** Build a drift/em-dash edit whose fingerprint is minted from the ORIGINAL content. */
function edit_at( $content, $phrase, $replacement, $context = '' ) {
	$pos = snt_ai_drift_locate_in_raw( $content, $phrase, $context );
	return array(
		'phrase'          => $phrase,
		'replacement'     => $replacement,
		'fingerprint'     => snt_ai_drift_fingerprint( $content, $phrase, $pos ),
		'context_snippet' => $context,
	);
}

echo "Group 1: the real regression — one logical edit, one resulting content\n";

$real = 'Techniques developed for the software supply chain — code signing, software '
	. 'bill of materials, SLSA provenance — were designed to verify that an artifact '
	. 'came from where it claims.';

$edits = array(
	edit_at( $real, 'chain — code', 'chain (code' ),
	edit_at( $real, 'provenance — were', 'provenance) were' ),
);
$plan = snt_sn_apply_plan_batch_edits( $real, 'emdash_replace', $edits );

ok( ! is_wp_error( $plan ), 'a two-edit batch plans without error' );
$got = is_wp_error( $plan ) ? '' : $plan['new_content'];
ok( false !== strpos( $got, 'supply chain (code signing' ), 'the OPENING paren landed' );
ok( false !== strpos( $got, 'SLSA provenance) were designed' ), 'the CLOSING paren landed' );
ok( false === strpos( $got, "\xE2\x80\x94" ), 'no em-dash survives anywhere in the result' );
ok( ! is_wp_error( $plan ) && 2 === $plan['count'], 'the plan reports 2 edits applied' );
ok(
	$got === 'Techniques developed for the software supply chain (code signing, software '
		. 'bill of materials, SLSA provenance) were designed to verify that an artifact '
		. 'came from where it claims.',
	'the whole string is byte-exact — both splices, correct offsets'
);

echo "\nGroup 2: descending order — an earlier splice must not shift a later offset\n";
// The first edit SHRINKS the string by 1 byte. Applied ascending without
// re-locating, every later position would be off by one and the second splice
// would cut mid-word. Assert the exact result rather than a substring.
$shrink = 'alpha — one and beta — two and gamma — three';
$eplan  = snt_sn_apply_plan_batch_edits(
	$shrink,
	'emdash_replace',
	array(
		edit_at( $shrink, 'alpha — one', 'alpha: one' ),
		edit_at( $shrink, 'beta — two', 'beta: two' ),
		edit_at( $shrink, 'gamma — three', 'gamma: three' ),
	)
);
ok( ! is_wp_error( $eplan ), 'three edits across one string plan cleanly' );
ok( ! is_wp_error( $eplan ) && 'alpha: one and beta: two and gamma: three' === $eplan['new_content'], 'all three offsets stayed correct under successive length changes' );

echo "\nGroup 3: fingerprints are checked against the ORIGINAL, never a half-edited string\n";
// Two edits 20 chars apart — INSIDE each other's 80-char fingerprint window.
// A naive sequential implementation would apply edit 1, then recompute edit 2's
// fingerprint over changed bytes and 409 against its own first write. This is
// the case that proves validate-all-first is load-bearing, not stylistic.
$near  = 'The first — item here and the second — item here, both close together.';
$e1    = edit_at( $near, 'first — item', 'first: item' );
$e2    = edit_at( $near, 'second — item', 'second: item' );
$after_first = substr_replace( $near, 'first: item', strpos( $near, 'first — item' ), strlen( 'first — item' ) );
$pos2_after  = snt_ai_drift_locate_in_raw( $after_first, 'second — item', '' );
ok(
	snt_ai_drift_fingerprint( $after_first, 'second — item', $pos2_after ) !== $e2['fingerprint'],
	'PREMISE: after edit 1, edit 2 fingerprint no longer matches (a sequential batch would 409)'
);
$nplan = snt_sn_apply_plan_batch_edits( $near, 'emdash_replace', array( $e1, $e2 ) );
ok( ! is_wp_error( $nplan ), 'the planner accepts both anyway — it validated against the original' );
ok( ! is_wp_error( $nplan ) && 'The first: item here and the second: item here, both close together.' === $nplan['new_content'], 'both near-neighbour edits landed correctly' );

echo "\nGroup 4: atomicity — a bad edit fails the WHOLE batch, never half of it\n";
$bad = $edits;
$bad[1]['fingerprint'] = str_repeat( 'f', 32 );
$bplan = snt_sn_apply_plan_batch_edits( $real, 'emdash_replace', $bad );
ok( is_wp_error( $bplan ), 'one stale fingerprint refuses the batch' );
ok( is_wp_error( $bplan ) && 409 === (int) ( $bplan->data['status'] ?? 0 ), 'a stale fingerprint is a 409 conflict' );
ok( is_wp_error( $bplan ) && false !== strpos( $bplan->get_error_message(), '2' ), 'the refusal names WHICH edit failed' );

$missing = array( edit_at( $real, 'chain — code', 'chain (code' ), edit_at( $real, 'not present anywhere', 'x' ) );
$mplan   = snt_sn_apply_plan_batch_edits( $real, 'emdash_replace', $missing );
ok( is_wp_error( $mplan ), 'an unlocatable phrase refuses the batch' );

echo "\nGroup 5: overlapping edits are refused, not silently corrupted\n";
$ov = snt_sn_apply_plan_batch_edits(
	$real,
	'emdash_replace',
	array(
		edit_at( $real, 'supply chain — code signing', 'supply chain (code signing' ),
		edit_at( $real, 'chain — code', 'chain: code' ),
	)
);
ok( is_wp_error( $ov ), 'two edits claiming the same bytes are refused' );
ok( is_wp_error( $ov ) && 422 === (int) ( $ov->data['status'] ?? 0 ), 'an overlap is a 422 caller error' );

echo "\nGroup 6: bounds\n";
ok( is_wp_error( snt_sn_apply_plan_batch_edits( $real, 'emdash_replace', array() ) ), 'an empty edits list is refused' );
$too_many = array_fill( 0, SNT_SN_APPLY_BATCH_EDITS_MAX + 1, edit_at( $real, 'chain — code', 'chain (code' ) );
ok( is_wp_error( snt_sn_apply_plan_batch_edits( $real, 'emdash_replace', $too_many ) ), 'exceeding the edit cap is refused' );
ok( is_wp_error( snt_sn_apply_plan_batch_edits( $real, 'og_card', $edits ) ), 'a non-body change type cannot batch edits' );

echo "\nGroup 7: single-edit batch is identical to the single form\n";
$one = snt_sn_apply_plan_batch_edits( $real, 'emdash_replace', array( edit_at( $real, 'chain — code', 'chain (code' ) ) );
$pos = snt_ai_drift_locate_in_raw( $real, 'chain — code', '' );
ok( ! is_wp_error( $one ) && $one['new_content'] === substr_replace( $real, 'chain (code', $pos, strlen( 'chain — code' ) ), 'a 1-edit batch equals the single splice exactly' );

echo "\nGroup 8: whitespace posture is inherited per type, not flattened\n";
// emdash_replace PRESERVES edge whitespace (': ' must not become ':'); drift_replace
// trims. Batching must not quietly pick one for both — that is the v10.65.2 bug class.
$ws  = 'you reach for — the studio and it works';
$wsp = snt_sn_apply_plan_batch_edits( $ws, 'emdash_replace', array( edit_at( $ws, 'for — the', 'for: the' ) ) );
ok( ! is_wp_error( $wsp ) && false !== strpos( $wsp['new_content'], 'reach for: the studio' ), 'emdash_replace keeps the space after the colon' );
ok( ! is_wp_error( $wsp ) && false === strpos( $wsp['new_content'], 'for:the' ), 'the v10.65.2 glued-replacement bug does not reappear through the batch path' );

$dr  = 'Written recently, will drift.';
$drp = snt_sn_apply_plan_batch_edits( $dr, 'drift_replace', array( edit_at( $dr, 'recently', '  in June 2026  ' ) ) );
ok( ! is_wp_error( $drp ) && false !== strpos( $drp['new_content'], 'Written in June 2026, will drift' ), 'drift_replace still trims its replacement (unchanged posture)' );

echo "\nGroup 9: sentence_replace batches too, with no per-edit fingerprint\n";
// Its binding is the whole-post content_hash carried on change.fingerprint and
// checked by gate 1, so an edit here carries phrase/replacement only.
$sr = 'The first sentence runs long enough to clear the minimum span. '
	. 'The second sentence also runs long enough to clear it.';
$srp = snt_sn_apply_plan_batch_edits(
	$sr,
	'sentence_replace',
	array(
		array( 'phrase' => 'The first sentence runs long enough', 'replacement' => 'Sentence one runs long enough' ),
		array( 'phrase' => 'The second sentence also runs long enough', 'replacement' => 'Sentence two also runs long enough' ),
	)
);
ok( ! is_wp_error( $srp ), 'sentence_replace accepts a batch without per-edit fingerprints' );
ok( ! is_wp_error( $srp ) && false !== strpos( $srp['new_content'], 'Sentence one runs' ) && false !== strpos( $srp['new_content'], 'Sentence two also runs' ), 'both sentence edits landed' );
$short = snt_sn_apply_plan_batch_edits( $sr, 'sentence_replace', array( array( 'phrase' => 'short', 'replacement' => 'x' ) ) );
ok( is_wp_error( $short ), 'sentence_replace still enforces its >= 20-char phrase floor inside a batch' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
