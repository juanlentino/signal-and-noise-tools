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
ok( array( 'general', 'notifications', 'security', 'ai', 'front-end', 'accounts', 'analytics' ) === array_keys( $panes ) && 'Analytics' === ( $panes['analytics']['label'] ?? '' ) && 'sn_admin_render_settings_analytics_section' === ( $panes['analytics']['render'] ?? '' ) && ! empty( $panes['analytics']['wide'] ), 'Settings has seven panes, analytics ("Analytics") last, after accounts, wide: it paints its own boxes' );
ok( array( 'analytics', 'rss', 'machine-readers', 'insights', 'health' ) === array_keys( $by_tab['monitoring']['sub_tabs'] ), 'Measurement keeps every leaf: each holds readings, tables or actions' );
ok( false !== strpos( (string) $by_tab['settings']['subtitle'], 'Analytics: the collector endpoint' ) && false !== strpos( (string) $by_tab['settings']['subtitle'], 'the Search Console credential and property' ) && false !== strpos( (string) $by_tab['monitoring']['subtitle'], 'are in Settings › Analytics' ), 'the subtitles follow: Settings names the pane and the Search Console box, Measurement says where its settings are' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/analytics'] ), 'native: the painter is registered under settings/analytics' );
$here = array( 'tab' => 'settings', 'sub' => 'analytics', 'anchor' => null );
ok( null === sn_admin_canonical_destination( 'settings', 'analytics' ) && $here === sn_admin_post_redirect_target( 'settings', 'analytics' ), 'classic: settings/analytics is canonical and a save posted from it returns to it' );
foreach ( array( 'analytics', 'rss', 'machine-readers', 'insights', 'search-console' ) as $kept ) {
	ok( null === sn_admin_canonical_destination( 'monitoring', $kept ), "Measurement › $kept does not redirect: the leaf stayed" );
}

// ── 2) The pane: the settings of four leaves, headed boxes, whole forms, on both twins.
$actions = array( 'analytics_collector_save', 'analytics_exclude_save', 'analytics_funnels_save', 'analytics_publish_window_save', 'analytics_tuning_save', 'machine_readers_save', 'north_star_save', 'rss_save_settings', 'save_insights_settings' );
$names   = array( 'enabled', 'event_name', 'insights_weekly_cron', 'log_retention_days', 'sn_an_collector_url', 'sn_anomaly_sensitivity', 'sn_exclude_roles[]', 'sn_funnels', 'sn_mr_worker_url', 'sn_publish_window_minutes', 'sn_signal_baseline_days' );
$nsm     = array( 'sn_nsm_dwell_s', 'sn_nsm_scroll', 'sn_nsm_sections[0]', 'sn_nsm_sections[1]', 'sn_nsm_sections[2]', 'sn_nsm_sections[3]' );
$names   = array_merge( $names, $nsm ); // North star: the same six names on both twins since 2026-11.
sort( $names );
$kit_names = array_merge( array_diff( $names, array( 'sn_exclude_roles[]' ) ), array( 'sn_exclude_roles[0]', 'sn_exclude_roles[1]', 'sn_exclude_roles[2]' ) );
sort( $kit_names );
ok( '' !== $classic && $names === snt_leaf_names( $classic ), 'classic: exactly the seventeen field names, North star\'s six among them: ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( '' !== $kit && $kit_names === snt_leaf_names( $kit ), 'native: the same, the roles one select each: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( $actions === snt_leaf_actions( $classic ) && $actions === snt_leaf_actions( $kit ), 'the same nine save actions on both twins, north_star_save among them; no test, scan, reset or purge action came along: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<os-disclosure' ) && false === strpos( $classic, '<details' ), 'no fold on either twin: a setting on a settings pane is open; native has no wp-admin markup' );
preg_match_all( '#<h2 class="sn-fieldset-h">([^<]+)</h2>#', $classic, $h_c );
preg_match_all( '#<os-section heading="([^"]+)"#', $kit, $h_k );
$titles = snt_settings_analytics_titles();
ok( array_values( $titles ) === $h_c[1] && $h_c[1] === $h_k[1] && array( 'Collector endpoint', 'Roles kept out', 'Engine tuning', 'Session funnels', 'Publish windows', 'North star', 'Feed tracking', 'Machine readers', 'Insights' ) === $h_k[1] && false === strpos( $classic, '<h3' ), 'the same nine headed boxes in the same order on both twins, every heading from the ONE list of titles, one heading level on the classic pane' );
ok( 'Collector endpoint, Roles kept out, Engine tuning, Session funnels and Publish windows moved to' === snt_settings_analytics_moved_text( 'analytics' ) && 'Collector endpoint, Roles kept out, Engine tuning, Session funnels, Publish windows and North star moved to' === snt_settings_analytics_moved_text( 'analytics-native' ), 'the line Measurement › Analytics keeps names each box as the pane titles it, built from the same list' );
$guards = array( 'rss_save_settings' => SNT_RSS_SAVE_FIELDS, 'analytics_publish_window_save' => SNT_PUBLISH_WINDOW_SAVE_FIELDS, 'analytics_tuning_save' => SNT_ANALYTICS_TUNING_SAVE_FIELDS, 'analytics_funnels_save' => SNT_ANALYTICS_FUNNELS_SAVE_FIELDS, 'machine_readers_save' => SNT_MR_SAVE_FIELDS, 'save_insights_settings' => SNT_INSIGHTS_SAVE_FIELDS, 'analytics_collector_save' => array( 'sn_an_collector_url' ), 'north_star_save' => array_merge( SNT_NORTH_STAR_SAVE_FIELDS, array( 'sn_nsm_sections[0]', 'sn_nsm_sections[1]', 'sn_nsm_sections[2]', 'sn_nsm_sections[3]' ) ) );
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
// North star, the twin the classic pane gained in 2026-11: one form, the fields, labels and hint the window has.
$ns_c = sa_form( $classic, 'form', 'north_star_save' );
$ns_k = sa_form( $kit, 'os-form', 'north_star_save' );
ok( '' !== $ns_c && '' !== $ns_k && $nsm === snt_leaf_names( $ns_c ) && $nsm === snt_leaf_names( $ns_k ), 'north star: one form on each twin, and both post the same six names: ' . implode( ',', snt_leaf_names( $ns_c ) ) );
$ns_same = true;
foreach ( array( 'Notes (/notes/)', 'Provenance (/provenance, /provhub)', 'Resume (/resume)', 'About (/about)', 'Read at scroll depth (%)', 'Or after time on page (seconds)', 'Counts', 'Does not count', 'Save north star' ) as $word ) {
	$ns_same = $ns_same && false !== strpos( $ns_c, $word ) && false !== strpos( $ns_k, $word );
}
$ns_words = array( 'Counts Notes; a read is 60% scroll or 45 s on the page.', 'The north star counts human visitors, per day, who read at least one page in the groups this box lists: past the scroll depth OR the time on page. The notes index, its pages, tags and feed never count; a listing is not a read.' );
ok( $ns_same && 1 === substr_count( $classic, $ns_words[0] ) && 1 === substr_count( $kit, $ns_words[0] ) && 1 === substr_count( $classic, $ns_words[1] ) && 1 === substr_count( $kit, $ns_words[1] ), 'north star: every label, both choices, the Save button, the current definition and the hint read the same on both twins' );
ok( 1 === substr_count( $ns_c, '<input type="hidden" name="_wpnonce" value="nonce-sn_north_star_save">' ) && 1 === substr_count( $ns_c, '<button type="submit" name="action" value="sn_north_star_save" class="button button-primary">Save north star</button>' ) && 0 === strpos( $ns_c, '<form method="post" action="' . sn_admin_post_url() . '"' ), 'north star, classic: its own nonce, its action on the Save button, and it posts to the address it was painted on, as the other forms of the pane do' );
preg_match_all( '#<label for="([^"]+)">#', $ns_c, $ns_for );
preg_match_all( '#<(?:select|input type="number") id="([^"]+)" name="(sn_nsm_[^"]+)"#', $ns_c, $ns_id );
ok( 6 === count( $ns_for[1] ) && $ns_for[1] === $ns_id[1] && $nsm === ( static function ( $n ) { sort( $n ); return $n; } )( $ns_id[2] ) && 6 === count( array_unique( $ns_id[1] ) ), 'north star, classic: each of the six controls has its own id and a label for it' );
ok( 1 === substr_count( $ns_c, '<select id="sn_nsm_sections_0" name="sn_nsm_sections[0]"><option value="notes" selected="selected">Counts</option><option value="">Does not count</option></select>' ) && 1 === substr_count( $ns_c, '<select id="sn_nsm_sections_1" name="sn_nsm_sections[1]"><option value="provenance">Counts</option><option value="" selected="selected">Does not count</option></select>' ) && 1 === substr_count( $ns_c, '<input type="number" id="sn_nsm_scroll" name="sn_nsm_scroll" value="60" min="1" max="100" step="1" class="small-text">' ) && 1 === substr_count( $ns_c, '<input type="number" id="sn_nsm_dwell_s" name="sn_nsm_dwell_s" value="45" min="1" max="600" step="1" class="small-text">' ), 'north star, classic: a group that counts is on Counts and one that does not is on Does not count; the two numbers show the saved values inside the handler\'s bounds' );
ok( strpos( $classic, '>North star</h2>' ) > strpos( $classic, '>Publish windows</h2>' ) && strpos( $classic, '>North star</h2>' ) < strpos( $classic, '>Feed tracking</h2>' ) && 1 === substr_count( $classic, '>North star</h2>' ), 'north star, classic: one box, between Publish windows and Feed tracking, where the window has it' );
ok( 1 === substr_count( $kit, '<os-select name="sn_exclude_roles[0]" value="administrator"><os-option value="">Counted</os-option><os-option value="administrator">Excluded</os-option></os-select>' ) && 1 === substr_count( $kit, '<os-select name="sn_exclude_roles[2]" value=""><os-option value="">Counted</os-option><os-option value="subscriber">Excluded</os-option></os-select>' ), 'native: each role is the Counted / Excluded select it was, an excluded role selected and a counted one on Counted' );
ok( -1 === snt_whole_forms( '<form>sn_analytics_tuning_save<input name="sn_signal_baseline_days"></form>', 'form', 'analytics_tuning_save', SNT_ANALYTICS_TUNING_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_analytics_funnels_save</form>', 'form', 'analytics_funnels_save', SNT_ANALYTICS_FUNNELS_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_machine_readers_save</form>', 'form', 'machine_readers_save', SNT_MR_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_save_insights_settings</form>', 'form', 'save_insights_settings', SNT_INSIGHTS_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_north_star_save<input name="sn_nsm_scroll"><select name="sn_nsm_sections[0]"></select></form>', 'form', 'north_star_save', array_merge( SNT_NORTH_STAR_SAVE_FIELDS, array( 'sn_nsm_sections[0]' ) ) ), 'the guard can fail: a tuning form with one field, a north star form without its time on page, and a funnels, a sensor and a weekly scan form without theirs, are each -1' );

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
sn_handle_north_star_save( array( 'sn_nsm_sections' => array( 'notes', '', 'resume', '' ), 'sn_nsm_scroll' => '70', 'sn_nsm_dwell_s' => '20' ) );
ok( array( 'sections' => array( 'notes', 'resume' ), 'scroll' => 70, 'dwell_s' => 20 ) === $GLOBALS['__settings']['north_star'], 'north_star_save reads the classic form\'s post as it reads the window\'s: one value per group, empty for a group that does not count' );
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

// ── 6) This device: on the pane beside Roles kept out, on both twins, for a user whose role is excluded.
ok( false === strpos( $classic . $kit, 'sn_owner_device' ) && false === strpos( $classic . $kit, 'This device is' ), 'a user whose role is counted is offered no device control on either twin' );
$GLOBALS['__owner_excluded'] = true;
foreach ( array( false => array( 'This device is counted when logged out.', 'Exclude this device', 'mark' ), true => array( 'This device is excluded even when logged out.', 'Count this device again', 'forget' ) ) as $flagged => $say ) {
	$GLOBALS['__device_flagged'] = (bool) $flagged;
	$dev_c  = sa_classic();
	$dev_k  = sa_kit();
	$box_c  = (string) substr( $dev_c, (int) strpos( $dev_c, '>Roles kept out</h2>' ), (int) strpos( $dev_c, '>Engine tuning</h2>' ) - (int) strpos( $dev_c, '>Roles kept out</h2>' ) );
	$box_k  = (string) substr( $dev_k, (int) strpos( $dev_k, '<os-section heading="Roles kept out"' ), (int) strpos( $dev_k, '<os-section heading="Engine tuning"' ) - (int) strpos( $dev_k, '<os-section heading="Roles kept out"' ) );
	$form_k = sa_form( $box_k, 'os-form', 'analytics_owner_device' );
	ok( 1 === substr_count( $dev_c, $say[0] ) && 1 === substr_count( $box_c, $say[0] ) && 1 === substr_count( $dev_k, $say[0] ) && 1 === substr_count( $box_k, $say[0] ), "excluded role, device $say[2]: \"$say[0]\" is said once on each twin, in the Roles kept out box" );
	ok( 1 === preg_match( '#<a href="[^"]*sn_owner_device=' . $say[2] . '[^"]*_wpnonce=nonce-sn_owner_device">' . $say[1] . '</a>#', $box_c ), "classic: the nonce'd link \"$say[1]\" it always was (sn_owner_device=$say[2])" );
	ok( '' !== $form_k && false !== strpos( $form_k, 'submit-label="' . $say[1] . '"' ) && false !== strpos( $form_k, 'name="sn_owner_device" value="' . $say[2] . '"' ) && false !== strpos( $box_k, '<os-cluster gap="8" class="snt-actions"><p class="snt-hint">' . $say[0] . '</p><os-form class="snt-form snt-form--inline"' ), "native: one form posts analytics_owner_device=$say[2], labeled \"$say[1]\", its line and compact button on one row as they were on Measurement" );
	ok( strpos( $box_k, 'sn_analytics_owner_device' ) > strpos( $box_k, 'sn_analytics_exclude_save' ) && 1 === count( sa_forms( $box_k, 'os-form', 'analytics_exclude_save' ) ) && false === strpos( sa_form( $box_k, 'os-form', 'analytics_exclude_save' ), 'sn_owner_device' ), 'native: the device form follows the roles form and is not inside it, so Save exclusion never posts the device verb' );
	$with = array_merge( $actions, array( 'analytics_owner_device' ) );
	sort( $with );
	ok( $with === snt_leaf_actions( $dev_k ) && $actions === snt_leaf_actions( $dev_c ), 'native gains the one action analytics_owner_device and nothing else; the classic link is a GET, so its actions are the nine' );
}
$GLOBALS['__owner_excluded'] = false;
$GLOBALS['__device_flagged'] = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
