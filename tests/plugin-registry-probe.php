<?php
/**
 * Tests: the runtime probe for an empty /wp/v2/plugins response (issue #1026).
 *
 * The fault is a SUCCESS response carrying an empty collection, so the probe
 * has to fire on exactly that and on nothing else. Most of these assertions are
 * about what it must NOT record — a probe that fires on every empty REST
 * response would bury the one case that matters.
 *
 * Run: php tests/plugin-registry-probe.php
 * @since 13.96.6
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

$GLOBALS['snt_options'] = array( 'active_plugins' => array( 'a/a.php', 'b/b.php' ) );
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['snt_options'] ) ? $GLOBALS['snt_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['snt_options'][ $name ] = $value;
	return true;
}
function is_wp_error( $t ) { return $t instanceof SNT_Probe_Error; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['snt_filters'][ $h ][] = $c; }
function human_time_diff( $from, $to = 0 ) { return '5 mins'; }

class SNT_Probe_Error {}
class SNT_Probe_Request {
	private $route;
	public function __construct( $route ) { $this->route = $route; }
	public function get_route() { return $this->route; }
}
class SNT_Probe_Response {
	private $data; private $err;
	public function __construct( $data, $err = false ) { $this->data = $data; $this->err = $err; }
	public function get_data() { return $this->data; }
	public function is_error() { return $this->err; }
}

require_once __DIR__ . '/../inc/plugin-registry-probe.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function reset_state( $active = array( 'a/a.php', 'b/b.php' ) ) {
	$GLOBALS['snt_options'] = array( 'active_plugins' => $active );
}
function recorded() {
	return isset( $GLOBALS['snt_options'][ SN_PLUGIN_REGISTRY_ANOMALY_OPTION ] );
}
function probe( $route, $data, $err = false ) {
	return snt_plugin_registry_probe( new SNT_Probe_Response( $data, $err ), array(), new SNT_Probe_Request( $route ) );
}

echo "plugin-registry-probe — plugin v13.96.6\n\nGroup 1: it fires on the fault\n";
reset_state();
$resp = probe( '/wp/v2/plugins', array() );
ok( recorded(), 'an EMPTY plugins collection with a success status is recorded' );
$seen = $GLOBALS['snt_options'][ SN_PLUGIN_REGISTRY_ANOMALY_OPTION ];
ok( 2 === $seen['active'], 'and it records how many plugins were active (' . $seen['active'] . ')' );
ok( $seen['time'] > 0, 'and when' );
ok( $resp instanceof SNT_Probe_Response, 'THE PIN: the response is passed through untouched — a probe must never alter what it watches' );

echo "\nGroup 2: what it must NOT record\n";
reset_state();
probe( '/wp/v2/posts', array() );
ok( ! recorded(), 'an empty collection on a DIFFERENT route is ignored' );

reset_state();
probe( '/wp/v2/plugins', array( array( 'plugin' => 'a/a' ) ) );
ok( ! recorded(), 'a NON-empty plugins collection is ignored' );

reset_state();
probe( '/wp/v2/plugins', array(), true );
ok( ! recorded(), 'an ERROR response is ignored — a 401 already reports itself honestly' );

reset_state( array() );
probe( '/wp/v2/plugins', array() );
ok( ! recorded(), 'an empty list is NOT a fault when no plugins are active' );

reset_state();
snt_plugin_registry_probe( new SNT_Probe_Response( array() ), array(), null );
ok( ! recorded(), 'a request object without get_route() does not fatal or record' );

echo "\nGroup 3: the observation expires so the check can reach zero again\n";
reset_state();
$GLOBALS['snt_options'][ SN_PLUGIN_REGISTRY_ANOMALY_OPTION ] = array( 'time' => time() - 60, 'active' => 3 );
$fresh = snt_plugin_registry_anomaly();
ok( is_array( $fresh ) && 3 === $fresh['active'], 'a recent observation is reportable' );

$GLOBALS['snt_options'][ SN_PLUGIN_REGISTRY_ANOMALY_OPTION ] = array( 'time' => time() - SN_PLUGIN_REGISTRY_ANOMALY_TTL - 60, 'active' => 3 );
ok( null === snt_plugin_registry_anomaly(), 'an observation past the 7-day window stops being reported' );

$GLOBALS['snt_options'][ SN_PLUGIN_REGISTRY_ANOMALY_OPTION ] = array( 'time' => time() + 86400, 'active' => 3 );
ok( null === snt_plugin_registry_anomaly(), 'a future timestamp (clock skew) is discarded rather than reported forever' );

unset( $GLOBALS['snt_options'][ SN_PLUGIN_REGISTRY_ANOMALY_OPTION ] );
ok( null === snt_plugin_registry_anomaly(), 'no record -> nothing reported' );

echo "\nGroup 4: it is actually attached\n";
ok( isset( $GLOBALS['snt_filters']['rest_request_after_callbacks'] ), 'registers on rest_request_after_callbacks' );
ok( in_array( 'snt_plugin_registry_probe', (array) ( $GLOBALS['snt_filters']['rest_request_after_callbacks'] ?? array() ), true ), 'and the callback attached is ours' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
