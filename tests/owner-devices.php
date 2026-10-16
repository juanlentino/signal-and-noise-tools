<?php
/**
 * Owner device enrollment, the revoked route, the token CLI, both painters
 * and the no-leak sweep (SN Traffic Lanes, Phase 1). The signer itself is
 * pinned by tests/owner-identity-sign.php.
 * Run: php tests/owner-devices.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
ob_start(); // Nothing reaches stdout before the end, so setcookie() never sees "headers sent".

// Real WordPress nonces are opaque hashes; the harness's are not. Ours first.
function wp_create_nonce( $a = -1 ) { return substr( hash( 'sha256', 'salt|' . $a ), 0, 10 ); }
function wp_verify_nonce( $n, $a = -1 ) { return hash_equals( wp_create_nonce( $a ), (string) $n ) ? 1 : false; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $h = '<input type="hidden" name="' . $n . '" value="' . wp_create_nonce( $a ) . '">'; if ( $echo ) { echo $h; } return $h; }
$GLOBALS['__options'] = array();
$GLOBALS['__reads'] = 0;
function get_option( $k, $d = false ) { $GLOBALS['__reads']++; return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
$GLOBALS['__is_admin'] = true; $GLOBALS['__can'] = true; $GLOBALS['__logs'] = array();
function is_admin() { return $GLOBALS['__is_admin']; }
function current_user_can( $c ) { return $GLOBALS['__can']; }
$GLOBALS['__logfile'] = tempnam( sys_get_temp_dir(), 'snlog' );
ini_set( 'error_log', $GLOBALS['__logfile'] ); // every error_log() line lands here for the sweep
function sn_setting( $k, $d = null ) { return $d; }
function sn_setting_update( $k, $v ) {}
function delete_transient( $k ) { return true; }
function sn_mask_secret( $v ) { $v = (string) $v; return '' === $v ? '' : ( strlen( $v ) <= 8 ? '••••••••' : '••••' . substr( $v, -4 ) ); }

require_once __DIR__ . '/lib/os-leaf-harness.php';
class WP_Error { public $code; public $data; public function __construct( $c, $m, $d ) { $this->code = $c; $this->data = $d; } }
class WP_REST_Response { public $data; public $status; public $headers = array(); public function __construct( $d, $s ) { $this->data = $d; $this->status = $s; } public function header( $k, $v ) { $this->headers[ $k ] = $v; } }
class Req { private $h; public function __construct( $h ) { $this->h = $h; } public function get_header( $k ) { return $this->h; } }
function register_rest_route( $ns, $r, $a ) { $GLOBALS['__routes'][ $ns . $r ] = $a; }

require SNT_PATH . 'inc/keyring.php';
require SNT_PATH . 'inc/admin-post-actions/keyring.php';
require SNT_PATH . 'inc/keyring-admin.php';
require SNT_PATH . 'inc/owner-identity-sign.php';
require SNT_PATH . 'inc/owner-devices.php';
require SNT_PATH . 'inc/owner-revoked-route.php';
require SNT_PATH . 'inc/owner-token-cli.php';
require SNT_PATH . 'inc/owner-devices-render.php';
require SNT_PATH . 'inc/admin-post-actions/owner-devices.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-analytics-owner.php';
use function SignalNoise\OpenStationHost\Dashboard\Leaves\analytics_owner_devices_html;

$pass = 0; $fail = 0; $out = '';
function ok( $c, $m ) { global $pass, $fail, $out; if ( $c ) { $pass++; $out .= "PASS: $m\n"; } else { $fail++; $out .= "FAIL: $m\n"; } }
$classic = static function () { ob_start(); snt_analytics_render_owner_devices(); return (string) ob_get_clean(); };
$now     = 1792130000; // 2026-10-15 UTC
$today   = gmdate( 'Ymd', $now );

// ── No key: enroll refuses, writes nothing, sets no cookie.
ok( 'owner_device_no_key' === sn_owner_device_enroll( 'Laptop', $now ) && array() === sn_owner_devices() && ! isset( $_COOKIE['sn_owner_dev'] ), 'no device key: enroll refuses with a clear code and writes nothing' );
ok( false !== strpos( $classic(), 'Generate the owner key' ) && false === strpos( $classic(), 'name="sn_owner_device_label"' ), 'no device key: the card says to generate it and offers no enroll form' );
$GLOBALS['__options']['sn_owner_key'] = 'site';
ok( 'owner_device_no_key' === sn_owner_device_enroll( 'Laptop', $now ), 'a key that is not 64 hex (the literal "site") counts as unset' );

sn_handle_keyring_generate( array( 'key_id' => 'owner_key' ) );
$dkey = sn_credential( 'owner_key' );
$akey = $dkey; // one key signs both formats (owner decision 2026-10-15)

// ── The front-end gate: no cookie off wp-admin.
$GLOBALS['__is_admin'] = false;
ok( null === sn_owner_dev_cookie_write( 'v1.x', $now ) && 'owner_device_not_admin' === sn_owner_device_enroll( 'Phone', $now ) && array() === sn_owner_devices() && ! isset( $_COOKIE['sn_owner_dev'] ), 'a front-end request never writes the marker, and enroll then saves nothing' );
define( 'REST_REQUEST', true );
$_SERVER['REQUEST_METHOD'] = 'GET';
ok( null === sn_owner_dev_cookie_write( 'v1.x', $now ), 'a REST GET never writes it either' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['__can'] = false;
ok( null === sn_owner_dev_cookie_write( 'v1.x', $now ), 'a REST POST without manage_options never writes it' );
$GLOBALS['__can'] = true;
ok( is_array( sn_owner_dev_cookie_write( 'v1.x', $now ) ), 'the native window\'s authenticated POST dispatch may write it' );
unset( $_COOKIE['sn_owner_dev'] ); $_SERVER['REQUEST_METHOD'] = 'GET'; $GLOBALS['__is_admin'] = true;
ok( array( 'admin_init' ) === array_values( array_filter( array_keys( $GLOBALS['__actions'] ), static function ( $h ) { foreach ( $GLOBALS['__actions'][ $h ] as $cb ) { if ( 'sn_owner_device_renew_hook' === $cb ) { return true; } } return false; } ) ), 'renewal hangs on admin_init only, never init, wp or template_redirect' );
$src = '';
require_once __DIR__ . '/lib/inc-population.php';
foreach ( snt_test_inc_files() as $f ) { $src .= file_get_contents( $f ); }
ok( 1 === substr_count( $src, 'setcookie( SN_OWNER_DEV_COOKIE' ) && 0 === preg_match( "/setcookie\\(\\s*'sn_owner_dev'/", $src ), 'exactly one setcookie() for the marker in the plugin: the gated writer' );

// ── Enroll.
ok( 'owner_device_no_label' === sn_handle_owner_device_enroll( array( 'sn_owner_device_label' => '  ' ) ), 'a blank label is refused' );
$opts = null;
ok( 'owner_device_enrolled' === sn_owner_device_enroll( 'Laptop Chrome', $now ), 'enroll with a key and a label succeeds' );
$devs   = sn_owner_devices();
$marker = (string) $_COOKIE['sn_owner_dev'];
$id     = $devs[0]['id'];
ok( 1 === count( $devs ) && 1 === preg_match( '/^[0-9a-f]{16}$/', $id ) && 'Laptop Chrome' === $devs[0]['label'] && $now === $devs[0]['enrolled'] && $now === $devs[0]['last_seen'] && array( 'id', 'label', 'enrolled', 'last_seen' ) === array_keys( $devs[0] ), 'the device row is {id, label, enrolled, last_seen}, the id 16 lowercase hex' );
ok( false === $GLOBALS['__autoload']['sn_owner_devices'], 'sn_owner_devices is not autoloaded' );
ok( sn_owner_device_marker( $dkey, $id, $today ) === $marker && is_array( sn_owner_device_verify( $marker, array( $dkey ), $today ) ), 'the cookie is the contract marker for that id and today, under the current key' );
$o = sn_owner_dev_cookie_write( $marker, $now );
ok( array( 'expires' => $now + 34560000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) === $o, 'Secure, HttpOnly, SameSite=Lax, Path=/, host-only (no domain), 400 days' );
ok( 'owner_device_already' === sn_owner_device_enroll( 'Again', $now ) && 1 === count( sn_owner_devices() ), 'an enrolled browser is not enrolled twice' );

// ── Renewal: same day and current key, nothing; next day, re-signed.
ok( false === sn_owner_device_renew( $now + 60 ), 'same day, current key: no renewal, no write' );
$GLOBALS['__is_admin'] = false;
ok( false === sn_owner_device_renew( $now + 86400 ) && $marker === $_COOKIE['sn_owner_dev'], 'off wp-admin, never renewed' );
$GLOBALS['__is_admin'] = true;
ok( true === sn_owner_device_renew( $now + 86400 ) && sn_owner_device_marker( $dkey, $id, gmdate( 'Ymd', $now + 86400 ) ) === $_COOKIE['sn_owner_dev'] && $now + 86400 === sn_owner_devices()[0]['last_seen'], 'an older-day marker is re-signed for today and last_seen moves' );
// Rotation: the previous key still verifies, and renewal re-signs with the new one the same day.
sn_handle_keyring_generate( array( 'key_id' => 'owner_key' ) );
$dkey2 = sn_credential( 'owner_key' );
$akey  = $dkey2;
ok( $dkey === sn_credential( 'owner_key_previous' ) && null !== sn_owner_this_device( $now + 86400 ), 'after Generate, the marker still verifies under the previous key' );
ok( true === sn_owner_device_renew( $now + 86400 ) && 'current' === sn_owner_device_verify( $_COOKIE['sn_owner_dev'], array( $dkey2, '' ), gmdate( 'Ymd', $now + 86400 ) )['key'], 'renewal drains the previous key: same day, re-signed with the current one' );
$marker = (string) $_COOKIE['sn_owner_dev'];
$GLOBALS['__options']['sn_owner_revoked'] = array( 'ids' => array( $id ), 'at' => 1 );
ok( null === sn_owner_this_device( $now + 86400 ) && false === sn_owner_device_renew( $now + 3 * 86400 ), 'an id on the revoked list is never this device, even while still listed' );
unset( $GLOBALS['__options']['sn_owner_revoked'] );

// ── The painters: label, enrolled, last seen, this browser; never an id, marker or key.
sn_owner_device_enroll( 'x', $now ); // already enrolled: no-op
$_COOKIE_saved = $_COOKIE; unset( $_COOKIE['sn_owner_dev'] );
sn_owner_device_enroll( 'Phone Safari', $now ); // a second device (a "different browser")
$phone_marker = (string) $_COOKIE['sn_owner_dev'];
$_COOKIE = $_COOKIE_saved;
$ids = array_column( sn_owner_devices(), 'id' );
$c   = $classic();
$n   = analytics_owner_devices_html();
foreach ( array( 'classic' => $c, 'native' => $n ) as $where => $html ) {
	ok( false !== strpos( $html, 'Laptop Chrome' ) && false !== strpos( $html, 'Phone Safari' ) && false !== strpos( $html, 'This browser is enrolled as' ) && false !== strpos( $html, '(this browser)' ), "$where: labels and whether this browser is enrolled" );
	$leak = false;
	foreach ( array_merge( $ids, array( $marker, $phone_marker, $dkey, $dkey2, $akey, substr( $marker, -43 ) ) ) as $secret ) { $leak = $leak || false !== strpos( $html, $secret ); }
	ok( ! $leak, "$where: no device id, marker, signature or key in the markup" );
	ok( false !== strpos( $html, 'then clear the previous owner key there' ), "$where: Revoke all names the Generate-then-clear-previous step (security M3)" );
	ok( false !== strpos( $html, 'owner_device_revoke' ) && false !== strpos( $html, 'owner_device_revoke_all' ) && false !== strpos( $html, 'sn_owner_row' ), "$where: revoke by row and revoke all" );
	ok( false === strpos( $html, 'owner_device_enroll' ) && false === strpos( $html, 'name="sn_owner_device_label"' ), "$where: an enrolled browser is offered no enroll form (live check 2026-10-15)" );
}

// Live check 2026-10-15: the native revoke is one <os-form> per device stacked
// under the table, never inside a table cell (it painted "[object Object]").
$tables = snt_leaf_tables( $n );
ok( 1 === count( $tables ) && array( 'device', 'enrolled', 'last_seen' ) === array_column( $tables[0]['columns'], 'key' ) && array() === $tables[0]['slots'], 'native: the table has no action column and no slot cell' );
ok( 2 === substr_count( $n, 'value="sn_owner_device_revoke"' ) && false !== strpos( $n, 'submit-label="Revoke &quot;Phone Safari&quot;"' ), 'native: one revoke form per device, labelled with its device, under the table' );
ok( false !== strpos( $c, '>Revoke &quot;Phone Safari&quot;</button>' ) && false !== strpos( $c, '>Revoke &quot;Laptop Chrome&quot;</button>' ), 'classic: each revoke button names its device, so no two buttons share an accessible name' );
$saved_cookie = $_COOKIE; unset( $_COOKIE['sn_owner_dev'] );
ok( false !== strpos( analytics_owner_devices_html(), 'owner_device_enroll' ) && false !== strpos( $classic(), 'name="sn_owner_device_label"' ), 'a browser that is not enrolled gets the enroll form on both surfaces' );
$_COOKIE = $saved_cookie;

// ── Revoke by row index and its nonce; a stale nonce revokes nothing.
ok( 'owner_device_list_changed' === sn_handle_owner_device_revoke( array( 'sn_owner_row' => 1, 'sn_owner_row_nonce' => wp_create_nonce( sn_owner_row_nonce_action( 0, $ids[0] ) ) ) ) && 2 === count( sn_owner_devices() ), 'a nonce minted for another row revokes nothing' );
ok( 'owner_device_list_changed' === sn_handle_owner_device_revoke( array( 'sn_owner_row' => 9, 'sn_owner_row_nonce' => 'x' ) ), 'a row that does not exist revokes nothing' );
ok( 'owner_device_revoked' === sn_handle_owner_device_revoke( array( 'sn_owner_row' => 1, 'sn_owner_row_nonce' => wp_create_nonce( sn_owner_row_nonce_action( 1, $ids[1] ) ) ) ) && array( $ids[1] ) === sn_owner_revoked()['ids'] && array( $ids[0] ) === array_column( sn_owner_devices(), 'id' ) && isset( $_COOKIE['sn_owner_dev'] ), 'revoke moves that id to the revoked list and keeps this browser\'s marker' );
ok( false === $GLOBALS['__autoload']['sn_owner_revoked'] && sn_owner_revoked()['at'] > 0, 'the revoked list is not autoloaded and records when it changed' );

// ── The revoked route: Bearer SN_SRV_TOKEN, 200 {revoked, at}.
ok( isset( $GLOBALS['__routes']['signal-noise/v1/owner/revoked'] ) || ( do_action( 'rest_api_init' ) || isset( $GLOBALS['__routes']['signal-noise/v1/owner/revoked'] ) ), 'the route registers on rest_api_init' );
ok( 'GET' === $GLOBALS['__routes']['signal-noise/v1/owner/revoked']['methods'] && 'sn_owner_revoked_permission' === $GLOBALS['__routes']['signal-noise/v1/owner/revoked']['permission_callback'], 'GET, behind the server-token permission' );
$perm = sn_owner_revoked_permission( new Req( 'Bearer anything' ) );
ok( $perm instanceof WP_Error && 503 === $perm->data['status'], 'no server token: 503, closed' );
add_filter( 'sn_server_token', static function ( $t ) { return 'srv-secret'; } );
foreach ( array( '', 'srv-secret', 'Bearer srv-secre', 'Bearer srv-secret ', 'bearer srv-secret' ) as $h ) {
	$p = sn_owner_revoked_permission( new Req( $h ) );
	ok( $p instanceof WP_Error && 403 === $p->data['status'], "a wrong or malformed header is 403: '$h'" );
}
ok( true === sn_owner_revoked_permission( new Req( 'Bearer srv-secret' ) ), 'the right Bearer passes' );
$GLOBALS['__options']['sn_owner_revoked']['ids'][] = 'NOT-AN-ID';
$r = sn_owner_revoked_response();
ok( 200 === $r->status && array( 'revoked', 'at' ) === array_keys( $r->data ) && array( $ids[1] ) === $r->data['revoked'] && is_int( $r->data['at'] ) && 'no-store' === $r->headers['Cache-Control'], 'the Worker\'s shape: 200, {"revoked":[...],"at":int}, uncached' );
ok( array() === array_filter( $r->data['revoked'], static function ( $i ) { return 1 !== preg_match( '/^[0-9a-f]{16}$/', $i ); } ), 'every id it answers is 16 lowercase hex; a malformed stored id never leaves' );
ok( '{"revoked":["' . $ids[1] . '"],"at":' . $r->data['at'] . '}' === wp_json_encode( $r->data ), 'the exact JSON the analytics Worker parses' );

// ── Revoke all: every id onto the list, this browser's marker expired.
ok( 'owner_device_revoked_all' === sn_handle_owner_device_revoke_all( array() ) && array() === sn_owner_devices() && in_array( $ids[0], sn_owner_revoked()['ids'], true ) && ! isset( $_COOKIE['sn_owner_dev'] ), 'revoke all lists every enrolled id and expires this browser\'s marker' );
ok( 'owner_device_none' === sn_handle_owner_device_revoke_all( array() ), 'revoke all with nothing enrolled says so' );
$_COOKIE['sn_owner_dev'] = $marker;
ok( null === sn_owner_this_device( $now + 86400 ) && false === sn_owner_device_renew( $now + 2 * 86400 ), 'a revoked marker is not this device and is never renewed' );

// ── Review round 1: renewal reads no option without a marker cookie.
$saved_cookie = $_COOKIE; unset( $_COOKIE['sn_owner_dev'] ); $GLOBALS['__reads'] = 0;
ok( false === sn_owner_device_renew( $now ) && 0 === $GLOBALS['__reads'], 'no sn_owner_dev cookie: renewal reads no option at all' );
$_COOKIE = $saved_cookie;

// ── Review round 1: the revoked list keeps and serves the newest 1,000 ids.
$many = array(); for ( $k = 0; $k < 1005; $k++ ) { $many[] = sprintf( '%016x', $k ); }
$GLOBALS['__options']['sn_owner_revoked'] = array( 'ids' => array(), 'at' => 0 );
sn_owner_revoke_ids( $many, $now );
ok( 1000 === count( sn_owner_revoked()['ids'] ) && sprintf( '%016x', 1004 ) === end( sn_owner_revoked()['ids'] ) && sprintf( '%016x', 5 ) === sn_owner_revoked()['ids'][0], 'storage keeps the newest 1,000 ids' );
$GLOBALS['__options']['sn_owner_revoked'] = array( 'ids' => $many, 'at' => 1 );
$big = sn_owner_revoked_response()->data['revoked'];
ok( 1000 === count( $big ) && sprintf( '%016x', 1004 ) === end( $big ) && sprintf( '%016x', 5 ) === $big[0], 'the route answers at most the newest 1,000, even over a longer stored list' );

// ── Review round 1 (security L9): a label is escaped in every attribute and cell.
$GLOBALS['__options']['sn_owner_devices'] = array( array( 'id' => 'aaaaaaaaaaaaaaaa', 'label' => '"><script>x</script>', 'enrolled' => $now, 'last_seen' => $now ) );
$nh = analytics_owner_devices_html();
$ch = $classic();
ok( false === strpos( $nh, '<script>' ) && false === strpos( $nh, '"><' . 'script' ) && false !== strpos( $nh, '&lt;script&gt;' ), 'native: the label is escaped in the revoke confirm and the table' );
ok( false === strpos( $ch, '<script>' ) && false !== strpos( $ch, '&lt;script&gt;' ), 'classic: the label is escaped in the confirm and the table' );
$GLOBALS['__options']['sn_owner_devices'] = array();

// ── One key, two domains: a device marker never verifies as an agent token or back.
ok( null === sn_owner_agent_verify( 'v1.claude-code.' . ( $now + 60 ) . '.' . sn_owner_sign( $akey, 'v1|dev|claude-code|' . ( $now + 60 ) ), array( $akey ), $now ) && null === sn_owner_device_verify( 'v1.0123456789abcdef.' . $today . '.' . sn_owner_sign( $akey, 'v1|agent|0123456789abcdef|' . $today ), array( $akey ), $today ), 'one key, domain-separated messages: a signature from one format never passes as the other' );

// ── The token CLI.
$m = sn_owner_token_mint( 'claude-code', 43200, $now, $akey );
ok( $m['ok'] && 0 === strpos( $m['line'], 'SN-Owner-Agent: v1.claude-code.' . ( $now + 43200 ) . '.' ) && is_array( sn_owner_agent_verify( substr( $m['line'], 16 ), array( $akey ), $now ) ), 'mints a header line that verifies, default TTL 43200' );
ok( ! sn_owner_token_mint( 'claude-code', 43201, $now, $akey )['ok'] && ! sn_owner_token_mint( 'claude-code', 0, $now, $akey )['ok'] && ! sn_owner_token_mint( 'claude-code', '12h', $now, $akey )['ok'], 'a TTL over 43200, zero or not a number is refused, not clamped' );
ok( ! sn_owner_token_mint( 'curl', 60, $now, $akey )['ok'] && ! sn_owner_token_mint( 'claude-code', 60, $now, '' )['ok'], 'an unknown agent or an unset key is refused' );
$token = substr( $m['line'], 16 );

// ── The no-leak sweep: no marker, signature or token in any option or log;
//    no device id outside the two option the contract puts them in.
$dump = $GLOBALS['__options']; unset( $dump['sn_owner_devices'], $dump['sn_owner_revoked'] );
$dump = wp_json_encode( $dump ) . (string) file_get_contents( $GLOBALS['__logfile'] ) . wp_json_encode( sn_keyring_models() ) . wp_json_encode( sn_owner_devices_view( $now ) );
$leaks = array();
foreach ( array_merge( $ids, array( $marker, $phone_marker, substr( $marker, -43 ), $token, substr( $token, -43 ) ) ) as $secret ) {
	if ( false !== strpos( $dump, $secret ) ) { $leaks[] = $secret; }
}
ok( array() === $leaks, 'no marker, token or device id in any option dump, log line, keyring model or view model' );
ok( false !== strpos( sn_keyring_copy()['generate'], 'stays valid there' ) && false !== strpos( sn_keyring_copy()['generate'], 'until you clear that row' ), 'Generate says the old key stays valid as the previous key until it is cleared' );
$agent_about = sn_keyring()['owner_key']['about'];
ok( false !== strpos( $agent_about, 'page.route limited to https://juanlentino.com/**' ) && false !== strpos( $agent_about, 'never extraHTTPHeaders' ) && false !== strpos( $agent_about, '-L' ) && false !== strpos( $agent_about, 'environment variable' ), 'the agent key\'s hint says how to carry the token safely' );
$models = wp_json_encode( sn_keyring_models(), JSON_UNESCAPED_UNICODE );
ok( false === strpos( $models, $dkey2 ) && false === strpos( $models, $akey ) && false !== strpos( $models, '••••' . substr( $akey, -4 ) ), 'the keyring paints the owner keys as last four only' );

$report = $out . "\n$pass passed, $fail failed\n";
ob_end_clean();
echo $report;
exit( $fail > 0 ? 1 : 0 );
