<?php
/**
 * Settings › Security, both twins: the native pane
 * (apps/sn-dashboard/parts/leaves/settings-security.php) and the classic
 * section (inc/admin-forms/security-settings.php).
 *
 * The pane holds two settings. The login address came WHOLE from Security ›
 * Login URL (status, slug form, current URL, emergency unlock): that leaf was
 * nothing but the setting and its own state, so it moved and
 * sn_admin_subtab_moves() redirects it. Audit log "Retention (days)" came
 * from Security › Audit log, which keeps its log and one line saying where
 * the field went (tests/os-leaf-security-audit-log.php).
 *
 * The login states below are the old Security › Login URL suite, every
 * assertion kept, painted on the pane. The classic slug form is not retyped:
 * the pane calls sn_admin_render_login_section() and the suite pins that its
 * output is on the pane byte for byte.
 *
 * Run: php tests/os-leaf-settings-security.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The pane's own readers.
$GLOBALS['__wps_active'] = false;
$GLOBALS['__rwl_active'] = false;
$GLOBALS['__retention']  = 45;
function is_plugin_active( $file ) { return 0 === strpos( $file, 'rename-wp-login' ) ? ! empty( $GLOBALS['__rwl_active'] ) : ! empty( $GLOBALS['__wps_active'] ); }
function wp_login_url() { return 'https://example.test/wp-login.php'; }
function sn_setting( $key, $default = '' ) { return 'audit.retention_days' === $key ? $GLOBALS['__retention'] : ( $GLOBALS['__slug'] ?? $default ); }
$snt_plugin_dir = sys_get_temp_dir() . '/snt-leaf-login-' . getmypid();
@mkdir( $snt_plugin_dir . '/wps-hide-login', 0777, true );
file_put_contents( $snt_plugin_dir . '/wps-hide-login/wps-hide-login.php', '<?php' );
@mkdir( $snt_plugin_dir . '/rename-wp-login', 0777, true );
file_put_contents( $snt_plugin_dir . '/rename-wp-login/rename-wp-login.php', '<?php' );
define( 'WP_PLUGIN_DIR', $snt_plugin_dir );

require SNT_PATH . 'inc/admin-forms/login.php';
require SNT_PATH . 'inc/audit-log-admin.php';
require SNT_PATH . 'inc/admin-legacy-redirect.php';
require SNT_PATH . 'inc/admin-tabs.php';
if ( is_file( SNT_PATH . 'inc/admin-forms/security-settings.php' ) ) { // Absent before the change: the assertions go red, the suite still reports.
	require SNT_PATH . 'inc/admin-forms/security-settings.php';
}
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/settings-security.php';

/** The classic pane, or '' before the change. */
function sec_classic() { return function_exists( 'sn_admin_render_security_settings_section' ) ? snt_leaf_classic_html( 'sn_admin_render_security_settings_section' ) : ''; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/security'] ) && ! isset( \SignalNoise\OpenStationHost\Dashboard\painters()['security/login'] ), 'the painter is registered under settings/security, and security/login has none' );

// ── Editable state: the same field, the same action.
$GLOBALS['__slug'] = 'my-door';
$classic = sec_classic();
$kit     = snt_leaf_paint( 'settings', 'security' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'audit_retention_days', 'login_slug' ) === snt_leaf_names( $kit ), 'the pane holds exactly two settings: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( array( 'audit_save_retention', 'save_login' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the two existing actions, unchanged, on both twins: ' . implode( ',', snt_leaf_actions( $classic ) ) );
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

