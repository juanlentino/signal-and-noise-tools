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
// 15.2.0: the override source the fold reads (inc/cloudflare-credentials.php is the HTTP-side module, not loaded here).
function sn_cf_analytics_override_source() { return ( defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN ) ? 'constant' : ( '' !== (string) get_option( 'sn_cf_analytics_token', '' ) ? 'option' : '' ); }
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
// 15.3.0: the Edge, 7 days section paints at the top of this leaf from the
// Cloudflare monitor's stored record, through the Connections leaf's parts.
if ( ! defined( 'SNT_CW_LAST_PURGE_OPT' ) ) { define( 'SNT_CW_LAST_PURGE_OPT', 'sn_cloudways_last_purge' ); }
if ( ! function_exists( 'size_format' ) ) { function size_format( $b ) { return $b . ' B'; } }
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/cloudflare-monitor.php'; // not the purge module: this suite stubs sn_cf_get_zone() itself
require SNT_PATH . 'inc/cloudflare-firewall-events.php';
require SNT_PATH . 'inc/cloudflare-readings-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cloudflare.php';
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
ok( false !== strpos( $kit, snt_leaf_row( 2, 290, 24 ) ), 'the kit leaf arranges content into a two-up kit grid (#1622)' );
ok( 2 === substr_count( $kit, '<os-stack gap="18">' ), 'the kit leaf has two os-stack columns' );

// 17.4.4 (#1602): __configured is false here, so sn_analytics_pipeline_complete()
// reads false (the read-credentials pill is not ok, the same seam
// tests/analytics-settings-folds.php:76 drives) and the Worker setup
// paints. Its four steps are the kit's <os-steps>; the wrangler block sits
// inside the second step.
// 2026-10-11 (no folds): an open section under a short heading, not a fold.
$setup = strpos( $kit, '<os-section heading="Cloudflare Worker setup" description="Manual and one-time: shown until every pipeline stage reads ok." stack>' );
ok( false !== $setup, 'pipeline incomplete: the Cloudflare Worker setup section paints, open, under a short heading' );
$setup_html = false !== $setup ? substr( $kit, $setup, strpos( $kit, '</os-section>', $setup ) - $setup ) : '';
ok( 1 === substr_count( $setup_html, '<os-steps>' ) && 4 === substr_count( $setup_html, '<os-step>' ), 'the Worker setup is one <os-steps> of four <os-step> -- ' . substr_count( $setup_html, '<os-steps>' ) . '/' . substr_count( $setup_html, '<os-step>' ) );
$first  = strpos( $setup_html, '<os-step>' );
$second = false !== $first ? strpos( $setup_html, '<os-step>', $first + 1 ) : false;
$second_end = false !== $second ? strpos( $setup_html, '</os-step>', $second ) : false;
ok( false !== $second && false !== $second_end && false !== strpos( substr( $setup_html, $second, $second_end - $second ), 'wrangler deploy' ) && false !== strpos( substr( $setup_html, $second, $second_end - $second ), '<os-code block wrap>' ), 'the wrangler os-code block sits inside the second step' );
ok( false === strpos( $kit, '<ol' ), 'no browser ordered list survives on Analytics' );

