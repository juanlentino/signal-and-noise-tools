<?php
/**
 * Suite: apps/sn-dashboard/parts/leaves/connections-cron.php
 *
 * The classic tab is the whole `sn_admin_cron_tab` hook, captured through
 * the real wrapper sn_admin_render_cron_section() (inc/admin-render-sections.php)
 * the way tests/os-leaf-connections-cloudways.php captures: the events table
 * (priority 10, no form), the morning brief settings (priority 20, one form,
 * morning_brief_save) and the scheduled read-only runs (priority 30, one form,
 * scheduled_reads_save). The oracle: same field names, same sn_action values,
 * same glance counts, same per-row Run-now/Unschedule facts, same readouts,
 * same empty state, zero classic markup, a hostile hook name escaped, and the
 * toggles' OFF state surviving the handler.
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

// #1222: both leaves below call snt_cron_next_run_label() (inc/cron-dashboard.php),
// which this fixture doesn't otherwise load (it stubs snt_cron_get_events_impl()
// itself rather than requiring the real cron-dashboard.php and its own web of
// WP dependencies). Faithful stub, not a passthrough: branches on which side of
// "now" $next falls, matching the real function's contract.
if ( ! function_exists( 'snt_cron_next_run_label' ) ) {
	function snt_cron_next_run_label( $now, $next_run_ts ) {
		$diff = human_time_diff( $now, $next_run_ts );
		return $next_run_ts < $now ? "$diff overdue" : "in $diff";
	}
}

// ── The two settings callbacks' readers and writers: fixture-driven.
$GLOBALS['__settings'] = array();
$GLOBALS['__written']  = array();
$GLOBALS['__cron']     = array();
$GLOBALS['__drift']    = array( 'has_drift' => false, 'count' => 0 );
function sn_setting( $key, $default = null ) { return array_key_exists( $key, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $key ] : $default; }
function sn_setting_update( $key, $value ) { $GLOBALS['__settings'][ $key ] = $value; $GLOBALS['__written'][ $key ] = $value; return true; }
function snt_config_drift_status() { return $GLOBALS['__drift']; }
function wp_next_scheduled( $hook ) { return $GLOBALS['__cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['__cron'][ $hook ] = $ts; return true; }
function wp_unschedule_event( $ts, $hook ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_datetime() { return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ); }
if ( ! defined( 'SN_MCP_DOOR_READ' ) ) { define( 'SN_MCP_DOOR_READ', 'read' ); }

require_once SNT_PATH . 'inc/admin-glance.php';
require_once SNT_PATH . 'inc/admin-render-sections.php';
require_once SNT_PATH . 'inc/cron-dashboard-admin.php';
require_once SNT_PATH . 'inc/morning-brief.php';
require_once SNT_PATH . 'inc/scheduled-reads.php';
require_once SNT_PATH . 'inc/admin-post-actions/reports.php';
require_once SNT_PATH . 'inc/openstation-host-pipelines.php'; // snt_os_host_expand(), the round-trip pin.
require_once SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cron.php';

/**
 * Mirror of os-form.ts's `_readField()` (copied from
 * tests/os-leaf-security-login-defense.php): a checkbox-shaped tag reads its
 * boolean off `checked`; anything else falls through to its static `value`.
 */
