<?php
/**
 * Native window leaf: Monitoring → RSS (apps/sn-dashboard/parts/leaves/monitoring-rss.php).
 *
 * The oracle is the classic leaf (`sn_admin_render_rss_section()` →
 * `sn_rss_tracker_render_admin_tab()`). This is the ONE leaf on the Monitoring
 * tab whose form field is `sn_rss_action` (not `sn_action`) and whose nonce
 * action is `SN_RSS_TRACKER_NONCE` — so beyond the shared name/action oracles
 * the harness provides, this suite also pins the `sn_rss_action` values
 * directly, matching classic to kit.
 *
 * Run: php tests/os-leaf-monitoring-rss.php
 */
require_once __DIR__ . '/lib/site-timezone-stub.php'; // 14.7.4: the site's zone, not UTC
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// wp_parse_args isn't in the shared harness; sn_rss_tracker_settings() needs it.
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
}

/**
 * A minimal $wpdb the classic reader functions can run against. get_row() /
 * get_results() ignore the SQL and return whatever the fixture set — the
 * fixture is the single source of truth both the classic renderer AND the
 * kit painter read (through the SAME sn_rss_tracker_* functions), so their
 * outputs are naturally comparable.
 */
class SNT_Test_RSS_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public function get_row( $sql, $output = null ) { return $GLOBALS['__rss_row']; }
	public function get_results( $sql, $output = null ) { return $GLOBALS['__rss_recent']; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function query( $sql ) { return 0; }
	public function insert( $table, $data, $format = null ) { return 1; }
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb']        = new SNT_Test_RSS_Wpdb();
$GLOBALS['__rss_row']    = array(
	'most_recent' => null,
	'total_1'     => 0,
	'uniq_1'      => 0,
	'total_7'     => 0,
	'uniq_7'      => 0,
	'total_30'    => 0,
	'uniq_30'     => 0,
);
$GLOBALS['__rss_recent'] = array();

require SNT_PATH . 'inc/admin-glance.php'; // 2026-10-12: the classic tiles the feed reach rides
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/rss-feed-tracker.php';
require SNT_PATH . 'inc/feed-subscribers.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-rss.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Every `name="sn_rss_action"` value in a markup blob. */
function rss_actions( $html ) {
	preg_match_all( '/name=(["\'])sn_rss_action\1[^>]*value=(["\'])([^"\']+)\2/', (string) $html, $m );
	$out = array_values( array_unique( $m[3] ) );
	sort( $out );
	return $out;
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/rss'] ), 'the painter is registered under monitoring/rss' );

// ── Configured state: settings saved, activity + recent rows present.
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ] = array(
	'enabled'            => true,
	'collector_url'      => 'https://sn-px.workers.dev/_sn/px',
	'event_name'         => 'RSS Feed Request',
	'log_retention_days' => 45,
);
$GLOBALS['__rss_row']    = array(
	'most_recent' => '2026-09-05 12:00:00',
	'total_1'     => 3,
	'uniq_1'      => 2,
	'total_7'     => 41,
	'uniq_7'      => 9,
	'total_30'    => 210,
	'uniq_30'     => 33,
);
$GLOBALS['__rss_recent'] = array(
	array( 'ts' => '2026-09-05 12:00:00', 'ua_hash' => 'abc123', 'feed_url' => 'https://example.test/feed/' ),
	array( 'ts' => '2026-09-05 11:00:00', 'ua_hash' => 'def456', 'feed_url' => 'https://example.test/comments/feed/' ),
);
$_GET['sn_rss_ok'] = 'saved';

$classic = snt_leaf_classic_html( 'sn_admin_render_rss_section' );
$kit     = snt_leaf_paint( 'monitoring', 'rss', array( 'params' => array( 'sn_rss_ok' => 'saved' ) ) );

ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'purge_log', 'reset_defaults', 'save_settings' ) === rss_actions( $classic ), 'classic offers all three sn_rss_action values' );
ok( rss_actions( $classic ) === rss_actions( $kit ), 'sn_rss_action values match the classic form: ' . implode( ',', rss_actions( $kit ) ) . ' (classic: ' . implode( ',', rss_actions( $classic ) ) . ')' );
ok( array() === snt_leaf_actions( $classic ) && array() === snt_leaf_actions( $kit ), 'neither carries the SHARED sn_action field name — this leaf bypasses that pipeline on both sides' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'os-arg-pipeline="rss"' ), 'the forms declare the rss pipeline for the host replay' );

