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
		'next_run_ts'    => time() + 1000000,
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
		'next_run_ts'    => time() + 1000500,
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
		'next_run_ts'    => time() + 1001000,
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
// #1607: the classic Heartbeat client's last-fired refresh is the runtime's
// os-poll (Experimental, OpenStation 1.1.10), gated on `sn_watch`: the two
// settings forms under the ledger carry kit checkboxes whose `checked` the
// morph re-syncs on every tick, focused or not, so the leaf polls only while
// they are folded away.
ok( false === strpos( $kit, 'os-action="refresh"' ) && false === strpos( $kit, 'os-poll' ), 'the default paint has no ghost Refresh and no poll: a tick would revert an unsaved settings checkbox' );
ok( false !== strpos( $kit, '<div class="snt-watch"><span class="snt-hint">Watch the ledger refresh every 30 seconds; the forms fold while it does.</span><os-button variant="ghost" os-action="go" os-arg-sub="cron" os-arg-sn_watch="1">Watch live</os-button></div>' ), 'the watch bar offers a Watch live go that lands back on this leaf with sn_watch=1' );
$kit_watch = snt_leaf_paint( 'connections', 'cron', array( 'params' => array( 'sn_watch' => '1' ) ) );
ok( 1 === substr_count( $kit_watch, '<span os-action="poll" os-poll="30000" hidden></span>' ) && false === strpos( $kit_watch, 'os-action="refresh"' ), 'watching: one hidden os-poll trigger on the no-op poll action, every 30 s, never on refresh' );
ok( array() === snt_leaf_actions( $kit_watch ) && false === strpos( $kit_watch, '<os-form' ) && false === strpos( $kit_watch, 'snt_morning_brief_enabled' ), 'watching: both settings forms are folded away, so no checkbox exists for a tick to revert' );
ok( false !== strpos( $kit_watch, 'heading="Scheduled events"' ) && false !== strpos( $kit_watch, 'os-action="cron_run"' ) && false !== strpos( $kit_watch, 'heading="Cron at a glance"' ) && false !== strpos( $kit_watch, '<os-button variant="ghost" os-action="go" os-arg-sub="cron">Stop watching</os-button>' ), 'watching: the ledger with its controls and the glance row still paint, and a Stop watching go without sn_watch unfolds the forms' );
ok( 1 === preg_match( '/<os-field-row hint="A deterministic prose reading[^"]*"><os-checkbox-label name="snt_morning_brief_enabled"/', $kit ) && 1 === preg_match( '/<os-field-row hint="Read door only[^"]*"><os-checkbox-label name="snt_scheduled_reads_enabled"/', $kit ), '#1600: each toggle\'s helper is the hint of the field row around the checkbox, the layout every other field already has' );
ok( '<os-field-row hint="x"><os-checkbox-label name="t" value="1" label="T"></os-checkbox-label></os-field-row>' === \snt_kit_field( 'checkbox', 't', 'T', false, array( 'hint' => 'x' ) ) && '<os-checkbox-label name="t" value="1" label="T"></os-checkbox-label>' === \snt_kit_field( 'checkbox', 't', 'T' ), '#1600: snt_kit_field( checkbox ) with a hint wraps the control in an os-field-row carrying hint=; without one it stays the bare control' );

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