// ── 2026-10-11, owner rule: no folds. Each settings form is an open section
// whose description is the one-line current state the fold's hint carried.
ok( array() === snt_leaf_folds( $kit ), 'rich: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, '<os-section heading="Credentials" description="Not configured" stack>' ), 'the Credentials state (was the fold hint) is the open section\'s description' );
ok( false !== strpos( $kit, '<os-section heading="Collector endpoint" description="This site (default)" stack>' ), 'the Collector endpoint state is the open section\'s description' );
ok( false !== strpos( $kit, '<os-section heading="Exclude my own visits" description="Off" stack>' ), 'the exclusion state is the open section\'s description' );
ok( false !== strpos( $kit, '<os-section heading="Engine tuning" description="30-day baseline · Standard: designed default (≈3.5σ)" stack>' ), 'the tuning state is the open section\'s description' );
ok( false !== strpos( $kit, '<os-section heading="Session funnels" description="1 funnel" stack>' ), 'the funnels state is the open section\'s description' );
// Open forms made the left column three times the right: the two definitions
// (North star, Session funnels) moved to the right column, under the readings.
$an_right = strpos( $kit, '<os-stack gap="18">', (int) strpos( $kit, '<os-stack gap="18">' ) + 1 );
ok( false !== $an_right && strpos( $kit, 'heading="Engine tuning"' ) < $an_right && strpos( $kit, 'heading="Identity salt window"' ) > $an_right && strpos( $kit, 'heading="Session funnels"' ) > strpos( $kit, 'heading="Identity salt window"' ) && strpos( $kit, 'heading="Session funnels"' ) < strpos( $kit, 'heading="Configured elsewhere"' ), 'the left column ends with Engine tuning; Session funnels sits in the right column between the readings and the mirrors' );
// columns="auto": os-form goes two-up once the FORM is 480px wide and stays one
// column below that (a forced "2" would squeeze two selects into a 290px column).
ok( 2 === substr_count( $kit, 'columns="auto"' ) && 3 === substr_count( $kit, '<p class="snt-hint" full-width>' ), 'the two short-field forms (exclusion, tuning) go two-up when wide enough; their notes span the row' );


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
$expected_names = array( 'sn_an_collector_url', 'sn_anomaly_sensitivity', 'sn_exclude_roles[0]', 'sn_exclude_roles[1]', 'sn_funnels', 'sn_signal_baseline_days' ); // 15.2.0: the two credential fields moved to the keyring
sort( $expected_names );
ok( $expected_names === $kit_names, 'kit field names are exactly the classic writable set (role list now indexed per role): ' . implode( ',', $kit_names ) . ' (classic: ' . implode( ',', $classic_names ) . ')' );
ok( normalize_exclude_names( $classic_names ) === normalize_exclude_names( $kit_names ), 'the kit form fields match the classic forms name-for-name once the role list’s per-role indexed names are folded back to the classic’s one shared name' );

$kit_exclude_names = array_values( array_filter( $kit_names, static function ( $n ) { return 0 === strpos( $n, 'sn_exclude_roles[' ) && 'sn_exclude_roles[]' !== $n; } ) );
sort( $kit_exclude_names );
ok( array( 'sn_exclude_roles[0]', 'sn_exclude_roles[1]' ) === $kit_exclude_names, 'the role list carries one DISTINCT field name per role, not a shared name that silently collapses to one key at runtime' );

$expected_actions = array( 'analytics_collector_save', 'analytics_exclude_save', 'analytics_funnels_save', 'analytics_test', 'analytics_tuning_save' ); // 15.2.0: analytics_save is gone with its fields
sort( $expected_actions );
$kit_actions = snt_leaf_actions( $kit );
ok( $expected_actions === $kit_actions, 'all five sn_action values are offered: ' . implode( ',', $kit_actions ) );
ok( snt_leaf_actions( $classic ) === $kit_actions, 'the kit actions match the classic actions set (analytics_test now a standalone action button)' );

ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Specific readouts for the rich fixture.
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, 'Beacon token set' ), 'the beacon pill reads ok when the token resolves' );
ok( false !== strpos( $kit, 'Zone ID set' ), 'the zone pill reads ok' );
ok( false === strpos( $kit, 'name="sn_cf_account_id"' ) && false !== strpos( $kit, 'label="Account ID"' ) && false !== strpos( $kit, 'Connections › Credentials' ) && false !== strpos( $kit, 'label="Reads with"' ), '15.2.0: no account-ID field; the fold reads the source and points to the keyring' );
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
ok( array() === snt_leaf_folds( $kit ), 'excluded role: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, '<os-select name="sn_exclude_roles[1]" value="editor"' ), 'the excluded role’s slug is carried as the select’s value, not a checked flag' );
$GLOBALS['__settings']['analytics.exclude_roles'] = array();

// ── Escaping: a hostile role name never reaches the markup raw.
$GLOBALS['__roles'] = array( 'hostile' => '"><script>x</script>' );
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'hostile role: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile role name is escaped' );
$GLOBALS['__roles'] = array( 'subscriber' => 'Subscriber', 'editor' => 'Editor' );

