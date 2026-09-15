<?php
/**
 * Native window leaf: Security → Firewall (apps/sn-dashboard/parts/leaves/security-firewall.php).
 *
 * The oracle is the classic leaf (sn_admin_render_firewall_section →
 * sn_admin_firewall_render, inc/cloudflare-readings-admin.php). Both paint
 * the monitor's stored firewall reading and the event log's tops, never
 * fetching; one action, cf_monitor_refresh; the same words for never-run,
 * unconfigured, refused and read. 15.3.0: the reading moved here from
 * Connections › Cloudflare.
 *
 * Run: php tests/os-leaf-security-firewall.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! defined( 'SNT_CW_LAST_PURGE_OPT' ) ) { define( 'SNT_CW_LAST_PURGE_OPT', 'sn_cloudways_last_purge' ); }
if ( ! function_exists( 'size_format' ) ) { function size_format( $b ) { return $b . ' B'; } }

require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/cloudflare-purge-verify.php';
require SNT_PATH . 'inc/cloudflare-purge.php';
require SNT_PATH . 'inc/cloudflare-monitor.php';
require SNT_PATH . 'inc/cloudflare-firewall-events.php';
require SNT_PATH . 'inc/cloudflare-credentials.php';
require SNT_PATH . 'inc/cloudflare-readings-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cloudflare.php'; // the parts the Security leaf paints through
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/security-firewall.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fw_opts( array $o ) { $GLOBALS['__options'] = $o; }
$configured = array( 'sn_cf_api_token' => 'cf-token-abcdef1234', 'sn_cf_zone_id' => 'zone0123456789abcdef' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['security/firewall'] ), 'the painter is registered under security/firewall' );

// ── Never run.
fw_opts( array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_firewall_section' );
$kit     = snt_leaf_paint( 'security', 'firewall' );
ok( '' !== $kit && '' !== $classic, 'both leaves paint before the monitor ran' );
ok( array( 'cf_monitor_refresh' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'one action, cf_monitor_refresh, on both leaves' );
ok( array() === array_diff( snt_leaf_names( $kit ), array( '_wpnonce', 'sn_action' ) ) && array() === snt_leaf_classic_markers( $kit ), 'no field, no wp-admin markup' );
ok( false !== strpos( $kit, 'heading="Firewall, 24 hours"' ) && false !== strpos( $kit, 'The monitor has not run yet' ) && false !== strpos( $classic, 'The monitor has not run yet' ), 'never run: says so on both leaves, under the Firewall heading' );

// ── Configured, refused on both datasets: the two sentences.
fw_opts( $configured + array( SN_CF_MONITOR_OPT => array( 'fetched_at' => time(), 'configured' => true, 'token' => array( 'verified' => true, 'status' => 'active', 'expires_on' => '', 'error' => '', 'kind' => 'user' ), 'zone' => array( 'available' => false, 'needs_permission' => false, 'error' => 'x' ), 'firewall' => array( 'available' => false, 'needs_permission' => true, 'error' => "zone 'z' does not have access to the path", 'error_raw' => 'cannot request a time range wider than 1d', 'events' => 0, 'by_action' => array(), 'top_rules' => array() ) ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_firewall_section' );
$kit     = snt_leaf_paint( 'security', 'firewall' );
ok( false !== strpos( $kit, 'does not have access to the path' ) && false !== strpos( $kit, 'The raw dataset said' ) && false !== strpos( $kit, 'wider than 1d' ) && false !== strpos( $classic, 'The raw dataset said: &quot;cannot request a time range wider than 1d&quot;' ), 'refused: both API sentences, grouped and raw, on both leaves' );

// ── Read: by action, top rules, the log's tops, the raw note, the footer.
fw_opts( $configured + array(
	SN_CF_MONITOR_OPT   => array( 'fetched_at' => time(), 'configured' => true, 'token' => array( 'verified' => true, 'status' => 'active', 'expires_on' => '', 'error' => '', 'kind' => 'account' ), 'zone' => array( 'available' => false, 'needs_permission' => false, 'error' => 'x' ), 'firewall' => array( 'available' => true, 'events' => 3015, 'by_action' => array( 'skip' => 1843, 'block' => 1172 ), 'top_rules' => array( array( 'source' => 'firewallCustom', 'rule' => 'r1', 'action' => 'block', 'count' => 971 ) ), 'dataset' => 'raw', 'truncated' => false ) ),
	SN_CF_FW_EVENTS_OPT => array( 'fetched_at' => time(), 'configured' => true, 'available' => true, 'rows' => array( array( 'clientRequestPath' => '/wp-json/wp-abilities/v1/abilities', 'clientCountryName' => 'US', 'weight' => 487 ), array( 'clientRequestPath' => '/', 'clientCountryName' => 'DE', 'weight' => 558 ) ), 'truncated' => false ),
) );
$classic = snt_leaf_classic_html( 'sn_admin_render_firewall_section' );
$kit     = snt_leaf_paint( 'security', 'firewall' );
ok( false !== strpos( $kit, '3,015 events' ) && false !== strpos( $kit, '>skip<' ) && false !== strpos( $kit, '1,843' ) && false !== strpos( $kit, 'firewallCustom r1' ) && false !== strpos( $kit, 'block × 971' ), 'read: the count, by action, the top rule with its action' );
ok( false !== strpos( $kit, 'Top paths acted on' ) && strpos( $kit, '>/<' ) < strpos( $kit, '/wp-json/wp-abilities/v1/abilities' ) && false !== strpos( $kit, 'Top countries acted on' ) && false !== strpos( $kit, 'grouped dataset is not on this zone' ), 'the log\'s tops, heaviest first, and the raw-dataset note' );
ok( false !== strpos( $classic, '3,015 events, 24 hours' ) && false !== strpos( $classic, '<td>skip</td>' ) && false !== strpos( $classic, '/wp-json/wp-abilities/v1/abilities' ) && false !== strpos( $classic, 'grouped dataset is not on this zone' ), 'the classic leaf paints the same reading' );
ok( 1 === substr_count( $kit, 'os-arg-action="cf_monitor_refresh"' ) && false !== strpos( $kit, 'Refresh reads the token, the edge and the firewall again' ) && false !== strpos( $classic, 'value="cf_monitor_refresh"' ), 'one Refresh on each leaf, saying what it refreshes' );
ok( false === strpos( $kit, 'heading="Edge, 7 days"' ) && false === strpos( $kit, 'heading="Token"' ) && false === strpos( $kit, 'heading="Cache"' ), 'only the firewall: no Edge, Token or Cache here' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
