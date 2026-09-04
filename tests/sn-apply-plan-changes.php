<?php
/**
 * Tests: the heterogeneous `changes` planner (v13.94.0).
 *
 * The property under test is not "it splices" — it is that the planner
 * REFUSES every arrangement whose result would be undefined. A batch planner
 * that quietly picks an order is worse than no batch planner: the caller gets
 * one of several possible posts and cannot tell which.
 *
 * Every conflict rule below is negative-controlled in the mutation harness
 * (tools/mutate-plan-changes.sh): disabling a rule must turn a named
 * assertion red. A conflict rule that cannot be made to fail is not a rule.
 *
 * @since 13.94.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data( $key = '' ) { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }

/* ────────────────────────────────────────────────────────────────────────
 * Block-grammar stubs, lifted BYTE-IDENTICALLY from
 * tests/abilities-sn-apply-block-edit.php.
 *
 * Copied rather than re-written on purpose: these model core's delimiter
 * parsing closely enough that a malformed delimiter round-trips as freeform
 * (the blindness the freeform check exists for). A second, independently
 * written stub would model a DIFFERENT grammar, and the two suites would
 * then disagree about what the same markup means without either failing.
 * ──────────────────────────────────────────────────────────────────────── */
$GLOBALS['__registered_blocks'] = array( 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'signal-noise/sidenote', 'signal-noise/pull-quote' );
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$content = (string) $content;
		if ( '' === $content ) { return array(); }
		$re = '#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(\s+\{.*?\})?\s+?(/)?-->#s';
		if ( ! preg_match_all( $re, $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array( tf_be_freeform( $content ) );
		}
		$out = array(); $pos = 0; $i = 0; $n = count( $m );
		while ( $i < $n ) {
			$mt  = $m[ $i ];
			$off = (int) $mt[0][1];
			$len = strlen( $mt[0][0] );
			if ( $off > $pos ) { $out[] = tf_be_freeform( substr( $content, $pos, $off - $pos ) ); }
			$is_closer = '' !== ( $mt[1][0] ?? '' );
			$is_void   = '' !== ( $mt[4][0] ?? '' );
			$name      = (string) $mt[2][0];
			$full      = false === strpos( $name, '/' ) ? 'core/' . $name : $name;
			$attrs_raw = trim( (string) ( $mt[3][0] ?? '' ) );
			$attrs     = '' !== $attrs_raw ? (array) json_decode( $attrs_raw, true ) : array();
			if ( $is_closer ) { // stray closer: freeform, like core's recovery
				$out[] = tf_be_freeform( substr( $content, $off, $len ) );
				$pos = $off + $len; $i++;
				continue;
			}
			if ( $is_void ) {
				$out[] = array( 'blockName' => $full, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
				$pos = $off + $len; $i++;
				continue;
			}
			$depth = 1; $j = $i + 1; $close = null;
			while ( $j < $n ) {
				$jt = $m[ $j ];
				if ( '' !== ( $jt[1][0] ?? '' ) ) { $depth--; if ( 0 === $depth ) { $close = $jt; break; } }
				elseif ( '' === ( $jt[4][0] ?? '' ) ) { $depth++; }
				$j++;
			}
			if ( null === $close ) { // unbalanced opener: freeform
				$out[] = tf_be_freeform( substr( $content, $off, $len ) );
				$pos = $off + $len; $i++;
				continue;
			}
			$inner_start = $off + $len;
			$inner       = substr( $content, $inner_start, (int) $close[0][1] - $inner_start );
			$out[]       = array( 'blockName' => $full, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $inner, 'innerContent' => array( $inner ) );
			$pos = (int) $close[0][1] + strlen( $close[0][0] );
			$i   = $j + 1;
		}
		if ( $pos < strlen( $content ) ) { $out[] = tf_be_freeform( substr( $content, $pos ) ); }
		return $out;
	}
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		$outp = '';
		foreach ( (array) $blocks as $b ) {
			$b = (array) $b;
			if ( null === ( $b['blockName'] ?? null ) ) { $outp .= (string) ( $b['innerHTML'] ?? '' ); continue; }
			$name  = (string) $b['blockName'];
			$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
			$attrs = ! empty( $b['attrs'] ) ? ' ' . json_encode( $b['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			if ( empty( $b['innerContent'] ) && '' === (string) ( $b['innerHTML'] ?? '' ) ) {
				$outp .= '<!-- wp:' . $short . $attrs . ' /-->';
			} else {
				$outp .= '<!-- wp:' . $short . $attrs . ' -->' . (string) ( $b['innerHTML'] ?? '' ) . '<!-- /wp:' . $short . ' -->';
			}
		}
		return $outp;
	}
}
if ( ! function_exists( 'serialize_block' ) ) { function serialize_block( $b ) { return serialize_blocks( array( $b ) ); } }

