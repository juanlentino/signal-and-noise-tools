<?php
/**
 * Wave 5 — batch schedule edit, the guard that makes it safe.
 *
 * D2 put this on the wp-admin path so `sn-apply`'s flat "post_date never moves"
 * invariant stays whole. That decision only pays off if the admin path itself
 * refuses the boundary core coerces at, so this suite pins BOTH sides of it.
 *
 * @since plugin v13.56.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/batch-schedule.php';

// A fixed clock, so this suite is never a race against the real one.
$NOW = 1767225600; // 2026-01-01 00:00:00 GMT
function at( $offset_seconds ) { global $NOW; return gmdate( 'Y-m-d H:i:s', $NOW + $offset_seconds ); }

echo "Group: THE BOUNDARY core coerces at — under a minute is not a schedule\n";
ok( true === snt_batch_date_would_early_publish( 'future', at( 0 ), $NOW ), 'a future post moved to NOW would early-publish' );
ok( true === snt_batch_date_would_early_publish( 'future', at( 59 ), $NOW ), '59 seconds out still trips the coercion' );
ok( true === snt_batch_date_would_early_publish( 'future', at( -3600 ), $NOW ), 'a date in the PAST trips it (this is the overdue case)' );
ok( false === snt_batch_date_would_early_publish( 'future', at( 60 ), $NOW ), 'exactly 60 seconds out is SAFE — the comparison is strictly-less-than, mirroring core' );
ok( false === snt_batch_date_would_early_publish( 'future', at( 86400 ), $NOW ), 'a day out is safe' );

echo "\nGroup: only 'future' carries a transition for core to resolve\n";
ok( false === snt_batch_date_would_early_publish( 'draft', at( 0 ), $NOW ), 'a DRAFT moved to now is not at risk — no scheduled transition exists' );
ok( false === snt_batch_date_would_early_publish( 'publish', at( -86400 ), $NOW ), 'a PUBLISHED post backdated is not at risk' );
ok( false === snt_batch_date_would_early_publish( 'pending', at( 0 ), $NOW ), 'nor a pending one' );

echo "\nGroup: an unreadable date is REFUSED, never assumed safe\n";
ok( true === snt_batch_date_would_early_publish( 'future', 'not a date', $NOW ), 'an unparseable date refuses — guessing at it is how a post publishes early' );
ok( true === snt_batch_date_would_early_publish( 'future', '', $NOW ), 'an empty date refuses' );

echo "\nGroup: the plan reports BOTH halves\n";
// A batch that silently skips its unsafe rows reports a smaller, cleaner-
// looking success. The caller must be able to say "12 moved, 3 refused, why".
$posts = array(
	11 => array( 'status' => 'future',  'date_gmt' => at( 172800 ) ),
	12 => array( 'status' => 'future',  'date_gmt' => at( 172800 ) ),
	13 => array( 'status' => 'draft',   'date_gmt' => at( 0 ) ),
	14 => array( 'status' => 'publish', 'date_gmt' => at( -99999 ) ),
);
$safe = snt_batch_schedule_plan( $posts, at( 604800 ), $NOW );
ok( 4 === count( $safe['apply'] ), 'a week-out target applies to every post in the batch' );
ok( array() === $safe['refused'], 'and refuses none' );

$unsafe = snt_batch_schedule_plan( $posts, at( 30 ), $NOW );
ok( array( 13, 14 ) === $unsafe['apply'], 'a 30-second target applies ONLY to the non-future posts' );
ok( 2 === count( $unsafe['refused'] ), 'and refuses both scheduled posts' );
ok( 'would_early_publish' === ( $unsafe['refused'][11] ?? '' ), 'naming the reason per post, not a bare count' );
ok( array_key_exists( 12, $unsafe['refused'] ), 'for every affected post' );

echo "\nGroup: THE WHOLE POINT — a batch spanning the boundary does not publish\n";
// The row's warning: "a batch spanning that boundary publishes early".
$spanning = array(
	21 => array( 'status' => 'future', 'date_gmt' => at( 999999 ) ),
	22 => array( 'status' => 'draft',  'date_gmt' => at( 0 ) ),
);
$r = snt_batch_schedule_plan( $spanning, at( 10 ), $NOW );
ok( ! in_array( 21, $r['apply'], true ), 'the scheduled post in a mixed batch is held back' );
ok( in_array( 22, $r['apply'], true ), 'while the draft in the SAME batch still moves — one unsafe row does not cancel the batch' );

echo "\nGroup: the rule is SHARED with the MCP path, not re-derived\n";
// v13.94.0: the MCP path's boundary rule MOVED to inc/sn-apply-plan-changes.php
// when the scheduled-post guard was extracted so the new batch write could not
// drift from the single write. The intent of this group is unchanged — the MCP
// path must enforce the SAME boundary in the SAME unit, never re-derive it —
// but the previous form named a FILE for what is really a property of the
// LAYER, so a pure move turned it red. It now pins the rule where it lives AND
// that the block-edit path still reaches it, which the old form could not see:
// a block_edit_impl that quietly stopped calling the guard would have kept this
// green while publishing scheduled posts early.
$guard = (string) file_get_contents( __DIR__ . '/../inc/sn-apply-plan-changes.php' );
ok( false !== strpos( $guard, 'snt_sn_apply_schedule_overdue' ), 'the MCP path still refuses the same boundary (409)' );
ok( false !== strpos( $guard, 'MINUTE_IN_SECONDS' ), 'and still measures it in the same unit' );
$mcp = (string) file_get_contents( __DIR__ . '/../inc/sn-apply-block-edit.php' );
ok( false !== strpos( $mcp, 'snt_sn_apply_write_preserving_schedule(' ), 'THE CHAIN: the block-edit write path actually CALLS the shared guard' );
$batch = (string) file_get_contents( __DIR__ . '/../inc/batch-schedule.php' );
ok( false !== strpos( $batch, 'MINUTE_IN_SECONDS' ), 'the admin path uses the same unit rather than a hard-coded 60' );

echo "\nGroup: the SHELL is thin — it decides nothing the planner has not decided\n";
// The surface exists now (v13.56.0). What must stay true is the split: the
// planner is pure and testable, the shell collects input and reports results.
ok( false !== strpos( $batch, "bulk_actions-edit-post" ), 'the bulk action is registered on the posts list' );
ok( false !== strpos( $batch, "handle_bulk_actions-edit-post" ), 'and its handler is wired' );
ok( false !== strpos( $batch, 'snt_batch_schedule_plan( $posts, $gmt, time() )' ), 'THE SPLIT: the handler asks the PLANNER what may move — it does not re-implement the boundary' );
ok( false !== strpos( $batch, "current_user_can( 'edit_others_posts' )" ), 'capability is re-checked in the handler, not only in the dropdown filter that offers the action' );
// Three gates, and each is load-bearing on its own: the dropdown must not
// OFFER it, the field must not RENDER, and the handler must not TRUST either —
// the filter only controls what is shown, never what is submitted.
ok( 3 === substr_count( $batch, "current_user_can( 'edit_others_posts' )" ), 'all three surfaces gate on the capability: the offer, the field, and the handler' );
// Scope this to the HANDLER's body. A whole-file check passes even when the
// redirect stops sending the count, because the notice function also names it —
// the fifth substring false positive in this codebase's tests, caught by a
// mutation rather than by reading.
preg_match( '/function snt_batch_schedule_handle\(.*?\n\}/s', $batch, $handler_m );
$handler_body = $handler_m[0] ?? '';
ok( '' !== $handler_body, 'vacuity: the handler body was actually extracted' );
ok( false !== strpos( $handler_body, 'snt_batch_refused' ), 'the HANDLER puts the refusal count in the redirect, so a partial success can never report as a whole one' );
ok( false !== strpos( $handler_body, 'snt_batch_moved' ), 'and the moved count beside it' );

echo "\nGroup: registrations stay loadable standalone\n";
ok( false !== strpos( $batch, "function_exists( 'add_filter' )" ), 'hook registrations are function_exists-guarded, so the pure planner still loads in a harness' );
// Assert on CODE, not prose. This file's docblock legitimately names
// wp_insert_post() while explaining core's coercion, so a raw substring scan
// matches the explanation and reports a write that does not exist. (It did, on
// the first run — the fourth such false positive in this codebase's tests.)
$batch_code = preg_replace( '#/\*.*?\*/#s', '', $batch );
$batch_code = preg_replace( '#//[^\n]*#', '', (string) $batch_code );
ok( false === strpos( (string) $batch_code, 'wp_insert_post(' ), 'nothing here calls wp_insert_post — the write path is wp_update_post on an existing post only' );
ok( 1 === substr_count( (string) $batch_code, 'wp_update_post(' ), 'exactly ONE write site, inside the loop over what the planner allowed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
