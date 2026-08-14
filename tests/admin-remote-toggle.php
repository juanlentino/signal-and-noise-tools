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
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : ''; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }

require __DIR__ . '/../inc/admin-post-actions.php';

echo "Group: the toggle writes the option in both directions\n";
$GLOBALS['__options'] = array();
$flash = sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( true === get_option( 'sn_mcp_remote_enabled', false ), 'checked -> option true' );
ok( 'remote_enabled' === $flash, 'and reports the enabled flash' );

$flash = sn_handle_remote_toggle( array() );
ok( false === get_option( 'sn_mcp_remote_enabled', true ), 'unchecked (absent key) -> option false' );
ok( 'remote_disabled' === $flash, 'and reports the disabled flash' );

echo "Group: THE ONE THAT MATTERS — the wp-config constant beats the form\n";
define( 'SN_MCP_REMOTE_DISABLED', true );
$GLOBALS['__options'] = array();
$flash = sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( 'remote_constant_locked' === $flash, 'the form refuses when the constant kills the door' );
ok( ! array_key_exists( 'sn_mcp_remote_enabled', $GLOBALS['__options'] ), 'and writes NOTHING — a killed door cannot be re-opened from a web request' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): admin-remote-toggle.php\n"
	: "\nFAILURES ($pass passed, $fail failed): admin-remote-toggle.php\n";
exit( $fail > 0 ? 1 : 0 );