$GLOBALS['__registered_blocks'] = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'signal-noise/sidenote', 'signal-noise/pull-quote' );
if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {
		private static $inst = null;
		public static function get_instance() { if ( null === self::$inst ) { self::$inst = new self(); } return self::$inst; }
		public function is_registered( $name ) { return in_array( (string) $name, $GLOBALS['__registered_blocks'], true ); }
	}
}

require __DIR__ . '/../inc/sn-apply/block-edit.php';
require __DIR__ . '/../inc/sn-apply/sentence-replace.php';
require __DIR__ . '/../inc/sn-apply/plan-changes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_apply heterogeneous changes planner (v13.94.0)\n\n";

/** A three-block post: the shape the 2026-09-03 amendment actually edited. */
function pc_content() {
	return "<!-- wp:paragraph -->\n<p>Detection accuracy sat at 71 percent in the trial.</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>The second paragraph carries no figures at all.</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>A closing paragraph that ends the note cleanly.</p>\n<!-- /wp:paragraph -->";
}
function pc_block( $text ) {
	return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
}
function pc_err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : '(not an error)'; }

/* ── 1. THE CASE THIS EXISTS FOR ─────────────────────────────────────────
   One sentence_replace + two block_inserts = ONE content string, one write,
   and therefore one ledger version instead of three. */
$changes = array(
	array( 'type' => 'sentence_replace', 'payload' => array(
		'phrase'      => 'Detection accuracy sat at 71 percent in the trial.',
		'replacement' => 'Detection accuracy sat at 68 percent in the trial.',
	) ),
	array( 'type' => 'block_insert', 'payload' => array(
		'anchor' => 'The second paragraph carries no figures at all.',
		'position' => 'after',
		'blocks' => pc_block( 'REFERENCES' ),
	) ),
	array( 'type' => 'block_insert', 'payload' => array(
		'position' => 'end',
		'blocks' => pc_block( 'CORRECTION' ),
	) ),
);
$r = snt_sn_apply_plan_changes( pc_content(), $changes );
ok( ! is_wp_error( $r ), 'a heterogeneous batch plans without error (' . pc_err( $r ) . ')' );
if ( ! is_wp_error( $r ) ) {
	ok( 3 === $r['count'], 'all three changes are counted (' . $r['count'] . ')' );
	ok( false !== strpos( $r['new_content'], '68 percent' ), 'the prose replacement landed' );
	ok( false === strpos( $r['new_content'], '71 percent' ), '...and the stale figure is gone' );
	ok( false !== strpos( $r['new_content'], 'REFERENCES' ), 'the mid-post block_insert landed' );
	ok( false !== strpos( $r['new_content'], 'CORRECTION' ), 'the end block_insert landed' );
	ok( strpos( $r['new_content'], 'REFERENCES' ) < strpos( $r['new_content'], 'CORRECTION' ), '...in the right order relative to each other' );
	ok( strpos( $r['new_content'], '68 percent' ) < strpos( $r['new_content'], 'REFERENCES' ), 'and the prose edit stayed ahead of both inserts' );
	// The claims are reported in the CALLER order, never the splice order.
	ok( 1 === $r['changes'][0]['index'] && 3 === $r['changes'][2]['index'], 'claims report in caller order, not descending splice order' );
}