function os_form_read_field( $html, $name ) {
	if ( ! preg_match( '/<([a-z-]+)([^>]*\bname="' . preg_quote( $name, '/' ) . '"[^>]*)>/i', $html, $m ) ) {
		return null;
	}
	$tag   = strtoupper( $m[1] );
	$attrs = $m[2];
	$is_checkbox_shaped = in_array( $tag, array( 'OS-CHECKBOX', 'OS-CHECKBOX-LABEL' ), true )
		|| ( 'INPUT' === $tag && false !== strpos( $attrs, 'type="checkbox"' ) );
	if ( $is_checkbox_shaped ) {
		return (bool) preg_match( '/(^|\s)checked(\s|=|$|>)/', $attrs );
	}
	return preg_match( '/\bvalue="([^"]*)"/', $attrs, $vm ) ? $vm[1] : null;
}

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
		'next_run_ts'    => time() + 1000000, // #1222: must be FUTURE-relative or snt_cron_next_run_label() reads it as overdue.
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
		'next_run_ts'    => time() + 1000500, // #1222: must be FUTURE-relative or snt_cron_next_run_label() reads it as overdue.
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
		'next_run_ts'    => time() + 1001000, // #1222: must be FUTURE-relative or snt_cron_next_run_label() reads it as overdue.
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
ok( 1 === substr_count( $kit, 'os-action="refresh"' ) && false !== strpos( $kit, '>Refresh</os-button>' ), 'live cron snapshot has one local read-only Refresh even when mobile hides the titlebar' );

// Classic HTML for the same fixture: the WHOLE hook through the real wrapper,
// not the priority-10 table alone (which has no form, so [] === [] greened
// the two comparisons below while two forms went unpainted).
$classic = snt_leaf_classic_html( 'sn_admin_render_cron_section' );
ok( array( 'morning_brief_save', 'scheduled_reads_save' ) === snt_leaf_actions( $classic ), 'fixture sanity: the classic hook carries both sn_action values' );
ok( 2 === substr_count( $classic, '<form' ), 'fixture sanity: the classic hook paints two forms' );

// 2) Same field names.
$classic_names = snt_leaf_names( $classic );
$kit_names     = snt_leaf_names( $kit );
ok(
	$classic_names === $kit_names,
	'snt_leaf_names match: classic=' . json_encode( $classic_names ) . ' kit=' . json_encode( $kit_names )
);

// 3) Same sn_action values.
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
		'next_run_ts'    => time() + 1000000, // #1222: must be FUTURE-relative or snt_cron_next_run_label() reads it as overdue.
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
$classic_empty = snt_leaf_classic_html( 'sn_admin_render_cron_section' );
ok( false !== strpos( $kit_empty, 'No scheduled events.' ), 'kit empty state: heading' );
// do_action paints all three callbacks whether or not cron has rows, so the
// settings row must be on the empty branch too.
ok( snt_leaf_actions( $classic_empty ) === snt_leaf_actions( $kit_empty ) && 2 === count( snt_leaf_actions( $kit_empty ) ), 'empty cron: the settings row still paints both sn_action values' );
ok( 1 === substr_count( $kit_empty, 'os-action="refresh"' ), 'empty cron snapshot can be refreshed after events are restored' );
ok( false !== strpos( $classic_empty, 'No scheduled events.' ), 'classic empty state: heading (sanity check on the fixture)' );
ok( false !== strpos( $kit_empty, 'wp_version_check' ), 'kit empty state: names the core hooks WP schedules at install' );
ok( array() === snt_leaf_classic_markers( $kit_empty ), 'empty state carries no classic markup either' );


// ── The Args cell is clamped so one payload cannot claim the table. ──
// Measured live 2026-09-10: an analytics rollup event carried 1315 characters of
// JSON, and under `table-layout: auto` that single unbreakable cell took 2668px
// of a 3369px table -- every other column collapsed to its minimum and the
// timestamps wrapped onto four lines. The cells sit in os-table's shadow root,
// which exposes only `part=scroll`, so this cannot be fixed in CSS.
$long_payload = array( 'rollup' => str_repeat( 'abcdefghij', 140 ) );      // ~1400 chars, no spaces to break on
$short_payload = array( 'gravatars' );

$long_cell  = \SignalNoise\OpenStationHost\Dashboard\Leaves\cron_args_summary( $long_payload );
$short_cell = \SignalNoise\OpenStationHost\Dashboard\Leaves\cron_args_summary( $short_payload );