// 7) Run now / Unschedule are CONTROLS per row (#1604): kit buttons on the
// app's cron_run / cron_unschedule actions carrying the row's hook and args,
// the shell's confirm dialog before each (danger-styled on Unschedule), the
// classic button's disabled state with its reason as the title. Red on 17.4.4,
// where the two cells were the strings "Available" / "Locked".
ok( 3 === substr_count( $kit, 'os-action="cron_run"' ) && 3 === substr_count( $kit, 'os-action="cron_unschedule"' ), 'every row carries a Run now and an Unschedule button on the app`s two cron actions' );
ok( false === strpos( $kit, 'Available' ), 'no "Available" string cell remains: the state is the control, not a word under a heading' );
ok( 1 === preg_match( '/<os-button[^>]*os-action="cron_run"[^>]*os-arg-hook="other_plugin_cron"[^>]*os-arg-args="\[\]"[^>]*>Run now<\/os-button>/', $kit, $m ) && false === strpos( $m[0], 'disabled' ) && false !== strpos( $m[0], 'os-confirm="Run other_plugin_cron now?' ), 'foreign hook: Run now is enabled, carries the hook and its [] args, and asks first' );
ok( 1 === preg_match( '/<os-button[^>]*os-action="cron_run"[^>]*os-arg-hook="sn_cache_sweep"[^>]*>/', $kit, $m ) && false !== strpos( $m[0], ' disabled' ) && false !== strpos( $m[0], 'title="Not runnable here' ), 'SN-owned+handled hook: Run now is disabled with the not-runnable reason as its title' );
ok( 1 === preg_match( '/<os-button[^>]*os-action="cron_run"[^>]*os-arg-hook="some_orphan_hook"[^>]*>/', $kit, $m ) && false !== strpos( $m[0], ' disabled' ) && false !== strpos( $m[0], 'title="No handler' ), 'orphan hook: Run now is disabled with the no-handler reason as its title' );
ok( 1 === preg_match( '/<os-button[^>]*os-action="cron_unschedule"[^>]*os-arg-hook="sn_cache_sweep"[^>]*>/', $kit, $m ) && false !== strpos( $m[0], ' disabled' ) && false !== strpos( $m[0], 'title="Locked' ), 'SN-owned hook: Unschedule is disabled with the locked reason as its title' );
ok( 1 === preg_match( '/<os-button[^>]*os-action="cron_unschedule"[^>]*os-arg-hook="some_orphan_hook"[^>]*>/', $kit, $m ) && false === strpos( $m[0], 'disabled' ) && false !== strpos( $m[0], 'os-confirm-danger' ) && false !== strpos( $m[0], 'os-arg-args="{&quot;foo&quot;:&quot;bar&quot;}"' ), 'orphan hook: Unschedule is enabled, danger-confirmed, and carries the row`s args JSON so the impl matches the scheduled signature' );
ok( 3 === substr_count( $kit, 'os-confirm-danger' ) && 6 === substr_count( $kit, 'os-confirm="' ), 'every Unschedule is danger-confirmed and every control asks before dispatching' );
ok( false === strpos( $kit, '<os-table' ) && 4 === substr_count( $kit, '<li class="snt-list__row"' ) && 3 === substr_count( $kit, ' os-key="' ), 'the ledger is a list of rows (a control cannot ride an os-table cell, upstream #862): one header row, three keyed rows the poll morph moves rather than rebuilds' );

// 8) Recurrence + args readouts.
ok( false !== strpos( $kit, 'hourly (1 hour)' ), 'recurrence: hourly with interval' );
ok( false !== strpos( $kit, 'single event' ), 'recurrence: single event for the orphan' );
// human_time_diff() is stubbed to always return '1 hour' (see lib/os-leaf-harness.php)
// regardless of args, so the 86400s interval prints the same literal as the
// 3600s one above — pin that exact string, not a hedge that also passes on a
// wrong interval.
ok( false !== strpos( $kit, 'daily (1 hour)' ), 'recurrence: daily with its 86400s interval' );
ok( false !== strpos( $kit, 'foo' ) && false !== strpos( $kit, 'bar' ), 'args JSON for the orphan row carries foo/bar' );

// 8b) Column labels: every classic <th> heading survives as the header row's cells.
foreach ( array( 'Hook', 'Next run', 'Recurrence', 'Last fired', 'Args', 'Run now', 'Unschedule' ) as $lbl ) {
	ok( false !== strpos( $kit, '">' . $lbl . '</span>' ), "column label present: $lbl" );
}

