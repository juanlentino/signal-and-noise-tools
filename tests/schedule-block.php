<?php
/**
 * Standalone fixture tests for inc/schedule-block.php (the sn/scheduled
 * dynamic-block RENDER CALLBACK).
 *
 * The render callback is the only cache-coherent place to gate hand-authored
 * content on a date window: the site is behind Cloudflare Cache-Everything, so
 * a per-request PHP decision has to run BELOW the fragment that is cached as
 * part of the page HTML. These tests pin the two load-bearing contracts:
 *
 *   - OPEN  -> the content is returned BYTE-IDENTICAL (strict ===). No wrapper,
 *     no attribute, no markup is added, so a gated-open card is indistinguishable
 *     from an un-gated one in view-source.
 *   - CLOSED -> the result is the EMPTY string (strict '' ===), and the
 *     distinctive content substring is PROVEN absent. The content must never
 *     enter the served HTML before the window opens (no display:none leak,
 *     scrapers and view-source see nothing).
 *
 * UTC-now is obtained through current_time( 'timestamp', true ), which this
 * fixture STUBS to a controlled instant so each boundary case is deterministic.
 *
 * Run: php tests/schedule-block.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Allow only CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ─── Stub: a controllable current_time( 'timestamp', true ) ───────────────
// The render callback reads UTC-now via current_time( 'timestamp', true ). We
// stub it to return whatever $GLOBALS['__test_now_utc'] holds so every boundary
// case is pinned to an exact instant. The gate ($from/$until strings) is parsed
// as UTC by sn_schedule_is_open, so the stubbed integer is a UTC Unix timestamp.
$GLOBALS['__test_now_utc'] = 0;
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		// Only the ( 'timestamp', true ) shape is exercised by this module; honor
		// it and return the controlled UTC instant. Any other shape would be a
		// drift from the documented contract and is intentionally unsupported.
		if ( 'timestamp' === $type && $gmt ) {
			return (int) $GLOBALS['__test_now_utc'];
		}
		return (int) $GLOBALS['__test_now_utc'];
	}
}

// schedule-engine.php registers an init action via add_action and runs a
// version-gated install on require; stub the two it touches at include time.
if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $GLOBALS['__test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }

$GLOBALS['__test_options'] = array();
// Pretend the install already ran so maybe_install short-circuits on require.
$GLOBALS['__test_options']['sn_schedules_db_version'] = '1';

// The gate (sn_schedule_is_open) lives in schedule-engine.php; the render
// callback under test lives in schedule-block.php. Both are required.
require_once __DIR__ . '/../inc/schedule-engine.php';
require_once __DIR__ . '/../inc/schedule-block.php';

// ─── Harness ──────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

/**
 * Set the stubbed UTC-now to a fixed instant. gmmktime builds a UTC Unix
 * timestamp regardless of the server's default timezone — the same basis the
 * gate parses its string boundaries against.
 */
function set_now_utc( $ts ) {
	$GLOBALS['__test_now_utc'] = (int) $ts;
}

echo "schedule-block: sn/scheduled render callback (date-window gate)\n\n";

// A distinctive payload so the no-leak assertion can prove the content is truly
// absent (not merely that the result is empty by coincidence).
$content   = '<p>SECRET-LAUNCH-COPY-7f3a</p>';
$needle    = 'SECRET-LAUNCH-COPY-7f3a';
$from_str  = '2026-07-01 00:00:00';
$until_str = '2026-08-01 00:00:00';
$from_ts   = gmmktime( 0, 0, 0, 7, 1, 2026 ); // 2026-07-01 00:00:00 UTC
$until_ts  = gmmktime( 0, 0, 0, 8, 1, 2026 ); // 2026-08-01 00:00:00 UTC

// ─── Group: open window returns content UNCHANGED (byte-identical) ─────────
echo "Group: open window -> byte-identical content\n";
set_now_utc( $from_ts + 86400 ); // mid-window
$open = sn_scheduled_block_render( array( 'from' => $from_str, 'until' => $until_str ), $content );
ok( $open === $content, 'open: returns $content EXACTLY (strict ===, proves no wrapper/attr added)' );

// Inclusive start boundary: now == from is OPEN.
set_now_utc( $from_ts );
ok( sn_scheduled_block_render( array( 'from' => $from_str, 'until' => $until_str ), $content ) === $content, 'open: now == from is open (inclusive start), content byte-identical' );