ok( mb_strlen( (string) wp_json_encode( $long_payload ) ) > 1000, 'the fixture really is an unbreakable payload (sanity check)' );
ok( false !== strpos( $long_cell, '…' ), 'a long Args payload is elided' );
ok(
	mb_strlen( $long_cell ) < 120,
	'...to one line: ' . mb_strlen( $long_cell ) . ' chars, not ' . mb_strlen( (string) wp_json_encode( $long_payload ) )
);
ok( (bool) preg_match( '/\(\S+ chars\)/u', $long_cell ), '...and says how much was elided, so it never reads as complete' );
ok( $short_cell === (string) wp_json_encode( $short_payload ), 'a short payload is untouched, verbatim JSON' );
ok( false === strpos( $short_cell, '…' ), '...with no ellipsis' );

// The clamp must reach the painted table, not just the helper.
$kit_rows = \SignalNoise\OpenStationHost\Dashboard\Leaves\cron_row_data( array( 'hook' => 'snt_rollup', 'args' => $long_payload, 'next_run_ts' => time(), 'last_fired_ts' => time() ) );
ok( mb_strlen( $kit_rows['args'] ) < 120, 'the row builder uses the clamp, not raw wp_json_encode' );

// ── The settings row: one paired row under the ledger, readouts, round trip. ──
$GLOBALS['__cron_rows'] = $rich_rows;

// 12) Layout: exactly one .snt-cols with two .snt-col children, the ledger
// outside it at full width (17.2.1: boxes share a row).
$kit = snt_leaf_paint( 'connections', 'cron', array() );
ok( 1 === substr_count( $kit, 'class="snt-cols"' ), 'exactly one snt-cols row' );
ok( 2 === substr_count( $kit, 'class="snt-col"' ), 'the row holds exactly two snt-col boxes' );
$cols_at  = strpos( $kit, 'class="snt-cols"' );
$table_at = strpos( $kit, '<os-table' );
ok( false !== $table_at && $table_at < $cols_at, 'the events table sits above the row, not inside it' );
ok( false !== strpos( $kit, 'heading="Morning operations brief"' ) && false !== strpos( $kit, 'heading="Scheduled read-only runs"' ), 'both settings boxes carry their classic headings' );

// 13) Readouts absent when their state is absent.
ok( false === strpos( $kit, 'Last sent' ) && false === strpos( $kit, 'Last send failed' ) && false === strpos( $kit, 'settings differ' ) && false === strpos( $kit, 'Last run' ), 'no last-sent, last-error, drift or last-run readout without state' );
ok( false === strpos( $kit, 'Acknowledge current settings' ) && false === strpos( $kit, 'name="snt_config_drift_acknowledge"' ), 'no Acknowledge form without drift' );
ok( 6 === count( snt_leaf_names( $kit ) ), 'six field names without drift: ' . json_encode( snt_leaf_names( $kit ) ) );

// 14) Readouts present with state, and drift ON adds the seventh name on BOTH sides.
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_SENT ]  = time() - 3600;
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_ERROR ] = array( 'message' => 'smtp <b>down</b>' );
$GLOBALS['__options'][ SNT_SCHEDULED_READS_HISTORY ]  = array( array( 'ran_at' => time() - 3600, 'door' => 'read', 'tools' => array( 'a' => array( 'error' => true ), 'b' => array( 'error' => false ) ) ) );
$GLOBALS['__drift'] = array( 'has_drift' => true, 'count' => 2 );
$kit     = snt_leaf_paint( 'connections', 'cron', array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_cron_section' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 7 === count( snt_leaf_names( $kit ) ), 'drift ON: seven names on both sides: ' . json_encode( snt_leaf_names( $kit ) ) );
ok( false !== strpos( $kit, 'Last sent 1 hour ago.' ), 'last-sent hint' );
ok( false !== strpos( $kit, 'Last send failed' ) && false !== strpos( $kit, 'smtp &lt;b&gt;down&lt;/b&gt;' ) && false === strpos( $kit, '<b>down</b>' ), 'last-error notice, message escaped' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '2 settings differ' ), 'drift is a warn notice naming the count' );
ok( strpos( $kit, 'settings differ' ) < strpos( $kit, 'name="snt_morning_brief_enabled"' ), 'the drift notice sits on top of the brief box' );
ok( false !== strpos( $kit, 'Acknowledge current settings' ) && false !== strpos( $kit, 'name="snt_config_drift_acknowledge"' ), 'drift ON paints the Acknowledge form with its differentiator' );
ok( false !== strpos( $kit, 'Last run 1 hour ago: 1 of 2 reads failed.' ), 'last-run hint tallies the errors' );
ok( false !== strpos( $kit, 'Send test brief' ) && false !== strpos( $kit, 'name="snt_morning_brief_test"' ), 'Send test brief form carries its differentiator' );
ok( false !== strpos( $kit, 'Run now</' ) || false !== strpos( $kit, 'submit-label="Run now"' ), 'Run now form paints' );
ok( false !== strpos( $kit, 'name="snt_scheduled_reads_now"' ), 'Run now form carries its differentiator' );
ok( array() === snt_leaf_classic_markers( $kit ), 'the settings row carries no classic markers: ' . json_encode( snt_leaf_classic_markers( $kit ) ) );

