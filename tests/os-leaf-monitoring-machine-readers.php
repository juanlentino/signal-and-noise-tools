<?php
/**
 * Native window leaf: Monitoring → Machine Readers
 * (apps/sn-dashboard/parts/leaves/monitoring-machine-readers.php).
 *
 * The oracle is the classic leaf (inc/machine-readers-admin.php,
 * `snt_mr_render_tab()`): the kit painting must carry the same field names
 * and the same sn_action as the classic form, plus every readout the classic
 * composer prints for a rich fixture, and none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-monitoring-machine-readers.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own readers: network + settings + the feed tracker, faked.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return is_array( $v ) && ! empty( $v['__wp_error'] ); }
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $u ) { return true; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $e = 0 ) { return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = false ) { $GLOBALS['__options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && ! empty( $r['__wp_error'] ) ? 0 : 200; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$fixtures = $GLOBALS['__mr_fixtures'] ?? array();
		if ( false !== strpos( $url, 'view=unknown' ) ) {
			$body = $fixtures['unknown'] ?? null;
		} elseif ( false !== strpos( $url, 'view=rights' ) ) {
			$body = $fixtures['rights'] ?? null;
		} elseif ( false !== strpos( $url, '/machine-readers' ) ) {
			$body = $fixtures['aggregate'] ?? null;
		} elseif ( false !== strpos( $url, 'crawler-list-status' ) ) {
			$body = $fixtures['crawler_status'] ?? null;
		} elseif ( false !== strpos( $url, '/version' ) ) {
			$body = $fixtures['version'] ?? null;
		} else {
			$body = null;
		}
		if ( null === $body ) {
			return array( '__wp_error' => true );
		}
		return array( 'body' => $body );
	}
}
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = '' ) {
		$map = $GLOBALS['__sn_settings'] ?? array();
		return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
	}
}
if ( ! function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
	function sn_rss_tracker_window_stats_multi( $windows ) { return $GLOBALS['__feed_stats'] ?? array(); }
}

require SNT_PATH . 'inc/machine-readers-api.php';
require SNT_PATH . 'inc/machine-readers-taxonomy.php';
require SNT_PATH . 'inc/machine-readers-render.php';
require SNT_PATH . 'inc/machine-readers-render-taxonomy.php';
require SNT_PATH . 'inc/machine-readers-insights.php';
require SNT_PATH . 'inc/machine-readers-compose.php';
require SNT_PATH . 'inc/machine-readers-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-machine-readers.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Build the {"data":[...],"truncated":bool} JSON body the sensor returns. */
function mr_body( array $rows, $truncated = false ) {
	return json_encode( array( 'data' => $rows, 'truncated' => $truncated, 'rows' => count( $rows ) ) );
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/machine-readers'] ), 'the painter is registered under monitoring/machine-readers' );

// ── Rich fixture: a configured, reachable sensor with a real mixed readership.
$GLOBALS['__sn_settings'] = array( 'machine_readers.read_token' => 'shh-token', 'machine_readers.worker_url' => '' );
$GLOBALS['__feed_stats']  = array(
	'most_recent' => '2026-09-05 10:00:00',
	'windows'     => array( 7 => array( 'total' => 40, 'uniques' => 5 ), 30 => array( 'total' => 210, 'uniques' => 9 ) ),
);
$aggregate_rows = array(
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-09-01', 'hits' => 120, 'vendor' => 'openai', 'agent' => 'openai-gptbot', 'purpose' => 'train', 'taxonomy_version' => '1', 'first_party' => '0', 'markdown_requested' => '1', 'signed_agent' => 'valid' ),
	array( 'family' => 'openai', 'surface' => 'rights', 'day' => '2026-09-01', 'hits' => 5, 'vendor' => 'openai', 'agent' => 'openai-gptbot', 'purpose' => 'train', 'taxonomy_version' => '1', 'signed_agent' => 'valid' ),
	array( 'family' => 'anthropic', 'surface' => 'html', 'day' => '2026-09-02', 'hits' => 30, 'vendor' => 'anthropic', 'agent' => 'claude-bot', 'purpose' => 'train', 'taxonomy_version' => '1', 'signed_agent' => 'invalid' ),
	array( 'family' => 'uptime', 'surface' => 'html', 'day' => '2026-09-02', 'hits' => 500, 'vendor' => 'betterstack', 'purpose' => 'ops', 'taxonomy_version' => '1', 'first_party' => '1' ),
	array( 'family' => 'other-bot', 'surface' => 'html', 'day' => '2026-09-01', 'hits' => 8, 'taxonomy_version' => '1' ),
);
$unknown_rows_raw = array(
	array( 'family' => 'other-bot', 'surface' => 'html', 'day' => '2026-09-01', 'hits' => 8, 'user_agent' => 'SomeCrawlerBot/1.0 review-me', 'taxonomy_version' => '1' ),
);
$rights_rows_raw  = array(
	array( 'observed_at' => '2026-09-05T10:00:00Z', 'path' => '/.well-known/ai-rights.txt', 'user_agent' => '<script>alert(1)</script>', 'vendor' => 'openai', 'purpose' => 'train', 'family' => 'openai', 'hits' => 3 ),
	array( 'observed_at' => '2026-09-04T09:00:00Z', 'path' => '/rights.json', 'user_agent' => 'GPTBot/1.0', 'vendor' => 'openai', 'purpose' => 'train', 'family' => 'openai', 'hits' => 2 ),
	array( 'observed_at' => '2026-09-05T11:00:00Z', 'path' => '/rights.json', 'user_agent' => 'SignalNoise-SmokeTest/1.0', 'vendor' => 'signal-and-noise', 'purpose' => 'ops', 'family' => 'other-bot', 'hits' => 24 ),
);
$GLOBALS['__mr_fixtures'] = array(
	'aggregate'      => mr_body( $aggregate_rows ),
	'unknown'        => mr_body( $unknown_rows_raw ),
	'rights'         => mr_body( $rights_rows_raw ),
	'version'        => json_encode( array( 'version' => '1.20.0', 'deployed_at' => '2026-09-01T00:00:00Z' ) ),
	'crawler_status' => json_encode( array( 'last_check' => array( 'ok' => true, 'drift' => false ) ) ),
);

$classic = snt_leaf_classic_html( 'snt_mr_render_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'machine-readers' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'machine_readers_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is machine_readers_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Sensor pipeline + KPI readout.
ok( false !== strpos( $kit, 'Sensor v1.20.0' ) && false !== strpos( $kit, 'tone="success"' ), 'the sensor version pill reads v1.20.0, ok toned' );
ok( false !== strpos( $kit, 'Read token set' ), 'the read-token pill reports set' );
ok( false !== strpos( $kit, 'Aggregates current' ), 'the aggregate-read pill reports current' );
ok( false !== strpos( $kit, '663' ), 'the total-reads stat sums every row (120+5+30+500+8=663)' );
ok( false !== strpos( $kit, 'openai' ) && false !== strpos( $kit, 'top family' ), 'the top-family stat names openai' );
ok( false !== strpos( $kit, '155' ) && false !== strpos( $kit, 'AI-training reads' ), 'the AI-training-reads stat sums openai+anthropic (120+5+30=155)' );
ok( false !== strpos( $kit, '210' ) && false !== strpos( $kit, 'feed fetches' ), 'the feed-fetches stat carries the 30d feed total' );
ok( false !== strpos( $kit, '125 / 155' ), 'the proved-identity stat is valid/measured (125 valid of 155 measured)' );
ok( false !== strpos( $kit, '120' ) && false !== strpos( $kit, 'asked for markdown' ), 'the markdown-adoption stat carries the markdown count' );

// ── Evidence column: rights log, unknown agents.
ok( false !== strpos( $kit, 'external read' ) && false !== strpos( $kit, 'GPTBot/1.0' ), 'the rights log shows the external GPTBot read' );
ok( false !== strpos( $kit, '&lt;script&gt;' ) && false === strpos( $kit, '<script>' ), 'a hostile user agent in the rights log is escaped, not executed' );
ok( false !== strpos( $kit, 'our own CI' ), 'our own CI traffic on the rights surfaces is folded away, not dropped' );
ok( false !== strpos( $kit, 'unclassified user agent' ) && false !== strpos( $kit, 'SomeCrawlerBot' ), 'the unknown-agents review list carries the sampled agent' );

// ── Reference column: folded lookup tables + edge readout + settings.
ok( false !== strpos( $kit, 'By purpose' ) && false !== strpos( $kit, 'By crawler family' ) && false !== strpos( $kit, 'By machine surface' ), 'the purpose/family/surface folds are present' );
ok( false !== strpos( $kit, 'Declared-crawler compliance' ) && false !== strpos( $kit, 'AI-training reconciliation' ), 'the compliance and reconciliation folds are present' );
ok( false !== strpos( $kit, 'Feed fetches' ) && false !== strpos( $kit, 'last 30 days' ), 'the feed-fetches table is present' );
ok( false !== strpos( $kit, 'sn-rights-signals' ) && false !== strpos( $kit, 'v1.20.0' ), 'the edge-sensor readout names the deployed worker version' );
ok( false !== strpos( $kit, 'token set' ) && false !== strpos( $kit, '<os-text-field name="sn_mr_worker_url"' ), 'the settings fold snapshot says "token set" and the worker-url field is present' );
ok( false !== strpos( $kit, 'What machine readers did' ) && false !== strpos( $kit, 'Reference' ), 'both column headings survive' );
ok( false !== strpos( $kit, 'Reads by purpose, last 30 days.' ) && false !== strpos( $kit, 'Reads by agent and purpose, third parties only.' ) && false !== strpos( $kit, 'Observed vs declared' ) && false !== strpos( $kit, 'Feed fetches per window (RSS and JSON Feed).' ), 'every table caption survives' );
ok( false !== strpos( $kit, '✓ Sensor v1.20.0' ) || false !== strpos( $kit, '! ' ) || false !== strpos( $kit, '? ' ), 'pill state marks (✓/!/?) survive alongside the tone' );

// ── not_configured: no token, sensor unreachable, crawler check unmeasured.
$GLOBALS['__sn_settings'] = array();
$GLOBALS['__mr_fixtures']['version']        = null;
$GLOBALS['__mr_fixtures']['crawler_status'] = null;
$kit = snt_leaf_paint( 'monitoring', 'machine-readers' );
ok( false !== strpos( $kit, 'Sensor unreachable' ), 'sensor-unreachable pill shown when the version endpoint fails' );
ok( false !== strpos( $kit, 'Read token missing' ) && false !== strpos( $kit, 'tone="warning"' ), 'read-token-missing pill shown when no token is configured' );
ok( false !== strpos( $kit, 'Not configured' ), 'not_configured pill shown when the read fails for lack of a token' );
ok( false !== strpos( $kit, 'Crawler list unchecked' ), 'crawler-list-unchecked pill shown when the status endpoint fails' );
ok( false !== strpos( $kit, 'No readership data yet' ), 'the evidence column explains the empty state when the read failed' );
ok( false !== strpos( $kit, 'not set' ), 'the settings snapshot says "not set" with no token' );
ok( array() === snt_leaf_classic_markers( $kit ), 'still no wp-admin markup in the empty state' );

// ── Truncated read: the caveat is shown and qualifies the headline.
$GLOBALS['__sn_settings']             = array( 'machine_readers.read_token' => 'shh-token' );
$GLOBALS['__mr_fixtures']['aggregate'] = mr_body( $aggregate_rows, true );
$GLOBALS['__mr_fixtures']['version']   = json_encode( array( 'version' => '1.20.0' ) );
$kit = snt_leaf_paint( 'monitoring', 'machine-readers' );
ok( false !== strpos( $kit, 'The edge capped this read at its row limit' ), 'the truncation notice shows when the sensor reports a capped read' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