// 8c) The classic #sn-cron-filter substring filter is a search field above
// the ledger bound to the app's `filter` state key (#1604): a keystroke is
// the framework's built-in `set`, the repaint keeps the rows whose hook
// contains the text, case-insensitively, as the classic keystroke handler
// did. Red on 17.4.4 (an os-table column filter) and on the first cut of
// #1604 (no filter at all).
ok( 1 === preg_match( '/<os-text-field[^>]*>/', $kit, $m ) && false !== strpos( $m[0], 'type="search"' ) && false !== strpos( $m[0], 'os-bind="filter"' ) && false !== strpos( $m[0], ' clearable' ) && false !== strpos( $m[0], 'placeholder="Filter by hook name"' ) && false !== strpos( $m[0], 'label="Filter cron events by hook name"' ) && false !== strpos( $m[0], ' hide-label' ), 'the classic #sn-cron-filter twin: one search field bound to the filter key, clearable, the classic placeholder, the classic screen-reader label hidden' );
ok( false === strpos( $kit, '&quot;filter&quot;:&quot;text&quot;' ) && strpos( $kit, '<os-text-field' ) < strpos( $kit, 'snt-list--ledger' ), 'it is not an os-table column filter, and it sits above the ledger' );
$kit_f = snt_leaf_paint( 'connections', 'cron', array( 'filter' => 'ORPHAN' ) );
ok( 1 === substr_count( $kit_f, ' os-key="' ) && false !== strpos( $kit_f, 'os-key="some_orphan_hook|' ) && false === strpos( $kit_f, 'os-arg-hook="sn_cache_sweep"' ) && 2 === substr_count( $kit_f, '<li class="snt-list__row"' ), 'filter "ORPHAN": only the orphan row survives, matched case-insensitively, under the header row' );
ok( false !== strpos( $kit_f, 'value="ORPHAN"' ) && false !== strpos( $kit_f, '3 scheduled events.' ), 'the field repaints with its text (the morph keeps the focused value) and the count line still counts every scheduled event' );
$kit_f = snt_leaf_paint( 'connections', 'cron', array( 'filter' => 'nothing-like-this' ) );
ok( 0 === substr_count( $kit_f, ' os-key="' ) && 1 === substr_count( $kit_f, '<li class="snt-list__row"' ) && false !== strpos( $kit_f, '<li class="snt-list__empty">No hook matches the filter.</li>' ), 'a filter no hook contains leaves the header row and says so' );
ok( 3 === substr_count( snt_leaf_paint( 'connections', 'cron', array( 'filter' => '   ' ) ), ' os-key="' ), 'whitespace is no filter' );

// 8e) The ledger's seven cells per row share seven column tracks (#1604):
// a plain .snt-list__row is a content-sized flex row, so a header cell and
// the value under it never share an x. The list is the grid, each row a
// subgrid of it. Pinned in the stylesheet with its comments stripped, the
// way tests/os-grid-auto-fit.php reads the same sheet.
ok( 1 === substr_count( $kit, '<div class="snt-ledger"><ul class="snt-list snt-list--ledger">' ), 'the ledger list wears the ledger modifier inside a scrolling wrapper' );
$sheet = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' ) );
ok( 1 === preg_match( '/\.snt-list--ledger\s*\{([^}]*)\}/', $sheet, $m ) && false !== strpos( $m[1], 'display: grid' ) && 1 === preg_match( '/grid-template-columns:\s*minmax\( 8em, 2fr \) repeat\( 4, minmax\( 4em, 1fr \) \) max-content max-content/', $m[1] ), 'os-app.css: the ledger list is a grid of seven tracks (hook flexes, four readings share, two controls take their width)' );
ok( 1 === preg_match( '/\.snt-list--ledger \.snt-list__row\s*\{([^}]*)\}/', $sheet, $m ) && false !== strpos( $m[1], 'grid-template-columns: subgrid' ) && false !== strpos( $m[1], 'grid-column: 1 / -1' ), 'os-app.css: each row is a subgrid spanning the tracks, so every cell sits in the column its header names' );
ok( 1 === preg_match( '/\.snt-list--ledger \.snt-list__label,\s*\.snt-list--ledger \.snt-list__value\s*\{([^}]*)\}/', $sheet, $m ) && false !== strpos( $m[1], 'max-width: none' ) && false !== strpos( $m[1], 'text-align: left' ), 'os-app.css: the 60% value cap and the right alignment of a two-cell row are lifted inside the ledger' );
ok( 1 === preg_match( '/\.snt-ledger\s*\{([^}]*)\}/', $sheet, $m ) && false !== strpos( $m[1], 'overflow-x: auto' ), 'os-app.css: the wrapper scrolls sideways under the ledger`s minimum width, the classic .snt-scroll-table' );

