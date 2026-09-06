<?php
/**
 * Native window leaf: Connections → Cloudways (apps/sn-dashboard/parts/leaves/connections-cloudways.php).
 *
 * The oracle is the classic leaf, driven through its real wrapper
 * (sn_admin_render_cloudways_section → do_action → sn_admin_cloudways_render).
 * It is display-only by a security decision, so the faithfulness oracle here is
 * the ABSENCE of a form: the kit leaf carries no field name and no sn_action,
 * exactly as the classic one carries none — and never a credential value. Every
 * one of the purge module's six outcomes paints its own counterpart, and none
 * of wp-admin's markup survives.
 *
 * Run: php tests/os-leaf-connections-cloudways.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers: the purge module's option key. inc/cloudways-purge.php
// is not loaded here (it is the HTTP layer), so the key is declared the way the
// classic suite (tests/admin-cloudways.php) declares it.
if ( ! defined( 'SNT_CW_LAST_PURGE_OPT' ) ) { define( 'SNT_CW_LAST_PURGE_OPT', 'sn_cloudways_last_purge' ); }

require SNT_PATH . 'inc/admin-glance.php';           // the classic grid the oracle paints through
require SNT_PATH . 'inc/admin-render-sections.php';  // the real wrapper the registry names
require SNT_PATH . 'inc/admin-forms/cloudways.php';  // registers sn_admin_cloudways_render on sn_admin_cloudways_tab
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cloudways.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** The last-purge record the option holds (null clears it). */
function cw_record( $record ) {
	if ( null === $record ) { unset( $GLOBALS['__options'][ SNT_CW_LAST_PURGE_OPT ] ); return; }
	$GLOBALS['__options'][ SNT_CW_LAST_PURGE_OPT ] = $record;
}
/** The Result cell's markup alone, so an assertion about it cannot be satisfied by another cell. */
function cw_result_cell( $kit ) {
	preg_match( '/<div class="snt-sys[^"]*"[^>]*><span class="snt-sys__k">Result<\/span>.*?<\/div>/s', $kit, $m );
	return $m[0] ?? '';
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/cloudways'] ), 'the painter is registered under connections/cloudways' );

// ── Unconfigured, never run: the four constants are undefined at this point and no record exists.
cw_record( null );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudways_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudways' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( false !== strpos( $classic, 'sn-glance-card' ) && 3 === substr_count( $classic, 'sn-glance-card__label' ), 'the classic oracle painted its three glance cards (not a hollow capture)' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic leaf: [' . implode( ',', snt_leaf_names( $kit ) ) . '] (classic: [' . implode( ',', snt_leaf_names( $classic ) ) . '])' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'actions match the classic leaf: [' . implode( ',', snt_leaf_actions( $kit ) ) . '] (classic: [' . implode( ',', snt_leaf_actions( $classic ) ) . '])' );
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_actions( $kit ) && false === strpos( $kit, '<os-form' ) && false === strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, '<os-text-field' ), 'display-only survives the port: no form, no field, no action — the credential input the classic refuses is refused here' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( 3 === substr_count( $kit, '<span class="snt-sys__k">' ) && 1 === substr_count( $kit, '<div class="snt-systems">' ), 'three cells on one systems wall, as three classic glance cards' );
ok( false !== strpos( $kit, '<span class="snt-sys__k">Connection</span><span class="snt-sys__v">Not configured</span><os-badge tone="warning">Inactive</os-badge>' ), 'unconfigured: the Connection cell reads Not configured with an Inactive warning badge' );
$named = true;
foreach ( sn_admin_cloudways_constants() as $name ) { $named = $named && false !== strpos( $kit, '<os-code>' . $name . '</os-code>' ); }
ok( $named && false !== strpos( $kit, 'the purge no-ops silently until all four are present' ), 'unconfigured: every missing constant is NAMED in inline os-code (the actionable part)' );
ok( false !== strpos( $kit, '<span class="snt-sys__k">Last purge</span><span class="snt-sys__v">Never</span>' ) && false !== strpos( $kit, 'Nothing recorded yet.' ), 'no record: Last purge reads Never with its meta line' );
$cell = cw_result_cell( $kit );
ok( false !== strpos( $cell, '<span class="snt-sys__v">Never run</span><os-badge tone="warning">No data</os-badge>' ) && false !== strpos( $cell, 'This is not a failure.' ) && false === strpos( $cell, 'danger' ), 'no record: Result reads Never run / No data as a WARNING, never as danger' );
ok( false !== strpos( $kit, 'snt-sys--warn" data-tone="warning"' ) && false === strpos( $kit, 'snt-sys--err' ), 'unconfigured cells carry the warning stripe and none the danger stripe' );
ok( false !== strpos( $kit, '<p class="snt-hint">Cloudways holds the origin cache (Breeze / Varnish). This leaf reports it; it never edits it. Credentials live in <os-code>wp-config.php</os-code> only — an account-wide API key is deliberately kept out of the database.</p>' ), 'the helper line survives as a hint, its <code> as inline os-code' );
ok( false === strpos( $kit, '<code>' ) && false !== strpos( $classic, '<code>' ), 'every classic inline <code> became inline <os-code>' );

