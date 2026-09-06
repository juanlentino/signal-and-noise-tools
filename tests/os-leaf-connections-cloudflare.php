<?php
/**
 * Native window leaf: Connections → Cloudflare (apps/sn-dashboard/parts/leaves/connections-cloudflare.php).
 *
 * The oracle is the classic leaf (the `sn_admin_cloudflare_tab` closure in
 * inc/cloudflare-purge.php): the kit must carry the same field names and the
 * same two sn_actions in every lock state, print every readout the classic
 * prints (status, last purge, probes, Cloudways), escape a hostile value, and
 * carry none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-connections-cloudflare.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers.
if ( ! function_exists( 'sn_mask_secret' ) ) {
	function sn_mask_secret( $value ) { $value = (string) $value; if ( '' === $value ) { return ''; } return strlen( $value ) <= 8 ? '••••••••' : '••••' . substr( $value, -4 ); }
}
$GLOBALS['__cw_configured'] = false;
if ( ! function_exists( 'sn_cloudways_is_configured' ) ) {
	function sn_cloudways_is_configured() { return ! empty( $GLOBALS['__cw_configured'] ); }
}
if ( ! defined( 'SNT_CW_LAST_PURGE_OPT' ) ) {
	define( 'SNT_CW_LAST_PURGE_OPT', 'sn_cloudways_last_purge' );
}

require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/cloudflare-purge-verify.php';
require SNT_PATH . 'inc/cloudflare-purge.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cloudflare.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function cf_opts( array $o ) { $GLOBALS['__options'] = $o; }
/** An os-prop-* JSON attribute of the probes table, decoded (null when the table is absent). */
function cf_table_prop( $html, $prop ) {
	if ( ! preg_match( '/os-prop-' . $prop . '="([^"]*)"/', $html, $m ) ) { return null; }
	return json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
}
/** Whether the cf_purge_now button is painted disabled: true, false, or null when there is no such button. */
function cf_purge_disabled( $html ) {
	if ( ! preg_match( '/<os-button[^>]*os-arg-action="cf_purge_now"[^>]*>Purge Cloudflare</', $html, $m ) ) { return null; }
	return 1 === preg_match( '/\sdisabled(\s|>)/', $m[0] );
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/cloudflare'] ), 'the painter is registered under connections/cloudflare' );

// ── Unconfigured: same names, same actions, the warning box, the purge button disabled.
cf_opts( array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'cf_purge_now', 'cf_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the two actions are cf_purge_now and cf_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false !== strpos( $kit, 'name="sn_action" value="cf_save"' ), 'the credentials form is an os-form dispatching post with cf_save' );
ok( false !== strpos( $kit, '<os-text-field name="sn_cf_token" type="text" value=""' ) && false !== strpos( $kit, '<os-text-field name="sn_cf_zone" type="text" value=""' ), 'both credential fields are kit text fields, empty' );
ok( false !== strpos( $kit, 'Paste a fresh token to update; type ‘clear’ to remove' ) && false !== strpos( $kit, '32-char zone ID from Cloudflare dashboard' ), 'the placeholders and hints survive' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'Not configured' ) && false !== strpos( $kit, '>Inactive</os-badge>' ), 'the unconfigured state paints a warning notice with the Inactive badge' );
ok( true === cf_purge_disabled( $kit ), 'the purge button is disabled until configured' );
ok( false !== strpos( $kit, 'Purge Everything Now' ) && false !== strpos( $kit, 'Clears the entire Cloudflare zone cache' ), 'the purge card keeps its title and helper' );
ok( false === strpos( $kit, 'Post-purge probes' ) && false === strpos( $kit, 'Cloudways purge' ), 'no probes fold and no Cloudways box when neither has anything to say' );
ok( false !== strpos( $kit, '<os-code>docs/CACHING.md</os-code>' ) && false !== strpos( $kit, 'heading="Credentials"' ) && false !== strpos( $kit, 'heading="Cache status"' ), 'the intro, the Credentials section and the Cache status section are painted' );

// ── Configured, with a full-zone purge an hour ago.
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_last_purge' => array( 'time' => time() - 3600, 'kind' => 'all' ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'configured: names and actions still match the classic leaf' );
ok( false !== strpos( $kit, 'value="••••1234"' ) && false === strpos( $kit, 'cf-token-abcdef1234' ), 'the token is shown obscured, never raw' );
ok( false !== strpos( $kit, 'value="zone0123456789abcdef"' ), 'the zone id is shown' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'Configured: auto-purge active' ) && false !== strpos( $kit, '>Active</os-badge>' ), 'the configured state paints a success notice with the Active badge' );
ok( false !== strpos( $kit, 'Last purge: 1 hour ago (full zone).' ), 'the last full-zone purge is read out' );
ok( false === cf_purge_disabled( $kit ), 'the purge button is live once configured' );

cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_last_purge' => array( 'time' => time() - 60, 'kind' => 'urls', 'count' => 7 ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, 'Last purge: 1 hour ago (7 URL(s)).' ), 'a per-URL purge is read out with its count' );

// ── The probes fold: counts in the hint, open when the newest is stale, every row in the table.
$log = array(
	array( 'time' => time() - 300, 'result' => 'stale', 'escalated' => true, 'algo' => 2, 'url' => 'https://example.test/notes/foo/' ),
	array( 'time' => time() - 900, 'result' => 'fresh', 'algo' => 2, 'url' => 'https://example.test/about/' ),
	array( 'time' => time() - 1800, 'result' => 'stale', 'algo' => 1, 'url' => 'https://example.test/old/' ),
	array( 'time' => 0, 'result' => '', 'algo' => 2, 'url' => '', 'source' => 'manual' ),
);
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_purge_probe_log' => $log ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
$rows    = cf_table_prop( $kit, 'data' );
$columns = cf_table_prop( $kit, 'columns' );
ok( false !== strpos( $classic, 'Post-purge probes' ) && 1 === preg_match( '/<os-disclosure heading="Post-purge probes" hint="4 retained, 2 stale" open>/', $kit ), 'the probes fold carries the counts and opens on a stale newest probe' );
ok( false !== strpos( $kit, '120 seconds after its purge' ), 'the probe delay is read out in the intro' );
ok( false !== strpos( $kit, '<os-table' ) && is_array( $rows ) && 4 === count( $rows ), 'the probes table carries all four rows' );
ok( is_array( $rows ) && array( 'when' => '1 hour ago', 'result' => 'stale → zone purge', 'page' => '/notes/foo/' ) === $rows[0], 'an escalated stale probe reads stale → zone purge with its path' );
ok( is_array( $rows ) && 'fresh' === $rows[1]['result'] && '/about/' === $rows[1]['page'], 'a fresh probe reads fresh with its path' );
ok( is_array( $rows ) && 'stale · retired detector' === $rows[2]['result'], 'a pre-fix verdict is marked as from the retired detector' );
ok( is_array( $rows ) && array( 'when' => '—', 'result' => 'unknown', 'page' => 'manual' ) === $rows[3], 'a manual zone purge names its source, an empty verdict reads unknown, no time reads —' );
ok( is_array( $columns ) && array( 'When', 'Result', 'Page' ) === array_column( $columns, 'label' ), 'the three classic columns survive, in order' );

array_unshift( $log, array( 'time' => time() - 10, 'result' => 'fresh', 'algo' => 2, 'url' => 'https://example.test/new/' ) );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_purge_probe_log' => $log ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( 1 === preg_match( '/<os-disclosure heading="Post-purge probes" hint="5 retained, 2 stale">/', $kit ), 'a fresh newest probe leaves the fold closed; history stays folded' );

cf_opts( array( 'sn_cf_purge_probe_log' => $log ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $classic, 'Post-purge probes' ) && false === strpos( $kit, 'Post-purge probes' ), 'unconfigured: the log is not shown, as on the classic leaf' );

