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
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } } // 2026-10-12: the monitor's token flush
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
require SNT_PATH . 'inc/cloudflare-readings-admin.php'; // PR #2025 review: the classic Refresh form's sentence
if ( ! function_exists( 'sn_keyring' ) ) { require SNT_PATH . 'inc/keyring.php'; } // 2026-10-12: the cf_token row's monitor flush
define( 'SN_ADMIN_CLOUDWAYS_TEST', true );
require SNT_PATH . 'inc/admin-forms/cloudways.php'; // 2026-10-12: the Cloudways verdict both leaves share
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cloudflare.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function cf_opts( array $o ) { $GLOBALS['__options'] = $o; }
/** An os-prop-* JSON attribute of the probes table, decoded (null when the table is absent). */
function cf_table_prop( $html, $prop ) {
	if ( ! preg_match( '/os-prop-' . $prop . '="([^"]*)"/', $html, $m ) ) { return null; }
	return json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
}
/** 15.2.0: field names minus the framing names every classic form carries; the leaf holds no credential field any more. */
function cf_fields( $html ) { return array_values( array_diff( snt_leaf_names( $html ), array( '_wpnonce', 'sn_action' ) ) ); }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/cloudflare'] ), 'the painter is registered under connections/cloudflare' );

// ── Unconfigured: same names, same actions, the warning box, no purge button.
cf_opts( array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === cf_fields( $classic ) && array() === cf_fields( $kit ), '15.2.0: no credential field on either leaf; the keyring holds them' );
ok( array( 'cf_monitor_refresh' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is cf_monitor_refresh, as on the classic leaf; cf_save is gone (15.2.0) and so is cf_purge_now' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false === strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'Connections › Credentials' ) && false === strpos( $kit, 'This leaf' ) && false === strpos( $classic, 'This leaf' ), 'no form: the leaf points to Connections › Credentials, in plain words (no "This leaf only reads them")' );
ok( false !== strpos( $kit, 'label="API token"' ) && false !== strpos( $kit, 'label="Zone ID"' ) && false !== strpos( $kit, 'label="Account ID"' ) && substr_count( $kit, '>not set</span></os-fact>' ) >= 3, 'the three sources read "not set" as facts rows' );
ok( false === strpos( $kit, 'Paste a fresh token' ) && false === strpos( $classic, 'sn_cf_token' ), 'no placeholder, no token field, on either leaf' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'Not configured' ) && false !== strpos( $kit, '>Inactive</os-badge>' ), 'the unconfigured state paints a warning notice with the Inactive badge' );
ok( false !== strpos( $kit, 'Set both the API token and zone ID under Credentials to activate.' ) && false !== strpos( $classic, 'Set both the API token and zone ID under Credentials to activate.' ), 'unconfigured: both surfaces name Credentials, not "the main column" (PR #2013 review)' );
ok( false === strpos( $kit, 'Purge all caches' ) && false === strpos( $kit, 'cf_purge_now' ) && false === strpos( $classic, 'Purge all caches' ) && false === strpos( $classic, 'cf_purge_now' ), 'no purge card and no purge button on either leaf: purging is automated, the command stays in the palette' );
ok( false === strpos( $kit, 'Post-purge probes' ) && false === strpos( $kit, 'Cloudways purge' ), 'no probes box and no Cloudways box when neither has anything to say' );
ok( false !== strpos( $kit, 'a Cache Rule in the Cloudflare dashboard caches HTML at the edge' ) && false === strpos( $kit, 'docs/CACHING.md' ) && false === strpos( $classic, 'docs/CACHING.md' ) && false !== strpos( $classic, 'a Cache Rule in the Cloudflare dashboard caches HTML at the edge' ) && false !== strpos( $kit, 'heading="Keys and grants"' ) && false !== strpos( $kit, 'heading="Cache"' ) && false === strpos( $kit, 'heading="Edge, 7 days"' ) && false === strpos( $kit, 'heading="Firewall, 24 hours"' ) && strpos( $kit, 'heading="Cache"' ) < strpos( $kit, 'a Cache Rule in the Cloudflare' ), '15.3.0: Cache carries the Cache Rule sentence as its hint (no repository path, on both surfaces), the credentials sit in Setup; Edge and Firewall live on Measurement and Security now' );
ok( false !== strpos( $kit, 'heading="Token"' ) && false !== strpos( $kit, 'The monitor has not run yet' ) && false !== strpos( $kit, 'cf_monitor_refresh' ), '15.3.0: before the monitor ran, the Token section says so and offers Refresh' );
// 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): a Status card leads
// with one status line, then Cache beside Token (the readings and Refresh),
// then the Setup card, whose one fold is the keys and grants: open while the
// token or zone is missing.
ok( 0 === strpos( $kit, '<os-section heading="Status" stack><div class="snt-status-line"><os-cluster gap="8"><os-badge tone="warning">Not configured</os-badge><os-chip>Last purge: never</os-chip><os-chip>Token not read yet</os-chip></os-cluster></div></os-section>' ), 'unconfigured: the leaf leads with one status line: Not configured, the last purge, the token' );
ok( 1 === substr_count( $kit, snt_leaf_row() ) && false === strpos( $kit, 'snt-2up' ) && 1 === preg_match( '/' . preg_quote( snt_leaf_row(), '/' ) . '<os-section heading="Cache".*?<\/os-section><os-section heading="Token".*?<\/os-section><\/os-grid><os-section heading="Setup"/s', $kit ), 'one paired row, Cache beside Token, then the Setup card; no snt-2up columns' );
ok( 1 === preg_match( '#<os-section heading="Setup" description="[^"]*" stack><os-disclosure heading="Keys and grants" hint="API token not set · Zone ID not set · Account ID not set"[^>]*\sopen[\s=>]#', $kit ), 'unconfigured: the Keys and grants fold says each source is not set, and opens itself' );

// PR #2025 review: Refresh is disabled until the token and zone ID are set, so
// no box says "press Refresh" then; it says what Refresh waits for.
$GLOBALS['__options'][ SN_CF_MONITOR_OPT ] = array( 'fetched_at' => time(), 'configured' => true, 'token' => array( 'verified' => false, 'status' => 'changed', 'expires_on' => '', 'error' => '', 'kind' => '' ), 'zone' => null, 'firewall' => null );
$no_zone = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $no_zone, 'Press Refresh' ) && false !== strpos( $no_zone, 'The token changed after the last read. Refresh reads it once the API token and zone ID are set under Credentials.' ), 'PR #2025 review: with Refresh disabled (no zone ID), the box never says "Press Refresh"; it says what Refresh waits for' );
unset( $GLOBALS['__options'][ SN_CF_MONITOR_OPT ] );
$never = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $never, 'press Refresh' ) && false !== strpos( $never, 'The monitor has not run yet. Refresh reads it once the API token and zone ID are set under Credentials.' ), 'nor before the first run' );
$classic_never = snt_leaf_classic_html( static function () { sn_cf_readings_refresh_form( null ); } );
ok( false !== strpos( $classic_never, 'The monitor has not run yet. Refresh reads it once the API token and zone ID are set under Credentials.' ), 'the classic Refresh form says the same' );
cf_opts( array() );

