<?php
/**
 * Standalone fixture tests for inc/scheduled-actions-health.php — the Action
 * Scheduler backlog diagnostic (Site Health test + snapshot + summary line).
 *
 * WHY this module exists: Action Scheduler's dispatch-gate count query
 * (pending actions due now) runs on essentially every page load, front and
 * admin; a table bloated by the dead-cron era makes that a per-page tax
 * (68.7 ms observed live). The plugin doesn't own the table — it OBSERVES
 * it, so the owner can see the backlog and watch it drain.
 *
 * Run: php tests/scheduled-actions-health.php
 *
 * @since plugin v9.48.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
$GLOBALS['__test_filters_added'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $p = 10, $a = 1 ) {
		$GLOBALS['__test_filters_added'][] = $hook;
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return $u; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://x/wp-json/' . $p; } }
$GLOBALS['__test_can'] = true;
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return ! empty( $GLOBALS['__test_can'] ); } }
$GLOBALS['__test_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args ) {
		$GLOBALS['__test_routes'][ $ns . $route ] = $args;
		return true;
	}
}

// ─── wpdb stub ────────────────────────────────────────────────────────
// Mirrors only what the module touches: prepare/get_var/get_results. SHOW
// TABLES resolves from $table_exists; the GROUP BY returns $status_rows; the
// overdue COUNT returns $overdue. Every SQL string is recorded for pinning.
class ASB_Stub_wpdb {
	public $prefix       = 'wp_';
	public $table_exists = true;
	public $status_rows  = array();
	public $overdue      = 0;
	public $queries      = array();

	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . $arg . "'";
			$pos         = strpos( $sql, '%s' );
			if ( false === $pos ) {
				$pos = strpos( $sql, '%d' );
			}
			if ( false !== $pos ) {
				$sql = substr_replace( $sql, $replacement, $pos, 2 );
			}
		}
		return $sql;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== stripos( $sql, 'SHOW TABLES' ) ) {
			return $this->table_exists ? $this->prefix . 'actionscheduler_actions' : null;
		}
		return (string) $this->overdue;
	}

	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		return $this->status_rows;
	}
}

require_once __DIR__ . '/../inc/scheduled-actions-health.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

$now = 1789000000; // fixed injectable "now" — the module must never call time() when given one.

// ─── Group: snapshot — table absent ───────────────────────────────────
echo "\nGroup: snt_asb_snapshot — table absent\n";
$db               = new ASB_Stub_wpdb();
$db->table_exists = false;
ok( null === snt_asb_snapshot( $db, $now ), 'returns null when wp_actionscheduler_actions does not exist' );
ok( 1 === count( $db->queries ), 'only the existence probe ran — no counts against a missing table' );

// ─── Group: snapshot — counts + overdue ───────────────────────────────
echo "\nGroup: snt_asb_snapshot — counts + overdue\n";
$db              = new ASB_Stub_wpdb();
$db->status_rows = array(
	array( 'status' => 'pending', 'n' => '12' ),
	array( 'status' => 'complete', 'n' => '1204' ),
	array( 'status' => 'failed', 'n' => '8' ),
);
$db->overdue     = 3;
$snap            = snt_asb_snapshot( $db, $now );
ok( is_array( $snap ), 'returns an array when the table exists' );
ok( 12 === $snap['counts']['pending'] && 1204 === $snap['counts']['complete'] && 8 === $snap['counts']['failed'], 'per-status counts are cast to int and keyed by status' );
ok( 1224 === $snap['total'], 'total sums every status' );
ok( 3 === $snap['overdue_pending'], 'overdue-pending count comes from the dedicated query' );

$all_sql = implode( ' || ', $db->queries );
ok( false !== strpos( $all_sql, gmdate( 'Y-m-d H:i:s', $now ) ), 'the overdue cutoff is built from the INJECTED now (gmdate), not the wall clock' );
ok( false !== stripos( $all_sql, "status = 'pending'" ), 'the overdue query filters to pending actions' );
ok( false !== stripos( $all_sql, 'GROUP BY status' ), 'per-status counts use a single GROUP BY query' );

// ─── Group: summary line (pure) ───────────────────────────────────────
echo "\nGroup: snt_asb_summary_line — pure formatter\n";
ok( 'Action Scheduler not installed' === snt_asb_summary_line( null ), 'null snapshot formats as not-installed' );
$line = snt_asb_summary_line( $snap );
ok( false !== strpos( $line, 'pending 12' ), 'summary names the pending count' );
ok( false !== strpos( $line, '3 overdue' ), 'summary names the overdue count' );
ok( false !== strpos( $line, 'complete 1204' ) || false !== strpos( $line, 'complete 1,204' ), 'summary names the complete count' );
ok( false !== strpos( $line, 'total 1224' ) || false !== strpos( $line, 'total 1,224' ), 'summary names the total' );
$quiet = snt_asb_summary_line( array( 'counts' => array( 'pending' => 2 ), 'total' => 2, 'overdue_pending' => 0 ) );
ok( false === strpos( $quiet, 'overdue' ), 'a zero overdue count is omitted from the summary, not rendered as "(0 overdue)"' );

// ─── Group: Site Health result — healthy ──────────────────────────────
echo "\nGroup: snt_asb_site_health_result — healthy states\n";
$db               = new ASB_Stub_wpdb();
$db->table_exists = false;
$res              = snt_asb_site_health_result( $db, $now );
ok( 'good' === $res['status'], 'table absent → good' );
ok( false !== stripos( $res['description'], 'not installed' ), 'table absent → says so instead of inventing zeros' );
ok( 'sn_as_backlog' === $res['test'], 'test slug is stable' );

$db               = new ASB_Stub_wpdb();
$db->status_rows  = array( array( 'status' => 'pending', 'n' => '4' ), array( 'status' => 'complete', 'n' => '900' ) );
$db->overdue      = 2;
$res              = snt_asb_site_health_result( $db, $now );
ok( 'good' === $res['status'], 'small healthy table → good' );
ok( false !== strpos( $res['description'], 'pending 4' ), 'healthy description still shows the counts summary' );

// ─── Group: Site Health result — recommended states ───────────────────
echo "\nGroup: snt_asb_site_health_result — backlog states\n";
$db              = new ASB_Stub_wpdb();
$db->status_rows = array( array( 'status' => 'pending', 'n' => '80' ) );
$db->overdue     = SN_ASB_OVERDUE_WARN;
$res             = snt_asb_site_health_result( $db, $now );
ok( 'recommended' === $res['status'], 'overdue-pending at the threshold → recommended' );
ok( false !== stripos( $res['description'], 'overdue' ), 'overdue state names the overdue backlog' );

$db              = new ASB_Stub_wpdb();
$db->status_rows = array( array( 'status' => 'pending', 'n' => '80' ) );
$db->overdue     = SN_ASB_OVERDUE_WARN - 1;
$res             = snt_asb_site_health_result( $db, $now );
ok( 'good' === $res['status'], 'one under the overdue threshold → still good (boundary)' );

$db              = new ASB_Stub_wpdb();
$db->status_rows = array( array( 'status' => 'complete', 'n' => (string) SN_ASB_ROWS_WARN ) );
$db->overdue     = 0;
$res             = snt_asb_site_health_result( $db, $now );
ok( 'recommended' === $res['status'], 'total rows at the bloat threshold → recommended' );
ok( false !== stripos( $res['description'], 'every page load' ), 'bloat state explains the per-page dispatch-gate cost' );

$db              = new ASB_Stub_wpdb();
$db->status_rows = array( array( 'status' => 'complete', 'n' => (string) SN_ASB_ROWS_WARN ) );
$db->overdue     = SN_ASB_OVERDUE_WARN + 10;
$res             = snt_asb_site_health_result( $db, $now );
ok( 'recommended' === $res['status'] && false !== stripos( $res['description'], 'overdue' ) && false !== stripos( $res['description'], 'every page load' ), 'both conditions → recommended with both explanations' );

// ─── Group: actions link gated on the AS UI actually existing ─────────
echo "\nGroup: actions link — class_exists gate\n";
$db              = new ASB_Stub_wpdb();
$db->status_rows = array( array( 'status' => 'pending', 'n' => '1' ) );
$res             = snt_asb_site_health_result( $db, $now );
ok( '' === $res['actions'], 'no ActionScheduler class → no dead link to a Tools page that is not there' );

// The class_exists gate flips once a (dummy) ActionScheduler class exists.
// Wrapped in a conditional so PHP binds it at EXECUTION time — an
// unconditional top-level class is compile-time hoisted, which would make
// class_exists() true before the negative case above ever ran.
if ( ! class_exists( 'ActionScheduler' ) ) {
	class ActionScheduler {}
}
$res = snt_asb_site_health_result( $db, $now );
ok( false !== strpos( $res['actions'], 'tools.php?page=action-scheduler' ), 'with ActionScheduler loaded, actions links to Tools → Scheduled Actions' );

// ─── Group: registration wiring ───────────────────────────────────────
echo "\nGroup: registration — async test + REST route\n";
$tests = snt_asb_register_site_health_test( array() );
ok( isset( $tests['async']['sn_as_backlog'] ), 'registers as an async Site Health test' );
ok( ! empty( $tests['async']['sn_as_backlog']['has_rest'] ), 'async test declares has_rest' );
ok( false !== strpos( (string) $tests['async']['sn_as_backlog']['test'], 'signal-noise/v1/site-health/scheduled-actions' ), 'async test polls the module REST route' );

snt_asb_register_rest_route();
$route = $GLOBALS['__test_routes']['signal-noise/v1/site-health/scheduled-actions'] ?? null;
ok( is_array( $route ), 'REST route registered under signal-noise/v1' );
$GLOBALS['__test_can'] = false;
ok( is_callable( $route['permission_callback'] ) && false === call_user_func( $route['permission_callback'] ), 'route denies without manage_options' );
$GLOBALS['__test_can'] = true;
ok( true === call_user_func( $route['permission_callback'] ), 'route allows with manage_options' );

// ─── Summary ──────────────────────────────────────────────────────────
echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );
