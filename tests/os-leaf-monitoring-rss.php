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

require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/rss-feed-tracker.php';
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
ok( false !== strpos( $kit, '2026-09-05 12:00:00' ) && false !== strpos( $kit, 'UTC' ), 'the most-recent feed request timestamp is shown' );
ok( false !== strpos( $kit, 'https://example.test/feed/' ) && false !== strpos( $kit, 'abc123' ), 'the recent-requests table carries the fixture rows' );
ok( false !== strpos( $kit, 'https://example.test/comments/feed/' ) && false !== strpos( $kit, 'def456' ), 'a second recent-requests row survives' );
ok( false !== strpos( $kit, 'https://sn-px.workers.dev/_sn/px' ), 'the collector endpoint is shown read-only' );
ok( false !== strpos( $kit, 'name="event_name" type="text" value="RSS Feed Request"' ), 'the event name field carries the stored value' );
ok( false !== strpos( $kit, 'name="log_retention_days"' ) && false !== strpos( $kit, 'value="45"' ), 'the retention field carries the stored value' );
ok( false !== strpos( $kit, 'checked' ) && false !== strpos( $kit, 'name="enabled"' ), 'the enabled switch reflects the stored (on) value' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'Settings saved' ), 'the saved flash paints a success notice' );
ok( 1 === preg_match( '/os-confirm="All RSS tracker settings[^"]*"[^>]*os-confirm-title="Reset RSS tracker to defaults\?"[^>]*os-confirm-label="Reset"/', $kit ), 'the reset form carries its own confirm, title and label' );
ok( 1 === preg_match( '/os-confirm="Log entries older than[^"]*"[^>]*os-confirm-title="Purge old log entries\?"[^>]*os-confirm-label="Purge"[^>]*os-confirm-danger/', $kit ), 'the purge form carries its own confirm, title, label and danger' );
ok( 1 === substr_count( $kit, 'os-confirm-danger' ), 'only the purge form (not reset) carries danger' );
ok( false !== strpos( $kit, 'wp_rss_feed_log' ), 'the settings retention hint names the live log table' );
ok( false !== strpos( $kit, '<os-code>wp_rss_feed_log</os-code>' ), 'the maintenance hint names the live log table via os-code' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
