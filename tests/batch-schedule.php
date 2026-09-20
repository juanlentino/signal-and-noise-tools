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
ok( array( 11, 12, 13 ) === $safe['apply'], 'a week-out target applies to the scheduled and draft posts' );
ok( array( 14 => 'would_unpublish' ) === $safe['refused'], '...and refuses the PUBLISHED one: core flips publish to future when the date is >= 60 s ahead (#1179)' );

echo "\nGroup: #1179 -- a published post moved into the future is an unpublish, not a date edit\n";
ok( 'would_unpublish' === ( snt_batch_schedule_plan( array( 14 => array( 'status' => 'publish' ) ), at( 60 ), $NOW )['refused'][14] ?? '' ), 'exactly 60 s ahead: core would flip it to future, so it is refused' );
ok( array( 14 ) === snt_batch_schedule_plan( array( 14 => array( 'status' => 'publish' ) ), at( 59 ), $NOW )['apply'], '59 s ahead stays published (core publishes within the minute), so it moves' );
ok( array( 14 ) === snt_batch_schedule_plan( array( 14 => array( 'status' => 'publish' ) ), at( -86400 ), $NOW )['apply'], 'a backdate keeps it published, so it moves' );

echo "\nGroup: #1179 -- the raw field value is validated BEFORE conversion\n";
// get_gmt_from_date() returns 1970-01-01 00:00:00 for an unparseable string,
// never '', so the old post-conversion guard could not fire: a text-rendered
// datetime-local value rescheduled the whole batch to 1970.
ok( '' === snt_batch_schedule_parse_date( '15/09/2026 10:30' ), 'a text-rendered dd/mm/yyyy value is refused' );
ok( '' === snt_batch_schedule_parse_date( 'not a date' ), 'prose is refused' );
ok( '' === snt_batch_schedule_parse_date( '' ), 'empty is refused' );
ok( '2026-09-15 10:30:00' === snt_batch_schedule_parse_date( '2026-09-15T10:30' ), 'the datetime-local shape converts to Y-m-d H:i:s site time' );
ok( '2026-09-15 10:30:45' === snt_batch_schedule_parse_date( '2026-09-15T10:30:45' ), '...with seconds when the browser sends them' );

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
// v13.94.0: the MCP path's boundary rule MOVED to inc/sn-apply/plan-changes.php
// when the scheduled-post guard was extracted so the new batch write could not
// drift from the single write. The intent of this group is unchanged — the MCP
// path must enforce the SAME boundary in the SAME unit, never re-derive it —
// but the previous form named a FILE for what is really a property of the
// LAYER, so a pure move turned it red. It now pins the rule where it lives AND
// that the block-edit path still reaches it, which the old form could not see:
// a block_edit_impl that quietly stopped calling the guard would have kept this
// green while publishing scheduled posts early.
$guard = (string) file_get_contents( __DIR__ . '/../inc/sn-apply/plan-changes.php' );
ok( false !== strpos( $guard, 'snt_sn_apply_schedule_overdue' ), 'the MCP path still refuses the same boundary (409)' );
ok( false !== strpos( $guard, 'MINUTE_IN_SECONDS' ), 'and still measures it in the same unit' );
$mcp = (string) file_get_contents( __DIR__ . '/../inc/sn-apply/block-edit.php' );
ok( false !== strpos( $mcp, 'snt_sn_apply_write_preserving_schedule(' ), 'THE CHAIN: the block-edit write path actually CALLS the shared guard' );
$batch = (string) file_get_contents( __DIR__ . '/../inc/batch-schedule.php' );
ok( false !== strpos( $batch, 'MINUTE_IN_SECONDS' ), 'the admin path uses the same unit rather than a hard-coded 60' );

echo "\nGroup: the SHELL is thin — it decides nothing the planner has not decided\n";
// The surface exists now (v13.56.0). What must stay true is the split: the
// planner is pure and testable, the shell collects input and reports results.
ok( false !== strpos( $batch, "bulk_actions-edit-post" ), 'the bulk action is registered on the posts list' );
ok( false !== strpos( $batch, "handle_bulk_actions-edit-post" ), 'and its handler is wired' );
ok( false !== strpos( $batch, 'snt_batch_schedule_plan( $posts, $gmt, $now_ts )' ), 'THE SPLIT: the shared write asks the PLANNER what may move, on an injected clock; it does not re-implement the boundary' );
ok( false !== strpos( $batch, 'snt_batch_schedule_apply( $post_ids, $gmt, time() )' ), '17.3.0: the classic handler reaches the planner only through the shared write (the REST twin does the same)' );
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
ok( false !== strpos( $handler_body, 'snt_batch_skipped' ), 'and the skipped count (17.3.0): a row the write would not touch is said, not dropped' );
preg_match( '/function snt_batch_schedule_apply\(.*?\n\}/s', $batch, $apply_m );
$apply_body = $apply_m[0] ?? '';
ok( '' !== $apply_body, 'vacuity: the apply body was actually extracted' );
ok( false !== strpos( $apply_body, "'edit_date'     => true" ), '#1179: the write passes edit_date, or core resets a draft\'s date to now (a draft\'s post_date_gmt is zero)' );
ok( false !== strpos( $handler_body, 'snt_batch_schedule_parse_date( $raw )' ), '#1179: the handler validates the raw field through the parser, not after conversion' );

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