// 8d) next_run / last_fired cell values, not just non-empty output — a
// negative-control mutation that deleted both columns from the painter
// passed this suite before these two assertions existed.
ok( 3 === substr_count( $kit, '>in 1 hour</os-relative-time>)' ) && 3 === preg_match_all( '#\d\d:\d\d:\d\d \(<os-relative-time datetime="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ">in 1 hour#', $kit ), '#1596: every row prints a next-run os-relative-time, the signed server reading as its fallback' );
ok( false !== strpos( $kit, '>1 hour ago</os-relative-time>)' ) && false !== strpos( $kit, '—' ), 'last fired prints an os-relative-time for fired rows and the em dash for the never-fired row' );

// 8e) #1596: a next_run_ts already behind now paints the same os-relative-time
// with a "%s ago" fallback; the #1222 "overdue" reversal lives in the
// component's signed output, not in the leaf. Against 17.4.3 the leaf paints
// "1 hour overdue" and no os-relative-time.
$saved_rows            = $GLOBALS['__cron_rows'];
$GLOBALS['__cron_rows'] = array( array( 'hook' => 'sn_stalled', 'args_signature' => 'sig-s', 'next_run_ts' => time() - 7200, 'schedule' => 'hourly', 'interval_s' => 3600, 'args' => array(), 'last_fired_ts' => 0, 'has_handler' => true, 'is_sn_owned' => true ) );
$kit                    = snt_leaf_paint( 'connections', 'cron' );
ok( false !== strpos( $kit, '(<os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>)' ) && false === strpos( $kit, 'overdue' ) && false === strpos( $kit, 'in 1 hour' ), '#1596: a past next run is an os-relative-time with a "1 hour ago" fallback; neither "1 hour overdue" nor "in 1 hour" in the leaf' );
$GLOBALS['__cron_rows'] = $saved_rows;
$kit                    = snt_leaf_paint( 'connections', 'cron' );

// 9) The helper sentence with the right count.
ok( false !== strpos( $kit, '3 scheduled events' ), 'helper sentence carries the plural count' );

