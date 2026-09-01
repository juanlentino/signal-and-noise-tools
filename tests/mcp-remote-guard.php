<?php
/**
 * Tests: the remote analytics door's kill switch FAILS CLOSED on absence.
 *
 * This is the inverse of every other switch in the plugin, and the inversion is
 * the security property. `sn_mcp_read_enabled` absent means "the owner never
 * turned it off" and the read door is open. `sn_mcp_remote_enabled` absent means
 * "the owner never turned it ON" and the remote door is shut.
 *
 * THE ASSERTION THAT MATTERS MOST: a caller holding the capability, asking for a
 * slug that IS on the remote list, is still refused when the option is absent.
 * If that ever passes, the remote surface ships live instead of shipping shut.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][] = $c; return true; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }

$GLOBALS['__caps'] = array();
function current_user_can( $c ) { return ! empty( $GLOBALS['__caps'][ $c ] ); }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';

$REMOTE = 'signal-noise/remote-get-analytics-summary';

echo "Group: the pure decision inverts the read door's absence semantics\n";
ok( true  === sn_mcp_remote_kill_switch_decision( false, false ), 'option off  -> door shut' );
ok( false === sn_mcp_remote_kill_switch_decision( false, true ),  'option on   -> door open' );
ok( true  === sn_mcp_remote_kill_switch_decision( true,  true ),  'constant beats an enabled option' );

echo "Group: the live switch defaults SHUT when the option was never written\n";
$GLOBALS['__options'] = array();
ok( true === sn_mcp_remote_kill_switch_engaged(), 'absent option -> engaged (fail CLOSED)' );
$GLOBALS['__options']['sn_mcp_remote_enabled'] = true;
ok( false === sn_mcp_remote_kill_switch_engaged(), 'option true -> not engaged' );

echo "Group: the slug list names exactly eight members, and it is not on either MCP allowlist\n";
ok( array(
	'signal-noise/remote-get-analytics-summary',
	'signal-noise/remote-get-analytics-events',
	'signal-noise/remote-get-insights',
	'signal-noise/remote-get-narration',
	'signal-noise/remote-uptime-status',
	'signal-noise/remote-get-health-scan',
	'signal-noise/remote-get-rss-stats',
	'signal-noise/remote-get-deploy-status',
	// v13.52.0 — the three ratified twins (D-rulings, docs/BACKLOG.md).
	// anchor is deliberately absent: RULED LOCAL (D1).
	'signal-noise/remote-provenance-integrity-status',
	'signal-noise/remote-machine-readers-summary',
	'signal-noise/remote-cron-health-summary',
	// v13.61.0 — weave Phase 4: two Search Console twins; crossexam stays local.
	'signal-noise/remote-search-performance',
	'signal-noise/remote-search-drift',
) === sn_mcp_remote_slugs(), 'the remote list holds exactly the thirteen slugs: eight from Increments 1+2, three ratified 2026-09-01, two Search Console twins (weave Phase 4)' );

echo "Group: all three gates must pass, and each alone is insufficient\n";
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );
ok( true === sn_remote_analytics_allows( $REMOTE ), 'switch on + capability + listed slug -> allowed' );

$GLOBALS['__options'] = array();
ok( false === sn_remote_analytics_allows( $REMOTE ), 'THE ONE THAT MATTERS: capability held, slug listed, switch ABSENT -> refused' );

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array();
ok( false === sn_remote_analytics_allows( $REMOTE ), 'switch on, capability absent -> refused' );

$GLOBALS['__caps'] = array( 'manage_options' => true );
ok( false === sn_remote_analytics_allows( $REMOTE ), 'a manage_options admin WITHOUT the remote capability -> refused' );

$GLOBALS['__caps'] = array( 'sn_read_remote_analytics' => true );
ok( false === sn_remote_analytics_allows( 'signal-noise/get-post-content' ), 'a corpus slug is not on the remote list -> refused' );
ok( false === sn_remote_analytics_allows( 'signal-noise/sn-apply' ), 'a write slug -> refused' );
ok( false === sn_remote_analytics_allows( '' ), 'an empty slug -> refused' );

echo "Group: SCOPE STABILITY — registering a new ability must not widen the remote surface\n";
// The owner's stated test obligation. A gate whose reach grows when someone
// registers an ability tomorrow is the exact failure this design exists to
// prevent, and this is the assertion that would catch it.
$fixture = 'signal-noise/fixture-registered-after-the-gate';
ok( false === sn_remote_analytics_allows( $fixture ), 'a brand-new ability slug is out of remote scope BY DEFAULT' );
ok( ! in_array( $fixture, sn_mcp_remote_slugs(), true ), 'and it did not appear on the remote list' );

echo "Group: the switch reaches the native run route, not just the predicate\n";
// Without these, sn_mcp_remote_guard_run_route() would be the one control in the
// file that nothing asserts — and a kill switch no test exercises is
// indistinguishable from one that does not work. It exists precisely so a remote
// slug held off the read allowlist cannot reach
// POST /wp-abilities/v1/abilities/<slug>/run with no switch consulted at all.
// This group runs BEFORE the constant group below on purpose: define() cannot be
// undone, so once SN_MCP_REMOTE_DISABLED exists the "switch open" half of these
// assertions could never be reached.

/** A minimal REST request stand-in: the guard only ever asks for the route. */
class RemoteG_Req {
	private $route;
	public function __construct( $r ) { $this->route = $r; }
	public function get_route() { return $this->route; }
}

