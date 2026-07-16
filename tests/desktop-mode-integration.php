<?php
/**
 * Standalone fixture tests for inc/desktop-mode-integration.php — the
 * WordPress/desktop-mode integration surface (since v1.15.0, FIRST coverage
 * added v9.52.0).
 *
 * The file shipped 647 lines and six widgets' worth of surface with ZERO
 * tests. v9.52.0 adds three analytics widgets + the living-tree filter and
 * retroactively locks the whole surface — including the widget-mount
 * contract that the pre-v9.52.0 widgets got WRONG (see below).
 *
 * THE MOUNT CONTRACT (verified against the desktop-mode source, not folklore):
 * desktop-mode has two widget paths. Ours is the PHP-declared one:
 * `desktop_mode_register_widget( $id, $args )` publishes label/description/
 * icon server-side, then desktop-mode's server-sync dynamically loads our
 * script and looks for the mount callback at `window.desktopModeWidgets[ id ]`
 * (src/widgets/server-sync.ts). A def is only registered once that global
 * exists; a missing callback logs `widget-missing-mount` and the widget never
 * appears. The OTHER path — `wp.desktop.registerWidget( def )` from JS — is
 * for pure client-side widgets and validates the def hard: WIDGET_CHECKS
 * (src/widgets/registry.ts) requires id + label + description + icon + mount
 * (a FUNCTION), and register() THROWS otherwise.
 *
 * The three pre-v9.52.0 widgets called `wp.desktop.registerWidget({id, render})`
 * — no label/description/icon, and `render` where the contract wants `mount` —
 * so they failed validation on one path AND never set the global on the other.
 * They were silently dead. v9.52.0 moves all six to the correct global.
 *
 * Run: php tests/desktop-mode-integration.php
 *
 * @since plugin v9.52.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'SNT_VERSION', '9.52.0-test' );
define( 'MINUTE_IN_SECONDS', 60 ); // WP core constant, absent from a standalone CLI run.

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }

$GLOBALS['__filters'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value );
	}
	return $value;
}

$GLOBALS['__scripts'] = array();
function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['__scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}

$GLOBALS['__localized'] = array();
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['__localized'][ $name ] = $data; }

$GLOBALS['__dm_widgets']  = array();
$GLOBALS['__dm_commands'] = array();
$GLOBALS['__dm_icons']    = array();
function desktop_mode_register_widget( $id, $args = array() ) { $GLOBALS['__dm_widgets'][ $id ] = $args; }
function desktop_mode_register_command( $args = array() ) { $GLOBALS['__dm_commands'][ $args['slug'] ] = $args; }
function desktop_mode_register_icon( $id, $args = array() ) { $GLOBALS['__dm_icons'][ $id ] = $args; }

$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }

function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function esc_url_raw( $u ) { return $u; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function current_user_can( $cap ) { return $GLOBALS['__cap'] ?? true; }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }

$GLOBALS['__transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

function current_time( $type = 'mysql' ) { return '2026-07-16 12:00:00'; }

class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
}

class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
}

// ── Analytics / health / uptime seams the widgets read ────────────────
// Real fns live in inc/analytics-read.php (DURABLE wp_sn_analytics_daily
// table reads — NOT Analytics Engine), inc/health-checks.php, and
// inc/uptime-status.php. Stubbed here so the suite stays standalone.
$GLOBALS['__series'] = array();
$GLOBALS['__totals'] = array();
function sn_analytics_daily_series( $from, $to, $class = 'human', $granularity = 'day', $refresh = false ) { return $GLOBALS['__series']; }
function sn_analytics_range_totals( $from, $to, $class = 'human', $refresh = false ) {
	$key = $from . '|' . $to;
	return $GLOBALS['__totals'][ $key ] ?? ( $GLOBALS['__totals']['*'] ?? array( 'views' => 0, 'visits' => 0, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
}
// health: load the REAL summary helpers rather than stubbing them.
// inc/health-summary.php is pure array logic (zero WP calls), so it loads
// standalone — and a contract you actually CALL cannot drift from the one you
// imagined. (v9.52.0 review caught exactly that: an invented `passed` key on
// each check, a shape sn_health_run_scan() has never produced.)
require_once __DIR__ . '/../inc/health-summary.php';

/**
 * A REAL sn_health_last_scan() payload. Shape per sn_health_run_scan()
 * (inc/health-checks.php): scanned_at is an INT timestamp, and `checks` is a
 * MAP of key => { count, findings, label, fix_hint } — there is no 'passed'
 * key anywhere in the model. "Passed" is DERIVED: a check with count 0.
 * external_links + link_opportunities are advisory-tier and never count as
 * flagged (sn_health_advisory_checks()).
 */
