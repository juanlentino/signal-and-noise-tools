<?php
/**
 * Native window leaf: Connections → Scheduled
 * (apps/sn-dashboard/parts/leaves/connections-scheduled-content.php).
 *
 * The oracle is the classic leaf (inc/schedule-admin.php,
 * `sn_admin_render_scheduled_content_section()`): the kit must fold the same
 * two producers (fragment/queue rows + native future posts) into the same
 * capped, soonest-first status wall, offer the same three ops
 * (schedule_run_now, schedule_repurge, schedule_swap_run_now) with the same
 * hidden fields, and carry none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-connections-scheduled-content.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own readers: date/time + post lookups the classic renderer
// (and its pure helpers) call, none of which the shared harness stubs.
$GLOBALS['__now'] = 1700000000; // fixed instant; every "future" fixture sits after this.
function current_time( $type, $gmt = 0 ) { return $GLOBALS['__now']; }
function get_date_from_gmt( $string, $format = 'Y-m-d H:i:s' ) {
	$string = (string) $string;
	if ( '' === $string ) { return ''; }
	$ts = strtotime( $string . ' UTC' );
	return false === $ts ? '' : gmdate( $format, $ts );
}
$GLOBALS['__titles'] = array();
function get_the_title( $id = 0 ) { return $GLOBALS['__titles'][ (int) $id ] ?? ''; }
$GLOBALS['__edit_links'] = array();
function get_edit_post_link( $id = 0 ) { return $GLOBALS['__edit_links'][ (int) $id ] ?? ''; }

// ── The two producers + the derived swap pairs, all fixture-fed.
$GLOBALS['__sched_fragments'] = array();
function sn_schedule_all() { return $GLOBALS['__sched_fragments']; }
$GLOBALS['__sched_posts'] = array();
function sn_schedule_future_posts() { return $GLOBALS['__sched_posts']; }
$GLOBALS['__sched_pairs'] = array();
function sn_schedule_swap_pairs( $fragments ) { return $GLOBALS['__sched_pairs']; }

require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/schedule-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-scheduled-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$NOW = $GLOBALS['__now'];
function iso( $offset ) { global $NOW; return gmdate( 'Y-m-d H:i:s', $NOW + $offset ); }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/scheduled-content'] ), 'the painter is registered under connections/scheduled-content' );

// ── Rich fixture: one reveal fragment (linked), one unlinked hide fragment,
// one past/no-pending fragment, one future post, one derived swap pair.
$GLOBALS['__titles']     = array( 55 => 'Home page' );
$GLOBALS['__edit_links'] = array( 55 => 'https://example.test/wp-admin/post.php?post=55&action=edit' );
$GLOBALS['__sched_fragments'] = array(
	array( 'id' => 201, 'target_ref' => 55, 'action' => 'reveal', 'starts_at' => iso( 3600 ), 'ends_at' => null, 'status' => 'queued' ),
	array( 'id' => 202, 'target_ref' => 0, 'action' => 'hide', 'starts_at' => null, 'ends_at' => iso( 7200 ), 'status' => 'error' ),
	array( 'id' => 203, 'target_ref' => 55, 'action' => 'hide', 'starts_at' => iso( -7200 ), 'ends_at' => iso( -3600 ), 'status' => 'done' ),
);
$GLOBALS['__sched_posts'] = array(
	array( 'id' => 77, 'title' => 'Launch announcement', 'edit_link' => 'https://example.test/wp-admin/post.php?post=77&action=edit', 'scheduled_gmt' => iso( 10800 ) ),
);
$GLOBALS['__sched_pairs'] = array(
	array(
		'target_ref' => 55,
		'swap_at'    => iso( 1800 ),
		'hide'       => array( 'id' => 301, 'status' => 'active' ),
		'show'       => array( 'id' => 302, 'status' => 'queued' ),
	),
);

$classic = snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' );
$kit     = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok(
	array( 'schedule_repurge', 'schedule_run_now', 'schedule_swap_run_now' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ),
	'the three ops (schedule_run_now, schedule_repurge, schedule_swap_run_now) match the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) )
);
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

ok( false !== strpos( $kit, 'value="4"' ) && false !== strpos( $kit, 'label="Scheduled"' ), 'the glance total (4 = 3 fragments + 1 post) is shown' );
ok( false !== strpos( $kit, 'value="3"' ) && false !== strpos( $kit, 'label="Fragments"' ) && false !== strpos( $kit, 'value="1"' ) && false !== strpos( $kit, 'label="Future posts"' ), 'the fragment/future-post glance split is shown' );

ok( false !== strpos( $kit, 'Home page' ) && false !== strpos( $kit, 'os-arg-url="https://example.test/wp-admin/post.php?post=55&amp;action=edit"' ), 'the linked fragment target is a kit door to its editor, not a raw admin <a>' );
ok( false !== strpos( $kit, '(unlinked fragment)' ), 'the unlinked fragment falls back to its classic label' );
ok( false !== strpos( $kit, 'Launch announcement' ) && false !== strpos( $kit, 'Publish' ) && false !== strpos( $kit, 'Page' ), 'the native future post shows its title, type (Page) and action (Publish)' );
ok( false !== strpos( $kit, 'native' ) && false !== strpos( $kit, 'Run now' ), 'the native post is marked "native" while fragment rows still carry Run now' );

ok( false !== strpos( $kit, 'active → queued' ), 'the version-swap pair shows its hide→show status' );
ok( false !== strpos( $kit, 'Run swap now' ) && false !== strpos( $kit, 'name="hide_id" value="301"' ) && false !== strpos( $kit, 'name="show_id" value="302"' ), 'the swap row carries the same hide_id/show_id the classic op posts' );

ok( false !== strpos( $kit, 'in 1 hour' ), 'a pending boundary reads as a relative "in …" time (fixture-fed human_time_diff)' );
ok( false !== strpos( $kit, '&mdash;' ), 'a row with no pending boundary (both past) falls back to the dash placeholder' );

ok( false !== strpos( $kit, 'name="row_id" value="201"' ) && false !== strpos( $kit, 'value="schedule_run_now"' ) && false !== strpos( $kit, 'value="schedule_repurge"' ), 'the fragment row carries its own row_id into both ops' );

// ── Escaping: a hostile action string never reaches the markup raw.
$GLOBALS['__sched_fragments'][0]['action'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile action value is escaped' );
$GLOBALS['__sched_fragments'][0]['action'] = 'reveal';

// ── Empty state: no fragments, no posts.
$GLOBALS['__sched_fragments'] = array();
$GLOBALS['__sched_posts']     = array();
$GLOBALS['__sched_pairs']     = array();
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'No scheduled content.' ), 'the empty state paints when nothing is scheduled' );
ok( false === strpos( $kit, 'snt-schedule-row' ), 'the empty state paints no rows' );

// ── Capped + remainder: more rows than the display cap.
$many = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$many[] = array( 'id' => 1000 + $i, 'target_ref' => 0, 'action' => 'reveal', 'starts_at' => iso( $i * 60 ), 'ends_at' => null, 'status' => 'queued' );
}
$GLOBALS['__sched_fragments'] = $many;
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false !== strpos( $kit, '30 scheduled items' ), 'the fold summary carries the TRUE total, not the capped count' );
ok( false !== strpos( $kit, '+5 more scheduled items, sorted soonest-first' ), 'the remainder line reports the hidden tail (30 - 25 cap = 5)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
