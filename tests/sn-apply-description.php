<?php
/**
 * sn-apply's description is its contract on the write door, and change.payload is
 * an untyped object, so the description is the ONLY place the payload rules live.
 * 2026-10-26: rewritten so each shared rule is stated once (19,998 -> ~16,000
 * chars). This pins that every safety rule is still there, that the shared ones
 * are stated exactly once, and that it advertises only types the door can reach.
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
) as $rule ) {
	ok( false !== strpos( $desc, $rule ), "kept: $rule" );
}

echo "Mode lists\n";
preg_match( '/PUBLISH-ONLY types,[^:]*:(.*?)\. REVISION-ONLY/s', $desc, $pm );
foreach ( array( 'og_card', 'anchor_sweep', 'restore_revision', 'roadmap_board', 'change_class_backfill', 'dismiss' ) as $t ) {
	ok( isset( $pm[1] ) && false !== strpos( $pm[1], $t ), "PUBLISH-ONLY lists $t" );
}
preg_match( '/REVISION-ONLY types,[^:]*:(.*?);/s', $desc, $rm );
ok( isset( $rm[1] ) && false !== strpos( $rm[1], 'create_draft' ) && false !== strpos( $rm[1], 'delete_draft' ), 'REVISION-ONLY lists create_draft and delete_draft' );

echo "Only reachable types are advertised\n";
// target.scope's enum (inc/abilities-sn-apply.php) admits no "tags",
// "template_overrides" or "cron", so the write door's input validation refuses
// merge_tags, clear_template_overrides and schedule_cron_event before execute().
// Advertise them only once the enum admits them (an owner decision).
foreach ( array( 'merge_tags', 'clear_template_overrides', 'schedule_cron_event' ) as $t ) {
	ok( false === strpos( $desc, $t ), "not advertised while unreachable: $t" );
}

echo "House style\n";
ok( false === strpos( $desc, "\u{2014}" ), 'no em dashes' );
ok( strlen( $desc ) < 17000, 'under 17,000 chars (was 19,998 before the rules were stated once)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
