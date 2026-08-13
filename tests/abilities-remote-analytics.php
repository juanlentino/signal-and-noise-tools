<?php
/**
 * Tests: the remote analytics ability is a SEPARATE slug, gated ONLY by the
 * remote callback, and absent from the laptop read door.
 *
 * The alternative was a union callback on the existing get-analytics-summary
 * (manage_options OR remote scope). A union can only add allow paths, so it
 * could not break the admin caller — but it would put remote logic inside an
 * admin-facing gate, where widening the remote branch later widens an admin
 * surface too. A separate slug keeps snt_ability_perm_manage_options untouched.
 *
 * THE ASSERTION THAT MATTERS MOST is the negative one: the remote slug must not
 * appear on sn_mcp_allowlist(). If it does, this increment quietly handed the
 * laptop door a tool it was never meant to gain.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
// Identity pass-through: sn_mcp_allowlist()/sn_mcp_rw_allowlist() end in
// apply_filters(). Nothing here registers a callback, so this suite reads the
// STATIC lists — which is the thing the negative assertion is about.
function apply_filters( $h, $v ) { return $v; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }

$GLOBALS['__caps'] = array();
function current_user_can( $c ) { return ! empty( $GLOBALS['__caps'][ $c ] ); }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// Capture registrations instead of standing up the Abilities API.
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/abilities-remote-analytics.php';

// Fire the registration hook the plugin would fire.
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

$REMOTE = 'signal-noise/remote-get-analytics-summary';
$ADMIN  = 'signal-noise/get-analytics-summary';

echo "Group: the remote ability registered, and it registered ONLY itself\n";
// The obvious phrasing here was `ok( $REMOTE !== $ADMIN, ... )`. It was rejected
// because it compares two constants THIS FILE declares: no change to the code
// under test could ever red it, so it reads as coverage while proving nothing.
// A tautological assertion is worse than a missing one for exactly that reason.
//
// The property it was gesturing at is real and testable. Only
// inc/abilities-remote-analytics.php has been required at this point, so the
// admin slug appearing in the registry would mean the remote file registered or
// shadowed it — which is the concrete way "it is its own slug" could be false.
ok( isset( $GLOBALS['__abilities'][ $REMOTE ] ), 'the remote ability registered' );
ok( ! isset( $GLOBALS['__abilities'][ $ADMIN ] ), 'and the remote file did NOT register or shadow the admin analytics slug' );
ok( 1 === count( $GLOBALS['__abilities'] ), 'exactly one ability registered — no second, unexpected registration' );

$reg = $GLOBALS['__abilities'][ $REMOTE ];

echo "Group: it is gated ONLY by the remote callback\n";
ok( 'snt_ability_perm_remote_analytics_summary' === $reg['permission_callback'], 'permission_callback is the remote per-slug callback' );
ok( 'snt_ability_perm_manage_options' !== $reg['permission_callback'], 'and is NOT the manage_options helper' );

echo "Group: it delegates to the SAME execute callback — one implementation, two doors\n";
ok( 'sn_ability_get_analytics_summary' === $reg['execute_callback'], 'execute_callback is the existing analytics reader' );

echo "Group: THE NEGATIVE ONE — the laptop read door gains nothing\n";
ok( ! in_array( $REMOTE, sn_mcp_allowlist(), true ), 'the remote slug is ABSENT from the MCP read allowlist' );
ok( ! in_array( $REMOTE, sn_mcp_rw_allowlist(), true ), 'and absent from the write allowlist' );
ok( in_array( $ADMIN, sn_mcp_allowlist(), true ), 'while the admin analytics slug is still on the read allowlist (unchanged)' );

echo "Group: the per-slug callback honours all three gates\n";
// This group deliberately does NOT claim to prove the callback passes its own
// slug as a literal, because with one member in sn_mcp_remote_slugs() there is
// no other value to mutate the literal into: swapping it for any other listed
// slug is not an available mutation, so no assertion here could catch it.
// That becomes a real gap at Increment 2, when a second remote slug exists —
// at which point this group needs an assertion that the callback is refused for
// a DIFFERENT listed slug's conditions. Recorded here so it is not rediscovered.
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );
ok( true === snt_ability_perm_remote_analytics_summary(), 'switch on + capability -> allowed' );

$GLOBALS['__options'] = array();
ok( false === snt_ability_perm_remote_analytics_summary(), 'switch absent -> refused (fail closed reaches the callback)' );

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'manage_options' => true );
ok( false === snt_ability_perm_remote_analytics_summary(), 'a manage_options admin without the remote capability -> refused' );

echo "Group: the ability is annotated read-only, like its admin twin\n";
ok( true === $reg['meta']['annotations']['readonly'], 'annotated readonly' );
ok( true === $reg['meta']['annotations']['idempotent'], 'annotated idempotent' );

echo "Group: SCHEMA PARITY — the duplicated schemas may not drift apart\n";
// The remote registration copies its schemas rather than extracting shared ones,
// because extracting would modify the admin registration this increment promises
// to leave unchanged. That trade is only safe while something notices drift.
require_once __DIR__ . '/../inc/abilities-analytics.php';
$GLOBALS['__abilities'] = array();
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

$r = $GLOBALS['__abilities'][ $REMOTE ];
$a = $GLOBALS['__abilities'][ $ADMIN ];

ok( $r['input_schema'] === $a['input_schema'], 'input_schema is identical to the admin ability\'s' );
ok( $r['output_schema'] === $a['output_schema'], 'output_schema is identical to the admin ability\'s' );
ok( $r['execute_callback'] === $a['execute_callback'], 'both dispatch to the same reader' );
ok( $r['permission_callback'] !== $a['permission_callback'], 'but their gates are different — that is the whole point' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): abilities-remote-analytics.php\n"
	: "\nFAILURES ($pass passed, $fail failed): abilities-remote-analytics.php\n";
exit( $fail > 0 ? 1 : 0 );