$remote_route = '/wp-abilities/v1/abilities/' . $REMOTE . '/run';

$GLOBALS['__options'] = array();
$denied               = sn_mcp_remote_guard_run_route( null, null, new RemoteG_Req( $remote_route ) );
ok( is_wp_error( $denied ) && 'sn_mcp_remote_disabled' === $denied->get_error_code(), 'switch engaged -> the remote run route is refused as sn_mcp_remote_disabled' );

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
ok( null === sn_mcp_remote_guard_run_route( null, null, new RemoteG_Req( $remote_route ) ), 'switch open -> the guard stands down and claims nothing' );

// THE NEGATIVE ONE: the remote switch must not darken slugs that are not its
// business. The admin analytics ability is reached by the owner's own laptop
// through the read door, and a remote kill that also closed it would be a worse
// bug than the gap this dispatcher was added to close.
$GLOBALS['__options'] = array();
ok( null === sn_mcp_remote_guard_run_route( null, null, new RemoteG_Req( '/wp-abilities/v1/abilities/signal-noise/get-analytics-summary/run' ) ), 'THE NEGATIVE ONE: an admin analytics run route is untouched by the REMOTE switch' );

$prior = new WP_Error( 'someone_elses_refusal', 'x', array( 'status' => 401 ) );
ok( $prior === sn_mcp_remote_guard_run_route( $prior, null, new RemoteG_Req( $remote_route ) ), 'a non-null prior result passes through untouched — the guard never overrides another answer' );

ok( null === sn_mcp_remote_guard_run_route( null, null, new RemoteG_Req( '/wp/v2/posts' ) ), 'an unrelated REST route is not a run route and is untouched' );

echo "Group: the wp-config constant wins over an enabled option, LIVE and not only in the predicate\n";
// Defined last, because define() cannot be undone: every assertion after this
// point sees the constant. An attacker holding only a leaked credential can
// never flip a wp-config constant, which is why it is the strongest lever.
define( 'SN_MCP_REMOTE_DISABLED', true );
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );
ok( true === sn_mcp_remote_kill_switch_engaged(), 'the constant engages the switch despite the option being on' );
ok( false === sn_remote_analytics_allows( $REMOTE ), 'and the gate refuses a fully-credentialled caller' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-remote-guard.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-remote-guard.php\n";
exit( $fail > 0 ? 1 : 0 );