// ── The registry: Settings has a second pane, Security lost a leaf.
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
$panes = (array) ( $by_tab['settings']['sub_tabs'] ?? array() );
ok( array( 'general', 'notifications', 'security' ) === array_slice( array_keys( $panes ), 0, 3 ) && 'Security' === ( $panes['security']['label'] ?? '' ) && 'sn_admin_render_security_settings_section' === ( $panes['security']['render'] ?? '' ) && ! empty( $panes['security']['wide'] ), 'Settings\' third pane is security ("Security"; the second until General took first place), after notifications, wide: it paints its own two cards (the third, AI, is tests/os-leaf-settings-ai.php)' );
ok( array( 'login-defense', 'firewall', 'audit-log' ) === array_keys( $by_tab['security']['sub_tabs'] ), 'the Security tab keeps login-defense, firewall and audit-log; login left it' );
ok( false === stripos( (string) $by_tab['security']['subtitle'], 'login URL' ) && false !== strpos( (string) $by_tab['settings']['subtitle'], 'Security' ), 'the two subtitles follow: Security no longer promises the login URL, Settings names the pane' );

// ── Old addresses: GET bookmarks 301, in-flight POSTs land, one hop each.
$dest = array( 'tab' => 'settings', 'sub' => 'security', 'anchor' => null );
ok( $dest === sn_admin_canonical_destination( 'security', 'login' ), 'security/login is a moved leaf: its bookmark resolves to settings/security' );
ok( $dest === sn_admin_post_redirect_target( 'security', 'login' ), 'a save posted from the old leaf lands on the pane (the same resolver as the 301)' );
ok( $dest === sn_admin_canonical_destination( 'login', '' ) && 'login' === sn_admin_page_tab_for_slug( 'sn-login' ), 'the pre-v3.8 slug (tab=login, page=sn-login) names the pane directly: the resolver runs once and does not chain' );
ok( $dest === sn_admin_post_redirect_target( 'settings', 'security' ) && null === sn_admin_canonical_destination( 'settings', 'security' ), 'a save from the pane returns to the pane' );
ok( null === sn_admin_canonical_destination( 'security', 'audit-log' ) && array( 'tab' => 'security', 'sub' => 'audit-log', 'anchor' => null ) === sn_admin_post_redirect_target( 'security', 'audit-log' ), 'security/audit-log is NOT redirected: a field left it, the leaf stayed' );

// ── The leaf strip shows: two leaves are a choice, one was not.
ob_start();
sn_admin_render_sub_tabs( 'settings', 'security' );
$strip = (string) ob_get_clean();
ok( 1 === preg_match( '#^<nav class="sn-sub-tabs"[^>]*><a href="[^"]*tab=settings&sub=general" class="sn-sub-tab">General</a><a href="[^"]*tab=settings&sub=notifications" class="sn-sub-tab">Notifications and reports</a><a href="[^"]*tab=settings&sub=security" class="sn-sub-tab is-active" aria-current="page">Security</a><a href="[^"]*tab=settings&sub=ai" class="sn-sub-tab">AI</a><a href="[^"]*tab=settings&sub=front-end" class="sn-sub-tab">Front end</a><a href="[^"]*tab=settings&sub=accounts" class="sn-sub-tab">Accounts and connections</a><a href="[^"]*tab=settings&sub=analytics" class="sn-sub-tab">Analytics</a></nav>$#', $strip ), 'classic: the Settings tab paints its sub-tab strip, Security active: ' . $strip );
ok( array( 'general' => 'General', 'notifications' => 'Notifications and reports', 'security' => 'Security', 'ai' => 'AI', 'front-end' => 'Front end', 'accounts' => 'Accounts and connections', 'analytics' => 'Analytics' ) === \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'settings' ), 'native: the frame lists the seven panes for the strip' );