// 15) Round trip: painted OFF reads false and expands empty; ON reads true and expands non-empty.
foreach ( array( 'snt_morning_brief_enabled' => 'operations.morning_brief_enabled', 'snt_scheduled_reads_enabled' => 'operations.scheduled_reads_enabled' ) as $field => $setting ) {
	$GLOBALS['__settings'][ $setting ] = false;
	$off = os_form_read_field( snt_leaf_paint( 'connections', 'cron', array() ), $field );
	ok( false === $off && empty( \snt_os_host_expand( array( $field => $off ) )[ $field ] ), "$field painted OFF reads false and expands empty" );
	$GLOBALS['__settings'][ $setting ] = true;
	$on = os_form_read_field( snt_leaf_paint( 'connections', 'cron', array() ), $field );
	ok( true === $on && ! empty( \snt_os_host_expand( array( $field => $on ) )[ $field ] ), "$field painted ON reads true and expands non-empty" );
}

// 16) The handler reads the native OFF (an unchecked os-checkbox-label arrives
// as false, expands to '') as OFF: isset('') is true, ! empty('') is not.
$GLOBALS['__written'] = array();
ok( 'morning_brief_saved' === sn_handle_morning_brief_save( \snt_os_host_expand( array( 'sn_action' => 'morning_brief_save', 'snt_morning_brief_enabled' => false ) ) ) && false === ( $GLOBALS['__written']['operations.morning_brief_enabled'] ?? null ), 'native unchecked brief toggle saves OFF' );
ok( 'morning_brief_saved' === sn_handle_morning_brief_save( \snt_os_host_expand( array( 'sn_action' => 'morning_brief_save', 'snt_morning_brief_enabled' => true ) ) ) && true === ( $GLOBALS['__written']['operations.morning_brief_enabled'] ?? null ), 'native checked brief toggle saves ON' );
ok( 'scheduled_reads_saved' === sn_handle_scheduled_reads_save( \snt_os_host_expand( array( 'sn_action' => 'scheduled_reads_save', 'snt_scheduled_reads_enabled' => false ) ) ) && false === ( $GLOBALS['__written']['operations.scheduled_reads_enabled'] ?? null ), 'native unchecked reads toggle saves OFF' );
ok( 'scheduled_reads_saved' === sn_handle_scheduled_reads_save( \snt_os_host_expand( array( 'sn_action' => 'scheduled_reads_save', 'snt_scheduled_reads_enabled' => true ) ) ) && true === ( $GLOBALS['__written']['operations.scheduled_reads_enabled'] ?? null ), 'native checked reads toggle saves ON' );

