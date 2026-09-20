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
require SNT_PATH . 'inc/cloudflare-monitor.php'; // 14.9.0: the monitor section + its Refresh action, on both leaves
require SNT_PATH . 'inc/cloudflare-firewall-events.php'; // 15.1.0: the event log's tops paint under the firewall reading
require SNT_PATH . 'inc/cloudflare-credentials.php'; // 14.10.0: the account id + grant list
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
/** 15.2.0: field names minus the framing names every classic form carries; the leaf holds no credential field any more. */
function cf_fields( $html ) { return array_values( array_diff( snt_leaf_names( $html ), array( '_wpnonce', 'sn_action' ) ) ); }
function cf_purge_disabled( $html ) {
	if ( ! preg_match( '/<os-button[^>]*os-arg-action="cf_purge_now"[^>]*>Purge all caches</', $html, $m ) ) { return null; }
	return 1 === preg_match( '/\sdisabled(\s|>)/', $m[0] );
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/cloudflare'] ), 'the painter is registered under connections/cloudflare' );

// ── Unconfigured: same names, same actions, the warning box, the purge button disabled.
cf_opts( array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === cf_fields( $classic ) && array() === cf_fields( $kit ), '15.2.0: no credential field on either leaf; the keyring holds them' );
ok( array( 'cf_monitor_refresh', 'cf_purge_now' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the two actions are cf_monitor_refresh and cf_purge_now, as on the classic leaf; cf_save is gone (15.2.0)' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false === strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'Connections › Credentials' ) && false !== strpos( $kit, 'This leaf only reads them' ), 'no form: the leaf points to Connections › Credentials and says it only reads' );
ok( false !== strpos( $kit, '>API token</dt>' ) && false !== strpos( $kit, '>Zone ID</dt>' ) && false !== strpos( $kit, '>Account ID</dt>' ) && substr_count( $kit, '>not set</dd>' ) >= 3, 'the three sources read "not set" as facts rows' );
ok( false === strpos( $kit, 'Paste a fresh token' ) && false === strpos( $classic, 'sn_cf_token' ), 'no placeholder, no token field, on either leaf' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'Not configured' ) && false !== strpos( $kit, '>Inactive</os-badge>' ), 'the unconfigured state paints a warning notice with the Inactive badge' );
ok( true === cf_purge_disabled( $kit ), 'the purge button is disabled until configured' );
ok( false !== strpos( $kit, '<h3>Purge all caches</h3>' ) && false !== strpos( $kit, 'Object cache, Breeze, Varnish, then Cloudflare, in that order, verified' ) && false === strpos( $kit, 'Purge Cloudflare' ), '15.1.0: the purge card runs the full chain and says so; the Cloudflare-only button is gone' );
ok( false === strpos( $kit, 'Post-purge probes' ) && false === strpos( $kit, 'Cloudways purge' ), 'no probes box and no Cloudways box when neither has anything to say' );
ok( false !== strpos( $kit, '<os-code>docs/CACHING.md</os-code>' ) && false !== strpos( $kit, 'heading="Credentials"' ) && false !== strpos( $kit, 'heading="Cache"' ) && false === strpos( $kit, 'heading="Edge, 7 days"' ) && false === strpos( $kit, 'heading="Firewall, 24 hours"' ) && strpos( $kit, 'heading="Credentials"' ) < strpos( $kit, 'heading="Cache"' ) && strpos( $kit, 'heading="Cache"' ) < strpos( $kit, '<os-code>docs/CACHING.md</os-code>' ), '15.3.0: Credentials, then Cache (with the CACHING.md note as its hint); Edge and Firewall live on Measurement and Security now' );
ok( false !== strpos( $kit, 'heading="Token"' ) && false !== strpos( $kit, 'The monitor has not run yet' ) && false !== strpos( $kit, 'cf_monitor_refresh' ), '15.3.0: before the monitor ran, the Token section says so and offers Refresh' );
// 17.4.1 (#1573): boxes on rows of comparable height. Credentials beside
// Cache; Token under them, alone at full width when there is no probes ledger.
ok( 1 === substr_count( $kit, '<div class="snt-cols">' ) && false === strpos( $kit, 'snt-2up' ) && 1 === preg_match( '/<div class="snt-cols"><os-section heading="Credentials".*?<\/os-section><os-section heading="Cache".*?<\/os-section><\/div><os-section heading="Token"/s', $kit ), '17.4.1: one paired row, Credentials beside Cache, then Token alone at full width; no snt-2up columns' );

// ── Configured, with a full-zone purge an hour ago.
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_last_purge' => array( 'time' => time() - 3600, 'kind' => 'all' ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( array() === cf_fields( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'configured: still no field, actions still match the classic leaf' );
ok( false !== strpos( $kit, '>••••1234</dd>' ) && false === strpos( $kit, 'cf-token-abcdef1234' ) && false === strpos( $classic, 'cf-token-abcdef1234' ), 'the token source shows the obscured value, never raw, on either leaf' );
ok( false !== strpos( $kit, '>zone0123456789abcdef</dd>' ), 'the zone id is shown as a fact' );
ok( false === strpos( $kit, 'Configured: auto-purge active' ) && false !== strpos( $kit, '>Auto-purge</dt>' ) && false !== strpos( $kit, '>Active</os-badge>' ) && false !== strpos( $kit, 'on post save, theme update and the REST endpoint' ), '15.1.0: the configured state is a facts row with the Active badge, not a notice' );
ok( false !== strpos( $kit, '>Last purge</dt>' ) && false !== strpos( $kit, '1 hour ago (full zone)' ), 'the last full-zone purge is read out' );
ok( false === cf_purge_disabled( $kit ), 'the purge button is live once configured' );

cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_last_purge' => array( 'time' => time() - 60, 'kind' => 'urls', 'count' => 7 ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '1 hour ago (7 URL(s))' ), 'a per-URL purge is read out with its count' );

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
ok( false !== strpos( $classic, 'Post-purge probes' ) && false === strpos( $kit, '<os-disclosure' ) && 1 === preg_match( '/<os-section heading="Post-purge probes" description="Each row is one check[^"]*">/', $kit ) && false !== strpos( $kit, '>4 retained, 2 stale</dd>' ), '17.4.1: the probes are a ledger box with the intro as its description, no fold; the tally is a Cache facts row' );
ok( 2 === substr_count( $kit, '<div class="snt-cols">' ) && 1 === preg_match( '/<div class="snt-cols"><os-section heading="Token".*?<\/os-section><os-section heading="Post-purge probes"/s', $kit ), '17.4.1: with a ledger, Token and Post-purge probes share the second row' );
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
ok( false === strpos( $kit, '<os-disclosure' ) && 5 === count( cf_table_prop( $kit, 'data' ) ) && false === strpos( $kit, 'more</p>' ), 'a fresh newest probe changes nothing: no fold to open or close, five rows, no "more" line under the cap' );
// The cap: the newest CF_PROBE_ROWS rows in the table, the rest as one line.
$long = array();
for ( $i = 0; $i < 11; $i++ ) { $long[] = array( 'time' => time() - 60 * ( $i + 1 ), 'result' => 'fresh', 'algo' => 2, 'url' => 'https://example.test/p' . $i . '/' ); }
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_purge_probe_log' => $long ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
$rows = cf_table_prop( $kit, 'data' );
ok( is_array( $rows ) && 8 === count( $rows ) && '/p0/' === $rows[0]['page'] && '/p7/' === $rows[7]['page'] && false !== strpos( $kit, '<p class="snt-hint">…and 3 more</p>' ) && false !== strpos( $kit, '>11 retained, 0 stale</dd>' ), '17.4.1: eleven probes paint the eight newest rows and "…and 3 more"; the Cache tally still counts all eleven' );

cf_opts( array( 'sn_cf_purge_probe_log' => $log ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $classic, 'Post-purge probes' ) && false === strpos( $kit, 'Post-purge probes' ), 'unconfigured: the log is not shown, as on the classic leaf' );

// ── Cloudways: OK, Error (HTTP + message), and never attempted.
$GLOBALS['__cw_configured'] = true;
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => true ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '>Cloudways purge</dt>' ) && false !== strpos( $kit, '>OK</os-badge>' ) && false !== strpos( $kit, 'Varnish leg of the same chain. Last attempt: 1 hour ago.' ), 'a successful Cloudways purge reads OK with its age, as a facts row' );
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
ok( false === strpos( $kit, '<script>' ) && substr_count( $kit, '&lt;script&gt;' ) >= 3 && false !== strpos( $kit, '>••••••••</dd>' ), 'hostile zone, probe path and Cloudways error are escaped; a short token masks fully' );
$GLOBALS['__cw_configured'] = false;