// ── Failed, with stage, HTTP and the captured error.
cw_record( array( 'ok' => false, 'stage' => 'auth', 'http' => 401, 'error' => 'bad credential', 'time' => time() - 3600 ) );
$kit  = snt_leaf_paint( 'connections', 'cloudways' );
$cell = cw_result_cell( $kit );
ok( false !== strpos( $cell, '<span class="snt-sys__v">Failed</span><os-badge tone="danger">Failed</os-badge>' ) && false !== strpos( $cell, 'bad credential (stage <os-code>auth</os-code>, HTTP <os-code>401</os-code>)' ), 'failed: Result reads Failed / danger, the error envelope, stage and HTTP are shown' );
ok( false !== strpos( $cell, 'class="snt-sys snt-sys--err" data-tone="danger"' ), 'failed: the Result cell carries the danger stripe' );
ok( false !== strpos( $kit, '<span class="snt-sys__k">Last purge</span><span class="snt-sys__v">1 hour ago</span>' ) && false !== strpos( $kit, 'Cache purge on the Cloudways application, fired by post save and theme update.' ), 'a timed record: Last purge reads the relative time with its meta line' );

// ── Inconclusive: its own outcome, never a failure.
cw_record( array( 'ok' => false, 'inconclusive' => true, 'error' => 'timeout' ) );
$cell = cw_result_cell( snt_leaf_paint( 'connections', 'cloudways' ) );
ok( false !== strpos( $cell, '<span class="snt-sys__v">Inconclusive</span><os-badge tone="warning">Unknown</os-badge>' ) && false !== strpos( $cell, 'we never heard back' ) && false !== strpos( $cell, '<br>timeout' ) && false === strpos( $cell, 'danger' ), 'inconclusive: Result reads Inconclusive / Unknown as a warning with the error, never danger' );

// ── OK after re-auth: a warning about the credential.
cw_record( array( 'ok' => true, 'reauthed' => true ) );
$cell = cw_result_cell( snt_leaf_paint( 'connections', 'cloudways' ) );
ok( false !== strpos( $cell, '<span class="snt-sys__v">OK after re-auth</span><os-badge tone="warning">Check credential</os-badge>' ) && false !== strpos( $cell, 'second token exchange' ), 're-authed: Result reads OK after re-auth / Check credential as a warning' );

// ── Coalesced: distinguishable from a fresh 200.
cw_record( array( 'ok' => true, 'coalesced' => true, 'stage' => 'dispatch' ) );
$cell = cw_result_cell( snt_leaf_paint( 'connections', 'cloudways' ) );
ok( false !== strpos( $cell, '<span class="snt-sys__v">Coalesced</span><os-badge tone="success">OK</os-badge>' ) && false !== strpos( $cell, 'Joined a purge that was already running' ) && false !== strpos( $cell, '(stage <os-code>dispatch</os-code>)' ), 'coalesced: Result reads Coalesced / OK as success with its stage' );

// ── A clean dispatch: an ok pill paints a badge but no stripe.
cw_record( array( 'ok' => true, 'stage' => 'dispatch', 'http' => 200 ) );
$cell = cw_result_cell( snt_leaf_paint( 'connections', 'cloudways' ) );
ok( false !== strpos( $cell, '<div class="snt-sys"><span class="snt-sys__k">Result</span><span class="snt-sys__v">OK</span><os-badge tone="success">OK</os-badge>' ) && false !== strpos( $cell, 'Dispatched and acknowledged. (stage <os-code>dispatch</os-code>, HTTP <os-code>200</os-code>)' ), 'clean: Result reads OK / OK as success, with stage and HTTP, and no stripe' );

// ── Escaping: a hostile option value (the record is written by the purge module) never reaches the markup raw.
cw_record( array( 'ok' => false, 'stage' => '"><script>x</script>', 'error' => '<script>alert(1)</script>' ) );
$kit = snt_leaf_paint( 'connections', 'cloudways' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false !== strpos( $kit, '<os-code>&quot;&gt;&lt;script&gt;x&lt;/script&gt;</os-code>' ), 'a hostile error and stage are escaped' );

// ── Configured: all four constants defined with sentinel values. Presence is shown; a value never is.
// ALL FOUR, deliberately: with any missing, configured=false and the only branch that could touch a value never runs.
define( 'SN_CLOUDWAYS_EMAIL', 'owner@example.test' );
define( 'SN_CLOUDWAYS_API_KEY', 'super-secret-account-wide-key' );
define( 'SN_CLOUDWAYS_SERVER_ID', 'srv-999999' );
define( 'SN_CLOUDWAYS_APP_ID', 'app-888888' );
cw_record( array( 'ok' => true, 'http' => 200, 'time' => time() - 3600 ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudways_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudways' );
ok( false !== strpos( $kit, '<span class="snt-sys__k">Connection</span><span class="snt-sys__v">Configured</span><os-badge tone="success">Ready</os-badge>' ) && false !== strpos( $kit, 'Set in <os-code>wp-config.php</os-code>. Display-only: this page cannot read or change these values.' ), 'configured: the Connection cell reads Configured / Ready with its display-only meta line (the security assertion runs against the CONFIGURED branch)' );
ok( false === strpos( $kit, 'super-secret-account-wide-key' ) && false === strpos( $kit, 'owner@example.test' ) && false === strpos( $kit, 'srv-999999' ) && false === strpos( $kit, 'app-888888' ), 'NEVER renders a credential value: not the key, the email, the server id or the app id' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_names( $kit ), 'configured: still no field and no action on either leaf' );
ok( false === strpos( $kit, 'snt-sys--' ) && false === strpos( $kit, 'data-tone=' ), 'a healthy leaf paints no tone stripe on any cell' );
ok( array() === snt_leaf_classic_markers( $kit ), 'configured: no wp-admin markup survives' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
