<?php
/**
 * Settings › Notifications and reports, both twins: the native leaf
 * (apps/sn-dashboard/parts/leaves/settings-notifications.php) and the classic
 * section (inc/admin-forms/notifications.php).
 *
 * The pane holds the three email switches that used to sit in Connections ›
 * Cron (morning brief, alerts) and Security › Login defense (weekly digest),
 * each with its hint, its test button and its last-sent lines. Pinned here:
 * the registry entry, the same field names and actions on both twins, the two
 * brief checkboxes in ONE form (sn_handle_morning_brief_save writes both on
 * every plain save), no drift notice (that stays on Cron), the pointer to
 * OS Settings, and where a save lands.
 *
 * Run: php tests/os-leaf-settings-notifications.php
 *
 * @package SignalNoiseTools
 */

require_once __DIR__ . '/lib/os-leaf-harness.php';
require_once __DIR__ . '/lib/brief-forms.php'; // the one-form rule, as a count.

$GLOBALS['__settings'] = array();
$GLOBALS['__written']  = array();
$GLOBALS['__cron']     = array();
$GLOBALS['__drift']    = array( 'has_drift' => true, 'count' => 2 ); // ON throughout: the pane must not show it.
function sn_setting( $key, $default = null ) { return array_key_exists( $key, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $key ] : $default; }
function sn_setting_update( $key, $value ) { $GLOBALS['__settings'][ $key ] = $value; $GLOBALS['__written'][ $key ] = $value; return true; }
function snt_config_drift_status() { return $GLOBALS['__drift']; }
function snt_config_drift_acknowledge() { $GLOBALS['__drift'] = array( 'has_drift' => false, 'count' => 0 ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['__cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['__cron'][ $hook ] = $ts; return true; }
function wp_unschedule_event( $ts, $hook ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_datetime() { return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ); }

require_once SNT_PATH . 'inc/morning-brief.php';
require_once SNT_PATH . 'inc/alerts.php';
require_once SNT_PATH . 'inc/alerts-cron.php';
require_once SNT_PATH . 'inc/security-digest.php';
require_once SNT_PATH . 'inc/admin-legacy-redirect.php';
require_once SNT_PATH . 'inc/admin-post-actions/reports.php';
require_once SNT_PATH . 'inc/openstation-host-pipelines.php';
foreach ( array( 'inc/admin-forms/notifications.php', 'apps/sn-dashboard/parts/leaves/settings-notifications.php' ) as $sn_new ) {
	if ( is_file( SNT_PATH . $sn_new ) ) { // Absent before the change: the assertions go red, the suite still reports.
		require_once SNT_PATH . $sn_new;
	}
}

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

ok( -1 === snt_brief_forms( '<os-form><input name="action" value="sn_morning_brief_save"><i name="snt_alerts_enabled"></i></os-form>', 'os-form' ), 'guard sanity: a brief form with one checkbox is caught' );

// ── 1) The registry: a ninth tab, last, one pane.
$tabs = sn_admin_top_tabs();
$last = end( $tabs );
ok( 9 === count( $tabs ) && 'settings' === ( $last['tab'] ?? '' ) && 'sn-settings' === ( $last['slug'] ?? '' ) && 'Settings' === ( $last['label'] ?? '' ), 'Settings is the ninth top tab, last, on page slug sn-settings' );
$subs = (array) ( $last['sub_tabs'] ?? array() );
ok( 'notifications' === ( array_keys( $subs )[0] ?? '' ) && 'Notifications and reports' === ( $subs['notifications']['label'] ?? '' ) && 'sn_admin_render_notifications_section' === ( $subs['notifications']['render'] ?? '' ), 'its first pane is notifications, labelled "Notifications and reports" (the pane list is pinned in tests/os-leaf-settings-security.php)' );
ok( 1 === count( array_keys( array_column( $tabs, 'slug' ), 'sn-settings', true ) ) && ! in_array( 'sn-settings', array_column( sn_admin_pages(), 'slug' ), true ) && ! isset( sn_admin_legacy_redirect_map()['settings'] ), 'the slug and the tab key collide with no legacy page and no legacy redirect' );

// ── 2) Where a save lands (classic PRG; the window uses the same resolver).
ok( array( 'tab' => 'settings', 'sub' => 'notifications', 'anchor' => null ) === sn_admin_post_redirect_target( 'settings', 'notifications' ), 'a POST from the pane redirects back to settings/notifications' );
ok( null === sn_admin_canonical_destination( 'connections', 'cron' ) && null === sn_admin_canonical_destination( 'security', 'login-defense' ), 'the two old leaves are NOT redirected: only switches moved, the leaves stay (no sn_admin_subtab_moves() entry)' );
ok( 'settings' === sn_admin_page_tab_for_slug( 'sn-settings' ), 'the sidebar slug resolves to the settings tab' );

// ── 3) The native leaf.
$GLOBALS['__settings'] = array( 'operations.morning_brief_enabled' => true, 'operations.alerts_enabled' => false, 'audit.digest_email_enabled' => true );
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_SENT ]   = time() - 3600;
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_ERROR ]  = array( 'message' => 'smtp <b>down</b>' );
$GLOBALS['__options'][ SN_SECURITY_DIGEST_LAST_SENT ]  = time() - 3600;
$GLOBALS['__options'][ SN_SECURITY_DIGEST_LAST_ERROR ] = array( 'message' => '<script>y</script>' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/notifications'] ), 'the painter is registered under settings/notifications' );
$kit     = snt_leaf_paint( 'settings', 'notifications' );
$classic = function_exists( 'sn_admin_render_notifications_section' ) ? snt_leaf_classic_html( 'sn_admin_render_notifications_section' ) : '';
$names   = array( 'sn_digest_enabled', 'sn_digest_test', 'snt_alerts_enabled', 'snt_morning_brief_enabled', 'snt_morning_brief_test' );

ok( $names === snt_leaf_names( $kit ), 'native: the three switches and the two test verbs, nothing else: ' . json_encode( snt_leaf_names( $kit ) ) );
ok( array( 'morning_brief_save', 'security_digest_save' ) === snt_leaf_actions( $kit ), 'native: the two existing actions, unchanged' );
ok( 2 === snt_brief_forms( $kit, 'os-form' ), 'native: two brief forms (Save with BOTH checkboxes, Send test brief), none that would switch one off' );
ok( 1 === preg_match( '#<os-form class="snt-form" os-action="post"[^>]*submit-label="Save"[^>]*>(?:(?!</os-form>).)*name="snt_morning_brief_enabled"(?:(?!</os-form>).)*name="snt_alerts_enabled"(?:(?!</os-form>).)*value="sn_morning_brief_save"#s', $kit ), 'native: both brief checkboxes sit in ONE Save form posting morning_brief_save' );
ok( 1 === preg_match( '/<os-field-row hint="A deterministic prose reading[^"]*"><os-checkbox-label name="snt_morning_brief_enabled"[^>]* checked/', $kit ) && 1 === preg_match( '/<os-field-row hint="Checked hourly[^"]*"><os-checkbox-label name="snt_alerts_enabled" value="1" label="Email an alert when a page spikes or breaks">/', $kit ), 'native: each brief switch is a labelled kit checkbox with its hint, ON painted checked and OFF not' );
preg_match( '/<[^>]*name="sn_digest_enabled"[^>]*>/', $kit, $tag );
ok( isset( $tag[0] ) && false !== strpos( $tag[0], 'os-checkbox-label' ) && false !== strpos( $tag[0], 'label="Email a weekly security digest to the admin address"' ) && false !== strpos( $tag[0], ' checked' ) && false !== strpos( $kit, 'the quiet email is the heartbeat.' ), 'native: the digest switch, labelled, checked, with its hint' );
ok( 1 === preg_match( '#<os-form class="snt-form snt-form--inline"[^>]*submit-label="Send test brief"[^>]*><input type="hidden" name="snt_morning_brief_test" value="1">#', $kit ) && 1 === preg_match( '#<p class="snt-hint">Sends this week’s digest[^<]*</p><os-form class="snt-form snt-form--inline"[^>]*submit-label="Send test digest"[^>]*><input type="hidden" name="sn_digest_test" value="1">#', $kit ), 'native: Send test brief and Send test digest are inline forms carrying their differentiators' );
ok( 2 === substr_count( $kit, 'Last sent <os-relative-time datetime="' ) && 2 === substr_count( $kit, '>1 hour ago</os-relative-time>.' ), 'native: each email has its own Last sent line' );
ok( 2 === substr_count( $kit, 'Last send failed:' ) && false !== strpos( $kit, 'smtp &lt;b&gt;down&lt;/b&gt;' ) && false !== strpos( $kit, '&lt;script&gt;y&lt;/script&gt;' ) && false === strpos( $kit, '<b>down</b>' ) && false === strpos( $kit, '<script>y' ), 'native: each email has its own Last send failed notice, escaped' );
ok( false !== strpos( $kit, 'heading="Morning brief and alerts"' ) && false !== strpos( $kit, 'heading="Weekly security digest"' ), 'native: the two boxes keep their headings' );
ok( false === strpos( $kit, 'settings differ' ) && false === strpos( $kit, 'snt_config_drift_acknowledge' ) && false === strpos( $kit, 'Acknowledge' ), 'native: the drift notice and Acknowledge did NOT move (drift is ON in this fixture)' );
ok( 1 === substr_count( $kit, 'OS Settings › Signal &amp; Noise' ) && false === strpos( $kit, 'alert_notify' ), 'native: one line points at OS Settings › Signal & Noise for in-app alerts, with no control' );
ok( array() === snt_leaf_classic_markers( $kit ), 'native: no wp-admin markup: ' . json_encode( snt_leaf_classic_markers( $kit ) ) );
ok( false === strpos( $kit . $classic, "\xe2\x80\x94" ), 'no em dash in either twin' );

// ── 4) The classic twin: the same fields, the same actions, real labels.
ok( $names === snt_leaf_names( $classic ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'classic: the same names and actions as the native leaf: ' . json_encode( snt_leaf_names( $classic ) ) );
ok( 2 === substr_count( $classic, '<form' ) && 1 === snt_brief_forms( $classic, 'form' ), 'classic: two forms, and the one brief form carries BOTH checkboxes' );
ok( 3 === preg_match_all( '#<label><input type="checkbox" name="(snt_morning_brief_enabled|snt_alerts_enabled|sn_digest_enabled)" value="1"[^>]*/> [^<]+</label>#', $classic ), 'classic: each switch is a checkbox inside its own <label>' );
ok( false !== strpos( $classic, 'name="snt_morning_brief_test"' ) && false !== strpos( $classic, 'name="sn_digest_test"' ) && 2 === substr_count( $classic, 'Last sent 1 hour ago.' ) && 2 === substr_count( $classic, 'Last send failed:' ), 'classic: both test buttons, both Last sent lines, both Last send failed lines' );
ok( false === strpos( $classic, 'Configuration drift' ) && false === strpos( $classic, 'snt_config_drift_acknowledge' ), 'classic: the drift notice and Acknowledge did NOT move' );
ok( 1 === substr_count( $classic, 'OS Settings › Signal &amp; Noise' ), 'classic: the same one line about in-app alerts' );
ok( false === strpos( $classic, '<script>y' ), 'classic: a hostile last-error message is escaped' );
ok( false !== strpos( $classic, 'Sends this week’s digest to the admin address now, whether or not the weekly email is on.' ) && false !== strpos( $kit, 'Sends this week’s digest to the admin address now, whether or not the weekly email is on.' ), 'Send test digest says what it does, on both twins' );
ok( 1 === preg_match( '#^<div class="sn-2up"><form [^>]*class="sn-fieldset">.*</form><form [^>]*class="sn-fieldset">.*</form></div><p class="sn-field-helper">[^<]+</p>$#s', $classic ), 'classic: the two cards sit side by side in .sn-2up, the pointer line after them (the leaf is wide, so no third card wraps them)' );

// ── 5) Round trip through the unchanged handlers.
foreach ( array( 'snt_morning_brief_enabled' => 'operations.morning_brief_enabled', 'snt_alerts_enabled' => 'operations.alerts_enabled', 'sn_digest_enabled' => 'audit.digest_email_enabled' ) as $field => $setting ) {
	foreach ( array( false, true ) as $want ) {
		$GLOBALS['__settings'][ $setting ] = $want;
		preg_match( '/<[^>]*name="' . $field . '"[^>]*>/', snt_leaf_paint( 'settings', 'notifications' ), $t );
		// os-form reads a checkbox-shaped tag's boolean off `checked`; the host expands false to ''.
		$read = isset( $t[0] ) && false !== strpos( $t[0], '<os-checkbox-label' ) ? ( 1 === preg_match( '/\schecked(\s|=|>|$)/', $t[0] ) ) : null;
		ok( $want === $read && $want === ! empty( \snt_os_host_expand( array( $field => $read ) )[ $field ] ), "$field painted " . ( $want ? 'ON reads true and expands non-empty' : 'OFF reads false and expands empty, which the handlers\' ! empty() reads as off' ) );
	}
}
$GLOBALS['__settings'] = array( 'operations.morning_brief_enabled' => true, 'operations.alerts_enabled' => true );
$GLOBALS['__written']  = array();
ok( 'config_drift_acknowledged' === sn_handle_morning_brief_save( \snt_os_host_expand( array( 'snt_config_drift_acknowledge' => '1' ) ) ) && array() === $GLOBALS['__written'], 'the Acknowledge form Cron keeps returns before the switches are written: both stay ON' );
ok( 'morning_brief_saved' === sn_handle_morning_brief_save( \snt_os_host_expand( array( 'snt_morning_brief_enabled' => true, 'snt_alerts_enabled' => false ) ) ) && true === $GLOBALS['__written']['operations.morning_brief_enabled'] && false === $GLOBALS['__written']['operations.alerts_enabled'], 'a plain save writes BOTH switches, which is why they share a form' );

// The owner's look (SNT_LEAF_PREVIEW_DIR): all three ON, sent an hour ago, no failure.
$GLOBALS['__settings'] = array( 'operations.morning_brief_enabled' => true, 'operations.alerts_enabled' => true, 'audit.digest_email_enabled' => true );
unset( $GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_ERROR ], $GLOBALS['__options'][ SN_SECURITY_DIGEST_LAST_ERROR ] );
$quiet = snt_leaf_paint( 'settings', 'notifications' );
ok( false === strpos( $quiet, 'Last send failed' ) && false === strpos( $quiet, '<os-notice' ) && 2 === substr_count( $quiet, 'Last sent' ), 'no failure stored: no Last send failed notice, both Last sent lines still read' );
snt_leaf_preview( 'settings-notifications', $quiet );

// ── 6) Every sentence that sent a reader to the old spots names the pane now.
foreach ( array(
	'inc/alerts.php'               => 'Switch alerts off in Settings › Notifications and reports.',
	'inc/security-digest.php'      => 'Turn it off in Settings › Notifications and reports.',
	'inc/admin-flash-messages.php' => 'the reason is on the Weekly security digest box, Settings › Notifications and reports',
) as $file => $want ) {
	$src = (string) file_get_contents( SNT_PATH . $file );
	ok( false !== strpos( $src, $want ) && false === strpos( $src, 'off in Connections › Cron' ) && false === strpos( $src, 'off in Security › Login defense' ) && false === strpos( $src, 'on the Login defense panel' ), "$file points at the pane, not at the old screen" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