// ── The classic slug form is the old one, byte for byte (nonce, action, field, hint, URL, Save, unlock).
$old_leaf = snt_leaf_classic_html( 'sn_admin_render_login_section' );
ok( '' !== $classic && false !== strpos( $classic, $old_leaf ), 'classic: everything sn_admin_render_login_section() paints is on the pane unchanged' );
ok( 1 === preg_match( '#<form method="post" action="https://example\.test/wp-admin/admin-post\.php[^"]*"><input type="hidden" name="_wpnonce" value="nonce-sn_save_login"><input type="hidden" name="action" value="sn_save_login">#', $old_leaf ) && false !== strpos( $old_leaf, '<label class="sn-field-label" for="sn_login_slug">Slug</label><input type="text" id="sn_login_slug" name="login_slug" value="my-door" placeholder="sn-login"><p class="sn-field-helper">Letters, numbers, dashes only.' ) && false !== strpos( $old_leaf, 'Bookmark this URL.' ) && false !== strpos( $old_leaf, 'Emergency unlock' ), 'classic: that form carries its nonce, its action, the labelled Slug field with its hint, the URL line and the unlock note' );
ok( 1 === preg_match( '#<os-form class="snt-form" os-action="post"[^>]*submit-label="Save"[^>]*><os-field-row label="Slug" hint="Letters, numbers, dashes only[^"]*"><os-text-field name="login_slug" type="text" value="my-door" placeholder="sn-login">.*<os-field-row label="Current login URL">(?:(?!</os-form>).)*<input type="hidden" name="action" value="sn_save_login"><input type="hidden" name="_wpnonce" value="nonce-sn_save_login">#s', $kit ), 'native: the slug form keeps its label, hint, placeholder, URL row, action and nonce' );
ok( 2 === substr_count( $kit, 'https://example.test/my-door' ) && 1 === substr_count( $kit, 'value="my-door"' ) && 3 === substr_count( $kit, 'my-door' ), 'native: the slug is printed where it was (the field, and the URL as link text and target), nowhere new' );

// ── Audit log retention: the same field, hint, bounds and action, open on the pane.
$hint = 'How long to keep the daily counts, the successful-login records and the change records, 7 to 365 days. A daily cleanup deletes anything older.';
ok( 1 === preg_match( '#<form method="post" action="https://example\.test/wp-admin/admin-post\.php[^"]*" class="sn-fieldset"><input type="hidden" name="_wpnonce" value="nonce-sn_audit_save_retention"><input type="hidden" name="action" value="sn_audit_save_retention"><h2 class="sn-fieldset-h">Audit log retention</h2><label class="sn-field-label" for="sn_audit_retention">Retention \(days\)</label><input type="number" id="sn_audit_retention" name="audit_retention_days" class="small-text" min="7" max="365" value="45">#', $classic ) && false !== strpos( $classic, '<p class="sn-field-helper">' . $hint . '</p>' ) && false !== strpos( $classic, '>Save retention</button>' ), 'classic: the retention form with its nonce, action, label, bounds, value, hint and Save' );
ok( 1 === preg_match( '#heading="Audit log retention"[^>]*>.*<os-form class="snt-form" os-action="post"[^>]*submit-label="Save retention"[^>]*><os-field-row label="Retention \(days\)" hint="' . preg_quote( $hint, '#' ) . '"><os-number-field name="audit_retention_days" value="45" min="7" max="365">(?:(?!</os-form>).)*value="sn_audit_save_retention"><input type="hidden" name="_wpnonce" value="nonce-sn_audit_save_retention">#s', $kit ), 'native: the retention form with its label, hint, bounds, value, action and nonce' );
ok( false === strpos( $kit, '<os-disclosure heading="Retention"' ) && false === strpos( $kit, '<os-disclosure heading="Audit log retention"' ), 'native: on a settings pane the setting is open, not a fold' );
ok( 1 === preg_match( '#^<div class="sn-2up"><div class="sn-fieldset"><h2 class="sn-fieldset-h">Login URL</h2>.*</div><form [^>]*class="sn-fieldset">.*</form></div>$#s', $classic ), 'classic: two cards side by side in .sn-2up, the login address then retention' );
ok( false === strpos( $kit . $classic, "\xe2\x80\x94" ) || false !== strpos( $old_leaf, "\xe2\x80\x94" ), 'no em dash in new text on either twin' );

