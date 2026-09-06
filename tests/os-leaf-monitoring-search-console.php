<?php
/**
 * Native window leaf: Monitoring → Search Console
 * (apps/sn-dashboard/parts/leaves/monitoring-search-console.php).
 *
 * The oracle is the classic leaf (inc/search-console-admin.php): the kit
 * form(s) must carry the same field names and the same sn_action set, across
 * every distinct state (not configured / unparseable / configured / property
 * chosen / synced / scheduled-ok / scheduled-failed), with none of wp-admin's
 * markup.
 *
 * NOTE on get_transient(): redeclared UNCONDITIONALLY below, before requiring
 * the harness — declarations hoist, so this binds first and the harness's
 * `if ( ! function_exists( 'get_transient' ) )` guard (tests/lib/os-leaf-harness.php:131)
 * then sees it already defined and skips its own no-op version. Same pattern
 * as tests/os-leaf-content-tags.php:19, tests/os-leaf-monitoring-insights.php:21
 * and tests/os-leaf-ai-models-budget.php:20. This makes the `snt_gsc_last_test`
 * transient fully drivable, so the "Test connection returned a property list"
 * state (and the last-test-failed error message) ARE exercised below, not just
 * the identity/raw/property/data/cron readers.
 *
 * Run: php tests/os-leaf-monitoring-search-console.php
 */
$GLOBALS['__gsc_last_test'] = false;
function get_transient( $k ) { return 'snt_gsc_last_test' === $k ? $GLOBALS['__gsc_last_test'] : false; }

require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers — all controllable via globals.
$GLOBALS['__gsc_identity'] = null;
$GLOBALS['__gsc_raw']     = '';
$GLOBALS['__gsc_property'] = '';
$GLOBALS['__gsc_data']     = null;
$GLOBALS['__gsc_next']     = false;
$GLOBALS['__gsc_status']   = null;

function snt_gsc_credential_identity() { return $GLOBALS['__gsc_identity']; }
function snt_gsc_credential_raw() { return $GLOBALS['__gsc_raw']; }
function sn_setting( $key, $default = '' ) { return 'search_console.property' === $key ? $GLOBALS['__gsc_property'] : $default; }
function snt_gsc_data() { return $GLOBALS['__gsc_data']; }
function wp_next_scheduled( $hook ) { return $GLOBALS['__gsc_next']; }
function snt_gsc_sync_last_status() { return $GLOBALS['__gsc_status']; }
if ( ! defined( 'SNT_GSC_SYNC_HOOK' ) ) {
	define( 'SNT_GSC_SYNC_HOOK', 'sn_gsc_sync_daily' );
}

require SNT_PATH . 'inc/search-console-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-search-console.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/search-console'] ), 'the painter is registered under monitoring/search-console' );

// ── State 1: not configured — credential form only, onboarding steps shown.
$classic = snt_leaf_classic_html( 'snt_gsc_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'search-console' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'not-configured: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'gsc_credential_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'not-configured: only gsc_credential_save is offered' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'Not configured.' ) && false !== strpos( $kit, 'ENABLE the &quot;Google Search Console API&quot;' ), 'not-configured: the pill and the four onboarding steps are painted' );
ok( false !== strpos( $kit, '<os-textarea name="sn_gsc_credential"' ), 'the credential textarea is a kit textarea carrying the classic field name' );
ok( false === strpos( $kit, 'Property' ), 'not-configured: the Property section is not painted (no identity yet)' );

// ── Unparseable-but-stored state (non-hostile raw value, since snt_gsc_credential_raw()
// is only ever consumed as a boolean by the painter — a hostile value placed there
// would never reach the markup in any form and would prove nothing).
$GLOBALS['__gsc_raw'] = 'stored-value';
// snt_gsc_credential_identity stays null (unparseable), stored becomes true.
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, 'no longer parses as a service-account key' ), 'stored-but-unparseable: the warning pill is painted' );
$GLOBALS['__gsc_raw'] = '';

// ── Escaping: a hostile value on a reader that IS painted (the identity card)
// is escaped, not merely absent raw — both sides asserted so a mutant that
// paints it unescaped, or one that drops it entirely, both fail.
$GLOBALS['__gsc_identity'] = array(
	'client_email'    => '"><script>x</script>@evil.test',
	'project_id'      => 'project-123',
	'private_key_id'  => 'abc123',
	'key_fingerprint' => 'sha256:deadbeef0000',
	'signing_ready'   => true,
);
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;x' ), 'a hostile identity value is painted, and painted escaped' );
$GLOBALS['__gsc_identity'] = null;

