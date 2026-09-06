<?php
/**
 * Suite: apps/sn-dashboard/parts/leaves/connections-cron.php
 *
 * The classic leaf (inc/cron-dashboard-admin.php) has no forms and no
 * sn_action at all, so the faithfulness oracle here is: same hook names,
 * same glance counts, same per-row Run-now/Unschedule facts, same empty
 * state, zero classic markup, and a hostile hook name escaped.
 *
 * @package SignalNoiseTools
 */

require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── Stubs the classic renderer file needs that the harness doesn't already
// provide (it never calls these at require-time, but declare them anyway so
// a future call path doesn't fatal).
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/x/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( ...$a ) { return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) { return true; } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) { return true; } }
if ( ! function_exists( 'wp_set_script_translations' ) ) { function wp_set_script_translations( ...$a ) { return true; } }
if ( ! function_exists( 'wp_script_is' ) ) { function wp_script_is( ...$a ) { return false; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '' ) { throw new \RuntimeException( (string) $m ); } }

// ── The reader the classic leaf and the kit leaf BOTH call: fixture-driven,
// since a real _get_cron_array()/has_action() walk needs a live WP cron.
$GLOBALS['__cron_rows'] = array();
if ( ! function_exists( 'snt_cron_get_events_impl' ) ) {
	function snt_cron_get_events_impl( $sn_only = false ) {
		return $GLOBALS['__cron_rows'];
	}
}

require_once SNT_PATH . 'inc/admin-glance.php';
require_once SNT_PATH . 'inc/cron-dashboard-admin.php';
require_once SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cron.php';

$pass = 0;
$fail = 0;
/**
 * @param bool   $cond
 * @param string $label
 */
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

// ── Fixture rows: SN-owned+has-handler (locked both ways), an orphan (no
// handler, not SN), and a normal foreign hook (fully actionable), covering
// every distinct per-row state the classic leaf renders.
$rich_rows = array(
	array(
		'hook'           => 'sn_cache_sweep',
		'args_signature' => 'sig-a',
		'next_run_ts'    => 1000000,
		'schedule'       => 'hourly',
		'interval_s'     => 3600,
		'args'           => array(),
		'last_fired_ts'  => 999000,
		'has_handler'    => true,
		'is_sn_owned'    => true,
	),
	array(
		'hook'           => 'some_orphan_hook',
		'args_signature' => 'sig-b',
		'next_run_ts'    => 1000500,
		'schedule'       => false,
		'interval_s'     => null,
		'args'           => array( 'foo' => 'bar' ),
		'last_fired_ts'  => 0,
		'has_handler'    => false,
		'is_sn_owned'    => false,
	),
	array(
		'hook'           => 'other_plugin_cron',
		'args_signature' => 'sig-c',
		'next_run_ts'    => 1001000,
		'schedule'       => 'daily',
		'interval_s'     => 86400,
		'args'           => array(),
		'last_fired_ts'  => 998000,
		'has_handler'    => true,
		'is_sn_owned'    => false,
	),
);

// 1) Registered under connections/cron.
$GLOBALS['__cron_rows'] = $rich_rows;
$kit = snt_leaf_paint( 'connections', 'cron', array() );
ok( '' !== $kit, 'painter registered under connections/cron produced output' );

// Classic HTML for the same fixture.
$classic = snt_leaf_classic_html( 'snt_cron_render_admin_tab' );

// 2) Same field names (both empty — neither leaf has a form).
$classic_names = snt_leaf_names( $classic );
$kit_names     = snt_leaf_names( $kit );
ok(
	$classic_names === $kit_names,
	'snt_leaf_names match: classic=' . json_encode( $classic_names ) . ' kit=' . json_encode( $kit_names )
);

// 3) Same sn_action values (both empty — the classic leaf has none).
$classic_actions = snt_leaf_actions( $classic );
$kit_actions     = snt_leaf_actions( $kit );
ok(
	$classic_actions === $kit_actions,
	'snt_leaf_actions match: classic=' . json_encode( $classic_actions ) . ' kit=' . json_encode( $kit_actions )
);

// 4) No classic markup.
$markers = snt_leaf_classic_markers( $kit );
ok( array() === $markers, 'no classic markers: found ' . json_encode( $markers ) );

// 5) The glance counts: 3 total, 1 SN-owned, 1 orphan.
ok( false !== strpos( $kit, 'value="3"' ) && false !== strpos( $kit, 'label="Scheduled events"' ), 'glance: 3 scheduled events' );
ok( false !== strpos( $kit, 'value="1"' ) && false !== strpos( $kit, 'label="Signal &amp; Noise"' ), 'glance: 1 Signal &amp; Noise owned' );
ok( false !== strpos( $kit, 'label="Orphans"' ), 'glance: an Orphans card is present' );