function fixture_scan( array $counts, $scanned_at = 1752660000 ) {
	$checks = array();
	foreach ( $counts as $key => $count ) {
		$checks[ $key ] = array(
			'count'    => $count,
			'findings' => array_fill( 0, $count, array( 'ID' => 1 ) ),
			'label'    => ucfirst( str_replace( '_', ' ', $key ) ),
			'fix_hint' => 'Fix it.',
		);
	}
	return array( 'scanned_at' => $scanned_at, 'elapsed_ms' => 12, 'checks' => $checks );
}

$GLOBALS['__health_scan'] = null;
function sn_health_last_scan() { return $GLOBALS['__health_scan']; }

// uptime: sn_uptime_status_fetch() returns a SNAPSHOT — array{fetched_at:int,
// rows:array} — or a WP_Error when unconfigured / the API fails. NOT a flat row
// list. (The known-good consumer sn_uptime_status_detail() does $snap['rows'].)
$GLOBALS['__uptime_configured'] = false;
$GLOBALS['__uptime_snapshot']   = null;
function sn_uptime_status_configured() { return (bool) $GLOBALS['__uptime_configured']; }
function sn_uptime_status_fetch( $force = false ) {
	if ( ! $GLOBALS['__uptime_configured'] ) {
		return new WP_Error( 'not_configured', 'No Better Stack API token configured.' );
	}
	return $GLOBALS['__uptime_snapshot'];
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function uptime_snapshot( array $rows ) { return array( 'fetched_at' => 1752660000, 'rows' => $rows ); }

// ── Test harness ─────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; }
	else { $fail++; echo "  FAIL— $label\n"; }
}

require_once __DIR__ . '/../inc/desktop-mode-integration.php';

/** Fire every callback registered on a hook. */
function fire( $hook ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { $cb(); }
}

echo "\n── Widget registration gate ──\n";
fire( 'admin_enqueue_scripts' );
$widgets = $GLOBALS['__dm_widgets'];

ok( isset( $widgets['sn-site-views'] ), 'W1: registers the sn-site-views widget' );
ok( isset( $widgets['sn-pulse'] ),      'W2: registers the sn-pulse widget' );
ok( isset( $widgets['sn-health'] ),     'W3: registers the sn-health widget' );
ok( count( $widgets ) === 6, 'all six widgets register (3 pre-existing + 3 new), got ' . count( $widgets ) );

ok( ( $widgets['sn-site-views']['label'] ?? '' ) === 'SN Site Views', 'W1 carries its label' );
ok( ( $widgets['sn-pulse']['label'] ?? '' ) === 'SN Pulse',           'W2 carries its label' );
ok( ( $widgets['sn-health']['label'] ?? '' ) === 'SN Health',         'W3 carries its label' );

// desktop-mode's picker shows description + icon; a missing icon fails
// WIDGET_CHECKS on the client-side path and renders a generic tile here.
foreach ( array( 'sn-site-views', 'sn-pulse', 'sn-health' ) as $id ) {
	ok( ! empty( $widgets[ $id ]['description'] ?? '' ), "$id declares a picker description" );
	ok( ! empty( $widgets[ $id ]['icon'] ?? '' ),        "$id declares a dashicon" );
}

