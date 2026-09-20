<?php
/**
 * 17.3.0: the batch reschedule's native twin, POST /openstation/reschedule.
 *
 * OpenStation's Posts window has no bulk dropdown, so the classic action was
 * unreachable from it (native-twin audit 2026-09-20, gap #2). This pins that
 * the route is registered behind the classic action's capability, that the
 * datetime-local wire value parses through the same shape check, that a bad
 * date is the classic sentence as a 400, that refused and skipped rows are
 * reported never dropped, and that the shell reaches the planner only through
 * the one shared write.
 *
 * Run: php tests/batch-schedule-rest.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WordPress stubs, recording every call ─────────────────────────────
$GLOBALS['__actions']     = array();
$GLOBALS['__rest_routes'] = array();
$GLOBALS['__posts']       = array();
$GLOBALS['__writes']      = array();
$GLOBALS['__deny']        = array();
$GLOBALS['__caps']        = array();
function add_action( $hook, $cb ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb ) {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__rest_routes'][ $ns ][ $route ] = $args; return true; }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }
class WP_REST_Response { public $data; public function __construct( $d = null ) { $this->data = $d; } public function get_data() { return $this->data; } }
class WP_REST_Request { private $p; public function __construct( $p ) { $this->p = $p; } public function get_param( $k ) { return $this->p[ $k ] ?? null; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function rest_ensure_response( $r ) { return $r instanceof WP_Error ? $r : new WP_REST_Response( $r ); }
function __( $t ) { return $t; }
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function wp_slash( $v ) { return $v; }
function wp_update_post( $args ) { $GLOBALS['__writes'][] = $args; return (int) $args['ID']; }
function get_date_from_gmt( $gmt ) { return $gmt; }
function get_gmt_from_date( $site ) { return $site; }
function current_user_can( $cap, $id = 0 ) { if ( 'edit_post' === $cap ) { return ! in_array( (int) $id, $GLOBALS['__deny'], true ); } return ! empty( $GLOBALS['__caps'][ $cap ] ); }
function post( $status, $type = 'post' ) { return (object) array( 'post_status' => $status, 'post_type' => $type ); }

require __DIR__ . '/../inc/batch-schedule.php';
require __DIR__ . '/../inc/batch-schedule-rest.php';

echo "Group: the route, behind the classic action's gate\n";
ok( in_array( 'snt_batch_schedule_register_rest', $GLOBALS['__actions']['rest_api_init'] ?? array(), true ), 'registers on rest_api_init' );
snt_batch_schedule_register_rest();
$route = $GLOBALS['__rest_routes']['signal-noise/v1']['/openstation/reschedule'] ?? null;
ok( is_array( $route ) && 'POST' === ( $route['methods'] ?? '' ) && 'snt_batch_schedule_rest_handle' === ( $route['callback'] ?? '' ), 'POST signal-noise/v1/openstation/reschedule is registered' );
ok( 'snt_batch_schedule_rest_permission' === ( $route['permission_callback'] ?? '' ), 'the permission callback is the route\'s own, by NAME (not the preferences route\'s manage_options gate)' );
ok( 'array' === ( $route['args']['ids']['type'] ?? '' ) && 'integer' === ( $route['args']['ids']['items']['type'] ?? '' ) && true === ( $route['args']['ids']['required'] ?? false ), 'ids: a required array of integers' );
ok( 'string' === ( $route['args']['date']['type'] ?? '' ) && true === ( $route['args']['date']['required'] ?? false ), 'date: a required string' );
$GLOBALS['__caps'] = array();
ok( false === snt_batch_schedule_rest_permission(), 'no edit_others_posts: refused' );
$GLOBALS['__caps'] = array( 'manage_options' => true );
ok( false === snt_batch_schedule_rest_permission(), 'manage_options alone is not the gate' );
$GLOBALS['__caps'] = array( 'edit_others_posts' => true );
ok( true === snt_batch_schedule_rest_permission(), 'edit_others_posts: allowed, classic parity' );

echo "\nGroup: the date is validated by SHAPE before conversion (#1179)\n";
$GLOBALS['__posts'] = array( 41 => post( 'future' ), 42 => post( 'publish' ), 43 => post( 'draft' ), 44 => post( 'future', 'page' ) );
$GLOBALS['__writes'] = array();
$res = snt_batch_schedule_rest_handle( new WP_REST_Request( array( 'ids' => array( 41 ), 'date' => '15/09/2026 10:30' ) ) );
ok( is_wp_error( $res ) && 'snt_batch_baddate' === $res->get_error_code() && 400 === ( $res->get_error_data()['status'] ?? 0 ), 'a text-rendered date is a 400 snt_batch_baddate' );
ok( is_wp_error( $res ) && 'That date could not be read, so nothing was rescheduled.' === $res->get_error_message(), 'with the classic notice\'s sentence' );
ok( array() === $GLOBALS['__writes'], 'and nothing was written' );
$res = snt_batch_schedule_rest_handle( new WP_REST_Request( array( 'ids' => array( 41 ), 'date' => '' ) ) );
ok( is_wp_error( $res ) && 'snt_batch_baddate' === $res->get_error_code(), 'an empty date is the same refusal' );

echo "\nGroup: a good date reaches the shared write; both halves come back\n";
$GLOBALS['__writes'] = array();
$res = snt_batch_schedule_rest_handle( new WP_REST_Request( array( 'ids' => array( '41', 42, 43, 44 ), 'date' => '2099-01-01T10:00' ) ) );
$d = $res instanceof WP_REST_Response ? $res->get_data() : array();
ok( array( 'moved' => 2, 'refused' => 0, 'unpublish' => 1, 'skipped' => 1 ) === array_intersect_key( $d, array_flip( array( 'moved', 'refused', 'unpublish', 'skipped' ) ) ), 'the datetime-local wire value (YYYY-MM-DDTHH:MM) moves the scheduled post and the draft, refuses the published one, skips the page (got ' . json_encode( $d ) . ')' );
ok( array( 41, 43 ) === array_map( static fn( $w ) => (int) $w['ID'], $GLOBALS['__writes'] ), 'exactly those two writes, the string id coerced' );
ok( '2099-01-01 10:00:00' === ( $GLOBALS['__writes'][0]['post_date_gmt'] ?? '' ), 'the wire value parsed through snt_batch_schedule_parse_date into Y-m-d H:i:s' );
ok( snt_batch_schedule_message( 2, 0, 1, 1 ) === ( $d['message'] ?? '' ) && false !== strpos( (string) ( $d['message'] ?? '' ), '1 selected item was left untouched' ), 'the answer carries the classic sentence, both halves and the skipped row (the toast prints message alone)' );
$GLOBALS['__deny'] = array( 43 );
$GLOBALS['__writes'] = array();
$d = snt_batch_schedule_rest_handle( new WP_REST_Request( array( 'ids' => array( 41, 43 ), 'date' => '2099-01-01T10:00' ) ) )->get_data();
ok( 1 === $d['moved'] && 1 === $d['skipped'] && array( 41 ) === array_map( static fn( $w ) => (int) $w['ID'], $GLOBALS['__writes'] ), 'an id the operator cannot edit_post is skipped and reported, never written' );
$GLOBALS['__deny'] = array();

echo "\nGroup: the REST shell decides nothing\n";
// Comments stripped: the docblock may explain the planner by name.
$src  = (string) file_get_contents( __DIR__ . '/../inc/batch-schedule-rest.php' );
$code = (string) preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', $src );
ok( false !== strpos( $code, 'snt_batch_schedule_apply(' ), 'the route calls the shared write' );
ok( false === strpos( $code, 'snt_batch_schedule_plan(' ) && false === strpos( $code, 'wp_update_post(' ), 'and never the planner or wp_update_post directly' );
ok( false !== strpos( $code, 'snt_batch_schedule_parse_date(' ) && false !== strpos( $code, 'snt_batch_schedule_message(' ), 'the same shape check and the same sentence as the classic path' );
ok( false === strpos( $code, "'manage_options'" ), 'manage_options is not named anywhere in the route file' );
$main = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( strpos( $main, "inc/batch-schedule-rest.php'" ) > strpos( $main, "inc/batch-schedule.php'" ), 'the plugin requires the route file right after the planner file it depends on' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
