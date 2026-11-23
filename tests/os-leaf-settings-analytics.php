<?php
/**
 * Settings › Analytics, both twins: the native pane
 * (apps/sn-dashboard/parts/leaves/settings-analytics.php) and the classic
 * section (inc/admin-forms/settings-analytics.php). The sixth pane of the
 * Settings tab.
 *
 * Seven settings moved here as FIELDS from three Measurement leaves
 * (Analytics, Machine Readers, Insights). Every leaf keeps its readings and
 * actions and one line saying where the setting went, so none has a moves
 * line. The three RSS settings did not move: see tests/rss-tracker-admin-post.php.
 *
 * This suite: the registry, the pane's boxes and whole forms, where a save
 * lands, what each handler writes on a plain save. Each leaf a field left has
 * its own suite (tests/os-leaf-monitoring-*.php).
 *
 * Run: php tests/os-leaf-settings-analytics.php
 */
require_once __DIR__ . '/lib/settings-analytics-fixture.php';

sa_seed();
$classic = sa_classic();
$kit     = sa_kit();

// ── 1) The registry: a sixth pane; Measurement keeps every leaf.
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
$panes = (array) ( $by_tab['settings']['sub_tabs'] ?? array() );
ok( array( 'notifications', 'security', 'ai', 'front-end', 'accounts', 'analytics' ) === array_keys( $panes ) && 'Analytics' === ( $panes['analytics']['label'] ?? '' ) && 'sn_admin_render_settings_analytics_section' === ( $panes['analytics']['render'] ?? '' ) && ! empty( $panes['analytics']['wide'] ), 'Settings has six panes, analytics ("Analytics") after accounts, wide: it paints its own boxes' );
ok( array( 'analytics', 'rss', 'machine-readers', 'insights', 'health' ) === array_keys( $by_tab['monitoring']['sub_tabs'] ), 'Measurement keeps every leaf: each holds readings, tables or actions' );
ok( false !== strpos( (string) $by_tab['settings']['subtitle'], 'Analytics: the collector endpoint' ) && false !== strpos( (string) $by_tab['settings']['subtitle'], 'the Search Console credential and property' ) && false !== strpos( (string) $by_tab['monitoring']['subtitle'], 'are in Settings › Analytics' ), 'the subtitles follow: Settings names the pane and the Search Console box, Measurement says where its settings are' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/analytics'] ), 'native: the painter is registered under settings/analytics' );
$here = array( 'tab' => 'settings', 'sub' => 'analytics', 'anchor' => null );
ok( null === sn_admin_canonical_destination( 'settings', 'analytics' ) && $here === sn_admin_post_redirect_target( 'settings', 'analytics' ), 'classic: settings/analytics is canonical and a save posted from it returns to it' );
foreach ( array( 'analytics', 'rss', 'machine-readers', 'insights', 'search-console' ) as $kept ) {
	ok( null === sn_admin_canonical_destination( 'monitoring', $kept ), "Measurement › $kept does not redirect: the leaf stayed" );
}

// ── 2) The pane: seven settings in six boxes, in whole forms, on both twins.
$actions = array( 'analytics_collector_save', 'analytics_exclude_save', 'analytics_funnels_save', 'analytics_tuning_save', 'machine_readers_save', 'save_insights_settings' );
ok( '' !== $classic && array( 'insights_weekly_cron', 'sn_an_collector_url', 'sn_anomaly_sensitivity', 'sn_exclude_roles[]', 'sn_funnels', 'sn_mr_worker_url', 'sn_signal_baseline_days' ) === snt_leaf_names( $classic ), 'classic: exactly the seven field names: ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( '' !== $kit && array( 'insights_weekly_cron', 'sn_an_collector_url', 'sn_anomaly_sensitivity', 'sn_exclude_roles[0]', 'sn_exclude_roles[1]', 'sn_exclude_roles[2]', 'sn_funnels', 'sn_mr_worker_url', 'sn_signal_baseline_days' ) === snt_leaf_names( $kit ), 'native: the same seven, the roles one select each: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( $actions === snt_leaf_actions( $kit ) && $actions === snt_leaf_actions( $classic ), 'the six existing actions, unchanged, on both twins; no test, scan, owner-device or RSS action came along: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<os-disclosure' ) && false === strpos( $classic, '<details' ), 'no fold on either twin: a setting on a settings pane is open; native has no wp-admin markup' );
preg_match_all( '#<h2 class="sn-fieldset-h">([^<]+)</h2>#', $classic, $h_c );
preg_match_all( '#<os-section heading="([^"]+)"#', $kit, $h_k );
ok( array( 'Collector endpoint', 'Exclude my own visits', 'Engine tuning', 'Session funnels', 'Machine readers', 'Insights' ) === $h_c[1] && array( 'Collector endpoint', 'Roles kept out', 'Engine tuning', 'Session funnels', 'Machine readers', 'Insights' ) === $h_k[1] && false === strpos( $classic, '<h3' ), 'six headed boxes by subject on both twins (each twin keeps the title its box had), one heading level on the classic pane' );
$guards = array( 'analytics_tuning_save' => SNT_ANALYTICS_TUNING_SAVE_FIELDS, 'analytics_funnels_save' => SNT_ANALYTICS_FUNNELS_SAVE_FIELDS, 'machine_readers_save' => SNT_MR_SAVE_FIELDS, 'save_insights_settings' => SNT_INSIGHTS_SAVE_FIELDS, 'analytics_collector_save' => array( 'sn_an_collector_url' ) );
foreach ( array( 'form' => $classic, 'os-form' => $kit ) as $tag => $html ) {
	foreach ( $guards as $action => $whole ) {
		ok( 1 === snt_whole_forms( $html, $tag, $action, $whole ), "<$tag>: one form posts $action and it carries every field the handler writes: " . implode( ', ', $whole ) );
	}
	$roles = sa_form( $html, $tag, 'analytics_exclude_save' );
	ok( 1 === count( sa_forms( $html, $tag, 'analytics_exclude_save' ) ) && count( $GLOBALS['__roles'] ) === substr_count( $roles, 'name="sn_exclude_roles[' ), "<$tag>: one form posts analytics_exclude_save and it holds one control per role, all three" );
	$order = array_map( static fn( $slug ) => strpos( $roles, '"' . $slug . '"' ), array_keys( $GLOBALS['__roles'] ) );
	$sorted = $order;
	sort( $sorted );
	ok( ! in_array( false, $order, true ) && $sorted === $order, "<$tag>: the roles are the site's, in the site's order" );
	ok( '' !== $html && false === strpos( $html, 'name="tab"' ) && false === strpos( $html, 'name="sub"' ), "<$tag>: no form carries a hidden tab or sub, so a save lands on the pane it was posted from and not on the old leaf" );
}
ok( 1 === substr_count( $kit, '<os-select name="sn_exclude_roles[0]" value="administrator"><os-option value="">Counted</os-option><os-option value="administrator">Excluded</os-option></os-select>' ) && 1 === substr_count( $kit, '<os-select name="sn_exclude_roles[2]" value=""><os-option value="">Counted</os-option><os-option value="subscriber">Excluded</os-option></os-select>' ), 'native: each role is the Counted / Excluded select it was, an excluded role selected and a counted one on Counted' );
ok( -1 === snt_whole_forms( '<form>sn_analytics_tuning_save<input name="sn_signal_baseline_days"></form>', 'form', 'analytics_tuning_save', SNT_ANALYTICS_TUNING_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_analytics_funnels_save</form>', 'form', 'analytics_funnels_save', SNT_ANALYTICS_FUNNELS_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_machine_readers_save</form>', 'form', 'machine_readers_save', SNT_MR_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_save_insights_settings</form>', 'form', 'save_insights_settings', SNT_INSIGHTS_SAVE_FIELDS ), 'the guard can fail: a tuning form with one field, and a funnels, a sensor and a weekly scan form without theirs, are each -1' );

// ── 3) Why each handler's fields share a form: what a plain save writes.
sn_handle_analytics_exclude_save( array() );
ok( array() === $GLOBALS['__settings']['analytics.exclude_roles'], 'analytics_exclude_save without the roles counts every role again: the list is written whole' );
sn_handle_analytics_tuning_save( array( 'sn_anomaly_sensitivity' => 'strict' ) );
ok( 30 === $GLOBALS['__settings']['analytics.signal_baseline_days'] && 'strict' === $GLOBALS['__settings']['analytics.anomaly_sensitivity'], 'analytics_tuning_save without the baseline sets it back to 30' );
sn_handle_analytics_tuning_save( array( 'sn_signal_baseline_days' => '45' ) );
ok( 45 === $GLOBALS['__settings']['analytics.signal_baseline_days'] && 'standard' === $GLOBALS['__settings']['analytics.anomaly_sensitivity'], 'and without the sensitivity sets it back to standard: the two stay in one form' );
ok( 'analytics_funnels_saved' === sn_handle_analytics_funnels_save( array() ) && array() === $GLOBALS['__settings']['analytics.funnels'], 'analytics_funnels_save without the textarea saves no funnels' );
sn_handle_machine_readers_save( array() );
ok( array( 'worker_url' => '', 'read_token' => 'fake-read-token-for-test' ) === $GLOBALS['__options'][ SN_SETTINGS_OPTION ]['machine_readers'] && array( 'kept' => 1 ) === $GLOBALS['__options'][ SN_SETTINGS_OPTION ]['theme'], 'machine_readers_save without the URL clears it, and leaves the read token and every other subtree as they were' );
sn_handle_save_insights_settings( array() );
ok( false === $GLOBALS['__settings']['insights.weekly_cron_enabled'], 'save_insights_settings without the switch turns the weekly scan off: the one field, written on every save' );
ok( 'analytics_collector_invalid' === sn_handle_analytics_collector_save( array() ) && SA_FAKE_COLLECTOR === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['collector_url'] && 45 === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['log_retention_days'], 'analytics_collector_save resets nothing by omission: no URL is refused, and the tracker keys beside it are kept' );

// ── 4) The two URLs are printed nowhere new: once each, in their own field.
sa_seed();
foreach ( array( 'classic' => sa_classic(), 'native' => sa_kit() ) as $twin => $html ) {
	ok( 1 === substr_count( $html, SA_FAKE_COLLECTOR ) && 1 === substr_count( $html, 'value="' . SA_FAKE_COLLECTOR . '"' ) && 1 === substr_count( $html, SA_FAKE_WORKER ) && 1 === substr_count( $html, 'value="' . SA_FAKE_WORKER . '"' ) && false === strpos( $html, 'fake-read-token' ), "$twin: the collector endpoint and the Worker URL appear once each, as the value of their own field; the read token nowhere" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