/* ── 2. DETERMINISM ──────────────────────────────────────────────────── */
$a = snt_sn_apply_plan_changes( pc_content(), $changes );
$b = snt_sn_apply_plan_changes( pc_content(), $changes );
ok( ! is_wp_error( $a ) && ! is_wp_error( $b ) && $a['new_content'] === $b['new_content'], 'planning is deterministic across runs' );

/* ── 3. NEGATIVE CONTROL (a): two inserts at the SAME point ───────────────
   Undefined order. A range test cannot see this: both claims have width 0. */
$same = array(
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'position' => 'after', 'blocks' => pc_block( 'ONE' ) ) ),
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'position' => 'after', 'blocks' => pc_block( 'TWO' ) ) ),
);
$r = snt_sn_apply_plan_changes( pc_content(), $same );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_conflict' === $r->get_error_code(), 'NEGATIVE CONTROL (a): two inserts at one point REFUSE (' . pc_err( $r ) . ')' );
ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'undefined' ), '...and the refusal says the order would be undefined' );

/* ── 4. NEGATIVE CONTROL (b): insert anchored to a REPLACED block ─────────
   Byte ranges do not overlap — the insert has no width — but its anchor is
   being destroyed. */
$anchored = array(
	array( 'type' => 'block_replace', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'blocks' => pc_block( 'REPLACED' ) ) ),
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'position' => 'before', 'blocks' => pc_block( 'INSERTED' ) ) ),
);
$r = snt_sn_apply_plan_changes( pc_content(), $anchored );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_conflict' === $r->get_error_code(), 'NEGATIVE CONTROL (b): an insert anchored to a block being REPLACED refuses (' . pc_err( $r ) . ')' );

/* ── 4a2. Rule (b) is only LOAD-BEARING when the insert comes FIRST.
   With the replace first, rule (c) already catches the pair (the insert
   starts inside the replaced span). Reverse the caller order and prev is
   the zero-width insert, so `cur.start < prev.start + 0` is false and rule
   (c) goes silent — this ordering is the ONLY thing rule (b) answers.
   Found by mutation: disabling (b) left the replace-first case green. */
$insert_first = array(
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'position' => 'before', 'blocks' => pc_block( 'INSERTED' ) ) ),
	array( 'type' => 'block_replace', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'blocks' => pc_block( 'REPLACED' ) ) ),
);
$r = snt_sn_apply_plan_changes( pc_content(), $insert_first );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_conflict' === $r->get_error_code(), 'NEGATIVE CONTROL (b2): insert-BEFORE listed ahead of the replace still refuses (' . pc_err( $r ) . ')' );

/* ── 4b. NEGATIVE CONTROL (d): insert at the TRAILING edge of a replaced span
   The range test uses a strict <, so an insert at exactly
   prev.start + prev.length slips through it while still being anchored to
   content the other change is rewriting. Distinct from (b), which is the
   LEADING edge. Without its own case this rule would be unverifiable. */
$trailing = array(
	array( 'type' => 'block_replace', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'blocks' => pc_block( 'REPLACED' ) ) ),
	array( 'type' => 'block_insert', 'payload' => array( 'anchor' => 'The second paragraph carries no figures at all.', 'position' => 'after', 'blocks' => pc_block( 'INSERTED' ) ) ),
);
$r = snt_sn_apply_plan_changes( pc_content(), $trailing );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_conflict' === $r->get_error_code(), 'NEGATIVE CONTROL (d): an insert at the TRAILING edge of a replaced span refuses (' . pc_err( $r ) . ')' );

