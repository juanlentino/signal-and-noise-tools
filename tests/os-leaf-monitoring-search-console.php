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
// 2026-11: the credential form and the property picker are settings of an
// account and moved to Settings › Accounts and connections, as its Search
// Console box. What this suite pinned about the two forms it pins on the box.
require SNT_PATH . 'inc/admin-forms/settings-accounts.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-search-console.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/settings-accounts-search-console.php';
function gsc_box_c() { return snt_leaf_classic_html( 'snt_accounts_render_search_console_box' ); }
function gsc_box_k() { return \SignalNoise\OpenStationHost\Dashboard\Leaves\accounts_search_console_html(); }
const GSC_MOVED_C = 'The service-account JSON and the property picker moved to <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=accounts">Settings › Accounts and connections</a>.</p>';
const GSC_MOVED_K = '<p class="snt-hint">The service-account JSON and the property picker moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="accounts" data-snt-anchor="">Settings › Accounts and connections</os-button>.</p>';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/search-console'] ), 'the painter is registered under monitoring/search-console' );

// ── State 1: not configured — credential form only, onboarding steps shown.
$classic = snt_leaf_classic_html( 'snt_gsc_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'search-console' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_names( $classic ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ), 'not-configured: the leaf has no field and no action on either surface; the credential form is on Settings › Accounts and connections' );
ok( array( 'sn_gsc_credential' ) === snt_leaf_names( gsc_box_k() ) && snt_leaf_names( gsc_box_c() ) === snt_leaf_names( gsc_box_k() ) && array( 'gsc_credential_save' ) === snt_leaf_actions( gsc_box_k() ) && snt_leaf_actions( gsc_box_c() ) === snt_leaf_actions( gsc_box_k() ), 'not-configured: the box offers the credential field and gsc_credential_save alone, on both twins' );
ok( 1 === substr_count( $kit, GSC_MOVED_K ) && 1 === substr_count( $classic, GSC_MOVED_C ), 'the leaf keeps one line saying where the two forms went, the same sentence on both surfaces, with a link to the pane' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'Not configured.' ) && false !== strpos( $kit, 'ENABLE the &quot;Google Search Console API&quot;' ), 'not-configured: the pill and the four onboarding steps are painted' );
// 17.4.4 (#1602): the four credential steps are the kit's <os-steps>, not a browser <ol>.
ok( 1 === substr_count( $kit, '<os-steps>' ) && 4 === substr_count( $kit, '<os-step>' ) && false !== strpos( $kit, '<os-step>In Google Cloud, ENABLE the &quot;Google Search Console API&quot;' ), 'not-configured: one <os-steps> of four <os-step>, the first carrying the ENABLE sentence -- ' . substr_count( $kit, '<os-steps>' ) . '/' . substr_count( $kit, '<os-step>' ) );
ok( false === strpos( $kit, '<ol' ), 'not-configured: no browser ordered list survives' );
// 2026-11: the form that sets the credential was a Setup fold here; on the
// pane it is open. The onboarding steps (reference) stay in the readout, open.
ok( false === strpos( $kit, 'heading="Setup"' ) && false === strpos( $kit, '<os-disclosure' ) && false !== strpos( $kit, '<os-steps>' ) && false === strpos( gsc_box_k(), '<os-disclosure' ) && false === strpos( gsc_box_k(), '<os-steps>' ), 'the leaf has no Setup card and no fold; the steps stay in its readout box and did not go to the pane' );
ok( false !== strpos( gsc_box_k(), '<os-section heading="Search Console" stack><os-form class="snt-form" os-action="post" submit-label="Save credential" show-reset="false" columns="auto"><os-field-row label="Service-account JSON"><os-textarea name="sn_gsc_credential" value="" rows="8" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"></os-textarea></os-field-row>' ), 'the credential textarea is a kit textarea carrying the classic field name, in a box headed Search Console' );
ok( false === strpos( $kit, 'Property' ), 'not-configured: the Property section is not painted (no identity yet)' );
// 2026-10-12 audit: the form stood alone at full width beside nothing; the
// readout and the form that sets it share one row.
ok( 0 === substr_count( $kit, '<os-grid' ) && 0 === strpos( $kit, '<os-section heading="Search Console credential"' ), 'not-configured: the readout box alone (the form it sat beside is on the pane)' );
// 2026-10-12 audit: "Nothing here queries Google yet" was false beside Test
// connection and Sync now; "below" pointed at another box.
ok( false === strpos( $kit, 'Nothing here queries Google' ) && false === strpos( $classic, 'Nothing here queries Google' ) && false !== strpos( $kit, 'Test connection lists the properties it can read; Sync now pulls their pages and queries' ) && false !== strpos( $classic, 'Test connection lists the properties it can read; Sync now pulls their pages and queries' ), 'the intro says what the leaf does, on both surfaces' );
ok( false === strpos( $kit, 'key file below' ) && false !== strpos( $kit, 'Paste the whole JSON key file into Service-account JSON, in Settings › Accounts and connections, then press Test connection here.' ) && false !== strpos( $classic, 'Paste the whole JSON key file into Service-account JSON, in Settings › Accounts and connections, then press Test connection here.' ) && false === strpos( $kit, '—' ), 'the steps name the field and the pane that now holds it, with no em dash, on both surfaces' );