echo "\nGroup: 17.3.0 -- ONE shared write, both surfaces (the WP stubs record every call)\n";
$GLOBALS['__posts']   = array();
$GLOBALS['__writes']  = array();
$GLOBALS['__slashed'] = 0;
$GLOBALS['__deny']    = array(); // ids current_user_can( 'edit_post' ) refuses
$GLOBALS['__caps']    = array( 'edit_others_posts' => true );
$GLOBALS['__fail_id'] = 0;      // wp_update_post answers WP_Error for this id
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function wp_slash( $v ) { $GLOBALS['__slashed']++; return $v; }
function wp_update_post( $args, $wp_error = false ) { $GLOBALS['__writes'][] = $args; return (int) $args['ID'] === $GLOBALS['__fail_id'] ? new WP_Error( 'x' ) : (int) $args['ID']; }
function get_date_from_gmt( $gmt ) { return 'site:' . $gmt; }
function get_gmt_from_date( $site ) { return $site; }
function current_user_can( $cap, $id = 0 ) { if ( 'edit_post' === $cap ) { return ! in_array( (int) $id, $GLOBALS['__deny'], true ); } return ! empty( $GLOBALS['__caps'][ $cap ] ); }
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; }
function __( $t ) { return $t; }
function sanitize_text_field( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function add_query_arg( $args, $value, $url = '' ) { if ( ! is_array( $args ) ) { $args = array( $args => $value ); } else { $url = $value; } return $url . '?' . http_build_query( $args ); }
function post( $status, $type = 'post' ) { return (object) array( 'post_status' => $status, 'post_type' => $type ); }

$GLOBALS['__posts'] = array(
	31 => post( 'future' ),
	32 => post( 'future' ),
	33 => post( 'publish' ),
);
$r = snt_batch_schedule_apply( array( 31, 32, 33 ), at( 120 ), $NOW );
ok( 2 === $r['moved'] && 0 === $r['refused'] && 1 === $r['unpublish'] && 0 === $r['skipped'], 'apply at +120 s: two scheduled posts move, the published one is refused as an unpublish (got ' . json_encode( $r ) . ')' );
$GLOBALS['__writes'] = array(); $GLOBALS['__slashed'] = 0;
$r = snt_batch_schedule_apply( array( 31, 32, 33 ), at( 30 ), $NOW );
ok( 1 === $r['moved'] && 2 === $r['refused'] && 0 === $r['unpublish'] && 0 === $r['skipped'], 'apply at +30 s: both scheduled posts are refused (early publish), the published one moves (got ' . json_encode( $r ) . ')' );
ok( 1 === count( $GLOBALS['__writes'] ) && 33 === (int) $GLOBALS['__writes'][0]['ID'], 'exactly one write, for the one post the planner allowed' );
ok( at( 30 ) === ( $GLOBALS['__writes'][0]['post_date_gmt'] ?? '' ) && 'site:' . at( 30 ) === ( $GLOBALS['__writes'][0]['post_date'] ?? '' ) && true === ( $GLOBALS['__writes'][0]['edit_date'] ?? null ), 'the write carries post_date_gmt, the site-time twin and edit_date' );
ok( 1 === $GLOBALS['__slashed'], 'and the args went through wp_slash (core unslashes what it is handed)' );

$GLOBALS['__posts'][34] = post( 'future', 'page' );
$GLOBALS['__writes'] = array();
$r = snt_batch_schedule_apply( array( 31, 34 ), at( 86400 ), $NOW );
ok( 1 === $r['moved'] && 1 === $r['skipped'] && array( 31 ) === array_map( static fn( $w ) => (int) $w['ID'], $GLOBALS['__writes'] ), 'a PAGE in the batch is counted as skipped and never written (the REST surface has no posts-only dropdown)' );
$GLOBALS['__deny'] = array( 31 );
$GLOBALS['__writes'] = array();
$r = snt_batch_schedule_apply( array( 31, 32 ), at( 86400 ), $NOW );
ok( 1 === $r['moved'] && 1 === $r['skipped'] && array( 32 ) === array_map( static fn( $w ) => (int) $w['ID'], $GLOBALS['__writes'] ), 'an id the operator cannot edit_post is skipped and never written, whatever the batch-level capability said' );
$GLOBALS['__deny'] = array();
$GLOBALS['__fail_id'] = 32;
$r = snt_batch_schedule_apply( array( 31, 32 ), at( 86400 ), $NOW );
ok( 1 === $r['moved'], 'a wp_update_post WP_Error does not count as moved' );
$GLOBALS['__fail_id'] = 0;
ok( 0 === snt_batch_schedule_apply( array( 999 ), at( 86400 ), $NOW )['moved'], 'an unknown id is neither written nor counted' );
ok( 1 === snt_batch_schedule_apply( array( 34, 34 ), at( 86400 ), $NOW )['skipped'], 'a duplicated id (the REST body can carry one) is skipped ONCE, as it is written once' );

echo "\nGroup: 17.3.0 -- the sentence is the classic notice's, word for word\n";
ok( '1 post rescheduled.' === snt_batch_schedule_message( 1, 0, 0 ), 'singular' );
ok( '2 posts rescheduled. 1 scheduled post was left untouched: the new date is within a minute of now, and WordPress would have published it immediately instead of rescheduling it.' === snt_batch_schedule_message( 2, 1, 0 ), 'moved then refused, in that order' );
ok( '0 posts rescheduled. 1 published post was left untouched: moving it to a future date would have taken it off the site (WordPress flips it back to scheduled). Unpublish it deliberately first.' === snt_batch_schedule_message( 0, 0, 1 ), 'the unpublish sentence' );
ok( '3 posts rescheduled. 2 scheduled posts were left untouched: the new date is within a minute of now, and WordPress would have published them immediately instead of rescheduling them. 2 published posts were left untouched: moving them to a future date would have taken them off the site (WordPress flips them back to scheduled). Unpublish them deliberately first.' === snt_batch_schedule_message( 3, 2, 2 ), 'all three, plural' );
ok( '0 posts rescheduled. 1 selected item was left untouched: it is not a post, or you may not edit it.' === snt_batch_schedule_message( 0, 0, 0, 1 ), 'the skipped sentence, singular: a batch of nothing but skipped rows says why' );
ok( '1 post rescheduled. 2 selected items were left untouched: they are not posts, or you may not edit them.' === snt_batch_schedule_message( 1, 0, 0, 2 ), 'the skipped sentence, plural, after the moved count' );
$notice_body = preg_match( '/function snt_batch_schedule_notice\(.*?\n\}/s', $batch, $nm ) ? $nm[0] : '';
ok( false !== strpos( $notice_body, 'snt_batch_schedule_message( $moved, $refused, $unpublish, $skipped )' ) && false !== strpos( $notice_body, 'esc_html( $msg )' ), 'the classic notice prints the shared sentence, all four counts, escaped for HTML' );
ok( false !== strpos( $notice_body, '( $refused + $unpublish + $skipped ) > 0 ? \'warning\' : \'success\'' ), 'a skipped row paints the notice as a warning, as a refused one does' );

echo "\nGroup: 17.3.0 -- the classic handler answers the three classic query args and the skipped count\n";
$_REQUEST['snt_batch_date'] = '2099-01-01T10:00';
$GLOBALS['__writes'] = array();
$url = snt_batch_schedule_handle( 'edit.php', 'snt_batch_reschedule', array( 31, 32, 33, 34 ) );
ok( 'edit.php?snt_batch_moved=2&snt_batch_refused=0&snt_batch_unpublish=1&snt_batch_skipped=1' === $url, 'moved 2 (the scheduled posts), unpublish 1 (the published one), skipped 1 (the page): the redirect the notice reads carries all four (got ' . $url . ')' );
ok( 2 === count( $GLOBALS['__writes'] ), 'and the same two writes' );
ok( 'edit.php' === snt_batch_schedule_handle( 'edit.php', 'trash', array( 31 ) ), 'another action passes through untouched' );
$_REQUEST['snt_batch_date'] = '15/09/2026 10:30';
ok( 'edit.php?snt_batch_baddate=1' === snt_batch_schedule_handle( 'edit.php', 'snt_batch_reschedule', array( 31 ) ), 'a text-rendered date is still refused before conversion' );
unset( $_REQUEST['snt_batch_date'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