// ── Readouts: activity counters, most-recent line, recent rows, settings.
ok( false !== strpos( $kit, '<os-stat value="3"' ) && false !== strpos( $kit, '2 unique' ), '24-hour count and uniques are shown' );
ok( false !== strpos( $kit, '<os-stat value="41"' ) && false !== strpos( $kit, '9 unique' ), '7-day count and uniques are shown' );
ok( false !== strpos( $kit, '<os-stat value="210"' ) && false !== strpos( $kit, '33 unique' ), '30-day count and uniques are shown' );
// 2026-10-12 audit: the qualifier rode the label and wrapped in capitals; the
// label is one word, the qualifier the tile's caption.
foreach ( array( 'Subscribers' => 'reported by readers, a ceiling', 'Opens' => 'at least; many readers block images', 'Clicks' => 'visits from feed links' ) as $label => $note ) {
	ok( 1 === preg_match( '/<os-stat value="[^"]*" label="' . $label . '" caption="7 days · ' . preg_quote( $note, '/' ) . '"/', $kit ), "feed reach tile is labelled $label, its qualifier the caption" );
	ok( false !== strpos( $classic, '<p class="sn-glance-card__label">' . $label . '</p>' ), "the classic page shows the $label tile too (it had none)" );
}
ok( false !== strpos( $classic, 'Feed reach is three different measures, never added together.' ), 'the classic page says the feed reach sentence' );
// 14.7.4: the tracker stores 2026-09-05 12:00:00 UTC; the owner reads Eastern,
// in the site's own date and time format (2026-10-12 audit: one format).
ok( false !== strpos( $kit, 'September 5, 2026 8:00 am' ) && false !== strpos( $classic, 'September 5, 2026 8:00 am' ) && false === strpos( $classic, '</code> UTC' ), 'the most-recent feed request is shown in the SITE timezone and format, on both surfaces (the classic printed UTC)' );
ok( false !== strpos( $kit, 'Client (hashed)' ) && false !== strpos( $classic, 'Client (hashed)' ) && false === strpos( $kit, 'DB writes' ) && false === strpos( $classic, 'DB writes' ), 'the client column says it is a hash; the switch hint is plain words, on both surfaces' );
ok( false === strpos( $kit, '12:00:00' ) && false === strpos( $kit, 'Time (UTC)' ), 'and nowhere as UTC: the column is Time, the value carries its zone' );
ok( false !== strpos( $kit, 'https://example.test/feed/' ) && false !== strpos( $kit, 'abc123' ), 'the recent-requests table carries the fixture rows' );
ok( false !== strpos( $kit, 'https://example.test/comments/feed/' ) && false !== strpos( $kit, 'def456' ), 'a second recent-requests row survives' );
ok( false !== strpos( $kit, 'https://sn-px.workers.dev/_sn/px' ), 'the collector endpoint is shown read-only' );
ok( 1 === preg_match( '/<os-text-field name="event_name"[^>]*type="text" value="RSS Feed Request"/', $kit ), 'the event name field carries the stored value' );
// 2026-10-12: a required kit field carries `required` on the control, where os-form validates it.
ok( 1 === preg_match( '/<os-text-field name="event_name"[^>]*\brequired\b/', $kit ), 'the required event name field carries required on the control itself' );
ok( false !== strpos( $kit, 'name="log_retention_days"' ) && false !== strpos( $kit, 'value="45"' ), 'the retention field carries the stored value' );
ok( false !== strpos( $kit, 'checked' ) && false !== strpos( $kit, 'name="enabled"' ), 'the enabled switch reflects the stored (on) value' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'Settings saved' ), 'the saved flash paints a success notice' );
ok( 1 === preg_match( '/os-confirm="Tracking, the event name and log retention return to their defaults\. The collector endpoint on Measurement › Analytics is kept\."[^>]*os-confirm-title="Reset RSS tracker to defaults\?"[^>]*os-confirm-label="Reset"/', $kit ), 'the reset form carries its own confirm (which says the collector endpoint is kept), title and label' );
ok( false !== strpos( $kit, 'Delete rows older than the number of days you enter.' ) && false === strpos( $kit, 'threshold below' ), 'the purge helper names no position (PR #2014 review)' );
ok( 1 === preg_match( '/os-confirm="Log entries older than[^"]*"[^>]*os-confirm-title="Purge old log entries\?"[^>]*os-confirm-label="Purge"[^>]*os-confirm-danger/', $kit ), 'the purge form carries its own confirm, title, label and danger' );
ok( 1 === substr_count( $kit, 'os-confirm-danger' ), 'only the purge form (not reset) carries danger' );
ok( false !== strpos( $kit, 'wp_rss_feed_log' ), 'the settings retention hint names the live log table' );
ok( false !== strpos( $kit, '<os-code>wp_rss_feed_log</os-code>' ), 'the maintenance hint names the live log table via os-code' );

