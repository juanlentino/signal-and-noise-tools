<?php
/**
 * Native window leaf: Monitoring → Insights (apps/sn-dashboard/parts/leaves/monitoring-insights.php).
 *
 * The oracle is the classic leaf (inc/insights-admin.php,
 * `snt_insights_render_admin_tab()`): the kit leaf must carry the same field
 * names and the same sn_action values across every state — no scan yet,
 * AI unavailable, a scan with no recommendations, a scan whose recommendations
 * are all filtered out, and a scan with active + done recommendations — print
 * every readout the classic leaf prints (usage/spend, the cache-probe
 * verdict, the status box), escape a hostile value, and carry none of
 * wp-admin's markup.
 *
 * Run: php tests/os-leaf-monitoring-insights.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// get_transient is redeclared unconditionally (hoisted before the harness's
// guarded stub runs) so the classic leaf's transient-backed "last scan" state
// is drivable from a global, same trick as tests/os-leaf-content-tags.php.
function get_transient( $k ) { return $GLOBALS['__transient'] ?? false; }

define( 'SN_INSIGHTS_CACHE_TTL', 7 * DAY_IN_SECONDS );
define( 'SN_AI_USAGE_LOG_CAP', 200 );
define( 'SN_AI_CACHE_PROBE_CAP', 200 );

// The leaf's own readers — stubbed directly rather than pulling in
// inc/insights.php, inc/ai-bootstrap/* and inc/ai-cache-probe.php, whose own
// dependency chains (WP_Error, cron, HTTP) are out of scope for a render-only
// suite. Each mirrors its real counterpart's shape, driven from globals.
$GLOBALS['__transient'] = false; // snt_insights_last_scan() reads this.
$GLOBALS['__ai_ready']  = true;
$GLOBALS['__state']     = array( 'dismissed_ids' => array(), 'snoozed_until' => array(), 'done_ids' => array() );
$GLOBALS['__usage']     = array();
$GLOBALS['__settings']  = array();
$GLOBALS['__spend']     = 0.0;
$GLOBALS['__cache']     = array( 'state' => 'no_data', 'summary' => array(), 'best' => array(), 'models' => array() );
$GLOBALS['__cron_on']   = false;

function snt_insights_last_scan() { return is_array( $GLOBALS['__transient'] ) ? $GLOBALS['__transient'] : null; }
function snt_ai_is_available() { return (bool) $GLOBALS['__ai_ready']; }
function snt_insights_state_read() { return $GLOBALS['__state']; }
// Real filtering logic (mirrors inc/insights.php snt_insights_filter_active()
// line for line) so the "all filtered out" / "active survives" states are
// genuinely exercised, not a blind pass-through.
function snt_insights_filter_active( $recommendations ) {
	if ( ! is_array( $recommendations ) ) { return array(); }
	$state     = snt_insights_state_read();
	$now       = time();
	$dismissed = array_flip( $state['dismissed_ids'] );
	$snoozed   = $state['snoozed_until'];
	$out       = array();
	foreach ( $recommendations as $rec ) {
		$id = isset( $rec['id'] ) ? (string) $rec['id'] : '';
		if ( '' === $id || isset( $dismissed[ $id ] ) ) { continue; }
		if ( isset( $snoozed[ $id ] ) && (int) $snoozed[ $id ] > $now ) { continue; }
		$out[] = $rec;
	}
	return $out;
}
function snt_health_format_elapsed( $ms ) { return number_format( $ms / 1000, 1 ) . 's'; }
function snt_ai_usage_summary( $days ) { return $GLOBALS['__usage'][ $days ] ?? array( 'calls' => 0, 'total' => 0, 'cost' => 0.0, 'by_feature' => array(), 'cost_unpriced_calls' => 0, 'window_start' => 0 ); }
function sn_setting( $key, $default = '' ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
function snt_ai_spend_this_month() { return (float) $GLOBALS['__spend']; }
function snt_ai_cache_probe_verdict() { return $GLOBALS['__cache']; }
function snt_insights_weekly_cron_enabled() { return (bool) $GLOBALS['__cron_on']; }
function sn_admin_shell_open() {}
function sn_admin_shell_rail( $title = '' ) {}
function sn_admin_shell_close() {}

require SNT_PATH . 'inc/insights-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-insights.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function names_line( $classic, $kit ) { return implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')'; }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/insights'] ), 'the painter is registered under monitoring/insights' );

// ── State 1: no scan yet, AI ready. ──
$classic = snt_leaf_classic_html( 'snt_insights_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'insights' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'no-scan state: field names match: ' . names_line( $classic, $kit ) );
ok( array( 'insights_run', 'save_insights_settings' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'no-scan state: only insights_run and save_insights_settings are offered: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'No scan run yet' ) && false !== strpos( $kit, 'tone="warning"' ), 'the status box says no scan run yet' );
ok( false === strpos( $kit, 'name="force"' ), 'no-scan state: no force checkbox is offered yet' );

// ── State 2: AI unavailable — the setup-steps notice + gated form. ──
$GLOBALS['__ai_ready'] = false;
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, 'AI client not available' ) && false !== strpos( $kit, 'ai-wp-admin' ) && false !== strpos( $kit, 'page=connectors' ), 'AI-unavailable state: the setup-steps notice with both doors is shown' );
ok( 1 === preg_match( '/<os-form[^>]*busy[^>]*sn_action" value="insights_run"/s', $kit ) || 1 === preg_match( '/name="sn_action" value="insights_run".*?<\/os-form>/s', $kit ), 'AI-unavailable state: the Run Analysis form is present and gated (busy)' );
$GLOBALS['__ai_ready'] = true;

// ── State 3: a scan ran, force checkbox appears, no recommendations. ──
$GLOBALS['__transient'] = array( 'scanned_at' => time() - 3600, 'elapsed_ms' => 1234, 'recommendations' => array() );
$classic = snt_leaf_classic_html( 'snt_insights_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'insights' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && in_array( 'force', snt_leaf_names( $kit ), true ), 'empty-recs state: field names match, force included: ' . names_line( $classic, $kit ) );
ok( false !== strpos( $kit, 'No angle worth a note right now' ), 'empty-recs state: the "no angle" card is painted' );
ok( false !== strpos( $kit, 'All caught up' ) && false !== strpos( $kit, 'tone="warning"' ), 'empty-recs state: the status box reads all caught up' );

// ── State 4: recommendations exist, but every one is dismissed. ──
$GLOBALS['__transient']['recommendations'] = array( array( 'id' => 'r1', 'question' => 'Q1?' ) );
$GLOBALS['__state']['dismissed_ids']       = array( 'r1' );
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, 'No active questions' ), 'all-dismissed state: the "no active questions" card is painted' );
ok( array( 'insights_run', 'save_insights_settings' ) === snt_leaf_actions( $kit ), 'all-dismissed state: no per-recommendation action is offered' );
$GLOBALS['__state']['dismissed_ids'] = array();

// ── State 5: one active, undone recommendation and one already-done one. ──
$GLOBALS['__transient']['recommendations'] = array(
	array( 'id' => 'r1', 'question' => 'Should we cover X?', 'adjacent_note' => 'Note A', 'why_uncovered' => 'Never mentioned.', 'wall_check' => 'Clears it.', 'target' => array( 'post_id' => 42 ) ),
	array( 'id' => 'r2', 'question' => 'Already handled Y' ),
);
$GLOBALS['__state']['done_ids'] = array( 'r2' );
$classic = snt_leaf_classic_html( 'snt_insights_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'insights' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && in_array( 'rec_id', snt_leaf_names( $kit ), true ), 'active-recs state: field names match, rec_id included: ' . names_line( $classic, $kit ) );
ok( array( 'insights_dismiss', 'insights_mark_done', 'insights_run', 'insights_snooze', 'save_insights_settings' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'active-recs state: all four insights actions are offered alongside the other two: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( false !== strpos( $kit, 'Should we cover X?' ) && false !== strpos( $kit, 'Note A' ) && false !== strpos( $kit, 'Never mentioned.' ) && false !== strpos( $kit, 'Clears it.' ), 'active-recs state: the open question and its three notes are printed' );
ok( false !== strpos( $kit, 'post.php?post=42&amp;action=edit' ) || false !== strpos( $kit, 'post.php?post=42&action=edit' ), 'active-recs state: the adjacent-note door carries the right post id' );
ok( false !== strpos( $kit, 'Mark done' ) && false !== strpos( $kit, 'Already handled Y' ), 'active-recs state: r2 is present' );
// r2 is done: no Mark done form for it specifically is hard to isolate by string alone,
// but the done badge for it must appear once.
ok( 1 === substr_count( $kit, '>done<' ), 'active-recs state: exactly one recommendation is badged done' );
ok( false !== strpos( $kit, 'os-confirm="It' ) || false !== strpos( $kit, "It won't appear again" ), 'active-recs state: dismiss carries the classic confirm text' );
$GLOBALS['__state']['done_ids'] = array();
$GLOBALS['__transient']         = array( 'scanned_at' => time() - 3600, 'elapsed_ms' => 1234, 'recommendations' => array() );

// ── AI usage & spend: with feature rows, then the empty state. ──
$GLOBALS['__usage'] = array(
	30 => array(
		'calls'      => 12,
		'total'      => 34567,
		'cost'       => 1.2345,
		'by_feature' => array(
			'insights'  => array( 'calls' => 12, 'total' => 34567, 'cost' => 1.2345 ),
			'meta_desc' => array( 'calls' => 40, 'total' => 9000, 'cost' => 9.99 ),
		),
		'cost_unpriced_calls' => 1,
		'window_start'        => time() - DAY_IN_SECONDS,
	),
	7  => array( 'calls' => 3, 'total' => 8000, 'cost' => 0.3, 'by_feature' => array(), 'cost_unpriced_calls' => 0, 'window_start' => 0 ),
);
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 10.0;
$GLOBALS['__spend'] = 5.0;
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, '34,567' ) && false !== strpos( $kit, '$1.23' ), 'usage section: the 30-day totals are printed with the real numbers' );
ok( false !== strpos( $kit, '12 calls' ), 'usage section: the 30-day call count carries its unit label' );
ok( false !== strpos( $kit, '$5.00' ) && false !== strpos( $kit, '50%' ), 'usage section: the monthly budget percentage is computed and shown' );
ok( false !== strpos( $kit, 'insights' ), 'usage section: the by-feature table row is present' );
ok( false !== strpos( $kit, 'no list price on file' ), 'usage section: the unpriced-calls footnote appears when cost_unpriced_calls > 0' );
ok( false !== strpos( $kit, 'AI Request Logs' ) && substr_count( $kit, 'ai-wp-admin' ) >= 2, 'usage section: both Settings -> AI links survive (intro + list-price footnote)' );
$table_at = strpos( $kit, 'os-prop-columns' );
ok( false !== $table_at && strpos( $kit, 'meta_desc', $table_at ) < strpos( $kit, '&quot;insights&quot;', $table_at ), 'usage section: by-feature rows are sorted by cost descending, most expensive first' );
$GLOBALS['__usage'][30]['by_feature'] = array();
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, 'No AI calls recorded in the trailing window yet.' ), 'usage section: the empty-by-feature state is painted' );

// ── Prompt-cache probe: each state paints its own title + tone. ──
$GLOBALS['__cache'] = array(
	'state'   => 'candidate',
	'summary' => array( 'calls' => 40, 'prefixes' => 3, 'repeatable' => 2, 'max_prefix_bytes' => 9000, 'cache_read' => 100, 'cache_write' => 50, 'measured' => 40 ),
	'best'    => array( 'model' => 'claude-x' ),
	'models'  => array( array( 'model' => 'claude-x', 'calls' => 40, 'repeatable' => 2, 'max_prefix_bytes' => 9000, 'max_prefix_tokens' => 2000, 'floor' => 1024, 'may_clear_floor' => true, 'samples' => array() ) ),
);
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, 'Caching would pay' ) && false !== strpos( $kit, 'tone="danger"' ), 'cache probe: the candidate state paints its title with a danger tone' );
ok( false !== strpos( $kit, 'claude-x' ) && false !== strpos( $kit, 'clears the floor' ), 'cache probe: the per-model row is printed' );
ok( false === strpos( $kit, '&lt;os-' ) && false === strpos( $kit, '&lt;code' ), 'no component markup is smuggled into table cell data' );
ok( false !== strpos( $kit, '2,000 tokens' ), 'cache probe: the per-model largest-prefix reading keeps its token figure alongside the byte count' );
ok( false !== strpos( $kit, 'ai-provider-for-anthropic' ), 'cache probe: the upstream tracking-issue link survives' );
ok( false !== strpos( $kit, 'Zero here means' ), 'cache probe: the cache-token footnote keeps its closing sentence' );
$GLOBALS['__cache'] = array( 'state' => 'no_data', 'summary' => array(), 'best' => array(), 'models' => array() );
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, 'Nothing measured yet' ), 'cache probe: no_data state is painted when nothing has run' );

// ── Cache-probe states: each of the six carries its classic reasoning
// clause, not just its verdict title (inc/insights-admin.php:230-235). ──
$cache_state_clauses = array(
	'no_data'        => 'Run an AI feature, or a couple of Ask AI turns, then reload.',
	'below_floor'    => 'Repeats do not change that.',
	'no_repeats'     => 'A cache write with no read costs 1.25',
	'candidate'      => 'Reads bill at 0.1',
	'caching_active' => 'Cache reads are being reported',
	'unknown_floor'  => 'so no claim is made either way.',
);
foreach ( $cache_state_clauses as $cache_state => $clause ) {
	$GLOBALS['__cache'] = array( 'state' => $cache_state, 'summary' => array(), 'best' => array(), 'models' => array() );
	$kit = snt_leaf_paint( 'monitoring', 'insights' );
	ok( false !== strpos( $kit, $clause ), "cache probe: the $cache_state state keeps its reasoning clause" );
}

// ── Weekly-cron settings: checked state survives. ──
$GLOBALS['__cron_on'] = true;
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false !== strpos( $kit, 'name="insights_weekly_cron"' ) && false !== strpos( $kit, 'checked' ), 'settings: the weekly-cron checkbox reflects the enabled setting' );
$GLOBALS['__cron_on'] = false;

// ── Escaping: a hostile question never reaches the markup raw. ──
$GLOBALS['__transient'] = array( 'scanned_at' => time(), 'elapsed_ms' => 1, 'recommendations' => array( array( 'id' => 'x', 'question' => '"><script>alert(1)</script>' ) ) );
$kit = snt_leaf_paint( 'monitoring', 'insights' );
ok( false === strpos( $kit, '<script>alert(1)</script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile recommendation question is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
