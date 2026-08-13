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

echo "Group: the slug list names exactly one member, and it is not on either MCP allowlist\n";
ok( array( $REMOTE ) === sn_mcp_remote_slugs(), 'the remote list holds exactly the one Increment 1 slug' );

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
