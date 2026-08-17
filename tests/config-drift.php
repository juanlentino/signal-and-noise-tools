<?php
/** Standalone tests for the R6a effective-settings snapshot and diff. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '11.10.2-test' );
define( 'SN_SETTINGS_OPTION', 'sn_settings' );

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }
function add_action() {}
function sn_settings_defaults() {
	// Mirrors the REAL defaults' credential-bearing leaves (settings.php):
	// monitoring.uptime_kuma_push_url is the one whose exact-leaf regex miss
	// shipped in the first cut — a suffix-named secret must stay pinned here.
	return array(
		'identity' => array( 'site_name' => 'Test Site' ),
		'theme' => array( 'reading_wpm' => 225 ),
		'machine_readers' => array( 'read_token' => '' ),
		'monitoring' => array( 'uptime_kuma_push_url' => 'https://kuma.example/api/push/s3cr3t' ),
	);
}

require __DIR__ . '/../inc/config-drift.php';

$pass = 0; $fail = 0;
function ok( $condition, $message ) { global $pass, $fail; if ( $condition ) { $pass++; echo "  PASS: $message\n"; } else { $fail++; echo "  FAIL: $message\n"; } }

echo "\nTest: pure diff\n";
$diff = snt_config_drift_diff(
	array( 'same' => 1, 'gone' => true, 'nested' => array( 'value' => 'old' ) ),
	array( 'same' => 1, 'new' => true, 'nested' => array( 'value' => 'new' ) )
);
ok( array( 'new' ) === $diff['added'], 'diff detects added keys' );
ok( array( 'gone' ) === $diff['removed'], 'diff detects removed keys' );
ok( array( 'nested.value' ) === $diff['changed'], 'diff detects changed nested keys as dot paths' );

echo "\nTest: snapshot lifecycle\n";
snt_config_drift_snapshot_lifecycle();
$snapshot = get_option( SNT_CONFIG_DRIFT_SNAPSHOT_OPTION );
ok( SNT_VERSION === $snapshot['version'], 'first lifecycle pass snapshots the current plugin version' );
ok( false === snt_config_drift_status()['has_drift'], 'fresh snapshot has no drift' );

$GLOBALS['__options'][ SN_SETTINGS_OPTION ] = array(
	'identity' => array( 'site_name' => 'Changed Site' ),
	'feature' => array( 'new_switch' => true ),
	'machine_readers' => array( 'read_token' => 'top-secret-token' ),
);
$status = snt_config_drift_status();
ok( true === $status['has_drift'] && 3 === $status['count'], 'same-version changes remain visible instead of moving the baseline' );
ok( in_array( 'identity.site_name', $status['changed'], true ), 'status names a changed setting' );
ok( in_array( 'feature.new_switch', $status['added'], true ), 'status names an added setting' );
$current = snt_config_drift_current_values();
ok( false === strpos( $current['machine_readers.read_token'], 'top-secret-token' ), 'secret-like values are hashed, never copied into the snapshot' );
ok( 0 === strpos( $current['machine_readers.read_token'], 'sha256:' ), 'secret hash remains change-detectable' );
ok( false === strpos( (string) $current['monitoring.uptime_kuma_push_url'], 's3cr3t' ), 'suffix-named secret (…_push_url) is hashed — the exact-leaf regex shipped plaintext here once' );
ok( 0 === strpos( (string) $current['monitoring.uptime_kuma_push_url'], 'sha256:' ), 'push_url hash remains change-detectable' );

snt_config_drift_acknowledge();
ok( false === snt_config_drift_status()['has_drift'], 'explicit acknowledgement moves the baseline' );
$GLOBALS['__options'][ SNT_CONFIG_DRIFT_SNAPSHOT_OPTION ]['version'] = 'older-plugin';
$GLOBALS['__options'][ SN_SETTINGS_OPTION ]['theme']['reading_wpm'] = 250;
snt_config_drift_snapshot_lifecycle();
ok( false === snt_config_drift_status()['has_drift'], 'plugin-version transition refreshes the baseline' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