// ── Configured, with a full-zone purge an hour ago.
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_last_purge' => array( 'time' => time() - 3600, 'kind' => 'all' ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( array() === cf_fields( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'configured: still no field, actions still match the classic leaf' );
ok( false !== strpos( $kit, '>••••1234</span></os-fact>' ) && false === strpos( $kit, 'cf-token-abcdef1234' ) && false === strpos( $classic, 'cf-token-abcdef1234' ), 'the token source shows the obscured value, never raw, on either leaf' );
ok( false !== strpos( $kit, '>zone0123456789abcdef</span></os-fact>' ), 'the zone id is shown as a fact' );
ok( 0 === strpos( $kit, '<os-section heading="Status" stack><div class="snt-status-line"><os-cluster gap="8"><os-badge tone="success">Auto-purge active</os-badge><os-chip>Last purge: 1 hour ago</os-chip><os-chip>Token not read yet</os-chip></os-cluster></div></os-section>' ), 'configured: the status line says auto-purge is active and when the last purge ran' );
ok( 1 === preg_match( '#<os-disclosure heading="Keys and grants" hint="API token set · Zone ID set · Account ID not set"[^>]*data-snt-open="0">#', $kit ) && false === strpos( $kit, 'hint="API token ••••' ), 'configured: the fold is closed, its summary says which source is set and never prints the token, not even masked' );
ok( false === strpos( $kit, 'Configured: auto-purge active' ) && false !== strpos( $kit, 'label="Auto-purge"' ) && false !== strpos( $kit, '>Active</os-badge>' ) && false !== strpos( $kit, 'on post save, on a Site Editor menu, template or template part save, on a theme or plugin update (which marks pages stale so each refreshes on its next request) and through the REST endpoint' ), '15.1.0: the configured state is a facts row with the Active badge, not a notice' );
ok( false !== strpos( $kit, 'label="Last purge"' ) && false !== strpos( $kit, '<os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time> (full zone)' ), '#1596: the last full-zone purge is an os-relative-time with the server reading as its fallback' );
ok( false === strpos( $kit, 'cf_purge_now' ) && false === strpos( $classic, 'cf_purge_now' ), 'and configuring adds no purge button: the Cache box is history only' );

cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_last_purge' => array( 'time' => time() - 60, 'kind' => 'urls', 'count' => 7 ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '>1 hour ago</os-relative-time> (7 URLs)' ), 'a per-URL purge is read out with its count, no "URL(s)"' );

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
ok( false !== strpos( $classic, 'Post-purge probes' ) && false === strpos( $kit, '<os-disclosure heading="Post-purge probes"' ) && 1 === preg_match( '/<os-section heading="Post-purge probes" description="Each row is one check[^"]*" stack>/', $kit ) && false !== strpos( $kit, '>4 retained, 2 stale</span></os-fact>' ), '17.4.1: the probes are a ledger box with the intro as its description, no fold; the tally is a Cache facts row' );
ok( 2 === substr_count( $kit, snt_leaf_row() ) && 1 === preg_match( '/' . preg_quote( snt_leaf_row(), '/' ) . '<os-section heading="Post-purge probes".*?<\/os-section><os-section heading="Setup"/s', $kit ), 'with a ledger, Post-purge probes and the Setup card share the second row' );
ok( false !== strpos( $kit, '<os-badge tone="warning">Probes: 4 retained, 2 stale</os-badge>' ), 'a stale probe is on the status line, in the warning tone' );
ok( false !== strpos( $kit, '120 seconds after its purge' ), 'the probe delay is read out in the intro' );
ok( false !== strpos( $kit, '<os-table' ) && is_array( $rows ) && 4 === count( $rows ), 'the probes table carries all four rows' );
ok( is_array( $rows ) && array( 'when' => '1 hour ago', 'result' => 'stale → zone purge', 'page' => '/notes/foo/' ) === $rows[0], 'an escalated stale probe reads stale → zone purge with its path' );
ok( is_array( $rows ) && 'fresh' === $rows[1]['result'] && '/about/' === $rows[1]['page'], 'a fresh probe reads fresh with its path' );
ok( is_array( $rows ) && 'stale · retired detector' === $rows[2]['result'], 'a pre-fix verdict is marked as from the retired detector' );
ok( is_array( $rows ) && array( 'when' => 'not recorded', 'result' => 'unknown', 'page' => 'manual' ) === $rows[3], 'a manual zone purge names its source, an empty verdict reads unknown, no time reads "not recorded", never an em dash' );
ok( is_array( $columns ) && array( 'When', 'Result', 'Page' ) === array_column( $columns, 'label' ), 'the three classic columns survive, in order' );

array_unshift( $log, array( 'time' => time() - 10, 'result' => 'fresh', 'algo' => 2, 'url' => 'https://example.test/new/' ) );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_purge_probe_log' => $log ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $kit, '<os-disclosure heading="Post-purge probes"' ) && 5 === count( cf_table_prop( $kit, 'data' ) ) && false === strpos( $kit, 'more</p>' ), 'a fresh newest probe changes nothing: no fold to open or close, five rows, no "more" line under the cap' );
// The cap: the newest CF_PROBE_ROWS rows in the table, the rest as one line.
$long = array();
for ( $i = 0; $i < 11; $i++ ) { $long[] = array( 'time' => time() - 60 * ( $i + 1 ), 'result' => 'fresh', 'algo' => 2, 'url' => 'https://example.test/p' . $i . '/' ); }
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_purge_probe_log' => $long ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
$rows = cf_table_prop( $kit, 'data' );
ok( is_array( $rows ) && 8 === count( $rows ) && '/p0/' === $rows[0]['page'] && '/p7/' === $rows[7]['page'] && false !== strpos( $kit, '<p class="snt-hint">…and 3 more</p>' ) && false !== strpos( $kit, '>11 retained, 0 stale</span></os-fact>' ), '17.4.1: eleven probes paint the eight newest rows and "…and 3 more"; the Cache tally still counts all eleven' );

