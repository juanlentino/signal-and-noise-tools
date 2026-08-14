<?php
/**
 * Tests: the remote-door toggle, and the constant that overrides it.
 *
 * This is the phone-reachable control. sn_mcp_remote_enabled is absent by
 * default, so without this the door needs WP-CLI to turn ON and WP-CLI to turn
 * OFF — a terminal in both directions, which is exactly what the owner cannot
 * reach from a phone.
 *
 * THE ASSERTION THAT MATTERS MOST is that SN_MCP_REMOTE_DISABLED beats the form.
 * A wp-config kill must not be re-openable from a web request, or the constant
 * is decorative. Same shape as sn_handle_cf_save() refusing to override
 * SN_CLOUDFLARE_API_TOKEN.
 *
 * ORDERING IS LOAD-BEARING. SN_MCP_REMOTE_DISABLED is define()d partway down and
 * cannot be undefined, so the constant-locked group MUST REMAIN LAST. Everything
 * that needs an unlocked door — every write pin, and both round-trip pins — has
 * to run above that define. A new group appended below it will see a permanently
 * killed door and fail in a way that looks like a handler bug rather than a test
 * ordering bug.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : ''; }

/**
 * THESE STUBS MODEL WORDPRESS'S STORAGE TRANSFORM, NOT A PLAIN ARRAY.
 *
 * WordPress does NOT preserve booleans through the options table. update_option()
 * serializes on the way in and the value comes back out of wp_options as a
 * STRING: `true` round-trips as '1' and `false` round-trips as '' (the empty
 * string). A site never hands anyone back the bool that was written.
 *
 * That is why sn_mcp_remote_kill_switch_engaged() reads
 * `(bool) get_option( SN_MCP_REMOTE_ENABLED_OPTION, false )` — the cast is what
 * turns '1'/'' back into true/false, and it is the only reason the door works.
 *
 * A stub that returned the raw boolean the handler passed in would validate a
 * shape WordPress never produces. Under such a stub, "simplifying" the guard's
 * cast to `true === get_option( ... )` would break production — every real site
 * would read '1', which is not identical to true, and the door would refuse to
 * open — while this suite stayed green. Modelling the transform here is what
 * makes the round-trip pins below able to catch that refactor.
 */
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v ? '1' : ''; return true; }

require __DIR__ . '/../inc/admin-post-actions.php';
require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';

echo "Group: the toggle writes the option in both directions\n";
$GLOBALS['__options'] = array();
$flash = sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( '1' === get_option( 'sn_mcp_remote_enabled', null ), 'checked -> option stored as WordPress stores true' );
ok( 'remote_enabled' === $flash, 'and reports the enabled flash' );

$flash = sn_handle_remote_toggle( array() );
ok( '' === get_option( 'sn_mcp_remote_enabled', null ), 'unchecked (absent key) -> option stored as WordPress stores false' );
ok( 'remote_disabled' === $flash, 'and reports the disabled flash' );

echo "Group: the toggle CONTROLS THE GUARD — what it achieved, not what it wrote\n";
// The pins above assert what the handler WROTE. These assert what it ACHIEVED:
// that flipping the checkbox changes whether the door is actually open, read
// through the same predicate production reads. This is the property the owner
// cares about, and it is the one that survives a refactor of either side.
// Must run while SN_MCP_REMOTE_DISABLED is still absent — see the file header.
$GLOBALS['__options'] = array();
sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( false === sn_mcp_remote_kill_switch_engaged(), 'checked -> the door is OPEN' );

sn_handle_remote_toggle( array() );
ok( true === sn_mcp_remote_kill_switch_engaged(), 'unchecked -> the door is SHUT' );

echo "Group: THE ONE THAT MATTERS — the wp-config constant beats the form\n";
// MUST BE LAST: the define below cannot be undone for the rest of this process.
define( 'SN_MCP_REMOTE_DISABLED', true );
$GLOBALS['__options'] = array();
$flash = sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( 'remote_constant_locked' === $flash, 'the form refuses when the constant kills the door' );
ok( ! array_key_exists( 'sn_mcp_remote_enabled', $GLOBALS['__options'] ), 'and writes NOTHING — a killed door cannot be re-opened from a web request' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): admin-remote-toggle.php\n"
	: "\nFAILURES ($pass passed, $fail failed): admin-remote-toggle.php\n";
exit( $fail > 0 ? 1 : 0 );