// ── Every sentence that sent a reader to the old spot names the pane now.
foreach ( array(
	'inc/security-watches.php'      => 'Save the Login URL again in S&N Home, Settings › Security:',
	'inc/login-door-leak-check.php' => 'then change the Login URL in Settings › Security (one save).',
) as $file => $want ) {
	ok( false !== strpos( (string) file_get_contents( SNT_PATH . $file ), $want ), "$file names Settings › Security" );
}

// ── Escaping: a hostile slug never reaches the markup raw.
$GLOBALS['__slug'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'settings', 'security' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile slug is escaped' );
$GLOBALS['__slug'] = 'my-door';

// ── Dormant state (wps-hide-login active).
$GLOBALS['__wps_active'] = true;
$kit = snt_leaf_paint( 'settings', 'security' );
ok( false !== strpos( $kit, 'Module dormant' ) && false !== strpos( $kit, 'tone="warning"' ), 'the dormant state paints a warning notice' );
// 2026-10-12 audit: a dormant module's slug 404s, so the URL row gives the one
// that works, and drops the bookmark line; rename-wp-login stands it down too.
$classic = sec_classic();
ok( false !== strpos( $kit, 'https://example.test/wp-login.php' ) && false === strpos( $kit, 'https://example.test/my-door' ) && false === strpos( $kit, 'Bookmark this URL' ) && false !== strpos( $classic, 'https://example.test/wp-login.php' ) && false === strpos( $classic, 'Bookmark this URL' ), 'dormant: both surfaces give the login URL that works, not the slug that 404s' );
$GLOBALS['__wps_active'] = false;
$GLOBALS['__rwl_active'] = true;
$kit     = snt_leaf_paint( 'settings', 'security' );
$classic = sec_classic();
ok( false !== strpos( $kit, 'Module dormant: conflict with rename-wp-login' ) && false === strpos( $kit, 'Module active' ) && false !== strpos( $classic, 'Module dormant: conflict with rename-wp-login' ) && false !== strpos( $kit, 'https://example.test/wp-login.php' ) && false === strpos( $kit, 'https://example.test/my-door' ), 'rename-wp-login stands the module down on both surfaces, as login-hide.php does, with the URL that works' );
$GLOBALS['__rwl_active'] = false;

// ── Locked state: the constant overrides the field, and no save is offered.
define( 'SN_LOGIN_SLUG', 'pinned' );
$classic = sec_classic();
$kit     = snt_leaf_paint( 'settings', 'security' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'locked: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'audit_save_retention' ) === snt_leaf_actions( $kit ) && array( 'audit_save_retention' ) === snt_leaf_actions( $classic ) && array( 'audit_retention_days' ) === snt_leaf_names( $kit ), 'locked: no slug Save on either surface (a constant decides the slug; the Save posted no slug and failed); retention still saves' );
ok( false !== strpos( $kit, 'disabled' ) && 1 === substr_count( $kit, 'SN_LOGIN_SLUG constant in wp-config.php is overriding' ) && false === strpos( $kit, 'Slug locked by' ), 'locked: the field is disabled and the lock is explained once' );
ok( false !== strpos( $kit, 'value="pinned" disabled' ) && false !== strpos( $classic, 'value="pinned" disabled' ) && false !== strpos( $kit, 'https://example.test/pinned' ), 'locked: both surfaces show the constant\'s slug and its URL, not the saved setting (PR #2015 review)' );
ok( false !== strpos( $kit, '<div class="snt-form snt-form--rows"><os-field-row label="Slug">' ) && 1 === preg_match( '/\.snt-app \.snt-form--rows \{\s*display: grid;\s*gap: 14px;/', (string) file_get_contents( SNT_PATH . 'assets/os-app.css' ) ), 'locked: the rows keep the form\'s width and its 14px gap without a form' );
ok( 1 === preg_match( '/<os-field-row label="Slug"><os-text-field type="text" value="[^"]*" disabled><\/os-text-field><\/os-field-row><p class="snt-hint">Locked\./', $kit ) && false !== strpos( $kit, '<os-field-row label="Current login URL"><os-button class="snt-link snt-link--url" variant="link" os-action="door"' ) && false === strpos( $kit, 'snt-field-static' ), '#1600: the locked slug and the login URL are os-field-rows (the music leaf\'s locked-field shape), no hand-rolled .snt-field-static div' );
ok( false !== strpos( $kit, '<os-disclosure heading="Emergency unlock" hint="SN_LOGIN_SLUG is set"' ) && false !== strpos( $kit, 'data-snt-fold="emergency-unlock" data-snt-open="1"' ) && false !== strpos( $kit, '>Slug pinned in wp-config.php<' ), 'locked: the unlock fold opens and names the constant, and the status line says the slug is pinned' );
ok( false !== strpos( $kit, 'Option 2: disable' ) && false !== strpos( $kit, 'Restores /wp-login.php' ) && false !== strpos( $kit, 'SN_LOGIN_SLUG' ), 'both emergency-unlock options survive, labeled' );

// ── Bypassed state (SN_LOGIN_BYPASS constant set: one-way, so it must run last).
define( 'SN_LOGIN_BYPASS', true );
$kit = snt_leaf_paint( 'settings', 'security' );
ok( false !== strpos( $kit, 'Module bypassed' ) && false !== strpos( $kit, '>Bypassed<' ) && false !== strpos( $kit, 'tone="warning"' ), 'the bypassed state paints a warning notice with the Bypassed badge' );
ok( 1 === preg_match( '/<div class="snt-status-line"><os-cluster gap="8"><os-badge tone="warning" title="Module bypassed">Bypassed<\/os-badge>/', $kit ) && false !== strpos( $kit, '<os-notice tone="warning"' ) && false !== strpos( $kit, 'hint="SN_LOGIN_BYPASS is set"' ) && false !== strpos( $kit, 'data-snt-fold="emergency-unlock" data-snt-open="1"' ), 'bypassed: the warning stays an open notice under an amber chip, and the unlock fold opens naming the bypass' );
$classicB = sec_classic();
ok( false !== strpos( $kit, 'https://example.test/wp-login.php' ) && false === strpos( $kit, 'https://example.test/pinned' ) && false !== strpos( $classicB, 'https://example.test/wp-login.php' ) && false === strpos( $classicB, 'Bookmark this URL' ), 'bypassed: both surfaces give /wp-login.php, the URL that works, not the slug (PR #2015 review)' );

unlink( WP_PLUGIN_DIR . '/wps-hide-login/wps-hide-login.php' );
rmdir( WP_PLUGIN_DIR . '/wps-hide-login' );
unlink( WP_PLUGIN_DIR . '/rename-wp-login/rename-wp-login.php' );
rmdir( WP_PLUGIN_DIR . '/rename-wp-login' );
rmdir( WP_PLUGIN_DIR );

// ── The three boxes share a row; a stack is for steps. ──
$kitP = snt_leaf_paint( 'settings', 'security' );
ok(
	1 === substr_count( $kitP, snt_leaf_row( 3, 260 ) ) && 1 === substr_count( $kitP, '<os-grid' ),
	'the three boxes (slug, emergency unlock, audit log retention) are painted in one row -- ' . substr_count( $kitP, snt_leaf_row( 3, 260 ) )
);
// Ordering, not a regex extract: `(.*?)</div>` stops at the first NESTED
// close, so it cannot delimit a row that contains divs. Position is enough --
// the row opens, then the three follow in order.
$posRow = strpos( $kitP, snt_leaf_row( 3, 260 ) );
$posA   = strpos( $kitP, 'Custom login slug' );
$posB   = strpos( $kitP, 'Emergency unlock' );
$posC   = strpos( $kitP, 'heading="Audit log retention"' );
ok(
	false !== $posRow && false !== $posA && false !== $posB && false !== $posC && $posRow < $posA && $posA < $posB && $posB < $posC,
	'...and the row opens before the three, in order'
);
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