// ── 2026-10-24: the change in counting is labeled on both surfaces. Core now
// answers a feed's 304 and the tracker counts it, so a step up from that day
// is measurement, not growth. No line before the first counted request.
$sn_304_line = 'Conditional fetches (304) are counted since 2026-10-24 (UTC), so a step up from that day is measurement, not growth.';
ok( false === strpos( $classic, 'Conditional fetches (304)' ) && false === strpos( $kit, 'Conditional fetches (304)' ), 'no 304 line before the first counted request' );
$GLOBALS['__options']['sn_rss_tracker_304_since'] = '2026-10-24';
$classic304 = snt_leaf_classic_html( 'sn_admin_render_rss_section' );
$kit304     = snt_leaf_paint( 'monitoring', 'rss' );
ok( false !== strpos( $classic304, $sn_304_line ), 'the wp-admin RSS view says since when 304s are counted' );
ok( false !== strpos( $kit304, $sn_304_line ), 'the OpenStation twin says the same line' );
$GLOBALS['__options']['sn_rss_tracker_304_since'] = 'not-a-date';
ok( false === strpos( snt_leaf_paint( 'monitoring', 'rss' ), 'Conditional fetches (304)' ), 'a stored value that is not a date prints no line' );
unset( $GLOBALS['__options']['sn_rss_tracker_304_since'] );

// ── Load-bearing token oracle: any classic sentence/hint/code value the
// classic leaf prints must survive the port, checked against BOTH sides so
// the oracle stays honest if the classic itself changes.
foreach ( array( 'wp_rss_feed_log', 'RSS Feed Request', 'https://sn-px.workers.dev/_sn/px', 'Reset RSS tracker to defaults?', 'Purge old log entries?' ) as $needle ) {
	ok( false !== strpos( $classic, $needle ) && false !== strpos( $kit, $needle ), "classic token survives the port: $needle" );
}