ok( ( $widgets['sn-site-views']['script'] ?? '' ) === 'sn-desktop-mode-widget-views', 'W1 names its script handle' );
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-widget-views'] ), 'W1 script handle is registered' );
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-widget-pulse'] ), 'W2 script handle is registered' );
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-widget-health'] ), 'W3 script handle is registered' );

echo "\n── The gate: no desktop-mode, no registration ──\n";
// Re-running the hook with the registry fn absent must be a no-op. We can't
// un-define a function, so assert the guard is present in the source instead.
$src = file_get_contents( __DIR__ . '/../inc/desktop-mode-integration.php' );
ok( strpos( $src, "function_exists( 'desktop_mode_register_widget' )" ) !== false, 'widget block is function_exists-gated' );

echo "\n── Localized \$shared ──\n";
$shared = $GLOBALS['__localized']['snDesktopData'] ?? array();
ok( ! empty( $shared['pages']['analytics'] ?? '' ), 'pages map gains an analytics deep-link' );
ok( array_key_exists( 'uptimeSummary', $shared ), 'shared carries uptimeSummary' );
ok( array_key_exists( 'healthSummary', $shared ), 'shared carries healthSummary' );
// Cheap-data-only rule: the views SERIES must never be localized (it would
// run two aggregate SQL queries on EVERY admin page load, for a widget that
// may not even be mounted). It goes over REST, fetch-on-render.
ok( ! isset( $shared['siteViews'] ) && ! isset( $shared['views'] ), 'views series is NOT localized (REST fetch-on-render only)' );

echo "\n── The localize gate: operational data is owner-only ──\n";
// admin_enqueue_scripts fires on EVERY admin screen, so a Contributor (or a
// Subscriber on their profile) reaches this hook. Uptime + health are owner
// data and must not ride the page source for them.
$GLOBALS['__uptime_configured'] = true;
$GLOBALS['__uptime_snapshot']   = uptime_snapshot( array( array( 'name' => 'api', 'status' => 'down', 'level' => 'alert' ) ) );
$GLOBALS['__health_scan']       = fixture_scan( array( 'broken_links' => 3 ) );

$GLOBALS['__cap'] = false; // a non-admin admin-screen visitor
$GLOBALS['__localized'] = array();
fire( 'admin_enqueue_scripts' );
$non_admin = $GLOBALS['__localized']['snDesktopData'] ?? array();
ok( array_key_exists( 'uptimeSummary', $non_admin ) && $non_admin['uptimeSummary'] === null,
	'uptimeSummary is withheld (null) from a non-manage_options user' );
ok( array_key_exists( 'healthSummary', $non_admin ) && $non_admin['healthSummary'] === null,
	'healthSummary is withheld (null) from a non-manage_options user' );

$GLOBALS['__cap'] = true; // the owner
$GLOBALS['__localized'] = array();
fire( 'admin_enqueue_scripts' );
$owner = $GLOBALS['__localized']['snDesktopData'] ?? array();
ok( is_array( $owner['uptimeSummary'] ?? null ), 'the owner DOES receive uptimeSummary' );
ok( is_array( $owner['healthSummary'] ?? null ), 'the owner DOES receive healthSummary' );

// Reset to the absent-source baseline for the degradation checks below.
$GLOBALS['__uptime_configured'] = false;
$GLOBALS['__uptime_snapshot']   = null;
$GLOBALS['__health_scan']       = null;
$GLOBALS['__localized'] = array();
fire( 'admin_enqueue_scripts' );
$shared = $GLOBALS['__localized']['snDesktopData'] ?? array();