// ── 17) The Action Scheduler backlog box: the Site Health reading on the leaf. ──
// snt_asb_snapshot() reads $GLOBALS['wpdb'], which this suite never set: without
// a stub every paint says "not installed" and greens, a silent-green shape. The
// stub mirrors tests/scheduled-actions-health.php: SHOW TABLES answers from
// $table_exists, the GROUP BY returns $status_rows, the overdue COUNT $overdue,
// and every SQL string is recorded so the paint's query count can be pinned.
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
require_once SNT_PATH . 'inc/scheduled-actions-health.php';
class Cron_Leaf_Stub_wpdb {
	public $prefix       = 'wp_';
	public $table_exists = true;
	public $status_rows  = array();
	public $overdue      = 0;
	public $queries      = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$pos = strpos( $sql, '%s' );
			if ( false !== $pos ) { $sql = substr_replace( $sql, "'" . $arg . "'", $pos, 2 ); }
		}
		return $sql;
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== stripos( $sql, 'SHOW TABLES' ) ) { return $this->table_exists ? $this->prefix . 'actionscheduler_actions' : null; }
		return (string) $this->overdue;
	}
	public function get_results( $sql, $output = ARRAY_A ) { $this->queries[] = $sql; return $this->status_rows; }
}
function cron_leaf_backlog_box( $html ) {
	$at = strpos( $html, 'heading="Action Scheduler backlog"' );
	if ( false === $at ) { return ''; }
	$start = strrpos( substr( $html, 0, $at ), '<os-section' );
	$end   = strpos( $html, '</os-section>', $at );
	return substr( $html, $start, $end - $start );
}
$GLOBALS['__cron_rows'] = $rich_rows;
$GLOBALS['__drift']     = array( 'has_drift' => false, 'count' => 0 );
unset( $GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_ERROR ] );

// (a) Counts from the fixture, the box between the ledger and the settings row, no fold, one snapshot.
$db              = new Cron_Leaf_Stub_wpdb();
$db->status_rows = array( array( 'status' => 'pending', 'n' => '12' ), array( 'status' => 'complete', 'n' => '1204' ), array( 'status' => 'failed', 'n' => '8' ) );
$db->overdue     = 3;
$GLOBALS['wpdb'] = $db;
$kit = snt_leaf_paint( 'connections', 'cron', array() );
$box = cron_leaf_backlog_box( $kit );
ok( '' !== $box, 'the backlog box paints under its heading' );
$box_at = strpos( $kit, 'heading="Action Scheduler backlog"' );
ok( strpos( $kit, '<os-table' ) < $box_at && $box_at < strpos( $kit, 'class="snt-cols"' ), 'the box sits under the events ledger and above the settings row' );
ok( false === strpos( $box, '<os-disclosure' ), 'a reading is painted directly, not behind a fold' );
ok( (bool) preg_match( '#<dt class="snt-kv__k">pending</dt><dd class="snt-kv__v">12 \(3 overdue\)</dd>#', $box ), 'pending row: 12 with 3 overdue, no tone under the line' );
ok( false !== strpos( $box, '<dt class="snt-kv__k">complete</dt><dd class="snt-kv__v">1204</dd>' ), 'complete row: raw status label, the raw figure the Info row prints' );
ok( false !== strpos( $box, '<dt class="snt-kv__k">total</dt><dd class="snt-kv__v">1224</dd>' ), 'total row sums every status' );
ok( false === strpos( $box, 'tone="warning"' ), 'a quiet table paints no warning notice' );
ok( 3 === count( $db->queries ), 'one snapshot per paint: 3 queries, not 6: ' . count( $db->queries ) );

// (b) The box adds no field and no action: parity with the classic hook holds.
$classic = snt_leaf_classic_html( 'sn_admin_render_cron_section' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 6 === count( snt_leaf_names( $kit ) ), 'the box adds no field name: ' . json_encode( snt_leaf_names( $kit ) ) );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the box adds no sn_action' );
ok( array() === snt_leaf_classic_markers( $kit ), 'the box carries no classic markers: ' . json_encode( snt_leaf_classic_markers( $kit ) ) );

