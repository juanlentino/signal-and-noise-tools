<?php
/**
 * sn-apply's description is its contract on the write door, and change.payload is
 * an untyped object, so the description is the ONLY place the payload rules live.
 * 2026-10-26: rewritten so each shared rule is stated once (19,998 -> ~16,000
 * chars). This pins that every safety rule is still there, that the shared ones
 * are stated exactly once, that the mode lists match the code, and which types
 * it leaves unadvertised.
 *
 * Run: php tests/sn-apply-description.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// The description literal, read from the source without loading WordPress.
$src  = (string) file_get_contents( __DIR__ . '/../inc/abilities-sn-apply.php' );
$desc = '';
$next = false;
foreach ( token_get_all( $src ) as $tok ) {
	if ( ! is_array( $tok ) || T_CONSTANT_ENCAPSED_STRING !== $tok[0] ) {
		continue;
	}
	if ( "'description'" === $tok[1] ) {
		$next = true;
		continue;
	}
	if ( $next ) {
		$desc = stripcslashes( substr( $tok[1], 1, -1 ) );
		break;
	}
}
ok( strlen( $desc ) > 5000, 'the description was found' );

echo "Shared rules, stated exactly once\n";
foreach ( array(
	'dry_run defaults to TRUE',
	'missing is a 422 caller error, stale is the 409 merge conflict',
	'any mismatch, a missing one included, is the 409',
	'ALL-OR-NOTHING',
	'Maximum 50',
	'Send the SAME key when retrying',
	'post_status is captured before the write',
	'located against the ORIGINAL content',
) as $rule ) {
	ok( 1 === substr_count( $desc, $rule ), "stated once: $rule" );
}

echo "Every safety rule is still there\n";
foreach ( array(
	'The only tool that mutates post content',
	'four run in order (fingerprint, server-side validation, mode capability, idempotency)',
	'Routine (non-owner) credentials are granted "revision" only',
	'never a client-chosen parameter',
	'this tool never makes a draft live',
	'there is deliberately NO whole-body update path',
	'BYTE-EXACTLY',
	'plain prose only, no HTML',
	'never a hard delete',
	'deliberately NOT an MCP method',
	'a routine credential is always refused',
	'rollback snapshot of the pre-restore live state',
	'staged_meta_pending',
	'REFUSES to empty a post',
	'snt_sn_apply_schedule_overdue',
	'fails loudly (500)',
	'EXACTLY ONE locator per call',
	'never a silent precedence rule',
	'409s BEFORE any path is dereferenced',
	'naming BOTH 1-based indexes',
	'or a landed code edit is silently overwritten',
	'check invalid before trusting an empty conflicts list',
	'refused at the door, never rendered',
	'The write labels every planned version or none',
	'retargeting is not this tool',
	'cannot be classed reading_aid or copy',
	'existing vocabulary only',
	'one target failing never rolls back another',
	'from those, make one call per post',
	// Review on #2297: these survived a mutation of the text unnoticed.
	'post_date strictly for status "future" only',
	'a foreign revision refuses 409 naming both ids',
	'apply_staged_meta? (default true)',
	'snt_sn_apply_locator_conflict / snt_sn_apply_locator_required',
	'expected_counts? ({class: n} for the labels this call applies; a mismatch is a 422)',
	'overlapping byte ranges are a 422, except in drift_replace and emdash_replace payload.edits, where every refusal reads as the 409',
	'for a fingerprint the caller supplies, missing is a 422',
	'a dry_run:true call also reports it as gates.fingerprint.observed',
	'Candidate-driven types (drift_replace, emdash_replace, link_insert, block_migration, pattern_adoption)',
	'so dry_run:true is the review step',
	'block-migrations | pattern-adoption | corpus-integrity',
) as $rule ) {
	ok( false !== strpos( $desc, $rule ), "kept: $rule" );
}

echo "Mode lists match snt_sn_apply_mode_support()\n";
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
require_once __DIR__ . '/../inc/sn-apply/executors.php';
preg_match( '/PUBLISH-ONLY types,[^:]*:(.*?)\. REVISION-ONLY/s', $desc, $pm );
preg_match( '/REVISION-ONLY types,[^:]*:(.*?);/s', $desc, $rm );
$unadvertised = array( 'merge_tags', 'clear_template_overrides', 'schedule_cron_event' );
$code         = array( 'publish' => array(), 'revision' => array() );
foreach ( SNT_SN_APPLY_CHANGE_TYPES as $t ) {
	$modes = snt_sn_apply_mode_support( $t )['modes'];
	if ( 1 === count( $modes ) && ! in_array( $t, $unadvertised, true ) ) {
		$code[ $modes[0] ][] = $t;
	}
}
// The types each list names, against the code's types for that mode: equal
// both ways, so a type in the wrong list, an extra one or a missing one fails.
$named = static function ( $list ) {
	return array_values( array_intersect( SNT_SN_APPLY_CHANGE_TYPES, preg_split( '/[^a-z_]+/', (string) $list ) ) );
};
foreach ( array( 'publish' => $pm[1] ?? '', 'revision' => $rm[1] ?? '' ) as $mode => $list ) {
	$want = $code[ $mode ];
	$have = $named( $list );
	sort( $want );
	sort( $have );
	ok( array() !== $want && $want === $have, strtoupper( $mode ) . '-ONLY names exactly the code\'s ' . $mode . '-only types (' . implode( ', ', $want ) . ')' . ( $want === $have ? '' : '; the text names ' . implode( ', ', $have ) ) );
}

echo "Left unadvertised (an owner decision whether to open them)\n";
foreach ( $unadvertised as $t ) {
	ok( false === strpos( $desc, $t ), "not advertised: $t" );
}

echo "House style\n";
ok( false === strpos( $desc, "\u{2014}" ), 'no em dashes' );
ok( strlen( $desc ) < 17000, 'under 17,000 chars (was 19,998 before the rules were stated once)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