echo "\n── Graceful degradation of the summaries ──\n";
// array_key_exists FIRST, deliberately: a bare `$shared['uptimeSummary'] === null`
// also passes when the key was never set at all (undefined index → null + a
// warning), i.e. it would go green against an unimplemented feature. Assert the
// key is PRESENT and null — "explicitly nothing", not "absent".
ok( array_key_exists( 'uptimeSummary', $shared ) && $shared['uptimeSummary'] === null,
	'uptimeSummary is present-and-null when uptime is unconfigured' );
ok( array_key_exists( 'healthSummary', $shared ) && $shared['healthSummary'] === null,
	'healthSummary is present-and-null when no scan has run' );

echo "\n── Summary helper shapes ──\n";
ok( function_exists( 'snt_health_summary_for_localize' ), 'snt_health_summary_for_localize() exists' );
ok( function_exists( 'snt_uptime_summary_for_localize' ), 'snt_uptime_summary_for_localize() exists' );

// A scan with one real fault (broken_links=3) among four checks.
$GLOBALS['__health_scan'] = fixture_scan( array(
	'missing_alt'  => 0,
	'broken_links' => 3,
	'stale_posts'  => 0,
	'color_drift'  => 0,
), 1752660000 );
$h = snt_health_summary_for_localize();
ok( is_array( $h ), 'health summary returns an array once a scan exists' );
ok( ( $h['total'] ?? 0 ) === 4,  'health summary counts total checks' );
ok( ( $h['passed'] ?? 0 ) === 3, 'health summary counts passed = checks with zero findings' );
ok( ( $h['all_passed'] ?? true ) === false, 'health summary flags the failing check' );
ok( ( $h['scanned_at'] ?? null ) === 1752660000, 'health summary carries scanned_at as the INT timestamp it really is' );

// THE REGRESSION THAT MATTERS: a 100%-clean site must read as all-passed.
// The pre-review code derived `passed` from a non-existent $check['passed']
// key, so a spotless scan rendered a permanent amber "0/11 passed".
$GLOBALS['__health_scan'] = fixture_scan( array(
	'missing_alt'  => 0,
	'broken_links' => 0,
	'stale_posts'  => 0,
) );
$clean = snt_health_summary_for_localize();
ok( ( $clean['passed'] ?? -1 ) === 3, 'a spotless scan reports every check passed (not 0)' );
ok( ( $clean['all_passed'] ?? false ) === true, 'a spotless scan is all_passed' );

// Advisory-tier checks (external_links, link_opportunities) carry findings by
// nature and must NOT read as failures — mirrors sn_health_flagged_checks().
$GLOBALS['__health_scan'] = fixture_scan( array(
	'missing_alt'       => 0,
	'external_links'    => 42,
	'link_opportunities' => 7,
) );
$adv = snt_health_summary_for_localize();
ok( ( $adv['all_passed'] ?? false ) === true, 'advisory findings alone do not fail the health summary' );

$GLOBALS['__health_scan'] = null;
ok( snt_health_summary_for_localize() === null, 'health summary is null with no scan (never a fake pass)' );

$GLOBALS['__uptime_configured'] = false;
ok( snt_uptime_summary_for_localize() === null, 'uptime summary is null when unconfigured (fetch returns WP_Error)' );

// THE OTHER REGRESSION: the snapshot is {fetched_at, rows} — iterating the
// OUTER array finds no 'level' key, so every monitor defaulted to 'ok' and the
// tile showed green through a real outage.
$GLOBALS['__uptime_configured'] = true;
$GLOBALS['__uptime_snapshot']   = uptime_snapshot( array(
	array( 'name' => 'site', 'status' => 'up',   'level' => 'ok' ),
	array( 'name' => 'api',  'status' => 'down', 'level' => 'alert' ),
) );
$u = snt_uptime_summary_for_localize();
ok( is_array( $u ), 'uptime summary returns an array when configured' );
ok( ( $u['level'] ?? '' ) === 'alert', 'a DOWN monitor surfaces as alert — worst level wins, never a false green' );
ok( ( $u['status'] ?? '' ) === 'down', 'uptime summary carries the failing monitor status' );