// (c) Overdue at the line: one warn notice on top of the box, the pending row toned.
$db->overdue = SN_ASB_OVERDUE_WARN;
$db->queries = array();
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( 1 === substr_count( $box, '<os-notice tone="warning"' ) && false !== strpos( $box, '50 pending actions are overdue' ), 'overdue at the line: exactly one warning notice naming the count' );
ok( strpos( $box, '<os-notice' ) < strpos( $box, '<dl class="snt-kv"' ), 'the notice sits on top of the box, above the facts' );
ok( (bool) preg_match( '#<dt class="snt-kv__k">pending</dt><dd class="snt-kv__v" data-tone="warning">#', $box ), 'the pending row carries the warning tone' );

// (d) One under the line: no notice, no tone (boundary).
$db->overdue = SN_ASB_OVERDUE_WARN - 1;
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( false === strpos( $box, '<os-notice' ) && false === strpos( $box, 'data-tone' ), 'one under the overdue line: no notice, no tone' );

// (e) Total at the bloat line: the page-load sentence, the total row toned; both lines: two notices.
$db->status_rows = array( array( 'status' => 'complete', 'n' => (string) SN_ASB_ROWS_WARN ) );
$db->overdue     = 0;
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( 1 === substr_count( $box, '<os-notice tone="warning"' ) && false !== strpos( $box, 'every page load' ), 'total at the bloat line: one warning notice naming the per-page cost' );
ok( (bool) preg_match( '#<dt class="snt-kv__k">total</dt><dd class="snt-kv__v" data-tone="warning">100000</dd>#', $box ), 'the total row carries the warning tone, the same raw figure the notice above it names' );
$db->status_rows = array( array( 'status' => 'pending', 'n' => '80' ), array( 'status' => 'complete', 'n' => (string) SN_ASB_ROWS_WARN ) );
$db->overdue     = SN_ASB_OVERDUE_WARN + 10;
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( 2 === substr_count( $box, '<os-notice tone="warning"' ), 'both lines crossed: two warning notices' );

// (f) The door is gated on the AS UI existing, the Site Health gate. The class
// is declared inside a conditional so PHP binds it at execution time; an
// unconditional class is hoisted and the no-door pin could never fail.
ok( false === strpos( $box, 'tools.php?page=action-scheduler' ), 'no ActionScheduler class: no door to a Tools page that is not there' );
if ( ! class_exists( 'ActionScheduler' ) ) {
	class ActionScheduler {}
}
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( (bool) preg_match( '#<os-button[^>]*os-action="door"[^>]*os-arg-url="[^"]*tools\.php\?page=action-scheduler"[^>]*>Open Scheduled Actions</os-button>#', $box ), 'with ActionScheduler loaded, a door to Scheduled Actions inside the box' );

// (g) Absent table: the box says so, no rows, no notice, one query.
$db->table_exists = false;
$db->queries      = array();
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( false !== strpos( $box, 'Action Scheduler not installed' ) && false === strpos( $box, '<dl class="snt-kv"' ) && false === strpos( $box, '<os-notice' ), 'absent table: the box says not installed, paints no facts and no notice' );
ok( 1 === count( $db->queries ), 'absent table: only the existence probe ran' );

// (h) No wpdb at all: the Info-row degrade, no fatal.
$GLOBALS['wpdb'] = null;
$box = cron_leaf_backlog_box( snt_leaf_paint( 'connections', 'cron', array() ) );
ok( false !== strpos( $box, 'Action Scheduler not installed' ), 'no wpdb: the box degrades to not installed' );

// (i) The no-rows branch paints the box too.
$GLOBALS['__cron_rows'] = array();
$GLOBALS['wpdb']        = $db;
$kit_empty = snt_leaf_paint( 'connections', 'cron', array() );
ok( false !== strpos( $kit_empty, 'heading="Action Scheduler backlog"' ) && strpos( $kit_empty, 'No scheduled events.' ) < strpos( $kit_empty, 'heading="Action Scheduler backlog"' ), 'empty cron: the box still paints, after the empty state' );
$GLOBALS['wpdb'] = null;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