// 6) Every hook name appears, with its tags.
ok( false !== strpos( $kit, 'sn_cache_sweep [SN]' ), 'SN-owned hook tagged [SN]' );
ok( false !== strpos( $kit, 'some_orphan_hook [orphan]' ), 'orphan hook tagged [orphan]' );
ok( false !== strpos( $kit, 'other_plugin_cron' ) && false === strpos( $kit, 'other_plugin_cron [' ), 'plain foreign hook carries no tag' );

// 7) Run-now / Unschedule facts per row, matching the classic button states.
ok( false !== strpos( $kit, 'Not runnable here' ), 'SN-owned+handled hook: Run now says not runnable here' );
ok( false !== strpos( $kit, 'No handler' ), 'orphan hook: Run now says no handler' );
ok( false !== strpos( $kit, 'Locked' ), 'SN-owned hook: Unschedule says locked' );
// Count "Available" occurrences: other_plugin_cron gets it for BOTH columns,
// some_orphan_hook gets it for Unschedule only == 3 total.
ok( 3 === substr_count( $kit, 'Available' ), 'exactly 3 Available cells (foreign hook x2, orphan unschedule x1)' );

// 8) Recurrence + args readouts.
ok( false !== strpos( $kit, 'hourly (1 hour)' ), 'recurrence: hourly with interval' );
ok( false !== strpos( $kit, 'single event' ), 'recurrence: single event for the orphan' );
// human_time_diff() is stubbed to always return '1 hour' (see lib/os-leaf-harness.php)
// regardless of args, so the 86400s interval prints the same literal as the
// 3600s one above — pin that exact string, not a hedge that also passes on a
// wrong interval.
ok( false !== strpos( $kit, 'daily (1 hour)' ), 'recurrence: daily with its 86400s interval' );
ok( false !== strpos( $kit, 'foo' ) && false !== strpos( $kit, 'bar' ), 'args JSON for the orphan row carries foo/bar' );

// 8b) Column labels: every classic <th> heading survives as an os-table column.
foreach ( array( 'Hook', 'Next run', 'Recurrence', 'Last fired', 'Args', 'Run now', 'Unschedule' ) as $lbl ) {
	ok( false !== strpos( $kit, '&quot;label&quot;:&quot;' . $lbl . '&quot;' ), "column label present: $lbl" );
}

// 8c) The hook column carries the live substring filter classic's
// #sn-cron-filter input gave (os-table's own client-side column filter).
ok( false !== strpos( $kit, '&quot;filter&quot;:&quot;text&quot;' ), 'hook column carries the live substring filter the classic #sn-cron-filter input gave' );

// 8d) next_run / last_fired cell values, not just non-empty output — a
// negative-control mutation that deleted both columns from the painter
// passed this suite before these two assertions existed.
ok( 3 === substr_count( $kit, '(in ' ), 'every row prints a next-run relative time' );
ok( false !== strpos( $kit, ' ago)' ) && false !== strpos( $kit, '—' ), 'last fired prints a relative time for fired rows and the em dash for the never-fired row' );

// 9) The helper sentence with the right count.
ok( false !== strpos( $kit, '3 scheduled events' ), 'helper sentence carries the plural count' );

// 10) Hostile fixture: a hook name with markup is escaped, never raw.
$GLOBALS['__cron_rows'] = array(
	array(
		'hook'           => '<script>alert(1)</script>',
		'args_signature' => 'sig-x',
		'next_run_ts'    => 1000000,
		'schedule'       => false,
		'interval_s'     => null,
		'args'           => array(),
		'last_fired_ts'  => 0,
		'has_handler'    => true,
		'is_sn_owned'    => false,
	),
);
$hostile = snt_leaf_paint( 'connections', 'cron', array() );
ok( false === strpos( $hostile, '<script>alert(1)</script>' ), 'hostile hook name never appears unescaped' );
// The raw literal winning this OR would mean the tag reached markup
// unescaped — the opposite of what this test claims. Assert the fully
// escaped string, not "escaped OR still raw".
ok( false !== strpos( $hostile, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'hostile hook name appears fully escaped' );
ok( array() === snt_leaf_classic_markers( $hostile ), 'hostile fixture carries no classic markers either: ' . json_encode( snt_leaf_classic_markers( $hostile ) ) );

// 11) The empty state, both leaves.
$GLOBALS['__cron_rows'] = array();
$kit_empty     = snt_leaf_paint( 'connections', 'cron', array() );
$classic_empty = snt_leaf_classic_html( 'snt_cron_render_admin_tab' );
ok( false !== strpos( $kit_empty, 'No scheduled events.' ), 'kit empty state: heading' );
ok( false !== strpos( $classic_empty, 'No scheduled events.' ), 'classic empty state: heading (sanity check on the fixture)' );
ok( false !== strpos( $kit_empty, 'wp_version_check' ), 'kit empty state: names the core hooks WP schedules at install' );
ok( array() === snt_leaf_classic_markers( $kit_empty ), 'empty state carries no classic markup either' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