// ── Escaping: a hostile event name / feed URL never reach the markup raw.
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['event_name'] = '"><script>x</script>';
$GLOBALS['__rss_recent'][0]['feed_url'] = 'https://example.test/"><script>y</script>';
$kit = snt_leaf_paint( 'monitoring', 'rss' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile event name / feed URL is escaped' );
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['event_name'] = 'RSS Feed Request';
$GLOBALS['__rss_recent'][0]['feed_url'] = 'https://example.test/feed/';

// ── Empty state: no requests logged yet, module disabled, no flash.
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['enabled'] = false;
$GLOBALS['__rss_row']    = array(
	'most_recent' => null,
	'total_1'     => 0,
	'uniq_1'      => 0,
	'total_7'     => 0,
	'uniq_7'      => 0,
	'total_30'    => 0,
	'uniq_30'     => 0,
);
$GLOBALS['__rss_recent'] = array();
unset( $_GET['sn_rss_ok'] );

$classic = snt_leaf_classic_html( 'sn_admin_render_rss_section' );
$kit     = snt_leaf_paint( 'monitoring', 'rss' );
ok( false !== strpos( $kit, 'No feed requests logged yet' ), 'the empty activity state is shown' );
ok( false !== strpos( $kit, 'No requests logged yet' ), 'the empty recent-requests state is shown' );
ok( false === strpos( $kit, 'checked' ), 'the disabled state has no checked switch' );
ok( false === strpos( $kit, 'tone="success"' ) || false === strpos( $kit, 'Settings saved' ), 'no flash notice paints without sn_rss_ok' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names still match in the empty/disabled state' );

// ── Purge flash state.
$kit = snt_leaf_paint( 'monitoring', 'rss', array( 'params' => array( 'sn_rss_ok' => 'purged-12' ) ) );
ok( false !== strpos( $kit, 'Purged' ) && false !== strpos( $kit, '12' ), 'the purged-N flash reports the count' );


// ── Leaf anatomy (2026-10-16, docs/kit-leaf-anatomy.md). ──
// One row: Activity (the status line on top) over the Setup card, beside
// the Recent requests ledger it is read against. Settings and Maintenance
// are Setup folds whose closed lines state the setting; Reset is compact.
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['enabled'] = true;
$kitP = snt_leaf_paint( 'monitoring', 'rss' );
ok( 1 === substr_count( $kitP, snt_leaf_row() ), 'anatomy: one paired row -- ' . substr_count( $kitP, snt_leaf_row() ) );
preg_match_all( '/<os-section heading="([^"]+)"/', $kitP, $h );
ok( array( 'Activity', 'Setup', 'Recent requests' ) === $h[1], 'anatomy: Activity, then Setup under it, beside Recent requests -- ' . implode( ' | ', $h[1] ) );
ok( 1 === preg_match( '/^' . preg_quote( snt_leaf_row(), '/' ) . '<os-stack gap="18"><os-section heading="Activity"[^>]*><div class="snt-status-line">.*?Tracking on.*?Keeps 45 days/s', $kitP ), 'anatomy: the leaf opens on the row, Activity leads with the status line (tracking on, retention)' );
ok( 1 === preg_match( '/<os-disclosure heading="Settings" hint="On · RSS Feed Request · 45 days"[^>]*data-snt-open="0"[^>]*>.*name="event_name".*name="log_retention_days"/s', $kitP ), 'anatomy: Settings is a closed Setup fold whose summary is tracking, event and retention' );
ok( 1 === preg_match( '/<os-disclosure heading="Maintenance" hint="[^"]*45 days[^"]*"[^>]*data-snt-open="0"[^>]*>.*name="purge_days"/s', $kitP ), 'anatomy: Maintenance (the purge form) is a closed Setup fold that names the daily prune' );
ok( 1 === preg_match( '/<os-form class="snt-form snt-form--inline"[^>]*submit-label="Reset to Defaults"/', $kitP ) && 1 === substr_count( $kitP, 'snt-form--inline' ), 'anatomy: Reset to Defaults is the one compact inline form' );
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['enabled'] = false;
$kitP = snt_leaf_paint( 'monitoring', 'rss' );
ok( 1 === preg_match( '/<div class="snt-status-line"><os-cluster gap="8"><os-badge tone="warning"[^>]*>Tracking off<\/os-badge>/', $kitP ) && false !== strpos( $kitP, 'hint="Off · RSS Feed Request · 45 days"' ), 'anatomy: tracking off is a warning chip on the status line, and the Settings fold says Off' );
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['event_name'] = '';
$kitP = snt_leaf_paint( 'monitoring', 'rss' );
ok( 1 === preg_match( '/<os-disclosure heading="Settings"[^>]*\sopen[^>]*data-snt-open="1"/', $kitP ), 'anatomy: the Settings fold opens itself while the required event name is empty' );
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['event_name'] = 'RSS Feed Request';
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