$GLOBALS['__uptime_snapshot'] = uptime_snapshot( array(
	array( 'name' => 'site', 'status' => 'up', 'level' => 'ok' ),
	array( 'name' => 'api',  'status' => 'up', 'level' => 'ok' ),
) );
ok( ( snt_uptime_summary_for_localize()['level'] ?? '' ) === 'ok', 'all monitors up reads as ok' );

$GLOBALS['__uptime_snapshot'] = uptime_snapshot( array(
	array( 'name' => 'site', 'status' => 'paused', 'level' => 'warn' ),
) );
ok( ( snt_uptime_summary_for_localize()['level'] ?? '' ) === 'warn', 'a paused monitor reads as warn, not ok' );

$GLOBALS['__uptime_snapshot'] = uptime_snapshot( array() );
ok( snt_uptime_summary_for_localize() === null, 'an empty snapshot yields null, not a fabricated ok' );

echo "\n── site-views REST endpoint ──\n";
fire( 'rest_api_init' );
$route = $GLOBALS['__routes']['signal-noise/v1/desktop/site-views'] ?? null;
ok( is_array( $route ), 'GET signal-noise/v1/desktop/site-views is registered' );
ok( ( $route['methods'] ?? '' ) === 'GET', 'site-views route is GET' );
ok( is_callable( $route['permission_callback'] ?? null ), 'site-views route has a permission callback' );

$GLOBALS['__cap'] = false;
ok( call_user_func( $route['permission_callback'] ) === false, 'permission callback denies without manage_options' );
$GLOBALS['__cap'] = true;
ok( call_user_func( $route['permission_callback'] ) === true, 'permission callback allows manage_options' );

