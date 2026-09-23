<?php
/**
 * Login lands admins in OpenStation only when no destination was asked for.
 * Run: php tests/openstation-login-redirect.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

class WP_User { public $caps; function __construct( $caps ) { $this->caps = $caps; } function has_cap( $c ) { return in_array( $c, $this->caps, true ); } }
class WP_Error {}
function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function add_filter() {}
$GLOBALS['os'] = true;
function snt_os_active() { return $GLOBALS['os']; }
require __DIR__ . '/../inc/openstation-login-redirect.php';

$os    = 'https://x.test/wp-admin/admin.php?page=openstation';
$admin = new WP_User( array( 'manage_options' ) );
$def   = 'https://x.test/wp-admin/';

ok( $os === snt_os_login_redirect( $def, '', $admin ), 'admin, no redirect_to: OpenStation' );
ok( $os === snt_os_login_redirect( $def, $def, $admin ), 'admin, redirect_to = the default /wp-admin/: OpenStation' );
ok( $os === snt_os_login_redirect( $def, 'https://x.test/wp-admin', $admin ), 'no trailing slash is still the default' );
$post = 'https://x.test/wp-admin/post.php?post=5&action=edit';
ok( $post === snt_os_login_redirect( $post, $post, $admin ), 'a specific destination is kept' );
ok( $def === snt_os_login_redirect( $def, '', new WP_User( array( 'read' ) ) ), 'non-admins keep the default' );
ok( $def === snt_os_login_redirect( $def, '', new WP_Error() ), 'a failed login is untouched' );
$GLOBALS['os'] = false;
ok( $def === snt_os_login_redirect( $def, '', $admin ), 'no OpenStation installed: untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