// ── Empty-roles state: no checkboxes, no exclude action, an explanatory line.
$GLOBALS['__roles'] = array();
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'empty roles: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
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
ok( array() === snt_leaf_folds( $kit ), 'locked: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( ! in_array( 'sn_cf_account_id', snt_leaf_names( $kit ), true ) && ! in_array( 'sn_cf_analytics_token', snt_leaf_names( $kit ), true ), 'locked: neither credential field is offered by name' );
ok( ! in_array( 'analytics_save', snt_leaf_actions( $kit ), true ) && in_array( 'analytics_test', snt_leaf_actions( $kit ), true ), 'locked: no save action anywhere; the connection test stays (15.2.0: the constants configure, the test still proves)' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'locked: kit actions still match the classic actions set' );
ok( false !== strpos( $kit, 'locked by SN_CF_ACCOUNT_ID' ) && false !== strpos( $kit, 'the analytics token override' ), 'locked: the account source names its constant and the override reads as in force' );
ok( normalize_exclude_names( snt_leaf_names( $classic ) ) === normalize_exclude_names( snt_leaf_names( $kit ) ), 'locked: kit field names still match the classic set (role list folded back to its shared name): ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );

// ── Worker unreachable: the warning branch, no Test-connection field noise.
// Clear the last-good option first — otherwise the stale-fallback branch
// (which a prior successful probe above just wrote) would mask this state.
unset( $GLOBALS['__options'][ SN_WORKER_VERSION_LASTGOOD ] );
$GLOBALS['__http_code'] = 500;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'worker unreachable: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, 'Worker version unknown.' ) && false !== strpos( $kit, 'tone="warning"' ), 'worker unreachable: the warning notice paints' );
ok( false !== strpos( $kit, 'v1.4.0' ) && false !== strpos( $kit, 'hairpin' ), 'worker unreachable: the remedy sentence (version floor + hairpin workaround) prints' );
$GLOBALS['__http_code'] = 200;

// ── AI budget > 0: the percent-used sentence AND the kit's own meter — the
// classic pairs the sentence with a <span class="sn-an-mirror-meter"> bar;
// dropping the meter was previously untested (the default fixture pins
// budget = 0, so the whole budget>0 branch never ran).
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 20.0;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'AI budget: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, '$4.20 of $20.00 budget this month (21%)' ) && false !== strpos( $kit, '<os-progress-bar' ), 'AI budget > 0: shows the percent sentence and the meter' );
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 0;

// ── Worker STALE: a live probe fails but a prior last-good result exists —
// the fallback must name how stale the reading is, not just that it failed.
$GLOBALS['__http_code'] = 200;
snt_leaf_paint( 'monitoring', 'analytics' ); // prime SN_WORKER_VERSION_LASTGOOD
$GLOBALS['__http_code'] = 500;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'worker stale: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, 'last value reached <os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>.' ), 'worker stale: the fallback names how stale the last-good reading is' );
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
ok( array() === snt_leaf_folds( $kit ), 'salt old-worker: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
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
ok( array() === snt_leaf_folds( $kit ), 'salt kv-failed: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, 'could not list its salt keys (KV read failed at the edge)' ) && false === strpos( $kit, 'could not read the worker.' ) && false === strpos( $kit, 'predates the salt window' ), 'salt kv-failed: its own copy prints, distinct from old-worker and unreachable' );

// ── 15.3.0: Edge, 7 days at the top, from the Cloudflare monitor's stored record; absent before it ran.
ok( false === strpos( $kit, 'heading="Edge, 7 days"' ) && false === strpos( $classic, 'Edge, 7 days' ), 'no Edge section before the monitor ran, on either leaf' );
$GLOBALS['__options'][ SN_CF_MONITOR_OPT ] = array( 'fetched_at' => time(), 'configured' => true, 'token' => array( 'verified' => true, 'status' => 'active', 'expires_on' => '', 'error' => '', 'kind' => 'user' ), 'zone' => array( 'available' => true, 'needs_permission' => false, 'error' => '', 'days' => array(), 'totals' => array( 'requests' => 166684, 'cached' => 13500, 'bytes' => 2000000000, 'cached_bytes' => 1, 'threats' => 12804, 'status_4xx' => 0, 'status_5xx' => 4473, 'cache_share' => 8.1, 'status_5xx_codes' => array( 503 => 4314, 520 => 132 ) ) ), 'firewall' => array( 'available' => false, 'needs_permission' => true, 'error' => 'x', 'events' => 0, 'by_action' => array(), 'top_rules' => array() ) );
$classic = snt_leaf_classic_html( '\snt_analytics_render_settings_section' );
$kit     = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'edge record: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, 'heading="Edge, 7 days"' ) && strpos( $kit, 'heading="Edge, 7 days"' ) > strpos( $kit, snt_leaf_row( 2, 290, 24 ) ) && strpos( $kit, 'heading="Edge, 7 days"' ) < strpos( $kit, 'heading="Credentials"' ) && false !== strpos( $kit, '166,684' ) && false !== strpos( $kit, '8.1%' ) && false !== strpos( $kit, '503 · answered by the origin' ) && false !== strpos( $kit, '4,314' ), '15.3.1: Edge, 7 days heads the left column, above the Credentials fold: the five figures and the 5xx split' );
ok( false !== strpos( $classic, 'Edge, 7 days' ) && false !== strpos( $classic, '166,684' ) && false !== strpos( $classic, '<th scope="row">503</th><td>4,314</td>' ) && strpos( $classic, 'Edge, 7 days' ) > strpos( $classic, '<div class="sn-2up">' ) && strpos( $classic, 'Edge, 7 days' ) < strpos( $classic, 'Credentials' ), 'the classic hub paints the same card at the head of its left column' );
ok( in_array( 'cf_monitor_refresh', snt_leaf_actions( $kit ), true ) && in_array( 'cf_monitor_refresh', snt_leaf_actions( $classic ), true ) && false === strpos( $kit, 'heading="Firewall, 24 hours"' ), 'the Edge section carries the monitor\'s Refresh on both leaves; no Firewall here' );
$an_edge_record = $GLOBALS['__options'][ SN_CF_MONITOR_OPT ];
unset( $GLOBALS['__options'][ SN_CF_MONITOR_OPT ] );

// ── The North star form (inc/north-star-settings.php) paints on this leaf only
// (the classic hub has no twin). Loaded here, after every classic oracle pin,
// because it adds north_star_save and its fields to the leaf.
require_once SNT_PATH . 'inc/north-star.php';
$GLOBALS['__settings']['north_star'] = array( 'sections' => array( 'notes', 'provenance' ), 'scroll' => 60, 'dwell_s' => 45 );
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ), 'north star: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, '<os-section heading="North star" description="Counts Notes, Provenance; a read is 60% scroll or 45 s on the page." stack>' ), 'north star: an open section whose description is the current definition (was the "60% or 45 s" fold hint)' );
ok( strpos( $kit, 'heading="North star"' ) > strpos( $kit, 'heading="Identity salt window"' ) && strpos( $kit, 'heading="North star"' ) < strpos( $kit, 'heading="Session funnels"' ), 'north star: in the right column, above Session funnels, the two definitions together' );
ok( in_array( 'north_star_save', snt_leaf_actions( $kit ), true ) && array() === array_diff( array( 'sn_nsm_sections[0]', 'sn_nsm_sections[3]', 'sn_nsm_scroll', 'sn_nsm_dwell_s' ), snt_leaf_names( $kit ) ) && 3 === substr_count( $kit, 'columns="auto"' ), 'north star: the form keeps its action and fields, two-up when wide enough' );

// ── Live-shaped previews. Pipeline complete: credentials set, the worker
// reachable with its bindings, exclusion on with 2 roles, tuned, 3 funnels,
// the north star set, the edge record present. Then the same site with no
// read credentials: the pipeline is incomplete and the setup steps show.
$GLOBALS['__http_code'] = 200;
$GLOBALS['__http_body'] = wp_json_encode( array( 'worker' => 'sn-analytics', 'version' => '1.14.2', 'cf_version_id' => 'cfv-1', 'cf_version_tag' => 'v1.14.2', 'deployed_at' => '2026-10-01T00:00:00Z', 'config' => array( 'px_token_set' => true, 'ae_bound' => true ), 'salt' => array( 'rotate_tz' => 'America/New_York', 'today_day' => '2026-10-11', 'today_present' => true, 'prev_day' => '2026-10-10', 'prev_present' => true, 'prev_expires_at' => time() + 3600, 'key_count' => 2 ) ) );
$GLOBALS['__options'][ SN_CF_MONITOR_OPT ] = $an_edge_record;
$GLOBALS['__configured'] = true;
$GLOBALS['__roles'] = array( 'administrator' => 'Administrator', 'editor' => 'Editor', 'author' => 'Author', 'subscriber' => 'Subscriber' );
$GLOBALS['__excluded_now'] = true;
$GLOBALS['__settings']['analytics.exclude_roles']        = array( 'administrator', 'editor' );
$GLOBALS['__settings']['analytics.signal_baseline_days'] = 45;
$GLOBALS['__settings']['analytics.anomaly_sensitivity']  = 'relaxed';
$GLOBALS['__settings']['analytics.funnels']              = array( array( 'name' => 'Note to resume', 'steps' => array( '/notes/', '/resume' ) ), array( 'name' => 'Provenance read', 'steps' => array( '/provenance', '/provhub' ) ), array( 'name' => 'About to contact', 'steps' => array( '/about', '/contact' ) ) );
$GLOBALS['__settings']['theme.ai_monthly_budget']        = 20.0;
$GLOBALS['__settings']['north_star']                     = array( 'sections' => array( 'notes', 'provenance', 'resume', 'about' ), 'scroll' => 50, 'dwell_s' => 30 );
ok( true === sn_analytics_pipeline_complete(), 'live-shaped: the fixture really is a complete pipeline (every pill ok)' );
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ) && false === strpos( $kit, 'heading="Cloudflare Worker setup"' ), 'live-shaped: no fold, and no setup steps on a complete pipeline' );
ok( false !== strpos( $kit, 'description="2 roles excluded"' ) && false !== strpos( $kit, 'description="45-day baseline · Relaxed: fewer flags (≈2.5σ)"' ) && false !== strpos( $kit, 'description="3 funnels"' ) && false !== strpos( $kit, 'description="Counts Notes, Provenance, Resume, About; a read is 50% scroll or 30 s on the page."' ), 'live-shaped: each section names its current state in one line' );
snt_leaf_preview( 'monitoring-analytics', $kit );
$GLOBALS['__configured'] = false;
$kit = snt_leaf_paint( 'monitoring', 'analytics' );
ok( array() === snt_leaf_folds( $kit ) && 1 === substr_count( $kit, 'heading="Cloudflare Worker setup"' ) && 4 === substr_count( $kit, '<os-step>' ), 'live-shaped setup: no fold; the four setup steps show, open, while the pipeline is incomplete' );
snt_leaf_preview( 'monitoring-analytics-setup', $kit );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