echo "\n── site-views payload shape ──\n";
delete_transient( 'sn_desktop_site_views_' . substr( current_time( 'mysql' ), 0, 10 ) );
$GLOBALS['__series'] = array(
	array( 'day' => '2026-07-15', 'views' => 10, 'visits' => 8 ),
	array( 'day' => '2026-07-16', 'views' => 30, 'visits' => 20 ),
);
$GLOBALS['__totals'] = array( '*' => array( 'views' => 40, 'visits' => 28, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$res  = call_user_func( $route['callback'] );
$body = $res instanceof WP_REST_Response ? $res->get_data() : $res;
ok( is_array( $body['days'] ?? null ), 'payload carries a days array' );
ok( count( $body['days'] ) === 2, 'payload days mirror the series' );
ok( ( $body['days'][0]['date'] ?? '' ) === '2026-07-15', 'each day carries a date key' );
ok( ( $body['days'][0]['views'] ?? null ) === 10, 'each day carries an int views key' );
ok( ( $body['total'] ?? null ) === 40, 'payload total comes from range totals' );
ok( array_key_exists( 'delta_pct', $body ), 'payload carries delta_pct' );

echo "\n── site-views fail-soft (the REAL failure mode: empty rollup, not AE) ──\n";
// inc/analytics-read.php reads the durable wp_sn_analytics_daily table via
// $wpdb — there is no Analytics Engine call on this path and thus no AE
// exception to catch. The real degenerate case is an empty/missing rollup:
// a fresh install, a table not yet created, or a window with no traffic.
delete_transient( 'sn_desktop_site_views_' . substr( current_time( 'mysql' ), 0, 10 ) );
$GLOBALS['__series'] = array();
$GLOBALS['__totals'] = array( '*' => array( 'views' => 0, 'visits' => 0, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$res  = call_user_func( $route['callback'] );
$body = $res instanceof WP_REST_Response ? $res->get_data() : $res;
ok( ( $body['days'] ?? null ) === array(), 'empty rollup yields an empty days array, not a warning' );
ok( ( $body['total'] ?? null ) === 0, 'empty rollup totals 0' );
// NOT `$body['delta_pct'] ?? 'x'` — `??` treats an existing null as absent, so
// that form can never observe the null it means to assert. Key-exists + identity.
ok( array_key_exists( 'delta_pct', $body ) && $body['delta_pct'] === null,
	'delta_pct is present-and-null when there is no prior window to compare' );

echo "\n── delta_pct maths ──\n";
ok( function_exists( 'snt_desktop_delta_pct' ), 'snt_desktop_delta_pct() exists' );
ok( snt_desktop_delta_pct( 150, 100 ) === 50.0,  '+50% when this window is 1.5x prior' );
ok( snt_desktop_delta_pct( 50, 100 ) === -50.0,  '-50% when this window is half prior' );
ok( snt_desktop_delta_pct( 100, 100 ) === 0.0,   '0% when flat' );
ok( snt_desktop_delta_pct( 10, 0 ) === null,     'null (not INF) when prior window is zero — no divide-by-zero' );
ok( snt_desktop_delta_pct( 0, 0 ) === null,      'null when both windows are zero' );

echo "\n── living-tree filter ──\n";
ok( isset( $GLOBALS['__filters']['desktop_mode_living_tree_traffic'] ), 'living-tree filter is registered' );
$GLOBALS['__totals'] = array( '*' => array( 'views' => 1234, 'visits' => 900, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$traffic = apply_filters( 'desktop_mode_living_tree_traffic', 0 );
ok( $traffic === 1234, 'living-tree filter returns the 14-day human view total' );
ok( is_int( $traffic ), 'living-tree filter returns an int (desktop-mode types it int)' );

echo "\n── THE MOUNT CONTRACT (all six widgets) ──\n";
// desktop-mode's server-sync looks for window.desktopModeWidgets[id]; a def
// is only registered once that global exists. The pre-v9.52.0 widgets used
// wp.desktop.registerWidget({id, render}) — wrong path AND wrong shape — so
// they never mounted. Lock all six to the correct contract.
$js_map = array(
	'sn-deploy-status'   => 'desktop-mode-widget.js',
	'sn-quick-actions'   => 'desktop-mode-widget-actions.js',
	'sn-rss-subscribers' => 'desktop-mode-widget-rss.js',
	'sn-site-views'      => 'desktop-mode-widget-views.js',
	'sn-pulse'           => 'desktop-mode-widget-pulse.js',
	'sn-health'          => 'desktop-mode-widget-health.js',
);
/**
 * Strip comments so the contract assertions read CODE, not prose. These files
 * legitimately DISCUSS wp.desktop.registerWidget in their fix notes; a naive
 * substring check would flag the explanation as the offence.
 */
function strip_js_comments( $js ) {
	$js = preg_replace( '#/\*.*?\*/#s', '', $js );          // block comments
	$js = preg_replace( '#^\s*//.*$#m', '', $js );          // whole-line // comments
	return $js;
}

foreach ( $js_map as $id => $file ) {
	$path = __DIR__ . '/../assets/' . $file;
	if ( ! file_exists( $path ) ) { ok( false, "$file exists" ); continue; }
	$code = strip_js_comments( file_get_contents( $path ) );
	ok( strpos( $code, "desktopModeWidgets['" . $id . "']" ) !== false,
		"$file assigns window.desktopModeWidgets['$id'] (the real mount contract)" );
	ok( strpos( $code, 'wp.desktop.registerWidget' ) === false,
		"$file does not CALL the client-side registerWidget path (it needs label/description/icon/mount and throws without them)" );
	ok( preg_match( '/\brender:\s/', $code ) !== 1,
		"$file does not pass a bare `render:` key (the contract wants mount)" );
	// Assert the real 2-arg signature, not merely "some function is assigned":
	// mount( container, ctx ) is the contract desktop-mode calls.
	ok( preg_match( '/(?:function\s+mount|desktopModeWidgets\[[^\]]+\]\s*=\s*function)\s*\(\s*container\s*,\s*ctx\s*\)/', $code ) === 1,
		"$file's mount callback takes (container, ctx)" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