// ── State: configured (identity parses), no Test connection run yet.
$GLOBALS['__gsc_identity'] = array(
	'client_email'    => 'svc@project.iam.gserviceaccount.com',
	'project_id'      => 'project-123',
	'private_key_id'  => 'abc123',
	'key_fingerprint' => 'sha256:deadbeef0000',
	'signing_ready'   => true,
);
$classic = snt_leaf_classic_html( 'snt_gsc_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'search-console' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'configured: field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'gsc_credential_save', 'gsc_test' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'configured: gsc_credential_save and gsc_test are both offered: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'configured: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'svc@project.iam.gserviceaccount.com' ) && false !== strpos( $kit, 'project-123' ) && false !== strpos( $kit, 'sha256:deadbeef0000' ), 'configured: the identity card carries the service account, project and fingerprint' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'openssl available' ), 'configured: signing-ready paints a success badge' );
ok( false !== strpos( $kit, 'Run Test connection above' ), 'configured: the Property section nudges toward Test connection (no sites yet)' );

// ── Signing NOT ready.
$GLOBALS['__gsc_identity']['signing_ready'] = false;
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'cannot mint a token on this host' ), 'signing not ready: a warning badge is painted' );
$GLOBALS['__gsc_identity']['signing_ready'] = true;

// ── State: property chosen, never synced.
$GLOBALS['__gsc_property'] = 'https://example.test/';
$classic = snt_leaf_classic_html( 'snt_gsc_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'search-console' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'property-chosen: field names match: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'gsc_credential_save', 'gsc_sync', 'gsc_test' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'property-chosen: gsc_sync joins the offered actions: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( false !== strpos( $kit, 'Currently reading:' ) && false !== strpos( $kit, 'https://example.test/' ), 'property-chosen: the current property is shown' );
ok( false !== strpos( $kit, 'Never synced.' ), 'property-chosen: the sync status reads Never synced' );
ok( false !== strpos( $kit, 'No scheduled sync' ), 'property-chosen: no cron scheduled yet reads honestly' );

// ── State: Test connection returned a property list (snt_gsc_last_test transient
// populated, ok=true) — the field and action the leaf exists to offer.
$GLOBALS['__gsc_last_test'] = array(
	'ok'    => true,
	'sites' => array(
		array( 'siteUrl' => 'https://example.test/', 'permissionLevel' => 'siteOwner' ),
		array( 'siteUrl' => '"><script>x</script>', 'permissionLevel' => 'siteFullUser' ),
	),
);
$classic = snt_leaf_classic_html( 'snt_gsc_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'search-console' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'sites-listed: field names match, including sn_gsc_property: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'gsc_credential_save', 'gsc_property_save', 'gsc_sync', 'gsc_test' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'sites-listed: gsc_property_save joins the offered actions: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( false !== strpos( $kit, '<os-select name="sn_gsc_property" value="https://example.test/"' ), 'sites-listed: the select carries the classic field name and current value' );
ok( false !== strpos( $kit, 'siteOwner' ), 'sites-listed: the permission level is painted' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;x' ), 'sites-listed: a hostile siteUrl is painted escaped' );
$GLOBALS['__gsc_last_test'] = false;

// ── State: last Test connection FAILED — the error message reaches the markup,
// escaped (this is the leaf's only WP_Error-message-into-markup path).
$GLOBALS['__gsc_last_test'] = array( 'ok' => false, 'error' => '<b>403</b> insufficient permission' );
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false === strpos( $kit, '<b>403' ) && false !== strpos( $kit, '&lt;b&gt;403&lt;/b&gt;' ), 'last-test-failed: the error message is painted, escaped' );
$GLOBALS['__gsc_last_test'] = false;

// ── State: synced, scheduled, has not fired yet.
$GLOBALS['__gsc_data'] = array(
	'window'    => array( 'start' => '2026-08-01', 'end' => '2026-08-28' ),
	'synced_at' => time() - 3600,
	'pages'     => array( 1, 2, 3 ),
	'queries'   => array( 1, 2 ),
);
$GLOBALS['__gsc_next'] = time() + 3600;
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, '2026-08-01' ) && false !== strpos( $kit, '2026-08-28' ) && false !== strpos( $kit, '3 pages, 2 queries.' ), 'synced: the window and counts are painted' );
ok( false !== strpos( $kit, 'Scheduled: daily' ) && false !== strpos( $kit, 'has not fired yet' ), 'synced+scheduled: the not-fired-yet state is painted' );

// ── State: scheduled run failed.
$GLOBALS['__gsc_status'] = array( 'ok' => false, 'ran_at' => time() - 60, 'message' => 'quota exceeded' );
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, 'FAILED' ) && false !== strpos( $kit, 'quota exceeded' ), 'scheduled run failed: the failure message is painted' );

// ── State: scheduled run ok.
$GLOBALS['__gsc_status'] = array( 'ok' => true, 'ran_at' => time() - 60 );
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, 'ago: ok.' ), 'scheduled run ok: the ok state is painted' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