// ── Cloudways: OK, Error (HTTP + message), and never attempted.
$GLOBALS['__cw_configured'] = true;
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => true ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, 'Cloudways purge' ) && false !== strpos( $kit, '>OK</os-badge>' ) && false !== strpos( $kit, 'Rides the same purge chain (Varnish leg). Last attempt: 1 hour ago.' ), 'a successful Cloudways purge reads OK with its age' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => false, 'http' => 422, 'error' => 'field validation failed' ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '>Error</os-badge>' ) && false !== strpos( $kit, 'Last attempt: 1 hour ago. HTTP 422: field validation failed' ) && substr_count( $kit, 'tone="warning"' ) >= 1, 'a failed Cloudways purge reads Error with the HTTP status and message in a warning notice' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef' ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, 'Cloudways purge' ) && false !== strpos( $kit, '>Active</os-badge>' ) && false === strpos( $kit, 'Last attempt' ), 'a configured Cloudways module that never purged reads Active' );

// ── Escaping: hostile values in the zone, a probe URL and the Cloudways error never reach the markup raw.
cf_opts( array(
	'sn_cf_api_token'        => 'abc',
	'sn_cf_zone_id'          => '"><script>x</script>',
	'sn_cf_purge_probe_log'  => array( array( 'time' => time() - 5, 'result' => 'stale', 'algo' => 2, 'url' => 'https://example.test/<script>y</script>/' ) ),
	'sn_cloudways_last_purge' => array( 'time' => time() - 5, 'ok' => false, 'http' => 500, 'error' => '<script>z</script>' ),
) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $kit, '<script>' ) && substr_count( $kit, '&lt;script&gt;' ) >= 3 && false !== strpos( $kit, 'value="••••••••"' ), 'hostile zone, probe path and Cloudways error are escaped; a short token masks fully' );
$GLOBALS['__cw_configured'] = false;

// ── Token locked by its constant: no sn_cf_token in either form, the zone still editable, Save still offered.
define( 'SN_CLOUDFLARE_API_TOKEN', 'const-token-9876' );
cf_opts( array( 'sn_cf_zone_id' => 'zone0123456789abcdef' ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && ! in_array( 'sn_cf_token', snt_leaf_names( $kit ), true ), 'token locked: names match and neither form carries sn_cf_token: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array( 'cf_purge_now', 'cf_save' ) === snt_leaf_actions( $kit ), 'token locked: Save is still offered for the zone' );
ok( 1 === preg_match( '/<os-field-row label="API token" hint="Locked\. Set via SN_CLOUDFLARE_API_TOKEN in wp-config\.php\."><os-text-field type="text" value="••••9876" disabled>/', $kit ), 'token locked: a nameless disabled field shows the obscured constant and explains the lock' );

// ── Both locked: no credentials form at all, only the purge action, as on the classic leaf.
define( 'SN_CLOUDFLARE_ZONE_ID', 'zoneconst0123456789' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( ! in_array( 'sn_cf_zone', snt_leaf_names( $classic ), true ) && ! in_array( 'sn_cf_zone', snt_leaf_names( $kit ), true ) && ! in_array( 'sn_cf_token', snt_leaf_names( $kit ), true ), 'both locked: neither form carries an editable credential' );
ok( array( 'cf_purge_now' ) === snt_leaf_actions( $classic ) && array( 'cf_purge_now' ) === snt_leaf_actions( $kit ), 'both locked: the only action is cf_purge_now (no Save on the classic leaf either)' );
ok( false === strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'value="zoneconst0123456789" disabled' ) && false !== strpos( $kit, 'nothing to save here' ), 'both locked: no form, both fields disabled, the lock explained' );
ok( false !== strpos( $kit, 'Configured: auto-purge active' ) && false === cf_purge_disabled( $kit ), 'both locked: the constants configure the module and the purge button is live' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
