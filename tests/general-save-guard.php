<?php
/**
 * The General save guard (16.7.3): admin_email leaves the general group, nothing else moves, other groups untouched, odd input passes through. Run: php tests/general-save-guard.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__g'] = array();
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__g'][ $t ][] = array( $c, $p ); return true; }
require __DIR__ . '/../inc/general-save-guard.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }

$core = array( 'blogname', 'blogdescription', 'site_icon', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'timezone_string', 'WPLANG', 'new_admin_email', 'siteurl', 'home', 'users_can_register', 'default_role' );
$in   = array( 'general' => array_merge( $core, array( 'preferred_languages', 'admin_email' ) ), 'reading' => array( 'posts_per_page', 'admin_email' ) );
$out  = sn_general_save_guard( $in );
ok( array_merge( $core, array( 'preferred_languages' ) ) === $out['general'], 'admin_email leaves the general group; every other name stays in order, new_admin_email included' );
ok( array( 'posts_per_page', 'admin_email' ) === $out['reading'], 'other groups are not touched' );
ok( array( 'general' => $core ) === sn_general_save_guard( array( 'general' => $core ) ), 'Core\'s own list is returned as is' );
ok( 'x' === sn_general_save_guard( 'x' ) && array() === sn_general_save_guard( array() ) && array( 'general' => 'nope' ) === sn_general_save_guard( array( 'general' => 'nope' ) ), 'non-arrays and empty maps pass through untouched' );
ok( isset( $GLOBALS['__g']['allowed_options'] ) && 'sn_general_save_guard' === $GLOBALS['__g']['allowed_options'][0][0] && 20 === $GLOBALS['__g']['allowed_options'][0][1], 'hooked on allowed_options after Core\'s option_update_filter (10)' );
echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