cf_opts( array( 'sn_cf_purge_probe_log' => $log ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $classic, 'Post-purge probes' ) && false === strpos( $kit, 'Post-purge probes' ), 'unconfigured: the log is not shown, as on the classic leaf' );

// ── Cloudways: OK, Failed (HTTP + message), timed out, re-authed, and never attempted.
// 2026-10-12 audit: the row takes the Cloudways leaf's own verdict, so the two
// leaves call one record by one name, on both surfaces.
$GLOBALS['__cw_configured'] = true;
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => true ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, 'label="Cloudways purge"' ) && false !== strpos( $kit, '<os-badge tone="success">OK</os-badge> Last attempt: <os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>. Dispatched and acknowledged.' ) && false === strpos( $kit, 'Varnish leg' ), 'a successful Cloudways purge reads OK with its age and the Cloudways leaf\'s sentence, as a facts row' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => false, 'http' => 422, 'error' => 'field validation failed' ) ) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '<os-badge tone="danger">Failed</os-badge>' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>. field validation failed (HTTP <os-code>422</os-code>)' ) && false !== strpos( $kit, 'data-tone="warning"' ), 'a failed Cloudways purge reads Failed with the message and the HTTP status, in the warning tone' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => false, 'inconclusive' => true, 'http' => 0, 'error' => 'cURL error 28' ) ) );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
ok( false !== strpos( $kit, '<os-badge tone="warning">Unknown</os-badge> Inconclusive.' ) && false === strpos( $kit, 'HTTP 0' ) && false !== strpos( $classic, '<span class="sn-pill sn-pill--warn">Unknown</span>' ) && false !== strpos( $classic, 'Inconclusive.' ), 'a timeout reads Inconclusive / Unknown on both surfaces, never "Error · HTTP 0"' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 120, 'ok' => true, 'reauthed' => true ) ) );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
ok( false !== strpos( $kit, '>Check credential</os-badge> OK after re-auth.' ) && false !== strpos( $classic, '>Check credential</span>' ), 'a re-authed success reads Check credential on both surfaces, as the Cloudways leaf does' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef' ) );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
ok( false !== strpos( $kit, 'Cloudways purge' ) && false !== strpos( $kit, '<os-badge tone="warning">No data</os-badge> Never run.' ) && false === strpos( $kit, 'Last attempt' ) && false !== strpos( $classic, '>No data</span>' ) && false === strpos( $classic, '>Active</span></div>' . "\n" ), 'a configured Cloudways module that never purged reads Never run / No data, not Active' );

// ── 2026-10-13: a stale probe escalates to the whole-site tag; both surfaces say which purge ran, in one sentence.
$GLOBALS['__cw_configured'] = false;
$tag_log = array(
	array( 'time' => time() - 60, 'result' => 'stale', 'escalated' => true, 'escalation' => 'tag', 'algo' => 2, 'url' => 'https://example.test/notes/bar/' ),
	array( 'time' => time() - 300, 'result' => 'stale', 'escalated' => true, 'escalation' => 'zone', 'algo' => 2, 'url' => 'https://example.test/notes/foo/' ),
);
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cf_purge_probe_log' => $tag_log, 'sn_last_purge_report' => array( 'legs' => array( 'breeze_page_cache' => false ) ), 'sn_cf_last_purge' => array( 'time' => time() - 3600, 'kind' => 'all', 'mode' => 'invalidate' ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
$rows    = cf_table_prop( $kit, 'data' );
ok( is_array( $rows ) && 'stale → whole-site tag purge' === $rows[0]['result'] && 'stale → zone purge' === $rows[1]['result'] && false !== strpos( $classic, 'stale → whole-site tag purge' ) && false !== strpos( $classic, 'stale → zone purge' ), 'a tag escalation reads "→ whole-site tag purge", a zone one "→ zone purge", on both surfaces' );
$intro = esc_html( snt_cf_probe_intro( 120 ) );
ok( false !== strpos( $classic, $intro ) && false !== strpos( $kit, $intro ) && false !== strpos( $intro, 'whole-site cache tag' ) && false === strpos( $intro, 'escalated to a full zone purge at the time' ), 'both surfaces carry the same intro, naming the tag first and the zone as the fallback' );
ok( false !== strpos( $kit, '(full zone, marked stale)' ) && false !== strpos( $classic, '(full zone, marked stale)' ), 'a code update reads "full zone, marked stale" on both surfaces, not a plain full zone' );
ok( false !== strpos( $kit, 'label="Breeze page cache"' ) && false !== strpos( $kit, '>off</span></os-fact>' ) && false !== strpos( $classic, 'Breeze page cache: off.' ), 'the theme report\'s Breeze page cache leg reads off on both surfaces' );
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef' ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '>not read</span></os-fact>' ) && false !== strpos( $classic, 'Breeze page cache: not read.' ), 'no report: the Breeze page cache reads "not read", never off' );
// Retired Cloudways leg: words, no growing "Last attempt" (Varnish is off for this app).
if ( true ) { function sn_cloudways_app_purge_on() { return ! empty( $GLOBALS['__cw_on'] ); } }
$GLOBALS['__cw_configured'] = true;
cf_opts( array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef', 'sn_cloudways_last_purge' => array( 'time' => time() - 86400 * 9, 'ok' => true ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '<os-badge tone="neutral">Retired</os-badge>' ) && false !== strpos( $kit, 'Varnish is off for this app' ) && false === strpos( $kit, 'Last attempt' ) && false !== strpos( $classic, '>Retired</span>' ) && false !== strpos( $classic, 'Varnish is off for this app' ) && false === strpos( $classic, 'Last attempt' ), 'a retired Cloudways purge says Retired, Varnish is off, on both surfaces, with no growing Last attempt' );
$GLOBALS['__cw_on'] = true;
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $kit, '>OK</os-badge> Last attempt:' ), 'turned back on, it reads its last record again' );

