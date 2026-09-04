<?php
/**
 * Standalone fixture tests for inc/schedule-admin.php. the read-mostly
 * scheduled-content admin status list + its two ops handlers.
 *
 * Task 8 of the scheduled-content subsystem. The render fn folds two data
 * sources into ONE native .wp-list-table: the fragment/queue rows from
 * sn_schedule_all() and the native scheduled posts from sn_schedule_future_posts().
 * The two ops handlers (Run now / Re-purge) are dispatched by the shared
 * sn_handle_admin_post() pipeline, which enforces cap + nonce BEFORE the handler
 * body runs, so the handlers themselves only do the row work.
 *
 * Four test groups:
 *   1. Render folds BOTH sources, with the right Type labels.
 *   2. Output is ESCAPED. a script-tag post title never lands raw in the HTML.
 *   3. The two handlers are wired into the sn_admin_post_handlers() map and map
 *      to functions that exist once schedule-admin.php is loaded.
 *   4. Handler behaviour: Run now fires the row (input-aware stub records the id),
 *      a missing row_id is a safe no-op; Re-purge forwards the row's exact
 *      purge_urls to the stubbed purge seam.
 *
 * Run: php tests/schedule-admin.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: CLI / WP-CLI only. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "PASS: $m\n";
	} else {
		$fail++;
		echo "FAIL: $m\n";
	}
}

echo "Scheduled-content admin suite (Task 8)\n\n";

// ─── WP function stubs the renderer + handlers need ───────────────────────────
// Escaping helpers: esc_html / esc_attr REALLY escape (so the XSS-escape test is
// meaningful. a renderer that forgot esc_html would let <script> through and
// fail group 2). esc_url is a light passthrough (no entities in our test URLs).
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return (string) $s; }
}
// wp_kses_post: light passthrough. the only inline markup the renderer feeds it
// is its OWN trusted window-cell HTML (already escaped per dynamic value), so the
// test does not need a full kses; passthrough keeps the escaped pieces intact.
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $s ) { return (string) $s; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return (string) $s; }
}
// Models WP's real signature: the PLURAL is returned for every count except
// exactly 1, including 0. A stub that always returned $single would hide a
// singular/plural mix-up in the fold summary and the remainder line.
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $d = null ) { return 1 === (int) $number ? (string) $single : (string) $plural; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) {
		$out = '<input type="hidden" name="' . $n . '" value="nonce">';
		if ( $e ) { echo $out; }
		return $out;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return true; }
}
// get_edit_post_link: a deterministic editor URL keyed by post id.
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) { return 'Post ' . (int) $id; }
}
// Site-tz display helpers. The stored values are UTC; get_date_from_gmt converts
// to the site timezone, wp_date formats. For the test the site tz == UTC so the
// value passes through, which is all the render needs to prove "displayed, escaped".
if ( ! function_exists( 'get_date_from_gmt' ) ) {
	function get_date_from_gmt( $gmt, $format = 'Y-m-d H:i:s' ) {
		$ts = strtotime( (string) $gmt . ' UTC' );
		return false === $ts ? (string) $gmt : gmdate( $format, $ts );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null ) {
		$ts = null === $ts ? time() : (int) $ts;
		return gmdate( $format, $ts );
	}
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) {
		$to = $to ? (int) $to : time();
		$d  = abs( (int) $to - (int) $from );
		return $d . ' seconds';
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) { return time(); }
}

// ─── Data-source stubs (overridable per test via $GLOBALS) ────────────────────
// The renderer reads from these two seams; the tests preload $GLOBALS to control
// what each returns, proving the union is folded.
$GLOBALS['__schedule_all']     = array();
$GLOBALS['__future_posts']     = array();
if ( ! function_exists( 'sn_schedule_all' ) ) {
	function sn_schedule_all() { return $GLOBALS['__schedule_all']; }
}
if ( ! function_exists( 'sn_schedule_future_posts' ) ) {
	function sn_schedule_future_posts() { return $GLOBALS['__future_posts']; }
}

// ─── Ops-seam stubs (input-aware, so handler behaviour is observable) ─────────
// sn_schedule_fire records the row id it was called with.
$GLOBALS['__fired_ids'] = array();
if ( ! function_exists( 'sn_schedule_fire' ) ) {
	function sn_schedule_fire( $row_id ) { $GLOBALS['__fired_ids'][] = (int) $row_id; }
}
// sn_schedule_get returns a row keyed by id from a fixture map.
$GLOBALS['__rows'] = array();
if ( ! function_exists( 'sn_schedule_get' ) ) {
	function sn_schedule_get( $id ) {
		$id = (int) $id;
		return isset( $GLOBALS['__rows'][ $id ] ) ? $GLOBALS['__rows'][ $id ] : null;
	}
}
// sn_schedule_purge_urls records the EXACT array it received.
$GLOBALS['__purged_with'] = null;
$GLOBALS['__purge_called'] = 0;
if ( ! function_exists( 'sn_schedule_purge_urls' ) ) {
	function sn_schedule_purge_urls( array $urls ) {
		$GLOBALS['__purged_with'] = $urls;
		$GLOBALS['__purge_called']++;
		return true;
	}
}

// add_action: the admin-post-handler.php require registers on admin_init at load.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() { return true; }
}
// sn_admin_pages: legacy-slug source for the allowlist (minimal).
if ( ! function_exists( 'sn_admin_pages' ) ) {
	function sn_admin_pages() { return array( array( 'slug' => 'sn-theme-options' ) ); }
}
// sn_admin_top_tabs: registry-slug source for the allowlist (minimal. the
// handler-map + allowlist tests don't need the full registry).
if ( ! function_exists( 'sn_admin_top_tabs' ) ) {
	function sn_admin_top_tabs() { return array( array( 'slug' => 'sn-connections' ) ); }
}

// ─── Load the unit under test ─────────────────────────────────────────────────
require __DIR__ . '/../inc/admin-glance.php'; // v6.45.0: the scheduled tab leads with a glance hero
require __DIR__ . '/../inc/schedule-admin.php';
require __DIR__ . '/../inc/admin-post-handler.php';
// v8.0.0: version-swap pairing + atomic run (Group 5).
$swap_module = __DIR__ . '/../inc/schedule-swap.php';
if ( file_exists( $swap_module ) ) {
	require $swap_module;
}

// ════════════════════════════════════════════════════════════════════════════
// GROUP 1: render folds BOTH sources, with the right Type labels.
// ════════════════════════════════════════════════════════════════════════════
$GLOBALS['__schedule_all'] = array(
	array(
		'id'          => 7,
		'schedule_id' => 'blk-abc',
		'target_type' => 'fragment',
		'target_ref'  => '42',
		'action'      => 'reveal',
		'starts_at'   => '2030-01-01 09:00:00',
		'ends_at'     => '2030-01-08 09:00:00',
		'status'      => 'queued',
		'last_run'    => null,
		'purge_urls'  => '["https://example.test/page/"]',
	),
);
$GLOBALS['__future_posts'] = array(
	array(
		'id'            => 99,
		'title'         => 'My Scheduled Launch Post',
		'scheduled_ts'  => 1893492000,
		'scheduled_gmt' => '2030-01-01 10:00:00',
		'edit_link'     => 'https://example.test/wp-admin/post.php?post=99&action=edit',
	),
);

ob_start();
sn_admin_render_scheduled_content_section();
$html = ob_get_clean();

ok( false !== strpos( $html, '<div class="sn-glance">' ), 'v6.45.0: leads with a first-glance hero when there is content' );
ok( false !== strpos( $html, 'wp-list-table' ), 'render emits a .wp-list-table' );
ok( false !== strpos( $html, 'widefat' ) && false !== strpos( $html, 'striped' ), 'table is widefat striped (native wp-admin)' );
// Fragment row: linked to its target_ref post id editor (42), labelled Fragment.
ok( false !== strpos( $html, 'post=42' ), 'fragment row links to its target post editor (target_ref 42)' );
ok( false !== strpos( $html, 'Fragment' ), 'fragment row carries the Fragment type label' );
ok( false !== strpos( $html, 'reveal' ), 'fragment row shows its action (reveal)' );
ok( false !== strpos( $html, 'queued' ), 'fragment row shows its status (queued)' );
// Future post: its title appears, labelled Page, Scheduled status.
ok( false !== strpos( $html, 'My Scheduled Launch Post' ), 'future-post row shows the post title' );
ok( false !== strpos( $html, 'post=99' ), 'future-post row links to its editor (id 99)' );
ok( false !== strpos( $html, 'Page' ), 'future-post row carries the Page type label' );
ok( false !== strpos( $html, 'Scheduled' ), 'future-post row status is Scheduled' );
// Ops buttons present only for the fragment row.
ok( false !== strpos( $html, 'schedule_run_now' ), 'fragment row exposes a Run now op (sn_action=schedule_run_now)' );
ok( false !== strpos( $html, 'schedule_repurge' ), 'fragment row exposes a Re-purge op (sn_action=schedule_repurge)' );
ok( false !== strpos( $html, 'name="row_id" value="7"' ), 'ops buttons carry the fragment row id' );

// Empty state.
$GLOBALS['__schedule_all'] = array();
$GLOBALS['__future_posts'] = array();
ob_start();
sn_admin_render_scheduled_content_section();
$empty = ob_get_clean();
ok( false !== stripos( $empty, 'No scheduled content' ), 'empty state shows a friendly "No scheduled content" row' );
ok( false === strpos( $empty, 'sn-glance' ), 'empty state shows no glance hero (nothing to glance)' );

// ════════════════════════════════════════════════════════════════════════════
// GROUP 2: output is ESCAPED. A <script> post title is esc_html'd, never raw.
// ════════════════════════════════════════════════════════════════════════════
$GLOBALS['__schedule_all'] = array();
$GLOBALS['__future_posts'] = array(
	array(
		'id'            => 5,
		'title'         => '<script>alert(1)</script>',
		'scheduled_ts'  => 1893492000,
		'scheduled_gmt' => '2030-01-01 10:00:00',
		'edit_link'     => 'https://example.test/wp-admin/post.php?post=5&action=edit',
	),
);
ob_start();
sn_admin_render_scheduled_content_section();
$xss = ob_get_clean();
ok( false === strpos( $xss, '<script>alert(1)</script>' ), 'a script-tag post title is NOT emitted raw (esc_html-escaped)' );
ok( false !== strpos( $xss, '&lt;script&gt;' ), 'the title is HTML-entity escaped in the output' );

// ════════════════════════════════════════════════════════════════════════════
// GROUP 3: handlers are in the dispatcher map and map to real functions.
// ════════════════════════════════════════════════════════════════════════════
$handlers = sn_admin_post_handlers();
ok( isset( $handlers['schedule_run_now'] ), 'sn_admin_post_handlers() has schedule_run_now' );
ok( isset( $handlers['schedule_repurge'] ), 'sn_admin_post_handlers() has schedule_repurge' );
ok( isset( $handlers['schedule_run_now'] ) && function_exists( $handlers['schedule_run_now'] ),
	'schedule_run_now maps to a defined function (' . ( $handlers['schedule_run_now'] ?? '?' ) . ')' );
ok( isset( $handlers['schedule_repurge'] ) && function_exists( $handlers['schedule_repurge'] ),
	'schedule_repurge maps to a defined function (' . ( $handlers['schedule_repurge'] ?? '?' ) . ')' );

// ════════════════════════════════════════════════════════════════════════════
// GROUP 4: handler behaviour.
// ════════════════════════════════════════════════════════════════════════════
// Run now WITH a row_id fires that exact id.
$GLOBALS['__fired_ids'] = array();
$flash = sn_handle_schedule_run_now( array( 'row_id' => '7' ) );
ok( $GLOBALS['__fired_ids'] === array( 7 ), 'run_now with row_id=7 calls sn_schedule_fire(7)' );
ok( 'schedule_fired' === $flash, 'run_now returns schedule_fired' );

// Run now WITHOUT a row_id is a safe no-op returning the invalid code.
$GLOBALS['__fired_ids'] = array();
$flash = sn_handle_schedule_run_now( array() );
ok( $GLOBALS['__fired_ids'] === array(), 'run_now with no row_id does NOT fire anything' );
ok( 'schedule_invalid' === $flash, 'run_now with no row_id returns schedule_invalid' );

// Re-purge loads the row and forwards its EXACT purge_urls array to the seam.
$GLOBALS['__rows'] = array(
	13 => array(
		'id'         => 13,
		'purge_urls' => '["https://example.test/a/","https://example.test/b/"]',
	),
);
$GLOBALS['__purged_with']  = null;
$GLOBALS['__purge_called'] = 0;
$flash = sn_handle_schedule_repurge( array( 'row_id' => '13' ) );
ok( 1 === $GLOBALS['__purge_called'], 're-purge calls sn_schedule_purge_urls exactly once' );
ok( $GLOBALS['__purged_with'] === array( 'https://example.test/a/', 'https://example.test/b/' ),
	're-purge forwards the row\'s exact decoded purge_urls array' );
ok( 'schedule_repurged' === $flash, 're-purge returns schedule_repurged' );

// Re-purge with no row_id / missing row is a safe no-op returning the invalid code.
$GLOBALS['__purged_with']  = null;
$GLOBALS['__purge_called'] = 0;
$flash = sn_handle_schedule_repurge( array() );
ok( 0 === $GLOBALS['__purge_called'], 're-purge with no row_id does NOT call the purge seam' );
ok( 'schedule_invalid' === $flash, 're-purge with no row_id returns schedule_invalid' );

// ════════════════════════════════════════════════════════════════════════════
// GROUP 5 (v8.0.0): version swaps — derived pair section + atomic run op.
// ════════════════════════════════════════════════════════════════════════════
$swap_rows = array(
	array(
		'id' => 21, 'schedule_id' => 'blk-old', 'target_type' => 'fragment',
		'target_ref' => '42', 'action' => 'reveal',
		'starts_at' => null, 'ends_at' => '2030-02-01 09:00:00',
		'status' => 'active', 'last_run' => null,
		'purge_urls' => '["https://example.test/launch/"]',
	),
	array(
		'id' => 22, 'schedule_id' => 'blk-new', 'target_type' => 'fragment',
		'target_ref' => '42', 'action' => 'reveal',
		'starts_at' => '2030-02-01 09:00:00', 'ends_at' => null,
		'status' => 'queued', 'last_run' => null,
		'purge_urls' => '["https://example.test/launch/"]',
	),
);
$GLOBALS['__schedule_all'] = $swap_rows;
$GLOBALS['__future_posts'] = array();
ob_start();
sn_admin_render_scheduled_content_section();
$swap_html = ob_get_clean();
ok( false !== strpos( $swap_html, 'Version swaps' ), 'paired rows render a Version swaps section' );
ok( false !== strpos( $swap_html, 'schedule_swap_run_now' ), 'swap section exposes the Run-swap op (sn_action=schedule_swap_run_now)' );
ok( false !== strpos( $swap_html, 'name="hide_id" value="21"' ), 'swap op carries the hide row id' );
ok( false !== strpos( $swap_html, 'name="show_id" value="22"' ), 'swap op carries the show row id' );
ok( false !== strpos( $swap_html, '2030-02-01 09:00' ), 'swap section shows the (site-tz) swap instant' );

// Unpaired rows render NO swap section.
$GLOBALS['__schedule_all'] = array( $swap_rows[0] );
ob_start();
sn_admin_render_scheduled_content_section();
$nopair_html = ob_get_clean();
ok( false === strpos( $nopair_html, 'Version swaps' ), 'an unpaired row renders no Version swaps section' );

// Handler is registered and defined.
$handlers = sn_admin_post_handlers();
ok( isset( $handlers['schedule_swap_run_now'] ) && function_exists( (string) $handlers['schedule_swap_run_now'] ),
	'schedule_swap_run_now maps to a defined function (' . ( $handlers['schedule_swap_run_now'] ?? '?' ) . ')' );

// Behaviour: a valid pair fires BOTH rows (hide first), returns the fired code.
$GLOBALS['__rows'] = array( 21 => $swap_rows[0], 22 => $swap_rows[1] );
$GLOBALS['__fired_ids'] = array();
$flash = function_exists( 'sn_handle_schedule_swap_run_now' )
	? sn_handle_schedule_swap_run_now( array( 'hide_id' => '21', 'show_id' => '22' ) )
	: null;
ok( $GLOBALS['__fired_ids'] === array( 21, 22 ), 'swap run fires hide (21) then show (22)' );
ok( 'schedule_swap_fired' === $flash, 'swap run returns schedule_swap_fired' );

// Behaviour: ids that are not a pair fire NOTHING and return the invalid code.
$GLOBALS['__fired_ids'] = array();
$flash = function_exists( 'sn_handle_schedule_swap_run_now' )
	? sn_handle_schedule_swap_run_now( array( 'hide_id' => '22', 'show_id' => '21' ) )
	: null;
ok( $GLOBALS['__fired_ids'] === array(), 'reversed (non-pair) ids fire nothing' );
ok( 'schedule_invalid' === $flash, 'reversed ids return schedule_invalid' );

$GLOBALS['__fired_ids'] = array();
$flash = function_exists( 'sn_handle_schedule_swap_run_now' )
	? sn_handle_schedule_swap_run_now( array() )
	: null;
ok( $GLOBALS['__fired_ids'] === array(), 'missing ids fire nothing' );
ok( 'schedule_invalid' === $flash, 'missing ids return schedule_invalid' );

// The flash registry resolves the new code (severity success).
if ( ! function_exists( 'sn_admin_flash_messages' ) ) {
	require __DIR__ . '/../inc/admin-flash-messages.php';
}
$codes = sn_admin_flash_messages();
ok( isset( $codes['schedule_swap_fired'] ) && 'success' === ( $codes['schedule_swap_fired'][0] ?? '' ),
	'flash registry resolves schedule_swap_fired as a success notice' );

// ════════════════════════════════════════════════════════════════════════════
// GROUP 5 (IA SCHED1): the schedule wall folds, caps, and leads with the
// soonest transition. The glance stays OUTSIDE the fold — the honesty layer is
// not collapsible.
// ════════════════════════════════════════════════════════════════════════════

// --- the sort key ------------------------------------------------------------
$now = time();
ok( 0 === sn_admin_schedule_next_transition_ts( gmdate( 'Y-m-d H:i:s', $now - 7200 ), gmdate( 'Y-m-d H:i:s', $now - 3600 ) ),
	'next-transition ts: both boundaries past -> 0 (nothing pending)' );
ok( 0 === sn_admin_schedule_next_transition_ts( '', null ),
	'next-transition ts: no boundaries -> 0' );
$soonest = sn_admin_schedule_next_transition_ts( gmdate( 'Y-m-d H:i:s', $now + 7200 ), gmdate( 'Y-m-d H:i:s', $now + 3600 ) );
ok( $soonest > $now && $soonest <= $now + 3600 + 2,
	'next-transition ts: returns the SOONEST future boundary, not the first one listed' );
ok( sn_admin_schedule_next_transition_ts( gmdate( 'Y-m-d H:i:s', $now - 3600 ), gmdate( 'Y-m-d H:i:s', $now + 3600 ) ) > $now,
	'next-transition ts: a past start does not mask a future end' );

// 30 fragments, supplied WORST-ORDER-FIRST (latest first) so a renderer that
// trusts producer order cannot pass by accident.
$many = array();
for ( $i = 30; $i >= 1; $i-- ) {
	$many[] = array(
		'id'          => $i,
		'schedule_id' => 'blk-' . $i,
		'target_type' => 'fragment',
		'target_ref'  => (string) ( 1000 + $i ),
		'action'      => 'reveal',
		'starts_at'   => gmdate( 'Y-m-d H:i:s', $now + ( $i * 86400 ) ),
		'ends_at'     => null,
		'status'      => 'queued',
		'last_run'    => null,
		'purge_urls'  => '[]',
	);
}
$GLOBALS['__schedule_all'] = $many;
$GLOBALS['__future_posts'] = array();
ob_start();
sn_admin_render_scheduled_content_section();
$wall = ob_get_clean();

ok( false !== strpos( $wall, '<details class="sn-schedule-log sn-disclosure">' ),
	'IA SCHED1: the schedule wall sits behind a .sn-disclosure fold' );
ok( false === strpos( $wall, '<details class="sn-schedule-log sn-disclosure" open' ),
	'IA SCHED1: the fold is CLOSED by default (the wall is the thing being folded away)' );
ok( false !== strpos( $wall, '<summary' ) && false !== strpos( $wall, '30' ),
	'IA SCHED1: the summary carries the TRUE total (30), so the fold hides evidence but never that there is any' );

// The glance is the honesty layer: it must render BEFORE, and outside, the fold.
$g = strpos( $wall, 'sn-glance' );
$d = strpos( $wall, '<details class="sn-schedule-log' );
ok( false !== $g && false !== $d && $g < $d,
	'IA SCHED1: the glance hero stays OUTSIDE the fold, above it' );

// Cap + order, asserted together: soonest survives, latest is dropped.
ok( false !== strpos( $wall, 'post=1001' ),
	'IA SCHED1: the SOONEST row survives the cap (sorted, not producer order)' );
ok( false === strpos( $wall, 'post=1030' ),
	'IA SCHED1: the farthest row is capped away (25 of 30)' );
ok( false !== strpos( $wall, 'post=1025' ) && false === strpos( $wall, 'post=1026' ),
	'IA SCHED1: the cap falls exactly at 25 rows, soonest-first' );
ok( false !== strpos( $wall, 'sn-schedule-remainder' ) && false !== strpos( $wall, '5' ),
	'IA SCHED1: an explicit remainder line names the 5 rows not shown' );

// Under the cap: no remainder line invented.
$GLOBALS['__schedule_all'] = array_slice( $many, 0, 3 );
ob_start();
sn_admin_render_scheduled_content_section();
$small = ob_get_clean();
ok( false === strpos( $small, 'sn-schedule-remainder' ),
	'IA SCHED1: under the cap, no remainder line is invented' );
ok( false !== strpos( $small, '<details class="sn-schedule-log sn-disclosure">' ),
	'IA SCHED1: the fold is unconditional, matching AL1/MR1-4 (the summary carries the count either way)' );

// Rows with NO future boundary sort last rather than pushing live rows out.
$GLOBALS['__schedule_all'] = array(
	array( 'id' => 1, 'schedule_id' => 'blk-dead', 'target_type' => 'fragment', 'target_ref' => '2001',
		'action' => 'reveal', 'starts_at' => gmdate( 'Y-m-d H:i:s', $now - 86400 ), 'ends_at' => null,
		'status' => 'fired', 'last_run' => null, 'purge_urls' => '[]' ),
	array( 'id' => 2, 'schedule_id' => 'blk-live', 'target_type' => 'fragment', 'target_ref' => '2002',
		'action' => 'reveal', 'starts_at' => gmdate( 'Y-m-d H:i:s', $now + 3600 ), 'ends_at' => null,
		'status' => 'queued', 'last_run' => null, 'purge_urls' => '[]' ),
);
$GLOBALS['__future_posts'] = array();
ob_start();
sn_admin_render_scheduled_content_section();
$mixed = ob_get_clean();
ok( strpos( $mixed, 'post=2002' ) < strpos( $mixed, 'post=2001' ),
	'IA SCHED1: a row with no pending boundary sorts BELOW one that still has a transition' );

// Both sources still fold into the one ordered list (the v6.40.0 contract).
$GLOBALS['__schedule_all'] = array(
	array( 'id' => 1, 'schedule_id' => 'blk-late', 'target_type' => 'fragment', 'target_ref' => '3001',
		'action' => 'reveal', 'starts_at' => gmdate( 'Y-m-d H:i:s', $now + 172800 ), 'ends_at' => null,
		'status' => 'queued', 'last_run' => null, 'purge_urls' => '[]' ),
);
$GLOBALS['__future_posts'] = array(
	array( 'id' => 3002, 'title' => 'Sooner Post', 'scheduled_ts' => $now + 3600,
		'scheduled_gmt' => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
		'edit_link' => 'https://example.test/wp-admin/post.php?post=3002&action=edit' ),
);
ob_start();
sn_admin_render_scheduled_content_section();
$both = ob_get_clean();
ok( false !== strpos( $both, 'Sooner Post' ) && false !== strpos( $both, 'post=3001' ),
	'IA SCHED1: both sources still render in the one table' );
ok( strpos( $both, 'Sooner Post' ) < strpos( $both, 'post=3001' ),
	'IA SCHED1: ordering is across BOTH sources, not fragments-then-posts' );

// ─── core's list-table responsive contract, on the RENDERED markup ─────────
// The sweep in tests/admin-table-mobile-contract.php skips this file: it holds
// two list tables AND the plain one-cell "no scheduled content" notice, and
// file-scoped text cannot say which cells belong to which. So the contract is
// pinned here, where the real output is already in hand.
ok( 1 === preg_match( '/<th scope="col" class="[^"]*column-primary[^"]*">Target/', $both ),
	'the Target header is the primary column, so rows stack under 782px (#1015)' );
ok( 1 === preg_match( '/<th scope="row" class="[^"]*column-primary[^"]*" data-colname="Target">/', $both ),
	'each row header is the primary cell and names its column' );

// Every DATA cell carries a label. A colspan cell spans the table and must not.
preg_match_all( '/<td\b[^>]*>/', $both, $cells );
$unlabelled = array();
foreach ( $cells[0] as $tag ) {
	if ( false !== stripos( $tag, 'colspan' ) ) {
		continue;
	}
	if ( false === strpos( $tag, 'data-colname' ) ) {
		$unlabelled[] = $tag;
	}
}
ok( count( $cells[0] ) >= 6, 'VACUITY: the render produced data cells to check (' . count( $cells[0] ) . ')' );
ok( array() === $unlabelled,
	'every data cell names its column; unlabelled: ' . ( $unlabelled ? implode( ' ', $unlabelled ) : 'none' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
