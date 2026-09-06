<?php
/**
 * Native window leaf: Security → Login URL (apps/sn-dashboard/parts/leaves/security-login.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * names and the same sn_action, in both the editable and the locked state,
 * and none of wp-admin's markup. The exemplar every fanned-out leaf suite
 * follows.
 *
 * Run: php tests/os-leaf-security-login.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers.
$GLOBALS['__wps_active'] = false;
function is_plugin_active( $file ) { return ! empty( $GLOBALS['__wps_active'] ); }
function sn_setting( $key, $default = '' ) { return $GLOBALS['__slug'] ?? $default; }
$snt_plugin_dir = sys_get_temp_dir() . '/snt-leaf-login-' . getmypid();
@mkdir( $snt_plugin_dir . '/wps-hide-login', 0777, true );
file_put_contents( $snt_plugin_dir . '/wps-hide-login/wps-hide-login.php', '<?php' );
define( 'WP_PLUGIN_DIR', $snt_plugin_dir );

require SNT_PATH . 'inc/admin-forms/login.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/security-login.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['security/login'] ), 'the painter is registered under security/login' );

// ── Editable state: the same field, the same action.
$GLOBALS['__slug'] = 'my-door';
$classic = snt_leaf_classic_html( 'sn_admin_render_login_section' );
$kit     = snt_leaf_paint( 'security', 'login' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'save_login' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is save_login, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ), 'the form is an os-form dispatching post' );
ok( false !== strpos( $kit, '<os-text-field name="login_slug" type="text" value="my-door"' ), 'the slug field is a kit text field carrying the current slug' );
ok( false !== strpos( $kit, 'https://example.test/my-door' ), 'the current login URL is shown' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'Module active' ), 'the active state paints a success notice' );
ok( false !== strpos( $kit, '<os-code' ) && false !== strpos( $kit, 'SN_LOGIN_BYPASS' ), 'the emergency unlock callout survives as kit code' );

// ── Escaping: a hostile slug never reaches the markup raw.
$GLOBALS['__slug'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'security', 'login' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile slug is escaped' );
$GLOBALS['__slug'] = 'my-door';

// ── Dormant state (wps-hide-login active).
$GLOBALS['__wps_active'] = true;
$kit = snt_leaf_paint( 'security', 'login' );
ok( false !== strpos( $kit, 'Module dormant' ) && false !== strpos( $kit, 'tone="warning"' ), 'the dormant state paints a warning notice' );
$GLOBALS['__wps_active'] = false;

// ── Locked state: the constant overrides the field, and no save is offered.
define( 'SN_LOGIN_SLUG', 'pinned' );
$classic = snt_leaf_classic_html( 'sn_admin_render_login_section' );
$kit     = snt_leaf_paint( 'security', 'login' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'locked: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array( 'save_login' ) === snt_leaf_actions( $kit ), 'locked: save_login still offered, as on the classic leaf' );
ok( false !== strpos( $kit, 'disabled' ) && false !== strpos( $kit, 'Slug locked' ), 'locked: the field is disabled and the lock is explained' );
ok( false !== strpos( $kit, 'Option 2' ) && false !== strpos( $kit, 'Restores /wp-login.php' ) && false !== strpos( $kit, 'SN_LOGIN_SLUG' ), 'both emergency-unlock options survive, labelled' );

// ── Bypassed state (SN_LOGIN_BYPASS constant set — one-way, so it must run last).
define( 'SN_LOGIN_BYPASS', true );
$kit = snt_leaf_paint( 'security', 'login' );
ok( false !== strpos( $kit, 'Module bypassed' ) && false !== strpos( $kit, '>Bypassed<' ) && false !== strpos( $kit, 'tone="warning"' ), 'the bypassed state paints a warning notice with the Bypassed badge' );

unlink( WP_PLUGIN_DIR . '/wps-hide-login/wps-hide-login.php' );
rmdir( WP_PLUGIN_DIR . '/wps-hide-login' );
rmdir( WP_PLUGIN_DIR );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