// ── Token locked by its constant: no sn_cf_token in either form, the zone still editable, Save still offered.
define( 'SN_CLOUDFLARE_API_TOKEN', 'const-token-9876' );
cf_opts( array( 'sn_cf_zone_id' => 'zone0123456789abcdef' ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( array() === cf_fields( $kit ) && array() === cf_fields( $classic ) && false !== strpos( $kit, '>locked by SN_CLOUDFLARE_API_TOKEN</dd>' ), 'token locked: no field on either leaf; the source names the constant' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array( 'cf_monitor_refresh', 'cf_purge_now' ) === snt_leaf_actions( $kit ), 'token locked: the same two actions' );
ok( false === strpos( $kit, 'const-token-9876' ) && false === strpos( $classic, 'const-token-9876' ), 'the constant\'s value is on neither leaf' );

// ── Both locked: no credentials form at all, only the purge action, as on the classic leaf.
define( 'SN_CLOUDFLARE_ZONE_ID', 'zoneconst0123456789' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( array() === cf_fields( $classic ) && array() === cf_fields( $kit ) && false !== strpos( $kit, '>locked by SN_CLOUDFLARE_ZONE_ID</dd>' ), 'both locked: no field, the zone source names its constant' );
// 14.10.0: the account id is the third credential; with only token and zone
// locked it is still editable, so Save stays offered on both leaves.
ok( array( 'cf_monitor_refresh', 'cf_purge_now' ) === snt_leaf_actions( $classic ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'token and zone locked: the two actions, no Save' );
ok( false !== strpos( $kit, '>Account ID</dt>' ) && false !== strpos( $classic, 'Account ID' ), 'the account id source is on both leaves' );
ok( false !== strpos( $kit, 'Grants this token needs' ) && false !== strpos( $kit, 'Account Analytics › Read' ) && false !== strpos( $kit, 'Cache Purge › Purge' ), 'the grant list paints on the kit leaf: one place to compare against the token summary' );
// 14.9.0: the monitor section paints on the kit leaf, honest about never having run.
ok( false !== strpos( $kit, 'The monitor has not run yet' ) && false !== strpos( $kit, 'cf_monitor_refresh' ), 'the Monitor section says the monitor has not run and offers Refresh now' );
// 15.1.0: with a stored reading and an event log, the firewall block paints
// the log's weighted tops (paths, countries) beside the by-action list.
$opts_before = $GLOBALS['__options'];
$GLOBALS['__options'][ SN_CF_MONITOR_OPT ]   = array( 'fetched_at' => time(), 'configured' => true, 'token' => array( 'verified' => true, 'status' => 'active', 'expires_on' => '', 'error' => '', 'kind' => 'user' ), 'zone' => array( 'available' => false, 'needs_permission' => false, 'error' => 'x' ), 'firewall' => array( 'available' => true, 'events' => 4, 'by_action' => array( 'block' => 4 ), 'top_rules' => array(), 'dataset' => 'raw', 'truncated' => false ) );
$GLOBALS['__options'][ SN_CF_FW_EVENTS_OPT ] = array( 'fetched_at' => time(), 'configured' => true, 'available' => true, 'rows' => array( array( 'clientRequestPath' => '/xmlrpc.php', 'clientCountryName' => 'CN', 'weight' => 3 ), array( 'clientRequestPath' => '/wp-login.php', 'clientCountryName' => 'CN', 'weight' => 1 ) ), 'truncated' => true );
$with_log = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $with_log, 'Top paths acted on' ) && false === strpos( $with_log, 'heading="Firewall, 24 hours"' ) && false === strpos( $with_log, 'heading="Edge, 7 days"' ), '15.3.0: with a stored reading, this leaf still paints no Edge and no Firewall (they moved)' );
ok( false !== strpos( $with_log, 'heading="Token"' ) && false !== strpos( $with_log, 'active · user' ) && false !== strpos( $with_log, '>Verified</dt>' ) && strpos( $with_log, 'heading="Token"' ) > strpos( $with_log, 'heading="Cache"' ), '15.1.0: the token\'s health is its own section, with when it was verified; 17.4.1 seats it on the row under Credentials and Cache' );
ok( false !== strpos( $with_log, 'heading="Cache"' ) && false === strpos( $with_log, 'heading="Monitor"' ), 'the Cache box stands; no Monitor section' );
ok( 1 === substr_count( $with_log, 'os-arg-action="cf_monitor_refresh"' ) && strpos( $with_log, 'os-arg-action="cf_monitor_refresh"' ) > strpos( $with_log, 'heading="Token"' ) && false !== strpos( $with_log, 'Refresh reads the token, the edge and the firewall again' ), '15.3.0: one Refresh footer, under Token, saying what it refreshes' );
ok( array( 'cf_monitor_refresh', 'cf_purge_now' ) === snt_leaf_actions( $with_log ), 'still the two actions' );
$GLOBALS['__options'] = $opts_before;
ok( false !== strpos( $kit, '>locked by SN_CLOUDFLARE_ZONE_ID</dd>' ) && false === strpos( $kit, 'zoneconst0123456789' ), 'both locked: the zone reads as locked and its constant value is not painted' );
define( 'SN_CF_ACCOUNT_ID', 'acctconst0123456789' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $kit, '<os-form' ) && false !== strpos( $kit, '>locked by SN_CF_ACCOUNT_ID</dd>' ) && array( 'cf_monitor_refresh', 'cf_purge_now' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'all three locked: no form, the three sources locked, the two actions on both leaves' );
ok( false !== strpos( $kit, '>Auto-purge</dt>' ) && false !== strpos( $kit, '>Active</os-badge>' ) && false === cf_purge_disabled( $kit ), 'both locked: the constants configure the module and the purge button is live' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
