<?php
/**
 * Native window leaf: Monitoring → Analytics (apps/sn-dashboard/parts/leaves/monitoring-analytics.php).
 *
 * The oracle is the classic settings hub (inc/analytics-admin.php
 * `snt_analytics_render_settings_section()` + the small renderers in
 * inc/analytics-render-settings.php): the kit leaf must carry the same field
 * names and the same six sn_action values, across the locked/unlocked
 * credentials states and the empty/populated role list, and none of
 * wp-admin's markup.
 *
 * Run: php tests/os-leaf-monitoring-analytics.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── Constants the classic files define, needed without requiring their full
// dependency chains (analytics-api.php / analytics-sessions.php).
if ( ! defined( 'SN_CF_ACCOUNT_ID_OPT' ) ) { define( 'SN_CF_ACCOUNT_ID_OPT', 'sn_cf_account_id' ); }
if ( ! defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ) { define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' ); }
if ( ! defined( 'SN_ANALYTICS_FUNNELS_MAX_STEPS' ) ) { define( 'SN_ANALYTICS_FUNNELS_MAX_STEPS', 8 ); }
if ( ! defined( 'SN_ANALYTICS_FUNNELS_MAX' ) ) { define( 'SN_ANALYTICS_FUNNELS_MAX', 10 ); }
// SN_WORKER_VERSION_LASTGOOD is declared by the real inc/worker-version.php
// (required below), which now paints both the classic and kit worker/salt
// cards — not stubbed here, so the classic side is no longer painting a leaf
// with those two cards silently missing (see finding: "the classic oracle
// paints only part of the leaf").

// ── Network-level stubs so the REAL inc/worker-version.php +
// inc/analytics-salt-window.php can run end to end: both derive the same
// /_sn/version URL from sn_rss_tracker_settings() and probe it via
// wp_remote_get(), so one canned HTTP response drives both cards at once —
// exactly like the real Worker endpoint answering one request for both.
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'wp_http_validate_url' ) ) { function wp_http_validate_url( $url ) { return is_string( $url ) && '' !== $url; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { return true; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'nonce-' . $a === $n; } }
$GLOBALS['__http_code'] = 200;
$GLOBALS['__http_body'] = wp_json_encode(
	array(
		'worker'         => 'sn-analytics',
		'version'        => '1.14.2',
		'cf_version_id'  => 'cfv-1',
		'cf_version_tag' => 'v1.14.2',
		'deployed_at'    => '2026-08-01T00:00:00Z',
		'config'         => array( 'px_token_set' => true, 'ae_bound' => true ),
		'salt'           => array(
			'rotate_tz'       => 'UTC',
			'today_day'       => '2026-09-06',
			'today_present'   => true,
			'prev_day'        => '2026-09-05',
			'prev_present'    => true,
			'prev_expires_at' => time() + 3600,
			'key_count'       => 2,
		),
	)
);
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => $GLOBALS['__http_code'] ), 'body' => $GLOBALS['__http_body'] ); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); } }

// ── The leaf's own readers — controllable fixtures, function_exists-guarded
// like every classic reader this leaf calls.
$GLOBALS['__settings'] = array(
	'analytics.exclude_roles'        => array(),
	'analytics.signal_baseline_days' => 30,
	'analytics.anomaly_sensitivity'  => 'standard',
	'analytics.funnels'              => array(),
	'theme.ai_model'                 => 'claude-sonnet-5',
	'theme.ai_monthly_budget'        => 0,
);
function sn_setting( $key, $default = '' ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
$GLOBALS['__configured'] = false;
function sn_analytics_config() { return $GLOBALS['__configured']; }
function sn_mask_secret( $s ) { return '' === (string) $s ? '' : str_repeat( '•', 4 ); }
$GLOBALS['__roles'] = array( 'subscriber' => 'Subscriber', 'editor' => 'Editor' );
function sn_beacon_excludable_roles() { return $GLOBALS['__roles']; }
$GLOBALS['__excluded_now'] = false;
function sn_beacon_owner_current_user_excluded() { return $GLOBALS['__excluded_now']; }
function sn_analytics_funnels_to_text( $funnels ) { return implode( "\n", array_map( static function ( $f ) { return (string) ( $f['name'] ?? '' ) . ': ' . implode( ' > ', (array) ( $f['steps'] ?? array() ) ); }, $funnels ) ); }
$GLOBALS['__rss'] = array( 'collector_url' => 'https://example.test/_sn/px' );
function sn_rss_tracker_settings() { return $GLOBALS['__rss']; }
function sn_rss_tracker_token() { return 'beacon-token'; }
function sn_rss_tracker_server_token() { return 'srv-token'; }
function sn_analytics_refresh_secret() { return 'srv-token'; }
function sn_cf_get_zone() { return 'zone-abc123'; }
function sn_theme_ai_models() { return array( 'claude-sonnet-5' => 'Claude Sonnet 5' ); }
function snt_ai_spend_this_month() { return 4.2; }
function snt_insights_weekly_cron_enabled() { return true; }
function snt_analytics_page_url() { return 'https://example.test/wp-admin/admin.php?page=sn-analytics'; }

require SNT_PATH . 'inc/worker-version.php';
require SNT_PATH . 'inc/analytics-salt-window.php';
require SNT_PATH . 'inc/analytics-render-settings.php';
require SNT_PATH . 'inc/analytics-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-analytics.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/analytics'] ), 'the painter is registered under monitoring/analytics' );

// ── Rich fixture: unlocked credentials, configured, two roles, populated
// funnels — the names/actions oracle against the classic renderer.
$GLOBALS['__settings']['analytics.funnels'] = array( array( 'name' => 'Home flow', 'steps' => array( '/entry', '/goal' ) ) );
$classic = snt_leaf_classic_html( '\snt_analytics_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'analytics' );
ok( '' !== $kit, 'the kit leaf paints' );

// A shared `sn_exclude_roles[]` name per checkbox is silent-by-construction
// at runtime (OsForm.getValues() collapses N same-named controls to ONE key,
// and os-checkbox-label has no `value` prop to carry the slug — see the
// blocking finding), so the port gives each role its OWN indexed name instead.
// That is a deliberate, necessary divergence from the classic's literal name,
// so it is folded back to the classic's shared name before the general
// name-for-name comparison, and pinned on its own terms just below.
function normalize_exclude_names( array $names ) {
	$seen_exclude = false;
	$out          = array();
	foreach ( $names as $n ) {
		if ( preg_match( '/^sn_exclude_roles\[\d+\]$/', $n ) ) {
			$seen_exclude = true;
			continue;
		}
		$out[] = $n;
	}
	if ( $seen_exclude ) {
		$out[] = 'sn_exclude_roles[]';
	}
	sort( $out );
	return $out;
}

$classic_names = snt_leaf_names( $classic );
$kit_names     = snt_leaf_names( $kit );
$expected_names = array( '_wpnonce', 'sn_action', 'sn_an_collector_url', 'sn_anomaly_sensitivity', 'sn_cf_account_id', 'sn_cf_analytics_token', 'sn_exclude_roles[0]', 'sn_exclude_roles[1]', 'sn_funnels', 'sn_signal_baseline_days' );
sort( $expected_names );
ok( $expected_names === $kit_names, 'kit field names are exactly the classic writable set (role list now indexed per role): ' . implode( ',', $kit_names ) . ' (classic: ' . implode( ',', $classic_names ) . ')' );
ok( normalize_exclude_names( $classic_names ) === normalize_exclude_names( $kit_names ), 'the kit form fields match the classic forms name-for-name once the role list’s per-role indexed names are folded back to the classic’s one shared name' );

$kit_exclude_names = array_values( array_filter( $kit_names, static function ( $n ) { return 0 === strpos( $n, 'sn_exclude_roles[' ) && 'sn_exclude_roles[]' !== $n; } ) );
sort( $kit_exclude_names );
ok( array( 'sn_exclude_roles[0]', 'sn_exclude_roles[1]' ) === $kit_exclude_names, 'the role list carries one DISTINCT field name per role, not a shared name that silently collapses to one key at runtime' );

$expected_actions = array( 'analytics_collector_save', 'analytics_exclude_save', 'analytics_funnels_save', 'analytics_save', 'analytics_test', 'analytics_tuning_save' );
sort( $expected_actions );
$kit_actions = snt_leaf_actions( $kit );
ok( $expected_actions === $kit_actions, 'all six sn_action values are offered: ' . implode( ',', $kit_actions ) );
ok( snt_leaf_actions( $classic ) === $kit_actions, 'the kit actions match the classic actions set (analytics_test now a standalone action button)' );

ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Specific readouts for the rich fixture.
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'Beacon token set' ), 'the beacon pill reads ok when the token resolves' );
ok( false !== strpos( $kit, 'Zone ID set' ), 'the zone pill reads ok' );
ok( false !== strpos( $kit, '<os-text-field name="sn_cf_account_id"' ), 'the account-ID field is a kit text field' );
ok( false !== strpos( $kit, 'zone-abc123' ), 'the Zone ID mirror shows the live zone' );
ok( false !== strpos( $kit, 'sn-analytics' ) && false !== strpos( $kit, 'v1.14.2' ), 'the edge-worker card shows the live worker + version' );
ok( false !== strpos( $kit, '2 salt keys at the edge' ), 'the salt-window card shows the live key count' );
ok( false !== strpos( $kit, 'Claude Sonnet 5' ) && false !== strpos( $kit, 'no monthly budget cap' ), 'the AI-model mirror shows the model and the no-budget note' );
ok( false !== strpos( $kit, 'Home flow' ), 'the funnels textarea is prefilled from the current setting' );
ok( false !== strpos( $kit, '<os-select name="sn_anomaly_sensitivity"' ), 'anomaly sensitivity is offered (mapped to a kit select, not a radio group)' );

// ── The excluded role's slug is carried as the field's VALUE, not a checked
// boolean — the actual wire shape sn_handle_analytics_exclude_save() reads.
$GLOBALS['__settings']['analytics.exclude_roles'] = array( 'editor' );
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false !== strpos( $kit, '<os-select name="sn_exclude_roles[1]" value="editor"' ), 'the excluded role’s slug is carried as the select’s value, not a checked flag' );
$GLOBALS['__settings']['analytics.exclude_roles'] = array();

// ── Escaping: a hostile role name never reaches the markup raw.
$GLOBALS['__roles'] = array( 'hostile' => '"><script>x</script>' );
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile role name is escaped' );
$GLOBALS['__roles'] = array( 'subscriber' => 'Subscriber', 'editor' => 'Editor' );

// ── Empty-roles state: no checkboxes, no exclude action, an explanatory line.
$GLOBALS['__roles'] = array();
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === preg_grep( '/^sn_exclude_roles\[/', snt_leaf_names( $kit ) ), 'empty roles: no exclude checkboxes are offered' );
ok( ! in_array( 'analytics_exclude_save', snt_leaf_actions( $kit ), true ), 'empty roles: no exclude_save action is offered' );
ok( false !== strpos( $kit, 'No roles available on this site.' ), 'empty roles: the explanatory line prints' );
$GLOBALS['__roles'] = array( 'subscriber' => 'Subscriber', 'editor' => 'Editor' );

// ── Locked credentials: both constants set, no save/test offered.
define( 'SN_CF_ANALYTICS_TOKEN', 'locked-token' );
define( 'SN_CF_ACCOUNT_ID', 'locked-acct' );
$GLOBALS['__configured'] = true;
$classic = snt_leaf_classic_html( '\snt_analytics_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'analytics' );
ok( ! in_array( 'sn_cf_account_id', snt_leaf_names( $kit ), true ) && ! in_array( 'sn_cf_analytics_token', snt_leaf_names( $kit ), true ), 'locked: neither credential field is offered by name' );
ok( ! in_array( 'analytics_save', snt_leaf_actions( $kit ), true ) && ! in_array( 'analytics_test', snt_leaf_actions( $kit ), true ), 'locked: no save/test action is offered, matching the classic gate' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'locked: kit actions still match the classic actions set' );
ok( false !== strpos( $kit, 'Locked by the' ) && false !== strpos( $kit, 'disabled' ), 'locked: the lock is explained and the fields are disabled' );
ok( normalize_exclude_names( snt_leaf_names( $classic ) ) === normalize_exclude_names( snt_leaf_names( $kit ) ), 'locked: kit field names still match the classic set (role list folded back to its shared name): ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );

// ── Worker unreachable: the warning branch, no Test-connection field noise.
// Clear the last-good option first — otherwise the stale-fallback branch
// (which a prior successful probe above just wrote) would mask this state.
unset( $GLOBALS['__options'][ SN_WORKER_VERSION_LASTGOOD ] );
$GLOBALS['__http_code'] = 500;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false !== strpos( $kit, 'Worker version unknown.' ) && false !== strpos( $kit, 'tone="warning"' ), 'worker unreachable: the warning notice paints' );
ok( false !== strpos( $kit, 'v1.4.0' ) && false !== strpos( $kit, 'hairpin' ), 'worker unreachable: the remedy sentence (version floor + hairpin workaround) prints' );
$GLOBALS['__http_code'] = 200;

// ── AI budget > 0: the percent-used sentence AND the kit's own meter — the
// classic pairs the sentence with a <span class="sn-an-mirror-meter"> bar;
// dropping the meter was previously untested (the default fixture pins
// budget = 0, so the whole budget>0 branch never ran).
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 20.0;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false !== strpos( $kit, '$4.20 of $20.00 budget this month (21%)' ) && false !== strpos( $kit, '<os-progress-bar' ), 'AI budget > 0: shows the percent sentence and the meter' );
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 0;

// ── Worker STALE: a live probe fails but a prior last-good result exists —
// the fallback must name how stale the reading is, not just that it failed.
$GLOBALS['__http_code'] = 200;
snt_leaf_paint( 'monitoring', 'analytics' ); // prime SN_WORKER_VERSION_LASTGOOD
$GLOBALS['__http_code'] = 500;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'last value reached 1 hour ago' ), 'worker stale: the fallback names how stale the last-good reading is' );
$GLOBALS['__http_code'] = 200;

// ── Salt window OLD-WORKER: the version-floor line, kept distinct from the
// generic unreachable copy on purpose (the classic never conflates the two).
$GLOBALS['__http_body'] = wp_json_encode(
	array(
		'worker'         => 'sn-analytics',
		'version'        => '1.14.2',
		'cf_version_id'  => 'cfv-1',
		'cf_version_tag' => 'v1.14.2',
		'deployed_at'    => '2026-08-01T00:00:00Z',
	)
);
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false !== strpos( $kit, 'Worker predates the salt window readout (needs v1.14.0+).' ) && false === strpos( $kit, 'could not read the worker.' ), 'salt old-worker: the version-floor line prints, distinct from the unreachable copy' );

// ── Salt window KV-FAILED: worker reachable, KV read failed at the edge —
// also kept distinct from both old-worker and unreachable.
$GLOBALS['__http_body'] = wp_json_encode(
	array(
		'worker'         => 'sn-analytics',
		'version'        => '1.14.2',
		'cf_version_id'  => 'cfv-1',
		'cf_version_tag' => 'v1.14.2',
		'deployed_at'    => '2026-08-01T00:00:00Z',
		'salt'           => null,
	)
);
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( false !== strpos( $kit, 'could not list its salt keys (KV read failed at the edge)' ) && false === strpos( $kit, 'could not read the worker.' ) && false === strpos( $kit, 'predates the salt window' ), 'salt kv-failed: its own copy prints, distinct from old-worker and unreachable' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