/* ── 5. Ordinary byte overlap, and NESTING for free ──────────────────────
   A prose span inside a block another change replaces starts within that
   block range, so the ordered sweep catches it with no extra rule. */
$nested = array(
	array( 'type' => 'block_replace', 'payload' => array( 'anchor' => 'Detection accuracy sat at 71 percent in the trial.', 'blocks' => pc_block( 'REPLACED' ) ) ),
	array( 'type' => 'sentence_replace', 'payload' => array( 'phrase' => 'Detection accuracy sat at 71 percent in the trial.', 'replacement' => 'Something else entirely here now.' ) ),
);
$r = snt_sn_apply_plan_changes( pc_content(), $nested );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_conflict' === $r->get_error_code(), 'a prose edit NESTED inside a replaced block refuses (' . pc_err( $r ) . ')' );

/* ── 6. ALL-OR-NOTHING ──────────────────────────────────────────────────
   A refusal returns a WP_Error and NOTHING else: there is no partial content
   for a caller to mistake for a result. */
ok( is_wp_error( $r ) && ! is_array( $r ), 'a refused batch yields no content at all (all-or-nothing)' );

/* ── 7. Type gate ────────────────────────────────────────────────────── */
foreach ( array( 'block_move', 'link_insert', 'unlink', 'og_card' ) as $bad ) {
	$r = snt_sn_apply_plan_changes( pc_content(), array( array( 'type' => $bad, 'payload' => array() ) ) );
	ok( is_wp_error( $r ) && 'snt_sn_apply_changes_unsupported_type' === $r->get_error_code(), "type \"$bad\" is refused by name from a changes batch" );
}
ok( in_array( 'sentence_replace', snt_sn_apply_change_types(), true ) && ! in_array( 'block_move', snt_sn_apply_change_types(), true ), 'the supported list is prose + the span-claiming block verbs, and excludes block_move' );

/* ── 8. Shape gates ─────────────────────────────────────────────────── */
$r = snt_sn_apply_plan_changes( pc_content(), array() );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_empty' === $r->get_error_code(), 'an empty changes list refuses' );
$many = array_fill( 0, SNT_SN_APPLY_CHANGES_MAX + 1, array( 'type' => 'block_insert', 'payload' => array( 'position' => 'end', 'blocks' => pc_block( 'X' ) ) ) );
$r = snt_sn_apply_plan_changes( pc_content(), $many );
ok( is_wp_error( $r ) && 'snt_sn_apply_changes_too_many' === $r->get_error_code(), 'more than the maximum refuses' );

/* ── 9. A refusal NAMES the change a human can find ─────────────────── */
$r = snt_sn_apply_plan_changes( pc_content(), array(
	array( 'type' => 'block_insert', 'payload' => array( 'position' => 'end', 'blocks' => pc_block( 'FINE' ) ) ),
	array( 'type' => 'sentence_replace', 'payload' => array( 'phrase' => 'this phrase is simply not in the post at all', 'replacement' => 'no' ) ),
) );
ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'change 2' ), 'a refusal names the 1-based change index (' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) . ')' );

/* ── 10. Two independent prose edits still batch (the v10.66.0 property) ── */
$r = snt_sn_apply_plan_changes( pc_content(), array(
	array( 'type' => 'sentence_replace', 'payload' => array( 'phrase' => 'Detection accuracy sat at 71 percent in the trial.', 'replacement' => 'Detection accuracy sat at 68 percent in the trial.' ) ),
	array( 'type' => 'sentence_replace', 'payload' => array( 'phrase' => 'A closing paragraph that ends the note cleanly.', 'replacement' => 'A closing paragraph that ends the note properly.' ) ),
) );
ok( ! is_wp_error( $r ) && false !== strpos( $r['new_content'], '68 percent' ) && false !== strpos( $r['new_content'], 'ends the note properly' ),
	'two non-overlapping prose edits still batch into one content string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