// ─── Group: before `from` -> empty, no leak ────────────────────────────────
echo "\nGroup: before from -> empty string (no leak)\n";
set_now_utc( $from_ts - 1 );
$before = sn_scheduled_block_render( array( 'from' => $from_str, 'until' => $until_str ), $content );
ok( '' === $before, 'before from: result is the EMPTY string (strict)' );
ok( strpos( $before, $needle ) === false, 'before from: distinctive content substring is ABSENT (no view-source leak)' );

// ─── Group: after `until` -> empty ─────────────────────────────────────────
echo "\nGroup: after until -> empty string\n";
set_now_utc( $until_ts ); // now == until is closed (exclusive end)
$at_end = sn_scheduled_block_render( array( 'from' => $from_str, 'until' => $until_str ), $content );
ok( '' === $at_end, 'at until: result is the EMPTY string (exclusive end closes at the boundary)' );
ok( strpos( $at_end, $needle ) === false, 'at until: content substring absent (no leak)' );

set_now_utc( $until_ts + 86400 ); // well past the end
$after = sn_scheduled_block_render( array( 'from' => $from_str, 'until' => $until_str ), $content );
ok( '' === $after, 'after until: result is the EMPTY string' );
ok( strpos( $after, $needle ) === false, 'after until: content substring absent (no leak)' );

// ─── Group: unbounded from (empty) + future until ──────────────────────────
echo "\nGroup: unbounded from + future until\n";
// Empty from = open from the start of time; only until bounds the window.
set_now_utc( $from_ts - 100000 ); // long before any explicit start
ok( sn_scheduled_block_render( array( 'from' => '', 'until' => $until_str ), $content ) === $content, 'unbounded from: open before until, content byte-identical' );
set_now_utc( $until_ts ); // at until -> closed (exclusive)
$ub_from_closed = sn_scheduled_block_render( array( 'from' => '', 'until' => $until_str ), $content );
ok( '' === $ub_from_closed, 'unbounded from: closed at until (exclusive end)' );
ok( strpos( $ub_from_closed, $needle ) === false, 'unbounded from: content absent once closed (no leak)' );
set_now_utc( $until_ts + 1 );
ok( '' === sn_scheduled_block_render( array( 'from' => '', 'until' => $until_str ), $content ), 'unbounded from: closed after until' );

// ─── Group: unbounded both -> always open ──────────────────────────────────
echo "\nGroup: unbounded both -> always open\n";
set_now_utc( 0 ); // epoch
ok( sn_scheduled_block_render( array( 'from' => '', 'until' => '' ), $content ) === $content, 'unbounded both: open at epoch (content byte-identical)' );
set_now_utc( 4102444800 ); // far future
ok( sn_scheduled_block_render( array( 'from' => '', 'until' => '' ), $content ) === $content, 'unbounded both: open in the far future' );
// Missing attribute keys behave the same as empty (the `?? ''` default path).
set_now_utc( 4102444800 );
ok( sn_scheduled_block_render( array(), $content ) === $content, 'unbounded both: absent attribute keys default to unbounded (always open)' );

// ─── Group: whitespace-only from is treated as UNBOUNDED (trim hardening) ──
echo "\nGroup: whitespace-only from -> unbounded (trim hardening)\n";
// A stray whitespace-only `from` must NOT alias to "now" inside strtotime (a
// theoretical early-leak: strtotime(' UTC') can resolve to the current time).
// Trimmed to '', it is the gate's unbounded case, so the window is open from the
// start of time and only `until` bounds it.
set_now_utc( $from_ts - 100000 ); // long before any explicit start
ok( sn_scheduled_block_render( array( 'from' => ' ', 'until' => $until_str ), $content ) === $content, 'whitespace from: treated as unbounded (open well before any start), NOT as "now"' );
// Whitespace-only until likewise trims to unbounded end.
set_now_utc( 4102444800 );
ok( sn_scheduled_block_render( array( 'from' => '', 'until' => "  \t " ), $content ) === $content, 'whitespace until: treated as unbounded end (still open in the far future)' );
// And a whitespace from with a real until still closes correctly past until.
set_now_utc( $until_ts + 1 );
$ws_after = sn_scheduled_block_render( array( 'from' => ' ', 'until' => $until_str ), $content );
ok( '' === $ws_after, 'whitespace from: real until still gates (closed after until)' );
ok( strpos( $ws_after, $needle ) === false, 'whitespace from: content absent once closed (no leak)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
