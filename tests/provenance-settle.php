<?php
/**
 * Tests: provenance settle window — one editing pass, one signed version.
 *
 * Multiple versions are correct. A Note revised next week SHOULD be v4. What
 * this guards against is versions BLEEDING: 2026-08-15, saves at 18:55, 19:00
 * and 19:05 minted v1/v2/v3, all permanent, public and Bitcoin-anchored, with
 * v1 carrying prose that was removed minutes later and never published.
 *
 * Run: php tests/provenance-settle.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__filter'] = null;
function apply_filters( $hook, $value ) {
	return null === $GLOBALS['__filter'] ? $value : $GLOBALS['__filter'];
}

require __DIR__ . '/../inc/provenance-settle.php';

echo "Group: settle window\n";
ok( 300 === sn_prov_settle_seconds(), 'default window is 300s — long enough to cover an editing pass' );
$GLOBALS['__filter'] = 60;
ok( 60 === sn_prov_settle_seconds(), 'the filter can shorten the window' );
$GLOBALS['__filter'] = -5;
ok( 0 === sn_prov_settle_seconds(), 'a negative filter clamps to 0, never schedules in the past' );
$GLOBALS['__filter'] = 86400;
ok( SN_PROV_SETTLE_MAX === sn_prov_settle_seconds(), 'a huge filter clamps to the ceiling' );
// The ceiling exists so the HOURLY reconcile sweep can never dispatch a commit
// the debounce still believes is private. If this ever passes an hour, the two
// mechanisms race and the supersede gate's premise breaks.
ok( SN_PROV_SETTLE_MAX < 3600, 'the ceiling stays inside the hourly sweep interval' );
$GLOBALS['__filter'] = null;

echo "\nGroup: supersedable — the commit is provably still private\n";
$private = array( 'status' => 'unanchored', 'version' => 1 );
ok( true === sn_prov_commit_is_supersedable( $private, true ), 'unanchored, unsigned, never dispatched, window open' );

echo "\nGroup: NOT supersedable — every way a commit can have escaped\n";
ok( false === sn_prov_commit_is_supersedable( $private, false ), 'window closed: the dispatch already fired' );
ok( false === sn_prov_commit_is_supersedable( array( 'status' => 'pending' ), true ), 'status pending: the Worker answered' );
ok( false === sn_prov_commit_is_supersedable( array( 'status' => 'confirmed' ), true ), 'status confirmed: anchored in Bitcoin' );
// The ground truth. A signature means the Worker SIGNED it, so it is in the
// public ledger — true even if the status update was lost on the way back.
ok( false === sn_prov_commit_is_supersedable( array( 'status' => 'unanchored', 'signature' => 'abc' ), true ), 'signed: it is already in the public ledger, whatever the status says' );
// The lost-response case. Without this, a dropped reply would let the next save
// rewrite a version the ledger had already published under the same number.
ok( false === sn_prov_commit_is_supersedable( array( 'status' => 'unanchored', 'dispatch_attempted' => 1786820812 ), true ), 'dispatch attempted: the POST may have reached the Worker even with no reply' );

echo "\nGroup: malformed input appends, never rewrites\n";
ok( false === sn_prov_commit_is_supersedable( null, true ), 'null commit is not supersedable' );
ok( false === sn_prov_commit_is_supersedable( 'nonsense', true ), 'a string commit is not supersedable' );
ok( false === sn_prov_commit_is_supersedable( array(), true ), 'an empty commit has no status, so it is not supersedable' );
ok( false === sn_prov_commit_is_supersedable( $private, 1 ), 'a truthy non-bool pending flag does not pass — strict true only' );
ok( false === sn_prov_commit_is_supersedable( $private, 'yes' ), 'a truthy string pending flag does not pass' );

echo "\nGroup: wiring\n";
$core = file_get_contents( __DIR__ . '/../inc/provenance-core.php' );
ok( false !== strpos( $core, 'sn_prov_commit_is_supersedable' ), 'the recorder consults the supersede gate' );
ok( false !== strpos( $core, 'sn_prov_replace_head_commit' ), 'the recorder can replace the head in place' );
ok( false !== strpos( $core, "\$version      = \$supersede ? \$last_version : \$last_version + 1;" ), 'superseding reuses the head version instead of incrementing' );
ok( false !== strpos( $core, "\$parent = \$last['parent'] ?? null;" ), 'superseding inherits the head parent, so no chain link is rewritten' );

$hook = file_get_contents( __DIR__ . '/../inc/provenance-webhook.php' );
ok( false !== strpos( $hook, 'wp_unschedule_event' ), 'a further save pushes the dispatch out (debounce, not dedupe)' );
// Pin the PROPERTY, not the exact expression: the window must be consulted and
// the schedule must be offset by it. An earlier version of this test pinned the
// literal `time() + sn_prov_settle_seconds()` and broke the moment the call was
// hoisted into a guarded variable — while the behaviour was identical.
ok( false !== strpos( $hook, 'sn_prov_settle_seconds()' ), 'the enqueue path consults the settle window' );
ok( false !== strpos( $hook, 'wp_schedule_single_event( time() + $settle' ), 'dispatch is scheduled after the window, not immediately' );
ok( false === strpos( $hook, 'wp_schedule_single_event( time(), SN_PROV_DISPATCH_ASYNC_HOOK' ), 'the old immediate scheduling is gone' );
// Ordering is the whole safety argument for the lost-response case.
$mark = strpos( $hook, "'dispatch_attempted' => time()" );
$post = strpos( $hook, 'wp_remote_post( $url' );
ok( false !== $mark && false !== $post && $mark < $post, 'the dispatch_attempted marker is written BEFORE the POST' );

$loader = file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
$pos_settle = strpos( $loader, 'inc/provenance-settle.php' );
$pos_core   = strpos( $loader, 'inc/provenance-core.php' );
ok( false !== $pos_settle && false !== $pos_core && $pos_settle < $pos_core, 'settle loads before the core that calls it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
