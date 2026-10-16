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
$GLOBALS['__rwl_active'] = false;
function is_plugin_active( $file ) { return 0 === strpos( $file, 'rename-wp-login' ) ? ! empty( $GLOBALS['__rwl_active'] ) : ! empty( $GLOBALS['__wps_active'] ); }
function wp_login_url() { return 'https://example.test/wp-login.php'; }
function sn_setting( $key, $default = '' ) { return $GLOBALS['__slug'] ?? $default; }
$snt_plugin_dir = sys_get_temp_dir() . '/snt-leaf-login-' . getmypid();
@mkdir( $snt_plugin_dir . '/wps-hide-login', 0777, true );
file_put_contents( $snt_plugin_dir . '/wps-hide-login/wps-hide-login.php', '<?php' );
@mkdir( $snt_plugin_dir . '/rename-wp-login', 0777, true );
file_put_contents( $snt_plugin_dir . '/rename-wp-login/rename-wp-login.php', '<?php' );
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

// ── 2026-10-16, the leaf anatomy (docs/kit-leaf-anatomy.md).
$line = strpos( $kit, '<div class="snt-status-line">' );
ok( false !== $line && 1 === preg_match( '/<div class="snt-status-line"><os-cluster gap="8"><os-badge tone="success" title="Module active">Active<\/os-badge>/', $kit ), 'the leaf leads with one status line: the module state as a chip, its title the classic status title' );
$how = strpos( $kit, '<os-disclosure heading="How this works" hint="A custom slug stands in for /wp-login.php."' );
ok( false !== $how && $line < $how && false !== strpos( substr( $kit, $how ), 'Designed to mask the WordPress login surface' ) && false === strpos( substr( $kit, 0, $how ), 'Designed to mask' ), 'the module explained is a How this works fold under the status line, not the leaf\'s lead paragraph' );
ok( false !== strpos( $kit, '<os-disclosure heading="Emergency unlock" hint="No unlock constant set"' ) && false !== strpos( $kit, 'data-snt-fold="emergency-unlock" data-snt-open="0"' ), 'Emergency unlock is a Setup fold whose closed summary says no constant is set, and it starts closed' );
ok( false === strpos( $kit, 'tone="success"><b>Module active' ), 'the active state is no longer a success notice box (its sentence is the hint under the line)' );