// ── Unparseable-but-stored state (non-hostile raw value, since snt_gsc_credential_raw()
// is only ever consumed as a boolean by the painter — a hostile value placed there
// would never reach the markup in any form and would prove nothing).
$GLOBALS['__gsc_raw'] = 'stored-value';
// snt_gsc_credential_identity stays null (unparseable), stored becomes true.
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, 'no longer parses as a service-account key' ), 'stored-but-unparseable: the warning pill is painted' );
ok( 1 === substr_count( $kit, GSC_MOVED_K ) && false !== strpos( gsc_box_k(), 'placeholder="Paste a fresh key file to replace; type clear to remove; leave empty to keep the current one"' ) && false === strpos( gsc_box_k(), 'sn_gsc_property' ) && false === strpos( gsc_box_k(), 'Test connection on' ), 'stored but unparseable: the leaf points at the pane, whose field says how to replace it; no picker until a key parses' );
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
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_names( $classic ), 'configured: still no field on the leaf, on either surface' );
ok( array( 'gsc_test' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'configured: the leaf offers gsc_test alone: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( 1 === preg_match( '#<form method="post" action="https://example\.test/wp-admin/admin-post\.php\?_wpnonce=nonce-sn_gsc_test"><p><button type="submit" name="action" value="sn_gsc_test" formaction="https://example\.test/wp-admin/admin-post\.php\?_wpnonce=nonce-sn_gsc_test" class="button">Test connection</button></p></form>#', $classic ), 'classic: Test connection is a form of its own whose one button names gsc_test with its own nonce, so it can write nothing else' );
ok( array( 'gsc_credential_save' ) === snt_leaf_actions( gsc_box_k() ) && snt_leaf_actions( gsc_box_c() ) === snt_leaf_actions( gsc_box_k() ), 'configured, no test run: the box offers gsc_credential_save alone; Test connection did not come along' );
ok( false === strpos( gsc_box_k() . gsc_box_c(), 'svc@project.iam.gserviceaccount.com' ) && false === strpos( gsc_box_k() . gsc_box_c(), 'project-123' ) && false === strpos( gsc_box_k() . gsc_box_c(), 'abc123' ) && false === strpos( gsc_box_k() . gsc_box_c(), 'deadbeef' ), 'the identity card (service account, project, key ID, fingerprint) is printed on the leaf only, never on the pane' );
ok( array() === snt_leaf_classic_markers( $kit ), 'configured: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'svc@project.iam.gserviceaccount.com' ) && false !== strpos( $kit, 'project-123' ) && false !== strpos( $kit, 'sha256:deadbeef0000' ), 'configured: the identity card carries the service account, project and fingerprint' );
ok( false !== strpos( $kit, '<os-fact label="Token signing"><span class="snt-kv__v"><os-badge tone="success">Available</os-badge>' ), 'configured: signing-ready paints a success badge, in plain words' );
ok( false !== strpos( gsc_box_k(), '<p class="snt-hint">To change the credential, paste a whole new key file into Service-account JSON; to remove it, type clear there.</p><os-form' ) && false !== strpos( gsc_box_c(), '<p class="description">To change the credential, paste a whole new key file into Service-account JSON; to remove it, type clear there.</p><p><label for="sn_gsc_credential">' ) && false === strpos( $kit . $classic, 'paste a whole new key file' ) && false !== strpos( $kit, 'The private key is never displayed.' ) && false !== strpos( $classic, 'The private key is never displayed.' ), 'configured: the replace sentence names the field and went to the pane with it, on both twins; the leaf keeps "never displayed"' );
ok( false !== strpos( gsc_box_k(), 'To list the properties this service account can read, press Test connection on <os-button class="snt-go" variant="link" data-snt-tab="monitoring" data-snt-sub="search-console" data-snt-anchor="">Measurement › Search Console</os-button>.' ) && false !== strpos( gsc_box_c(), 'To list the properties this service account can read, press Test connection on <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=search-console">Measurement › Search Console</a>.' ), 'configured, no test run: where the picker will be, the box says which screen runs the test, with a link' );
ok( false !== strpos( $kit, 'Run Test connection to list the properties' ) && false === strpos( $kit, 'Test connection above' ), 'configured: the Property section nudges toward Test connection (no sites yet)' );
$row = strpos( $kit, snt_leaf_row() );
ok( 1 === substr_count( $kit, snt_leaf_row() ) && false === strpos( $kit, 'snt-2up' ) && 0 === $row && $row < strpos( $kit, 'heading="Search Console credential"' ) && strpos( $kit, 'heading="Search Console credential"' ) < strpos( $kit, 'heading="Property"' ) && strpos( $kit, 'heading="Property"' ) < strpos( $kit, '</os-grid>' ), 'configured: one row, the readout beside Property' );
ok( strpos( $kit, 'os-arg-action="sn_gsc_test"' ) < strpos( $kit, 'heading="Property"' ) && false === strpos( $kit, 'name="sn_gsc_credential"' ) && false === strpos( $classic, 'name="sn_gsc_credential"' ), '17.4.1: Test connection sits in the readout box, before Property; the textarea is on neither surface of the leaf' );

// ── Signing NOT ready.
$GLOBALS['__gsc_identity']['signing_ready'] = false;
$kit = snt_leaf_paint( 'monitoring', 'search-console' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'cannot sign the token request' ) && false === strpos( $kit, 'openssl_sign' ), 'signing not ready: a warning badge in plain words' );
$GLOBALS['__gsc_identity']['signing_ready'] = true;

// ── State: property chosen, never synced.
$GLOBALS['__gsc_property'] = 'https://example.test/';
$classic = snt_leaf_classic_html( 'snt_gsc_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'search-console' );
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_names( $classic ), 'property-chosen: still no field on the leaf' );
ok( array( 'gsc_sync', 'gsc_test' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'property-chosen: gsc_sync joins the offered actions: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( 1 === preg_match( '#<form method="post" action="https://example\.test/wp-admin/admin-post\.php\?_wpnonce=nonce-sn_gsc_sync" class="sn-gsc-settings"><h3 class="sn-fieldset-h">Sync</h3>(?:(?!</form>).)*<button type="submit" name="action" value="sn_gsc_sync" formaction="[^"]*nonce-sn_gsc_sync" class="button">Sync now</button>#s', $classic ) && false === strpos( $classic, 'sn_gsc_property_save' ), 'classic: Sync now is a form of its own whose one button names gsc_sync; no property save is left beside it' );
ok( false !== strpos( $kit, 'Currently reading:' ) && false !== strpos( $kit, 'https://example.test/' ), 'property-chosen: the current property is shown' );
ok( false !== strpos( $kit, 'Never synced.' ), 'property-chosen: the sync status reads Never synced' );
ok( false !== strpos( $kit, 'No scheduled sync' ), 'property-chosen: no cron scheduled yet reads honestly' );
// 2026-10-12 audit: Sync was an 11px uppercase sub-label inside Property.
ok( false !== strpos( $kit, '<os-section heading="Sync" stack>' ) && strpos( $kit, 'heading="Property"' ) < strpos( $kit, 'heading="Sync"' ) && false === strpos( $kit, 'snt-col__h' ), 'property-chosen: Sync is its own box, after Property' );
// Live check (2026-10-12): Property beside Sync read 97 against 194px (2.0x).
// Property over Sync is one column beside the form, on the credential's row.
ok( 1 === substr_count( $kit, '<os-grid' ) && false !== strpos( $kit, snt_leaf_row() ) && 1 === preg_match( '#heading="Search Console credential".*?</os-section><os-stack gap="18"><os-section heading="Property".*?</os-section><os-section heading="Sync"#s', $kit ), 'property-chosen: one row, the readout, then Property over Sync in one column' );
snt_leaf_preview( 'monitoring-search-console-chosen', $kit );
// Re-measure (2026-10-12): "Set the credential" ended 116px above its
// neighbors, and the Property heading sat 16px low in its column.
$gsc_css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
ok( 1 === preg_match( '/\[data-snt-leaf="search-console"\] os-grid > os-section\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;/', $gsc_css ) && 1 === preg_match( '/\[data-snt-leaf="search-console"\] os-grid > os-section::part\( body \)\s*\{[^}]*flex:\s*1 1 auto;/', $gsc_css ), 'the row\'s boxes stretch to its height, as on login-defense and mcp-connect' );
ok( 1 === preg_match( '/\[data-snt-leaf="search-console"\] os-grid > os-stack\s*\{[^}]*padding:\s*0;/', $gsc_css ), 'the Property and Sync column starts level with its row' );
ok( 1 === preg_match( '/\[data-snt-leaf="search-console"\] os-grid > os-stack\s*\{[^}]*padding:\s*0;[^}]*display:\s*flex;[^}]*flex-direction:\s*column;/', $gsc_css ) && 1 === preg_match( '/\[data-snt-leaf="search-console"\] os-grid > os-stack > os-section:last-child\s*\{[^}]*flex:\s*1 1 auto;/', $gsc_css ) && 1 === preg_match( '/\[data-snt-leaf="search-console"\] os-grid > os-stack > os-section::part\( body \)/', $gsc_css ), 'the column has no inset, and Sync takes the rest of the row\'s height' );

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
$box_k = gsc_box_k();
$box_c = gsc_box_c();
ok( array( 'sn_gsc_credential', 'sn_gsc_property' ) === snt_leaf_names( $box_k ) && snt_leaf_names( $box_c ) === snt_leaf_names( $box_k ) && array() === snt_leaf_names( $kit ) && array() === snt_leaf_names( $classic ), 'sites-listed: the box holds both fields, including sn_gsc_property, on both twins; the leaf none' );
ok( array( 'gsc_credential_save', 'gsc_property_save' ) === snt_leaf_actions( $box_k ) && snt_leaf_actions( $box_c ) === snt_leaf_actions( $box_k ) && array( 'gsc_sync', 'gsc_test' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'sites-listed: gsc_property_save joins the box; the leaf keeps the test and the sync: ' . implode( ',', snt_leaf_actions( $box_k ) ) );
ok( false !== strpos( $box_k, '<os-select name="sn_gsc_property" value="https://example.test/"' ) && false !== strpos( $box_c, '<label for="sn_gsc_property"><strong>Read search data for</strong></label><br><select id="sn_gsc_property" name="sn_gsc_property">' ) && false !== strpos( $box_c, '<option value="https://example.test/" selected="selected">' ), 'sites-listed: the select carries the classic field name and current value, its label tied to it on the classic twin' );
ok( false !== strpos( $box_k, 'siteOwner' ), 'sites-listed: the permission level is painted' );
ok( false !== strpos( $box_k, '<os-option value="">Choose a property</os-option>' ) && false !== strpos( $box_c, '<option value="">Choose a property</option>' ), 'sites-listed: the placeholder option has no em dashes, on both surfaces' );
ok( false !== strpos( $kit, 'Currently reading:' ) && false !== strpos( $classic, 'Currently reading: <code>https://example.test/</code>' ) && false === strpos( $kit, 'Run Test connection to list' ), 'sites-listed: the leaf still says which property it reads (the picker used to say it), and no longer nudges toward the test' );
snt_leaf_preview( 'monitoring-search-console', $kit );
ok( false === strpos( $box_k . $box_c, '<script>' ) && false !== strpos( $box_k, '&lt;script&gt;x' ) && false !== strpos( $box_c, '&lt;script&gt;x' ), 'sites-listed: a hostile siteUrl is painted escaped' );
$GLOBALS['__gsc_property'] = '';
ok( false !== strpos( snt_leaf_paint( 'monitoring', 'search-console' ), '<p class="snt-hint">No property chosen yet.</p>' ) && false !== strpos( snt_leaf_classic_html( 'snt_gsc_render_settings_section' ), '<p class="description">No property chosen yet.</p>' ), 'sites-listed, none chosen: the leaf says so on both surfaces, where the picker used to stand' );
$GLOBALS['__gsc_property'] = 'https://example.test/';
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
ok( false !== strpos( $kit, 'Last scheduled run <os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>: ok.' ) && false !== strpos( $kit, 'Scheduled: daily, next <os-relative-time datetime="' ), '#1596: scheduled run ok: the last run and the next run are os-relative-time, the ok state is painted' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
