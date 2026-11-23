<?php
/**
 * Settings › Analytics, both twins: the native pane
 * (apps/sn-dashboard/parts/leaves/settings-analytics.php) and the classic
 * section (inc/admin-forms/settings-analytics.php). The sixth pane of the
 * Settings tab.
 *
 * Its settings moved here as FIELDS from four Measurement leaves (Analytics,
 * RSS, Machine Readers, Insights). Every leaf keeps its readings and actions
 * and one line saying where the settings went, so none has a moves line. The
 * RSS save's landing is tests/rss-tracker-admin-post.php.
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

// ── 2) The pane: the settings of four leaves, headed boxes, whole forms, on both twins.
$actions = array( 'analytics_collector_save', 'analytics_exclude_save', 'analytics_funnels_save', 'analytics_publish_window_save', 'analytics_tuning_save', 'machine_readers_save', 'rss_save_settings', 'save_insights_settings' );
$names   = array( 'enabled', 'event_name', 'insights_weekly_cron', 'log_retention_days', 'sn_an_collector_url', 'sn_anomaly_sensitivity', 'sn_exclude_roles[]', 'sn_funnels', 'sn_mr_worker_url', 'sn_publish_window_minutes', 'sn_signal_baseline_days' );
$nsm     = array( 'sn_nsm_dwell_s', 'sn_nsm_scroll', 'sn_nsm_sections[0]', 'sn_nsm_sections[1]', 'sn_nsm_sections[2]', 'sn_nsm_sections[3]' );
$kit_names = array_merge( array_diff( $names, array( 'sn_exclude_roles[]' ) ), array( 'sn_exclude_roles[0]', 'sn_exclude_roles[1]', 'sn_exclude_roles[2]' ), $nsm );
sort( $kit_names );
ok( '' !== $classic && $names === snt_leaf_names( $classic ), 'classic: exactly the eleven field names: ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( '' !== $kit && $kit_names === snt_leaf_names( $kit ), 'native: the same, the roles one select each, plus North star\'s (it never had a classic form): ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( $actions === snt_leaf_actions( $classic ) && array_merge( array_slice( $actions, 0, 6 ), array( 'north_star_save' ), array_slice( $actions, 6 ) ) === snt_leaf_actions( $kit ), 'the existing save actions, unchanged, on both twins; no test, scan, reset, purge or owner-device action came along: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<os-disclosure' ) && false === strpos( $classic, '<details' ), 'no fold on either twin: a setting on a settings pane is open; native has no wp-admin markup' );
preg_match_all( '#<h2 class="sn-fieldset-h">([^<]+)</h2>#', $classic, $h_c );
preg_match_all( '#<os-section heading="([^"]+)"#', $kit, $h_k );
$titles = snt_settings_analytics_titles();
ok( array_values( array_diff_key( $titles, array( 'north_star' => 1 ) ) ) === $h_c[1] && array_values( $titles ) === $h_k[1] && array( 'Collector endpoint', 'Roles kept out', 'Engine tuning', 'Session funnels', 'Publish windows', 'North star', 'Feed tracking', 'Machine readers', 'Insights' ) === $h_k[1] && false === strpos( $classic, '<h3' ), 'headed boxes by subject, every heading from the ONE list of titles on both twins (the native pane has North star too), one heading level on the classic pane' );
ok( 'Collector endpoint, Roles kept out, Engine tuning, Session funnels and Publish windows moved to' === snt_settings_analytics_moved_text( 'analytics' ) && 'Collector endpoint, Roles kept out, Engine tuning, Session funnels, Publish windows and North star moved to' === snt_settings_analytics_moved_text( 'analytics-native' ), 'the line Measurement › Analytics keeps names each box as the pane titles it, built from the same list' );
$guards = array( 'rss_save_settings' => SNT_RSS_SAVE_FIELDS, 'analytics_publish_window_save' => SNT_PUBLISH_WINDOW_SAVE_FIELDS, 'analytics_tuning_save' => SNT_ANALYTICS_TUNING_SAVE_FIELDS, 'analytics_funnels_save' => SNT_ANALYTICS_FUNNELS_SAVE_FIELDS, 'machine_readers_save' => SNT_MR_SAVE_FIELDS, 'save_insights_settings' => SNT_INSIGHTS_SAVE_FIELDS, 'analytics_collector_save' => array( 'sn_an_collector_url' ) );
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
ok( 1 === snt_whole_forms( $kit, 'os-form', 'north_star_save', array_merge( SNT_NORTH_STAR_SAVE_FIELDS, array( 'sn_nsm_sections[0]', 'sn_nsm_sections[3]' ) ) ), 'native: one form posts north_star_save and it carries every page group, the scroll depth and the time on page' );
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
sn_handle_analytics_publish_window_save( array() );
ok( 60 === $GLOBALS['__settings']['analytics.publish_window_minutes'], 'analytics_publish_window_save without the length sets it back to 60' );
sn_handle_north_star_save( array( 'sn_nsm_scroll' => '60' ) );
ok( array( 'sections' => array_keys( snt_nsm_sections() ), 'scroll' => 60, 'dwell_s' => 30 ) === $GLOBALS['__settings']['north_star'], 'north_star_save writes all three as one setting: without the groups every group counts, without the time it is 30 seconds' );
sn_handle_save_insights_settings( array() );
ok( false === $GLOBALS['__settings']['insights.weekly_cron_enabled'], 'save_insights_settings without the switch turns the weekly scan off: the one field, written on every save' );
ok( 'analytics_collector_invalid' === sn_handle_analytics_collector_save( array() ) && SA_FAKE_COLLECTOR === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['collector_url'] && 45 === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['log_retention_days'], 'analytics_collector_save resets nothing by omission: no URL is refused, and the tracker keys beside it are kept' );

// ── 4) The two URLs are printed nowhere new: once each, in their own field.
sa_seed();
foreach ( array( 'classic' => sa_classic(), 'native' => sa_kit() ) as $twin => $html ) {
	ok( 1 === substr_count( $html, SA_FAKE_COLLECTOR ) && 1 === substr_count( $html, 'value="' . SA_FAKE_COLLECTOR . '"' ) && 1 === substr_count( $html, SA_FAKE_WORKER ) && 1 === substr_count( $html, 'value="' . SA_FAKE_WORKER . '"' ) && false === strpos( $html, 'fake-read-token' ), "$twin: the collector endpoint and the Worker URL appear once each, as the value of their own field; the read token nowhere" );
}

// ── 5) A feed tracking save answers with the tracker's own notice, on the pane.
$_GET['sn_rss_ok'] = 'saved';
$said_c = sa_classic();
unset( $_GET['sn_rss_ok'] );
$said_k = snt_leaf_paint( 'settings', 'analytics', array( 'params' => array( 'sn_rss_ok' => 'saved' ) ) );
ok( false !== strpos( $said_c, 'Settings saved.' ) && false !== strpos( $said_k, 'Settings saved.' ) && false === strpos( sa_classic() . sa_kit(), 'Settings saved.' ), 'the pane paints the tracker\'s "Settings saved." when the save\'s landing carries sn_rss_ok, on both twins, and not otherwise' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