// ── Escaping: a hostile slug never reaches the markup raw.
$GLOBALS['__slug'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'security', 'login' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile slug is escaped' );
$GLOBALS['__slug'] = 'my-door';

// ── Dormant state (wps-hide-login active).
$GLOBALS['__wps_active'] = true;
$kit = snt_leaf_paint( 'security', 'login' );
ok( false !== strpos( $kit, 'Module dormant' ) && false !== strpos( $kit, 'tone="warning"' ), 'the dormant state paints a warning notice' );
// 2026-10-12 audit: a dormant module's slug 404s, so the URL row gives the one
// that works, and drops the bookmark line; rename-wp-login stands it down too.
$classic = snt_leaf_classic_html( 'sn_admin_render_login_section' );
ok( false !== strpos( $kit, 'https://example.test/wp-login.php' ) && false === strpos( $kit, 'https://example.test/my-door' ) && false === strpos( $kit, 'Bookmark this URL' ) && false !== strpos( $classic, 'https://example.test/wp-login.php' ) && false === strpos( $classic, 'Bookmark this URL' ), 'dormant: both surfaces give the login URL that works, not the slug that 404s' );
$GLOBALS['__wps_active'] = false;
$GLOBALS['__rwl_active'] = true;
$kit     = snt_leaf_paint( 'security', 'login' );
$classic = snt_leaf_classic_html( 'sn_admin_render_login_section' );
ok( false !== strpos( $kit, 'Module dormant: conflict with rename-wp-login' ) && false === strpos( $kit, 'Module active' ) && false !== strpos( $classic, 'Module dormant: conflict with rename-wp-login' ) && false !== strpos( $kit, 'https://example.test/wp-login.php' ) && false === strpos( $kit, 'https://example.test/my-door' ), 'rename-wp-login stands the module down on both surfaces, as login-hide.php does, with the URL that works' );
$GLOBALS['__rwl_active'] = false;

// ── Locked state: the constant overrides the field, and no save is offered.
define( 'SN_LOGIN_SLUG', 'pinned' );
$classic = snt_leaf_classic_html( 'sn_admin_render_login_section' );
$kit     = snt_leaf_paint( 'security', 'login' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'locked: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ), 'locked: no Save on either surface (a constant decides the slug; the Save posted no slug and failed)' );
ok( false !== strpos( $kit, 'disabled' ) && 1 === substr_count( $kit, 'SN_LOGIN_SLUG constant in wp-config.php is overriding' ) && false === strpos( $kit, 'Slug locked by' ), 'locked: the field is disabled and the lock is explained once' );
ok( false !== strpos( $kit, 'value="pinned" disabled' ) && false !== strpos( $classic, 'value="pinned" disabled' ) && false !== strpos( $kit, 'https://example.test/pinned' ), 'locked: both surfaces show the constant\'s slug and its URL, not the saved setting (PR #2015 review)' );
ok( false !== strpos( $kit, '<div class="snt-form snt-form--rows"><os-field-row label="Slug">' ) && 1 === preg_match( '/\.snt-app \.snt-form--rows \{\s*display: grid;\s*gap: 14px;/', (string) file_get_contents( SNT_PATH . 'assets/os-app.css' ) ), 'locked: the rows keep the form\'s width and its 14px gap without a form' );
ok( 1 === preg_match( '/<os-field-row label="Slug"><os-text-field type="text" value="[^"]*" disabled><\/os-text-field><\/os-field-row><p class="snt-hint">Locked\./', $kit ) && false !== strpos( $kit, '<os-field-row label="Current login URL"><os-button class="snt-link snt-link--url" variant="link" os-action="door"' ) && false === strpos( $kit, 'snt-field-static' ), '#1600: the locked slug and the login URL are os-field-rows (the music leaf\'s locked-field shape), no hand-rolled .snt-field-static div' );
ok( false !== strpos( $kit, '<os-disclosure heading="Emergency unlock" hint="SN_LOGIN_SLUG is set"' ) && false !== strpos( $kit, 'data-snt-fold="emergency-unlock" data-snt-open="1"' ) && false !== strpos( $kit, '>Slug pinned in wp-config.php<' ), 'locked: the unlock fold opens and names the constant, and the status line says the slug is pinned' );
ok( false !== strpos( $kit, 'Option 2: disable' ) && false !== strpos( $kit, 'Restores /wp-login.php' ) && false !== strpos( $kit, 'SN_LOGIN_SLUG' ), 'both emergency-unlock options survive, labeled' );

// ── Bypassed state (SN_LOGIN_BYPASS constant set — one-way, so it must run last).
define( 'SN_LOGIN_BYPASS', true );
$kit = snt_leaf_paint( 'security', 'login' );
ok( false !== strpos( $kit, 'Module bypassed' ) && false !== strpos( $kit, '>Bypassed<' ) && false !== strpos( $kit, 'tone="warning"' ), 'the bypassed state paints a warning notice with the Bypassed badge' );
ok( 1 === preg_match( '/<div class="snt-status-line"><os-cluster gap="8"><os-badge tone="warning" title="Module bypassed">Bypassed<\/os-badge>/', $kit ) && false !== strpos( $kit, '<os-notice tone="warning"' ) && false !== strpos( $kit, 'hint="SN_LOGIN_BYPASS is set"' ) && false !== strpos( $kit, 'data-snt-fold="emergency-unlock" data-snt-open="1"' ), 'bypassed: the warning stays an open notice under an amber chip, and the unlock fold opens naming the bypass' );
$classicB = snt_leaf_classic_html( 'sn_admin_render_login_section' );
ok( false !== strpos( $kit, 'https://example.test/wp-login.php' ) && false === strpos( $kit, 'https://example.test/pinned' ) && false !== strpos( $classicB, 'https://example.test/wp-login.php' ) && false === strpos( $classicB, 'Bookmark this URL' ), 'bypassed: both surfaces give /wp-login.php, the URL that works, not the slug (PR #2015 review)' );

unlink( WP_PLUGIN_DIR . '/wps-hide-login/wps-hide-login.php' );
rmdir( WP_PLUGIN_DIR . '/wps-hide-login' );
unlink( WP_PLUGIN_DIR . '/rename-wp-login/rename-wp-login.php' );
rmdir( WP_PLUGIN_DIR . '/rename-wp-login' );
rmdir( WP_PLUGIN_DIR );

// ── Siblings pair; a stack is for steps. ──
// Measured live 2026-09-10; see the painter for the per-leaf numbers.
$kitP = snt_leaf_paint( 'security', 'login' );
ok(
	1 === substr_count( $kitP, snt_leaf_row() ),
	'the sibling sections are painted in 1 paired row(s) -- ' . substr_count( $kitP, snt_leaf_row() )
);
// Ordering, not a regex extract: `(.*?)</div>` stops at the first NESTED
// close, so it cannot delimit a row that contains divs. Position is enough --
// the row opens, then both siblings follow, with no third section between.
$posRow = strpos( $kitP, snt_leaf_row() );
$posA   = strpos( $kitP, 'Custom login slug' );
$posB   = strpos( $kitP, 'Emergency unlock' );
ok(
	false !== $posRow && false !== $posA && false !== $posB && $posRow < $posA && $posA < $posB,
	'...and the row opens before both siblings, in order'
);
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