// 10) Hostile fixture: a hook name with markup is escaped, never raw.
$GLOBALS['__cron_rows'] = array(
	array(
		'hook'           => '<script>alert(1)</script>',
		'args_signature' => 'sig-x',
		'next_run_ts'    => time() + 1000000,
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
ok( false === strpos( $kit_empty, 'os-action="refresh"' ) && false !== strpos( $kit_empty, 'os-arg-sn_watch="1">Watch live</os-button>' ), 'empty cron snapshot carries the watch bar too, so the ledger can be watched once events are restored' );
ok( false !== strpos( $classic_empty, 'No scheduled events.' ), 'classic empty state: heading (sanity check on the fixture)' );
ok( false !== strpos( $kit_empty, 'wp_version_check' ), 'kit empty state: names the core hooks WP schedules at install' );
ok( array() === snt_leaf_classic_markers( $kit_empty ), 'empty state carries no classic markup either' );


// ── The Args cell is clamped so one payload cannot claim the table. ──
// Measured live 2026-09-10: an analytics rollup event carried 1315 characters of
// JSON, and under `table-layout: auto` that single unbreakable cell took 2668px
// of a 3369px table -- every other column collapsed to its minimum and the
// timestamps wrapped onto four lines. The list row's ellipsis now bounds the
// cell on screen; the clamp keeps the painted markup one line.
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
$table_at = strpos( $kit, 'heading="Scheduled events"' );
ok( false !== $table_at && $table_at < $cols_at, 'the events ledger sits above the row, not inside it' );
ok( false !== strpos( $kit, 'heading="Morning operations brief"' ) && false !== strpos( $kit, 'heading="Scheduled read-only runs"' ), 'both settings boxes carry their classic headings' );

// 13) Readouts absent when their state is absent.
ok( false === strpos( $kit, 'Last sent' ) && false === strpos( $kit, 'Last send failed' ) && false === strpos( $kit, 'settings differ' ) && false === strpos( $kit, 'Last run' ), 'no last-sent, last-error, drift or last-run readout without state' );
ok( false === strpos( $kit, 'Acknowledge current settings' ) && false === strpos( $kit, 'name="snt_config_drift_acknowledge"' ), 'no Acknowledge form without drift' );
ok( 4 === count( snt_leaf_names( $kit ) ), 'four field names without drift: ' . json_encode( snt_leaf_names( $kit ) ) );

// 14) Readouts present with state, and drift ON adds the seventh name on BOTH sides.
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_SENT ]  = time() - 3600;
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_ERROR ] = array( 'message' => 'smtp <b>down</b>' );
$GLOBALS['__options'][ SNT_SCHEDULED_READS_HISTORY ]  = array( array( 'ran_at' => time() - 3600, 'door' => 'read', 'tools' => array( 'a' => array( 'error' => true ), 'b' => array( 'error' => false ) ) ) );
$GLOBALS['__drift'] = array( 'has_drift' => true, 'count' => 2 );
$kit     = snt_leaf_paint( 'connections', 'cron', array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_cron_section' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 5 === count( snt_leaf_names( $kit ) ), 'drift ON: five names on both sides: ' . json_encode( snt_leaf_names( $kit ) ) );
ok( false !== strpos( $kit, 'Last sent <os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>.' ), 'last-sent hint is an os-relative-time' );
ok( false !== strpos( $kit, 'Last send failed' ) && false !== strpos( $kit, 'smtp &lt;b&gt;down&lt;/b&gt;' ) && false === strpos( $kit, '<b>down</b>' ), 'last-error notice, message escaped' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '2 settings differ' ), 'drift is a warn notice naming the count' );
ok( strpos( $kit, 'settings differ' ) < strpos( $kit, 'name="snt_morning_brief_enabled"' ), 'the drift notice sits on top of the brief box' );
ok( false !== strpos( $kit, 'Acknowledge current settings' ) && false !== strpos( $kit, 'name="snt_config_drift_acknowledge"' ), 'drift ON paints the Acknowledge form with its differentiator' );
ok( false !== strpos( $kit, 'Last run <os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>: 1 of 2 reads failed.' ), 'last-run hint tallies the errors' );
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
ok( strpos( $kit, 'heading="Scheduled events"' ) < $box_at && $box_at < strpos( $kit, 'class="snt-cols"' ), 'the box sits under the events ledger and above the settings row' );
ok( false === strpos( $box, '<os-disclosure' ), 'a reading is painted directly, not behind a fold' );
ok( (bool) preg_match( '#<dt class="snt-kv__k">pending</dt><dd class="snt-kv__v">12 \(3 overdue\)</dd>#', $box ), 'pending row: 12 with 3 overdue, no tone under the line' );
ok( false !== strpos( $box, '<dt class="snt-kv__k">complete</dt><dd class="snt-kv__v">1204</dd>' ), 'complete row: raw status label, the raw figure the Info row prints' );
ok( false !== strpos( $box, '<dt class="snt-kv__k">total</dt><dd class="snt-kv__v">1224</dd>' ), 'total row sums every status' );
ok( false === strpos( $box, 'tone="warning"' ), 'a quiet table paints no warning notice' );
ok( 3 === count( $db->queries ), 'one snapshot per paint: 3 queries, not 6: ' . count( $db->queries ) );

// (b) The box adds no field and no action: parity with the classic hook holds.
$classic = snt_leaf_classic_html( 'sn_admin_render_cron_section' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 4 === count( snt_leaf_names( $kit ) ), 'the box adds no field name: ' . json_encode( snt_leaf_names( $kit ) ) );
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
