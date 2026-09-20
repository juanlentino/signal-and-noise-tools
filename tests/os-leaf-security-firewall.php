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
require SNT_PATH . 'inc/cloudflare-posture.php'; // 15.4.0: the edge posture beside the firewall
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
// 15.3.1: two columns: what happened (events, rules) left; to what (paths, countries) right.
// #1573: the two are of a height, so they are one .snt-cols row (the 17.2.1 shape), not a .snt-2up column pair.
ok( false !== strpos( $kit, '<div class="snt-cols">' ) && false !== strpos( $kit, 'heading="Acted on"' ) && strpos( $kit, 'heading="Firewall, 24 hours"' ) < strpos( $kit, 'heading="Acted on"' ) && false !== strpos( $kit, '>Top rules</h4>' ) && strpos( $kit, '>Top rules</h4>' ) < strpos( $kit, 'heading="Acted on"' ) && strpos( $kit, 'Top paths acted on' ) > strpos( $kit, 'heading="Acted on"' ) && strpos( $kit, 'os-arg-action="cf_monitor_refresh"' ) < strpos( $kit, 'heading="Acted on"' ), 'two columns: events and the top rules (with a heading) left with Refresh; the paths and countries right under Acted on' );

// 15.4.0: the posture, under Acted on. Before it is read: one hint. Read:
// the judged settings as a dotted list in words, the readings as one quiet
// line, the custom rules by name with their state, and the top rule on the
// left now carries its NAME (the id kept as a title).
ok( false !== strpos( $kit, 'heading="Edge posture"' ) && strpos( $kit, 'heading="Edge posture"' ) > strpos( $kit, 'heading="Acted on"' ) && false !== strpos( $kit, 'Not read yet; it reads with Refresh now.' ), 'posture never read: the section sits under Acted on and says so' );
// #1573: the posture stands alone at full width UNDER the row, never stacked
// under Acted on in a column beside the Firewall box: the row's one `.snt-cols`
// closes before the posture opens, and no `.snt-2up` column pair remains.
$row_at = strpos( $kit, '<div class="snt-cols">' );
ok( false !== $row_at && strpos( $kit, '</div>', $row_at ) < strpos( $kit, 'heading="Edge posture"' ) && 1 === substr_count( $kit, 'snt-cols' ) && false === strpos( $kit, 'snt-2up' ), 'the row holds Firewall and Acted on and closes before the posture; the posture is full width below it' );
// #1573: a log with no rows paints no Acted on, and the Firewall box then
// stands alone at full width (the tags_pair idiom), the posture still below.
$with_log = $GLOBALS['__options'];
fw_opts( array_diff_key( $with_log, array( SN_CF_FW_EVENTS_OPT => 1 ) ) );
$alone = snt_leaf_paint( 'security', 'firewall' );
fw_opts( $with_log );
ok( false === strpos( $alone, 'snt-cols' ) && false === strpos( $alone, 'snt-2up' ) && false === strpos( $alone, 'heading="Acted on"' ) && false !== strpos( $alone, 'heading="Firewall, 24 hours"' ) && strpos( $alone, 'heading="Firewall, 24 hours"' ) < strpos( $alone, 'heading="Edge posture"' ), 'no event log: no row, the Firewall box alone with the posture under it' );
$posture = array( 'fetched_at' => time(), 'configured' => true,
	'settings' => array( 'available' => true, 'needs_permission' => false, 'error' => '', 'values' => array( 'ssl' => 'strict', 'min_tls_version' => '1.2', 'always_use_https' => 'on', 'development_mode' => 'on', 'security_level' => 'medium', 'browser_check' => 'on' ) ),
	'dnssec'   => array( 'available' => false, 'needs_permission' => true, 'error' => 'The token lacks Zone › DNS › Read. Authentication error', 'status' => '' ),
	'rules'    => array( 'available' => true, 'needs_permission' => false, 'error' => '', 'rules' => array( array( 'id' => 'r1', 'description' => 'Block Basic-auth on abilities API', 'action' => 'block', 'enabled' => true, 'expression' => '' ), array( 'id' => 'r2', 'description' => 'Old guard', 'action' => 'block', 'enabled' => false, 'expression' => '' ) ) ),
);
fw_opts( $GLOBALS['__options'] + array( SN_CF_POSTURE_OPT => $posture ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_firewall_section' );
$kit     = snt_leaf_paint( 'security', 'firewall' );
ok( false !== strpos( $kit, '>SSL mode<' ) && false !== strpos( $kit, '>Full (strict)<' ) && false !== strpos( $kit, '>TLS 1.2<' ) && false !== strpos( $kit, 'snt-dot--ok' ), 'judged settings in words with an ok dot' );
ok( false !== strpos( $kit, '>Development mode<' ) && 2 === substr_count( $kit, 'snt-dot--err' ) && 4 === substr_count( $kit, 'snt-dot--ok' ), 'two err dots (development mode on, the disabled rule) against four ok (three settings, the enabled rule)' );
ok( false !== strpos( $kit, 'Also set: Security level medium · Browser integrity check on.' ), 'the readings are one quiet line' );
ok( false !== strpos( $kit, 'The token lacks Zone › DNS › Read' ) && false !== strpos( $kit, '<os-notice tone="warning"' ), 'the refused DNSSEC read is a warning notice naming the scope' );
ok( false !== strpos( $kit, '>Custom rules</h4>' ) && false !== strpos( $kit, '>Old guard<' ) && false !== strpos( $kit, '>disabled<' ) && strpos( $kit, '>Custom rules</h4>' ) > strpos( $kit, 'heading="Edge posture"' ), 'custom rules by name; a disabled one says so' );
ok( false !== strpos( $kit, '>Block Basic-auth on abilities API<' ) && false === strpos( $kit, '>firewallCustom r1<' ) && false !== strpos( $kit, 'title="firewallCustom r1"' ), 'the top rule on the left now carries its name, the id as a title' );
ok( false !== strpos( $classic, 'Edge posture' ) && false !== strpos( $classic, '<td>Full (strict)</td>' ) && false !== strpos( $classic, '<strong>drift</strong>' ) && false !== strpos( $classic, 'Zone › DNS › Read' ) && false !== strpos( $classic, '<td>Block Basic-auth on abilities API</td>' ) && false !== strpos( $classic, '<strong>disabled</strong>' ), 'the classic leaf paints the same posture' );
ok( array() === snt_leaf_classic_markers( $kit ) && array( 'cf_monitor_refresh' ) === snt_leaf_actions( $kit ), 'still one action and no wp-admin markup with the posture painted' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