// ── Escaping: hostile values in the zone, a probe URL and the Cloudways error never reach the markup raw.
cf_opts( array(
	'sn_cf_api_token'        => 'abc',
	'sn_cf_zone_id'          => '"><script>x</script>',
	'sn_cf_purge_probe_log'  => array( array( 'time' => time() - 5, 'result' => 'stale', 'algo' => 2, 'url' => 'https://example.test/<script>y</script>/' ) ),
	'sn_cloudways_last_purge' => array( 'time' => time() - 5, 'ok' => false, 'http' => 500, 'error' => '<script>z</script>' ),
) );
$kit = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $kit, '<script>' ) && substr_count( $kit, '&lt;script&gt;' ) >= 3 && false !== strpos( $kit, '>••••••••</span></os-fact>' ), 'hostile zone, probe path and Cloudways error are escaped; a short token masks fully' );
$GLOBALS['__cw_configured'] = false;

// ── Token locked by its constant: no sn_cf_token in either form, the zone still editable, Save still offered.
define( 'SN_CLOUDFLARE_API_TOKEN', 'const-token-9876' );
cf_opts( array( 'sn_cf_zone_id' => 'zone0123456789abcdef' ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( array() === cf_fields( $kit ) && array() === cf_fields( $classic ) && false !== strpos( $kit, '>locked by SN_CLOUDFLARE_API_TOKEN</span></os-fact>' ), 'token locked: no field on either leaf; the source names the constant' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array( 'cf_monitor_refresh' ) === snt_leaf_actions( $kit ), 'token locked: the same one action' );
ok( false === strpos( $kit, 'const-token-9876' ) && false === strpos( $classic, 'const-token-9876' ), 'the constant\'s value is on neither leaf' );

// ── Both locked: no credentials form at all, only the purge action, as on the classic leaf.
define( 'SN_CLOUDFLARE_ZONE_ID', 'zoneconst0123456789' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( array() === cf_fields( $classic ) && array() === cf_fields( $kit ) && false !== strpos( $kit, '>locked by SN_CLOUDFLARE_ZONE_ID</span></os-fact>' ), 'both locked: no field, the zone source names its constant' );
// 14.10.0: the account id is the third credential; with only token and zone
// locked it is still editable, so Save stays offered on both leaves.
ok( array( 'cf_monitor_refresh' ) === snt_leaf_actions( $classic ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'token and zone locked: the one action, no Save' );
ok( false !== strpos( $kit, 'label="Account ID"' ) && false !== strpos( $classic, 'Account ID' ), 'the account id source is on both leaves' );
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
ok( false !== strpos( $with_log, 'heading="Token"' ) && false !== strpos( $with_log, 'active · user' ) && false !== strpos( $with_log, 'label="Verified"' ) && strpos( $with_log, 'heading="Token"' ) > strpos( $with_log, 'heading="Cache"' ), '15.1.0: the token\'s health is its own section, with when it was verified; 17.4.1 seats it on the row under Credentials and Cache' );
ok( false !== strpos( $with_log, 'heading="Cache"' ) && false === strpos( $with_log, 'heading="Monitor"' ), 'the Cache box stands; no Monitor section' );
ok( 1 === substr_count( $with_log, 'os-arg-action="sn_cf_monitor_refresh"' ) && strpos( $with_log, 'os-arg-action="sn_cf_monitor_refresh"' ) > strpos( $with_log, 'heading="Token"' ) && false !== strpos( $with_log, 'Refresh reads the token, the edge and the firewall again' ), '15.3.0: one Refresh footer, under Token, saying what it refreshes' );
ok( array( 'cf_monitor_refresh' ) === snt_leaf_actions( $with_log ), 'still the one action' );
// 2026-10-12 audit: the time printed twice (Verified, then "Read …" in the
// footer), and "expires within 14 days" was carried by the warning color alone.
ok( 1 === substr_count( $with_log, gmdate( 'F j, Y g:i a', time() ) ) && false !== strpos( $with_log, '<span class="snt-hint">Refresh reads the token, the edge and the firewall again.</span></os-cluster></footer>' ), 'the Token box says when it was read once, in its Verified row, in the site\'s format; the Refresh line shares the footer row with its button (2026-10-16)' );
$GLOBALS['__options'][ SN_CF_MONITOR_OPT ]['token']['expires_on'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 9 * DAY_IN_SECONDS + 600 );
$expiring = snt_leaf_paint( 'connections', 'cloudflare' );
ok( 1 === preg_match( '#<span class="snt-kv__v" data-tone="warning">active · user · expires in 1 hour</span>#', $expiring ), 'a token expiring within 14 days says so in words beside the warning tone (the harness human_time_diff() reads "1 hour")' );
ok( false === strpos( $with_log, 'rate-limit headers' ) && false === strpos( $with_log, 'answers 429' ) && false !== strpos( $with_log, '1,200 calls every five minutes, refused past that' ), 'the API allowance note is in plain words' );
// 2026-10-12 audit: saving a new token left the old token's status and expiry
// on the Token box until the daily run; the keyring's flush marks it changed.
ok( 'sn_cf_monitor_forget_token' === ( sn_keyring()['cf_token']['flush_fn'] ?? '' ), 'the keyring flushes the monitor\'s token reading when the Cloudflare token is saved' );
// PR #2025 review: the flush verifies the new token (tests/cloudflare-monitor.php);
// "changed" is what it stores when Cloudflare cannot be reached.
$GLOBALS['__options'][ SN_CF_MONITOR_OPT ]['token'] = array( 'verified' => false, 'status' => 'changed', 'expires_on' => '', 'error' => 'cURL error 28', 'kind' => '' );
$GLOBALS['__options'][ SN_CF_TOKEN_OPT ]            = 'tok-new';
$GLOBALS['__options'][ SN_CF_ZONE_OPT ]             = 'zone123';
$changed = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false !== strpos( $changed, 'The token changed after the last read. Press Refresh now to read the new one.' ) && false === strpos( $changed, 'active · user' ) && false === strpos( $changed, 'os-arg-action="sn_cf_monitor_refresh" disabled' ) && 1 === preg_match( '/<os-button[^>]*os-arg-action="sn_cf_monitor_refresh"(?![^>]*\bdisabled)/', $changed ), 'after a new token: the Token box says it changed, not the old status, and its Refresh is live' );
$GLOBALS['__options'] = $opts_before;
ok( false !== strpos( $kit, '>locked by SN_CLOUDFLARE_ZONE_ID</span></os-fact>' ) && false === strpos( $kit, 'zoneconst0123456789' ), 'both locked: the zone reads as locked and its constant value is not painted' );
define( 'SN_CF_ACCOUNT_ID', 'acctconst0123456789' );
$classic = snt_leaf_classic_html( 'sn_admin_render_cloudflare_section' );
$kit     = snt_leaf_paint( 'connections', 'cloudflare' );
ok( false === strpos( $kit, '<os-form' ) && false !== strpos( $kit, '>locked by SN_CF_ACCOUNT_ID</span></os-fact>' ) && array( 'cf_monitor_refresh' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'all three locked: no form, the three sources locked, the two actions on both leaves' );
ok( false !== strpos( $kit, 'label="Auto-purge"' ) && false !== strpos( $kit, '>Active</os-badge>' ) && false === strpos( $kit, 'cf_purge_now' ), 'both locked: the constants configure the module, and there is still no purge button' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
