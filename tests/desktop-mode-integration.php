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

// The stub HONOURS priority. It used to drop $p on the floor and replay
// callbacks in registration order, which made any ordering assertion vacuous —
// the harness could not express "runs last", so a test claiming it would have
// passed against code that ran first. Real WP sorts by priority ascending and
// keeps registration order within a priority; so does this.
$GLOBALS['__filters'] = array();
// __filter_args records the accepted_args a hook was LAST registered with —
// every hook in this file is registered exactly once, so "last" === "only".
// Lets tests assert accepted_args without threading a 3rd stub param through
// the by-priority apply_filters() loop below, which only ever needs $value.
$GLOBALS['__filter_args'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][ $p ][] = $cb; $GLOBALS['__filter_args'][ $hook ] = $a; }
function apply_filters( $hook, $value ) {
	$by_priority = $GLOBALS['__filters'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = $cb( $value );
		}
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
	public $data; public $status; public $set_data_calls = 0;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	// v10.43.1: real WP_REST_Response::set_data() exists — rest_prepare_plugin
	// (inc/desktop-mode-integration.php) calls it only when it actually changed
	// something. $set_data_calls lets a test prove the dirty-flag skip a
	// no-op write, not just that the final data looks right by coincidence.
	public function set_data( $data ) { $this->data = $data; $this->set_data_calls++; }
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
// Shapes copied from the REAL @return docblocks (inc/analytics-read.php), not
// imagined — the v9.52.0 review caught two HIGH bugs born of invented stubs.
//   class_series → array{day:string, total:int, bot:int, bot_pct:int}
//   top_paths    → array{path:string, views:int, visits:int, scroll_avg:float, time_avg:float}
//   top_sources  → array{value:string, views:int, visits:int, hosts:array}, views DESC
//                  (inc/analytics-sources.php). `value`, NOT `source` — v9.56.0
//                  invented `source`, the stub invented it too, and every row
//                  rendered `undefined`. The stub is where that lie starts.
$GLOBALS['__classes'] = array();
$GLOBALS['__top']     = array();
$GLOBALS['__sources'] = array();
function sn_analytics_class_series( $from, $to, $granularity = 'day' ) { return $GLOBALS['__classes']; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return array_slice( $GLOBALS['__top'], 0, $limit ); }
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) { return array_slice( $GLOBALS['__sources'], 0, $limit ); }
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

// Same rule for the forecast engine: inc/analytics-signals.php is pure array
// maths (its only WP touchpoints are apply_filters + wp_parse_url, both stubbed
// above), so load the REAL sn_analytics_forecast_of() rather than inventing a
// forecast shape. This is what caught the fit-window bug: MIN_POINTS is 21, so
// fitting on the 14-day display window returns null EVERY time — a stub would
// have happily returned a Signal and the widget would have shipped a forecast
// that never once rendered.
require_once __DIR__ . '/../inc/analytics-signals.php';

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

// v9.55.0: REQUIRE these, don't stub them. Both are pure array logic with no
// requires and no top-level side effects, and they own the only truth about
// which admin page slugs actually exist. A stubbed tab list is precisely how
// the dead-link bug below survived — see [[test-stub-drift-invents-shapes]].
require_once __DIR__ . '/../inc/admin-tabs-data.php';
require_once __DIR__ . '/../inc/admin-legacy-redirect.php';

// v10.43.0: the OpenStation rename compat seam desktop-mode-integration.php
// now calls into (snt_os_active(), snt_os_compat_add_filter(), etc.) — see
// inc/openstation-compat.php.
require_once __DIR__ . '/../inc/openstation-compat.php';
require_once __DIR__ . '/../inc/desktop-mode-integration.php';

/**
 * The integration's PHP source, as one blob.
 *
 * v10.87.2: desktop-mode-integration.php was split into a loader plus seven
 * modules, so a source-text assertion can no longer name one file and expect
 * to find the code. Every such assertion below means "this exists in the DM
 * integration", never "this exists in that specific file" — so the blob is the
 * right unit, and it keeps the pins working across any future re-split.
 *
 * The list is EXPLICIT rather than a glob over inc/desktop-mode-*.php: the
 * attention and dropzone modules are separate features with their own suites,
 * and folding them in here would let their source satisfy a pin meant for this
 * one — a false pass, which is the failure mode these greps exist to catch.
 */
function dm_integration_src() {
	static $blob = null;
	if ( null !== $blob ) {
		return $blob;
	}
	$blob = '';
	foreach ( array( 'integration', 'payloads', 'assets', 'commands', 'widgets', 'dock', 'plugins-window', 'ai' ) as $mod ) {
		$blob .= (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-' . $mod . '.php' );
	}
	return $blob;
}

/** Fire every callback registered on a hook. */
function fire( $hook ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { $cb(); }
}

echo "\n── REGISTRATION TIMING (the v9.52.1 root cause) ──\n";
// desktop-mode builds its serverWidgets / serverCommands / desktopIcons
// payload inside desktop_mode_enqueue_assets(), hooked on
// admin_enqueue_scripts at DEFAULT priority 10 (includes/render/assets.php).
// It reads the registries EAGERLY at that moment
// ($payload[$k] = $builder(); includes/core/payload.php).
//
// WordPress runs equal-priority callbacks in INSERTION order, and
// active_plugins is sorted alphabetically — 'desktop-mode' < 'signal-and-
// noise-tools' — so desktop-mode's priority-10 callback is ALWAYS added, and
// therefore runs, BEFORE any priority-10 callback of ours. Registering from
// our own admin_enqueue_scripts:10 closure is unwinnable: the payload is
// already built. Everything must be registered by the end of `init`, which is
// exactly what desktop-mode's own docs/examples/register-widget.md prescribes
// (scripts on init:5, widget on init:6) — and what this file already did
// correctly for desktop ICONS, while widgets + commands were left too late.
// The chromeless/live-refresh path rebuilds the same payload outside
// admin_enqueue_scripts entirely, and server-sync UNREGISTERS ids missing from
// a refresh — so a late registry can also actively remove live widgets.
fire( 'init' );
$widgets = $GLOBALS['__dm_widgets'];
ok( count( $widgets ) === 8, 'all eight widgets are registered by the end of init (NOT admin_enqueue_scripts), got ' . count( $widgets ) );
ok( count( $GLOBALS['__dm_commands'] ) === 22, 'all 22 Cmd+K commands are registered by the end of init, got ' . count( $GLOBALS['__dm_commands'] ) );
ok( count( $GLOBALS['__dm_icons'] ) === 2, 'both desktop icons are registered on init (this part was always correct)' );
foreach ( array( 'sn-desktop-mode', 'sn-desktop-mode-widget', 'sn-desktop-mode-widget-views', 'sn-desktop-mode-widget-uptime', 'sn-desktop-mode-widget-health' ) as $h ) {
	ok( isset( $GLOBALS['__scripts'][ $h ] ), "script handle $h is registered by the end of init (desktop-mode enqueues widget scripts at admin_enqueue_scripts:20)" );
}

echo "\n── v10.43.0: the OpenStation compat prelude is registered first, everywhere ──\n";
// window.desktopModeWidgets ↔ window.openStationWidgets and window.wp.desktop
// ↔ window.wp.os must be aliased BEFORE any widget script or desktop-mode.js
// runs, on either OpenStation line — so every sn-desktop-mode* handle must
// depend on it directly (not just transitively).
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-os-compat'] ), 'sn-desktop-mode-os-compat is registered' );
ok( array() === ( $GLOBALS['__scripts']['sn-desktop-mode-os-compat']['deps'] ?? null ),
	'the compat prelude itself has zero dependencies — nothing can beat it to the punch' );
foreach ( array(
	'sn-desktop-mode', 'sn-desktop-mode-widget', 'sn-desktop-mode-widget-actions',
	'sn-desktop-mode-widget-rss', 'sn-desktop-mode-widget-machine-readers',
	'sn-desktop-mode-widget-anchors', 'sn-desktop-mode-widget-views',
	'sn-desktop-mode-widget-health', 'sn-desktop-mode-widget-uptime',
) as $h ) {
	$deps = $GLOBALS['__scripts'][ $h ]['deps'] ?? array();
	ok( in_array( 'sn-desktop-mode-os-compat', $deps, true ), "$h depends directly on sn-desktop-mode-os-compat" );
}

// desktop_mode_register_widget() has NO 'sort' arg — absent from its $defaults
// and from the stored $entry in both v0.8.9 and v0.9.5. Order is registration
// order (`seed.push( def )`). Passing 'sort' looked like it controlled layout
// and controlled nothing; express order by registering in it instead.
foreach ( $widgets as $id => $args ) {
	ok( ! array_key_exists( 'sort', $args ), "$id passes no dead 'sort' key (desktop-mode ignores it)" );
}
// v9.53.0: SN Pulse is RETIRED. It duplicated Site Views (views + delta) and
// Health (the ratio) on a site where both already have dedicated cards, so the
// same numbers rendered twice. One widget per domain now, each enriched; the
// one row Pulse alone carried — uptime — becomes its own SN Uptime widget.
// Registration order IS picker order: traffic, then site condition, then ops.
// v9.78.0 appends SN Anchors (provenance) at the end of the ops group.
ok( array_keys( $widgets ) === array( 'sn-site-views', 'sn-health', 'sn-uptime', 'sn-deploy-status', 'sn-quick-actions', 'sn-rss-subscribers', 'sn-anchors', 'sn-machine-readers' ),
	'widgets register one-per-domain in display order (Site Views first, no Pulse)' );
ok( ! isset( $widgets['sn-pulse'] ), 'SN Pulse is retired — it duplicated Site Views + Health' );

echo "\n── v9.52.3: no dead commands (every palette entry must DO something) ──\n";
// The class of bug this pins: a command registered in PHP with no matching
// JS run() renders a real, clickable palette entry that does nothing. Twelve
// of those shipped for years — invisible only because the registration hook
// was ALSO wrong (v9.52.1), so no command reached the palette at all. The
// moment the hook was fixed, 29 entries appeared and 12 were inert.
//
// They were launchers for theme abilities, held display-only pending
// desktop_mode_register_ai_tool() — an API desktop-mode REMOVED in 0.9.4 and
// replaced with Abilities. The replacement is already live and strictly
// better: every read-only ability (meta.annotations.readonly) is offered to
// the AI Copilot automatically, with structured arguments, which is exactly
// what those launchers could never do. The abilities are untouched; only the
// inert UI is gone.
$dm_js   = strip_js_comments( file_get_contents( __DIR__ . '/../assets/desktop-mode.js' ) );
preg_match_all( "/registerCommand\(\s*\{\s*slug:\s*'([a-z0-9-]+)'/", $dm_js, $m );
$wired      = array_values( array_unique( $m[1] ) );
$registered = array_keys( $GLOBALS['__dm_commands'] );
$dead       = array_values( array_diff( $registered, $wired ) );
ok( empty( $dead ),
	'every registered command has a JS run() — no dead palette entries' . ( $dead ? ' [DEAD: ' . implode( ', ', $dead ) . ']' : '' ) );
ok( count( $registered ) === 22,
	'22 commands registered (17 + the v9.78.0 one-shot mirror five), got ' . count( $registered ) );
// The converse: a JS run() with no PHP registration is equally dead (it can
// never be invoked, because nothing puts it in the palette).
$orphan = array_values( array_diff( $wired, $registered ) );
ok( empty( $orphan ),
	'no JS command handler is orphaned without a PHP registration' . ( $orphan ? ' [ORPHAN: ' . implode( ', ', $orphan ) . ']' : '' ) );

echo "\n── v9.52.2: drag-and-drop + sizing ──\n";
// movable:true lets the user drag a card out of the right-side column and
// place it anywhere; desktop-mode then renders a chrome header (grip + label +
// remove) and drag initiates only from that chrome. resizable:true adds the 8
// grip handles. Both default FALSE, so without these the cards are locked to
// the column. Sizes matter once a card floats: the column drives geometry
// while docked, but a floating card falls back to defaults, and min_* stops a
// drag collapsing a card into an unreadable sliver.
foreach ( $widgets as $id => $args ) {
	ok( ( $args['movable'] ?? false ) === true,   "$id is movable (drag out of the column)" );
	ok( ( $args['resizable'] ?? false ) === true, "$id is resizable" );
	ok( ( $args['min_width'] ?? 0 ) > 0 && ( $args['min_height'] ?? 0 ) > 0,
		"$id declares a min size (a drag can't collapse it to a sliver)" );
	ok( ( $args['default_width'] ?? 0 ) > 0 && ( $args['default_height'] ?? 0 ) > 0,
		"$id declares a default floating size" );
	ok( ( $args['default_width'] ?? 0 ) >= ( $args['min_width'] ?? 0 ),
		"$id default width is not below its own minimum" );
	ok( ( $args['default_height'] ?? 0 ) >= ( $args['min_height'] ?? 0 ),
		"$id default height is not below its own minimum" );
}

echo "\n── v10.68.0: the sizes are MEASURED, and pinned value-level ──\n";
// The four assertions above pass on ANY positive number — they passed on
// every one of the pre-v10.68.0 values, which were guessed before a card had
// ever floated and left Site Views and Machine Readers clipping to roughly
// half their content while Health and Anchors floated with dead space.
//
// So the measured contract is pinned by VALUE. Each default_height below is
// the card's natural height, measured against OpenStation 1.0.0's own
// assets/css/desktop.css with the DOM src/widgets/frame.ts builds, at the
// docked column width, driving each widget's real mount callback with live
// site payloads — then rounded up to the next 10 with ~10px of slack.
//
// Changing a card's content SHOULD fail this test. Re-measure, don't re-guess.
$expected_height = array(
	'sn-site-views'       => 450, // budgeted: measured-463 −3 forecast lines +2 pages rows
	'sn-health'           => 160, // measured 148 all-passing
	'sn-uptime'           => 220, // measured 210
	'sn-deploy-status'    => 310, // v11.11.2 budgeted: measured-192 two-row grid + five worker rows ~22px each
	'sn-quick-actions'    => 250, // measured 242
	'sn-rss-subscribers'  => 220, // measured 207
	'sn-anchors'          => 180, // measured 167 idle
	'sn-machine-readers'  => 560, // budgeted: measured-508 −3 sensor rows +≤5 purpose rows
);
ok( array_keys( $expected_height ) === array_keys( $widgets ),
	'the measured-height table covers exactly the registered widgets, in registration order' );
foreach ( $expected_height as $id => $height ) {
	ok( ( $widgets[ $id ]['default_height'] ?? 0 ) === $height,
		"$id default_height is the measured $height (got " . ( $widgets[ $id ]['default_height'] ?? 'none' ) . ')' );
}

// default_width is the DOCKED width, identically for every card: the column
// is 320 (.os-widgets) minus 2×4 of .os-widgets__list padding. Anything else
// makes a card reflow the instant the user drags it off the column — which is
// what put three different card widths on the owner's 1.0.0 desktop.
foreach ( $widgets as $id => $args ) {
	ok( ( $args['default_width'] ?? 0 ) === 312,
		"$id floats at the docked column width, so liberating it doesn't reflow (got " . ( $args['default_width'] ?? 'none' ) . ')' );
	ok( ( $args['min_width'] ?? 0 ) === 240 && ( $args['min_height'] ?? 0 ) === 120,
		"$id uses the shared 240×120 legibility floor" );
}

echo "\n── v9.52.4: the chrome owns the title (no doubled headings) ──\n";
// movable:true (v9.52.2) makes desktop-mode render its own chrome header —
// grip + LABEL + remove — above every card body. The three pre-v9.52.0 widgets
// each painted their OWN title inside the body, which was right when no chrome
// existed and became a duplicate the moment dragging was enabled: the card
// then read "SN Quick Actions" (chrome) directly above "QUICK ACTIONS" (body).
// The label registered in PHP is the single source of truth for a card's name.
$sn_widget_js = array(
	'desktop-mode-widget.js'         => 'Signal & Noise',
	'desktop-mode-widget-actions.js' => 'Quick actions',
	'desktop-mode-widget-rss.js'     => 'RSS subscribers',
	'desktop-mode-widget-views.js'   => null,
	'desktop-mode-widget-uptime.js'  => null,
	'desktop-mode-widget-health.js'  => null,
	'desktop-mode-widget-machine-readers.js' => null,
	'desktop-mode-widget-anchors.js' => null,
);
foreach ( $sn_widget_js as $file => $old_heading ) {
	$code = strip_js_comments( file_get_contents( __DIR__ . '/../assets/' . $file ) );
	ok( strpos( $code, 'text-transform:uppercase' ) === false,
		"$file paints no uppercase title row — the chrome header already shows the label" );
	if ( null !== $old_heading ) {
		ok( strpos( $code, "'" . $old_heading . "'" ) === false,
			"$file no longer duplicates its own title (\"$old_heading\")" );
	}
}

echo "\n── v9.52.4: EVERY widget reads on the dark glass card ──\n";
// v9.52.2 fixed only Quick Actions, because that was the reported symptom —
// three opaque white slabs. The same light-theme palette was still sitting in
// the deploy-status and RSS widgets, hidden because grey-on-dark reads as DIM
// rather than obviously broken. Same bug, quieter.
$light_palette = array(
	'#1d2327' => 'near-black body text',
	'#646970' => 'mid-grey label',
	'#8c8f94' => 'light-grey note',
	'#c3c4c7' => 'light border',
	'#2271b1' => 'wp-admin blue link',
	'#dff4dc' => 'pastel success fill',
	'#fbe2e2' => 'pastel error fill',
);
foreach ( array_keys( $sn_widget_js ) as $file ) {
	$code = strip_js_comments( file_get_contents( __DIR__ . '/../assets/' . $file ) );
	$found = array();
	foreach ( $light_palette as $hex => $what ) {
		if ( stripos( $code, $hex ) !== false ) { $found[] = "$hex ($what)"; }
	}
	ok( empty( $found ), "$file carries no light-theme colours" . ( $found ? ' [' . implode( ', ', $found ) . ']' : '' ) );
}

echo "\n── v10.28.0: widgets inherit the desktop theme's typeface ──\n";
// desktop-mode 0.9.7 added DESKTOP THEMES: a theme sets --desktop-mode-font and
// the shell root applies `font-family: var(--desktop-mode-font, inherit)`
// (assets/css/desktop.css, verified at v0.9.8). Its own comment names the
// consumers: "dock labels, desktop icon labels, WIDGETS, the overview" — and
// the delivery mechanism is INHERITANCE. So any local font-family in a widget
// body silently overrides the user's chosen theme face. All eight SN widgets
// carried a hardcoded -apple-system stack, which blocked it across our entire
// widget surface.
//
// The `font:` SHORTHAND is the same bug wearing a different name: it RESETS
// font-family to the shorthand's own value, so `font:13px/1.2 -apple-system,…`
// blocks the theme exactly as `font-family:` does. `font:inherit` is the one
// safe form — it explicitly re-adopts the inherited face.
//
// This pins the PROPERTY (we declare no typeface), not a wording: font-size,
// line-height, font-weight and font-variant-numeric are all still fine, and
// none of them touch the family.
foreach ( array_keys( $sn_widget_js ) as $file ) {
	$code = strip_js_comments( file_get_contents( __DIR__ . '/../assets/' . $file ) );

	ok( stripos( $code, 'font-family' ) === false,
		"$file declares no font-family — the desktop theme's face inherits through" );

	// `font:` shorthand, excluding the longhands that merely start with "font-".
	preg_match_all( '/(?<![a-z-])font\s*:\s*([^;\'"]+)/i', $code, $m );
	$bad = array_values( array_filter( array_map( 'trim', $m[1] ), function ( $v ) {
		return 'inherit' !== strtolower( $v );
	} ) );
	ok( empty( $bad ),
		"$file uses no font: shorthand that resets the family" . ( $bad ? ' [' . implode( ', ', $bad ) . ']' : '' ) );
}

echo "\n── v9.52.2: Quick Actions reads on the dark glass card ──\n";
// .desktop-mode-widgets__card is NOT theme-switchable: it is fixed dark glass
// — background rgba(20,20,22,.55) + backdrop-filter blur, color:#fff
// (assets/css/desktop.css). The card sets white text and every SN widget
// inherits it. desktop-mode's own --wpd-color-* tokens are a RED HERRING here:
// they are consumed by first-party widget CSS but DEFINED NOWHERE in v0.9.5
// (no :root rule, no setProperty), so var(--wpd-color-text, …) always resolves
// to its fallback — which is why widget-starter.css falls back to near-black
// while widget-jazz-quote.css falls back to near-white. Style against the card
// that actually exists: light-on-dark.
//
// v10.28.0: re-verified at v0.9.8, AFTER desktop themes shipped. The card
// background is still the literal rgba(20,20,22,0.55) — the one surface a
// theme cannot retint — so light-on-dark stays correct and these literals stay
// literals. See assets/desktop-mode-widget-actions.js for why adopting the
// --wpd-* body palette here would REGRESS contrast under a light theme.
$aj = file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-actions.js' );
$aj_code = strip_js_comments( $aj );
ok( strpos( $aj_code, 'background:#fff' ) === false,
	'Quick Actions buttons are not opaque white blocks on the dark glass card' );
ok( strpos( $aj_code, 'color:#1d2327' ) === false,
	'Quick Actions uses no near-black text (invisible on a dark card)' );
ok( strpos( $aj_code, '#dff4dc' ) === false && strpos( $aj_code, '#fbe2e2' ) === false,
	'Quick Actions toasts are not light pastel fills on dark glass' );
ok( preg_match( '/rgba\(\s*255\s*,\s*255\s*,\s*255/', $aj_code ) === 1,
	'Quick Actions styles light-on-dark (translucent white), matching the card idiom' );
ok( strpos( $aj_code, 'mouseenter' ) !== false || strpos( $aj_code, 'mouseover' ) !== false,
	'Quick Actions buttons have a real hover state (the transition existed but nothing changed on hover)' );

echo "\n── Widget registration gate ──\n";
fire( 'admin_enqueue_scripts' );
$widgets = $GLOBALS['__dm_widgets'];

ok( isset( $widgets['sn-site-views'] ), 'W1: registers the sn-site-views widget' );
ok( isset( $widgets['sn-uptime'] ),     'W2: registers the sn-uptime widget' );
ok( isset( $widgets['sn-health'] ),     'W3: registers the sn-health widget' );
ok( count( $widgets ) === 8, 'all eight widgets register (v10.1.0 adds SN Machine Readers), got ' . count( $widgets ) );

ok( ( $widgets['sn-site-views']['label'] ?? '' ) === 'SN Site Views', 'W1 carries its label' );
ok( ( $widgets['sn-uptime']['label'] ?? '' ) === 'SN Uptime',         'W2 carries its label' );
ok( ( $widgets['sn-health']['label'] ?? '' ) === 'SN Health',         'W3 carries its label' );

// desktop-mode's picker shows description + icon; a missing icon fails
// WIDGET_CHECKS on the client-side path and renders a generic tile here.
foreach ( array( 'sn-site-views', 'sn-uptime', 'sn-health' ) as $id ) {
	ok( ! empty( $widgets[ $id ]['description'] ?? '' ), "$id declares a picker description" );
	ok( ! empty( $widgets[ $id ]['icon'] ?? '' ),        "$id declares a dashicon" );
}

ok( ( $widgets['sn-site-views']['script'] ?? '' ) === 'sn-desktop-mode-widget-views', 'W1 names its script handle' );
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-widget-views'] ), 'W1 script handle is registered' );
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-widget-uptime'] ), 'W2 script handle is registered' );
ok( isset( $GLOBALS['__scripts']['sn-desktop-mode-widget-health'] ), 'W3 script handle is registered' );

echo "\n── The gate: no desktop-mode, no registration ──\n";
// Re-running the hook with the registry fn absent must be a no-op. We can't
// un-define a function, so assert the guard is present in the source instead.
//
// v10.43.0: the raw function_exists('desktop_mode_register_widget') check
// was replaced by snt_os_register_widget_available() (inc/openstation-compat.php),
// which checks BOTH the pre-rename and post-#475 function names — a
// structurally-forced pin update, not a behavior change (the OLD-name path
// is still the exact same function_exists() check, just inside the helper).
$src = dm_integration_src();
ok( strpos( $src, 'snt_os_register_widget_available()' ) !== false, 'widget block is gated on widget-registration availability (either naming family)' );

echo "\n── Localized \$shared ──\n";
$shared = $GLOBALS['__localized']['snDesktopData'] ?? array();
ok( ! empty( $shared['pages']['analytics'] ?? '' ), 'pages map gains an analytics deep-link' );
ok( array_key_exists( 'healthSummary', $shared ), 'shared carries healthSummary' );
// v9.53.0: uptimeSummary is REMOVED. Pulse was its only consumer; SN Uptime
// fetches live via the ability. Keeping it would have run a Better Stack API
// call on every wp-admin page load for a payload nothing reads.
ok( ! array_key_exists( 'uptimeSummary', $shared ), 'uptimeSummary is gone — no consumer since Pulse retired' );
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
ok( array_key_exists( 'healthSummary', $non_admin ) && $non_admin['healthSummary'] === null,
	'healthSummary is withheld (null) from a non-manage_options user' );

$GLOBALS['__cap'] = true; // the owner
$GLOBALS['__localized'] = array();
fire( 'admin_enqueue_scripts' );
$owner = $GLOBALS['__localized']['snDesktopData'] ?? array();
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
ok( array_key_exists( 'healthSummary', $shared ) && $shared['healthSummary'] === null,
	'healthSummary is present-and-null when no scan has run' );

echo "\n── Summary helper shapes ──\n";
ok( function_exists( 'snt_health_summary_for_localize' ), 'snt_health_summary_for_localize() exists' );
ok( ! function_exists( 'snt_uptime_summary_for_localize' ), 'snt_uptime_summary_for_localize() is gone with its only consumer' );

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

// ═══ v10.83.0: report-only checks are neither passes nor denominator ═══
// `total - flagged` counted a report-only check as a pass. This card is a
// one-line glance with no room to name the gap, so report-only leaves BOTH
// numerator and denominator — a green dot beside "3/4" would read as a silent
// failure. The Health tab keeps the full denominator and names the gap in its
// meta line instead; both agree on the number that matters, and neither counts
// a report as a pass.
$GLOBALS['__health_scan'] = fixture_scan( array(
	'missing_alt'     => 0,
	'broken_links'    => 3,
	'contrast_tokens' => 0,
), 1752660000 );
$GLOBALS['__health_scan']['checks']['contrast_tokens']['report'] = array(
	'coverage' => 'Arithmetic tier only.',
	'pairs'    => array( array( 'pair' => 'a / b', 'ratio' => 2.1 ) ),
);
$rep = snt_health_summary_for_localize();
ok( ( $rep['passed'] ?? -1 ) === 1, 'report-only: passed counts ONLY the real pass (1), not the report' );
ok( ( $rep['total'] ?? -1 ) === 2, 'report-only: the report leaves the denominator too (2, not 3)' );
ok( ( $rep['report_only'] ?? -1 ) === 1, 'report-only: the payload carries the count so the card can name it later' );
ok( ( $rep['all_passed'] ?? true ) === false, 'report-only: a real fault still fails the summary' );

// And on a clean site the ratio must be FULL — the green dot and the numbers
// have to agree, which is the whole reason the denominator moved.
$GLOBALS['__health_scan'] = fixture_scan( array( 'missing_alt' => 0, 'contrast_tokens' => 0 ), 1752660000 );
$GLOBALS['__health_scan']['checks']['contrast_tokens']['report'] = array( 'coverage' => 'Arithmetic tier only.' );
$rep_clean = snt_health_summary_for_localize();
ok( ( $rep_clean['passed'] ?? -1 ) === 1 && ( $rep_clean['total'] ?? -1 ) === 1, 'report-only: a clean site reads 1/1, never 1/2 beside a green dot' );
ok( ( $rep_clean['all_passed'] ?? false ) === true, 'report-only: a report alone never blocks all_passed' );

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

// v9.53.0: the snt_uptime_summary_for_localize() block is gone with the function.
// It existed to feed SN Pulse's uptime row; Pulse is retired and SN Uptime reads
// the signal-noise/uptime-status ability live instead (inc/uptime-status.php owns
// that contract and tests/uptime-status.php covers it). The v9.52.0 regression it
// guarded — iterating the {fetched_at, rows} snapshot instead of ['rows'], so every
// monitor defaulted to 'ok' and the tile showed GREEN THROUGH A REAL OUTAGE — is
// now structurally impossible here: nothing in this file reads uptime rows.

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

echo "\n── v9.53.0: Site Views enrichment ──\n";
delete_transient( 'sn_desktop_site_views_' . substr( current_time( 'mysql' ), 0, 10 ) );
// 60 days of NOISY history. Two reasons this shape matters:
//   1. The stub ignores $from/$to and returns this global for any range, so a
//      14-day seed capped the 60-day FIT at 14 — below MIN_POINTS (21) — and
//      the forecast success path below never executed. Review caught that: the
//      is_array() assertions were dead code passing vacuously.
//   2. Perfectly linear data yields sigma=0 and a DEGENERATE interval
//      (low==high), which would let a broken interval look fine. Noise gives a
//      real band.
$GLOBALS['__series'] = array();
$noise = array( 12,19,9,22,15,31,8,17,25,11,28,14,20,9,33,16,21,13,27,10,24,18,30,12,26,15,22,19,29,17,
                14,23,11,26,18,9,31,20,13,28,16,22,10,25,19,30,12,27,15,21,17,24,13,29,11,26,20,14,23,18 );
foreach ( $noise as $i => $v ) {
	$GLOBALS['__series'][] = array( 'day' => gmdate( 'Y-m-d', strtotime( '2026-07-16 -' . ( 59 - $i ) . ' days' ) ), 'views' => $v, 'visits' => (int) round( $v * 0.6 ) );
}
$GLOBALS['__totals']  = array( '*' => array( 'views' => 189, 'visits' => 98, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$GLOBALS['__classes'] = array(
	array( 'day' => '2026-07-13', 'total' => 100, 'bot' => 25, 'bot_pct' => 25 ),
	array( 'day' => '2026-07-14', 'total' => 100, 'bot' => 35, 'bot_pct' => 35 ),
);
// Four rows so the top_paths cap-at-3 assertion has something to cut.
// Shape is the REAL sn_analytics_top_paths() row (inc/analytics-read.php):
// path / views / visits / scroll_avg / time_avg — the payload only surfaces
// path + views, same as the existing top_path compat key.
$GLOBALS['__top']     = array(
	array( 'path' => '/notes/provenance', 'views' => 42, 'visits' => 30, 'scroll_avg' => 0.7, 'time_avg' => 90.0 ),
	array( 'path' => '/notes/signal',     'views' => 18, 'visits' => 14, 'scroll_avg' => 0.6, 'time_avg' => 70.0 ),
	array( 'path' => '/',                 'views' => 11, 'visits' => 9,  'scroll_avg' => 0.5, 'time_avg' => 40.0 ),
	array( 'path' => '/about',            'views' => 3,  'visits' => 2,  'scroll_avg' => 0.4, 'time_avg' => 20.0 ),
);
// Four rows, views DESC — so the cap-at-3 assertion has something to cut, and
// '(direct)' carries an empty hosts[] because it aggregates and is never drillable.
$GLOBALS['__sources'] = array(
	array( 'value' => '(direct)',    'views' => 50, 'visits' => 40, 'hosts' => array() ),
	array( 'value' => 'Google',      'views' => 18, 'visits' => 13, 'hosts' => array( 'google.com' ) ),
	array( 'value' => 'Hacker News', 'views' => 9,  'visits' => 7,  'hosts' => array( 'news.ycombinator.com' ) ),
	array( 'value' => 'Bing',        'views' => 2,  'visits' => 1,  'hosts' => array( 'bing.com' ) ),
);
$res  = call_user_func( $route['callback'] );
$body = $res instanceof WP_REST_Response ? $res->get_data() : $res;

ok( ( $body['visits'] ?? null ) === 98, 'payload carries visits alongside views' );
ok( ( $body['bot_pct'] ?? null ) === 30, 'payload carries the window bot share (weighted across the class series, not the last day)' );
ok( ( $body['top_path']['path'] ?? '' ) === '/notes/provenance', 'payload carries the top path' );
ok( ( $body['top_path']['views'] ?? null ) === 42, 'the top path carries its view count' );

echo "\n── top_paths is additive (top_path stays the first row, for cached-payload compat) ──\n";
$top_paths = is_array( $body['top_paths'] ?? null ) ? $body['top_paths'] : array();
ok( is_array( $body['top_paths'] ?? null ), 'payload carries top_paths beside the existing top_path' );
ok( count( $top_paths ) === 3, 'top_paths is capped at 3 — a tile is a glance' );
ok( ( $top_paths[0]['path'] ?? '' ) === '/notes/provenance' && ( $top_paths[0]['views'] ?? null ) === 42,
	'top_paths[0] is the same row top_path already carried — the compat key is unchanged, not recomputed' );
ok( ( $top_paths[1]['path'] ?? '' ) === '/notes/signal' && ( $top_paths[1]['views'] ?? null ) === 18,
	'top_paths[1] is the second path by views' );
ok( ( $top_paths[2]['path'] ?? '' ) === '/' && ( $top_paths[2]['views'] ?? null ) === 11,
	'top_paths[2] is the third path by views — /about (4th) is cut' );
ok( isset( $top_paths[0] ) && array( 'path', 'views' ) === array_keys( $top_paths[0] ),
	'top_paths rows are { path, views } — visits/scroll/time stay on the accessor, not this glance' );
ok( ( $body['top_path']['path'] ?? '' ) === ( $top_paths[0]['path'] ?? null )
	&& ( $body['top_path']['views'] ?? null ) === ( $top_paths[0]['views'] ?? null ),
	'top_path remains byte-identical to the first top_paths row' );

echo "\n── v9.57.0: top sources (folded in from the retired sn-analytics-hud) ──\n";
// The desktop had no surface for WHERE traffic comes from. The HUD existed
// largely to show this; everything else it showed, this tile already covered.
//
// Row keys are the accessor's OWN: `value` / `visits` (inc/analytics-sources.php).
// NOT `source` — that invented key shipped in v9.56.0 and rendered `undefined`
// for every row, because the STUB invented the same wrong name. Pin the real one.
ok( is_array( $body['top_sources'] ?? null ), 'payload carries top_sources' );
ok( count( $body['top_sources'] ) === 3, 'top_sources is capped at 3 — a tile is a glance, not the full list' );
ok( ( $body['top_sources'][0]['value'] ?? '' ) === '(direct)',
	'top_sources rows use the REAL key `value` (inc/analytics-sources.php), never the invented `source`' );
ok( ( $body['top_sources'][0]['visits'] ?? null ) === 40, 'top_sources rows carry visits' );
ok( ! array_key_exists( 'source', $body['top_sources'][0] ),
	'top_sources rows do NOT expose `source` — the accessor has never emitted that key' );

echo "\n── v9.53.0: the forecast, and its honesty gates ──\n";
// sn_analytics_forecast_of() already encodes the discipline: below
// SN_ANALYTICS_FORECAST_MIN_POINTS → null, zero median level → null, and
// `confidence` comes from the backtest's MEASURED coverage — not a vibe. The
// widget's job is to not undo that: ship the interval WITH the number, or ship
// nothing. A bare point forecast is the dishonest version.
ok( array_key_exists( 'forecast', $body ), 'payload carries a forecast key' );
if ( is_array( $body['forecast'] ) ) {
	ok( isset( $body['forecast']['value'] ), 'forecast carries a point value' );
	ok( isset( $body['forecast']['interval']['low'], $body['forecast']['interval']['high'] ),
		'forecast ALWAYS carries its interval — a bare point forecast is the dishonest version' );
	ok( in_array( $body['forecast']['confidence'] ?? '', array( 'high', 'medium', 'low' ), true ),
		'forecast carries measured-backtest confidence' );
	ok( ( $body['forecast']['interval']['low'] ?? 1 ) <= ( $body['forecast']['value'] ?? 0 )
		&& ( $body['forecast']['value'] ?? 0 ) <= ( $body['forecast']['interval']['high'] ?? -1 ),
		'the point sits inside its own interval' );
} else {
	ok( $body['forecast'] === null, 'forecast is null when it cannot be produced honestly' );
}

// Too little history → the engine returns null → the payload says null, and the
// widget must render NOTHING rather than invent a number.
delete_transient( 'sn_desktop_site_views_' . substr( current_time( 'mysql' ), 0, 10 ) );
$GLOBALS['__series'] = array( array( 'day' => '2026-07-15', 'views' => 10, 'visits' => 5 ) );
$res2  = call_user_func( $route['callback'] );
$body2 = $res2 instanceof WP_REST_Response ? $res2->get_data() : $res2;
ok( array_key_exists( 'forecast', $body2 ) && $body2['forecast'] === null,
	'one day of history yields NO forecast (present-and-null) — never a fabricated number' );

echo "\n── v9.53.0: Health enrichment — which checks actually failed ──\n";
$GLOBALS['__health_scan'] = fixture_scan( array(
	'missing_alt'        => 0,
	'broken_links'       => 3,
	'stale_posts'        => 7,
	'color_drift'        => 0,
	'external_links'     => 42, // advisory — must NOT read as a failure
	'link_opportunities' => 5,  // advisory
) );
$h = snt_health_summary_for_localize();
ok( is_array( $h['flagged'] ?? null ), 'health summary carries the flagged checks' );
ok( count( $h['flagged'] ) === 2, 'only the two real faults are flagged (advisories excluded), got ' . count( (array) ( $h['flagged'] ?? array() ) ) );
ok( ( $h['flagged'][0]['count'] ?? 0 ) === 7, 'flagged checks are ranked by count, worst first' );
ok( ( $h['flagged'][0]['label'] ?? '' ) !== '', 'each flagged check carries a human label — "what is wrong", not just a key' );
ok( ( $h['findings_total'] ?? null ) === 10, 'findings_total sums the fault-tier findings (3 + 7)' );
ok( ( $h['advisory_total'] ?? null ) === 47, 'advisory_total is reported separately (42 + 5) — advisories are not faults' );
ok( ( $h['all_passed'] ?? true ) === false, 'a scan with faults is not all_passed' );

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

echo "\n── v9.52.5: the AI Copilot tool-schema normalizer ──\n";
// THE BUG (owner-reported, live): clicking Ask AI returned
//   Bad Request (400) - tools.12.custom.input_schema.type: Input should be 'object'
// …and the Copilot was dead — not degraded, DEAD, because one malformed tool
// fails the whole request.
//
// Cause: desktop-mode 0.9.4 made the Copilot's tools WordPress Abilities and
// offers EVERY read-only ability on the site automatically, with no opt-in. Its
// converter (includes/ai-copilot/search.php) passes the ability's input_schema
// through RAW as the tool's `parameters`. Our abilities deliberately declare a
// ['object','null'] union — that's their GET/null run-path — and Anthropic
// requires input_schema.type to be the literal string "object". desktop-mode's
// own abilities all use plain 'object', so their Copilot never trips on this;
// only a third-party union-typed ability breaks it, and it takes the whole
// request down.
//
// The schemas are NOT wrong and are not changed: MCP and REST rely on that null
// run-path. The missing piece was the BOUNDARY. We already own the fix —
// sn_mcp_normalize_schema() (inc/mcp/mcp-tools.php), whose own comment predicted
// this error verbatim ("strict MCP hosts (e.g. the Anthropic tool-schema
// validator that a client forwards to) reject"). It was simply never wired into
// a path nobody knew existed. desktop_mode_ai_tools exists to "transform the
// full tool list just before it goes to the provider" — that's the seam.
require_once __DIR__ . '/../inc/mcp/mcp-tools.php';

ok( isset( $GLOBALS['__filters']['desktop_mode_ai_tools'] ), 'the desktop_mode_ai_tools filter is registered' );

// The real broken shape, straight from inc/abilities-analytics.php.
$tools_in = array(
	array( 'type' => 'function', 'name' => 'search_posts', 'parameters' => array( 'type' => 'object', 'properties' => array( 'query' => array( 'type' => 'string' ) ) ) ),
	array( 'type' => 'function', 'name' => 'get_analytics_summary', 'parameters' => array(
		'type'                 => array( 'object', 'null' ),
		'properties'           => array( 'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ) ),
		'additionalProperties' => false,
	) ),
	// A command tool: no `parameters` at all. Must not fatal.
	array( 'type' => 'function', 'name' => 'sn_cmd_nav_dashboard' ),
);
$tools_out = apply_filters( 'desktop_mode_ai_tools', $tools_in );

ok( is_array( $tools_out ) && count( $tools_out ) === 3, 'the filter returns every tool it was given' );
ok( ( $tools_out[1]['parameters']['type'] ?? null ) === 'object',
	'a union ["object","null"] input schema is normalized to the literal "object" (the 400 that killed Ask AI)' );
ok( isset( $tools_out[1]['parameters']['properties']['range'] ),
	'normalizing preserves the properties — the tool keeps its arguments' );
ok( ( $tools_out[1]['parameters']['additionalProperties'] ?? null ) === false,
	'normalizing preserves the rest of the schema' );
ok( ( $tools_out[0]['parameters']['type'] ?? null ) === 'object',
	'an already-valid tool passes through unharmed' );
ok( ( $tools_out[0]['parameters']['properties']['query']['type'] ?? null ) === 'string',
	'an already-valid tool keeps its properties untouched' );
ok( ! isset( $tools_out[2]['parameters'] ),
	'a tool with no parameters (a command tool) is left alone, not given a fabricated schema' );

// Nested property unions are FINE — Anthropic only constrains the TOP level.
// Rewriting them would silently narrow the ability's real contract.
ok( ( $tools_out[1]['parameters']['properties']['range']['type'] ?? null ) === array( 'string', 'integer' ),
	'nested property unions are left intact (only the top-level type is constrained)' );

// v9.53.0 — THE SECOND VIOLATION. v9.52.5 fixed the type union and the live
// error MOVED rather than vanished:
//   tools.12.custom.input_schema.type: Input should be 'object'      (fixed)
//   tools.29.custom.input_schema: does not support oneOf, allOf, or anyOf
//                                 at the top level                   (this)
// The theme's signal-and-noise/get-active-template-structure declares a
// top-level anyOf — "supply post_id OR slug" — which is perfectly good JSON
// Schema that Anthropic simply rejects at the top level of input_schema.
//
// Two of my own mistakes compounded: the normalizer only forced `type`, and the
// filter SKIPPED any tool whose type was already 'object' — which this schema's
// was, so it was never even inspected.
//
// Stripping the combinator does NOT weaken anything: the ability's own
// execute-time validation still enforces post_id-or-slug server-side, and its
// description already states the requirement, so the model is still told — in
// prose instead of schema.
$anyof_tools = apply_filters( 'desktop_mode_ai_tools', array(
	array( 'type' => 'function', 'name' => 'get_active_template_structure', 'parameters' => array(
		'type'       => 'object', // ALREADY valid — the old skip condition bailed here
		'properties' => array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'slug'    => array( 'type' => 'string' ),
		),
		'anyOf'                => array( array( 'required' => array( 'post_id' ) ), array( 'required' => array( 'slug' ) ) ),
		'additionalProperties' => false,
	) ),
	array( 'type' => 'function', 'name' => 'combo_all', 'parameters' => array(
		'type'  => 'object',
		'oneOf' => array( array( 'required' => array( 'a' ) ) ),
		'allOf' => array( array( 'required' => array( 'b' ) ) ),
	) ),
) );
ok( ! isset( $anyof_tools[0]['parameters']['anyOf'] ),
	'a top-level anyOf is stripped even when type is ALREADY "object" (the skip condition that let it through)' );
ok( ! isset( $anyof_tools[1]['parameters']['oneOf'] ) && ! isset( $anyof_tools[1]['parameters']['allOf'] ),
	'top-level oneOf and allOf are stripped too' );
ok( isset( $anyof_tools[0]['parameters']['properties']['post_id'], $anyof_tools[0]['parameters']['properties']['slug'] ),
	'stripping the combinator keeps every property — the tool still takes its arguments' );
ok( ( $anyof_tools[0]['parameters']['additionalProperties'] ?? null ) === false,
	'stripping the combinator preserves the rest of the schema' );

echo "\n── v9.55.0: every desktop-mode admin link must reach a REGISTERED page ──\n";
// THE BUG (owner-found by clicking, 2026-07-16): opening most SN windows in
// Desktop Mode showed WP core's "Sorry, you are not allowed to access this
// page." EIGHT of our NINE admin links pointed at slugs that do not exist.
//
// v3.8.1 cut the wp-admin submenu from the 12 legacy slugs to 6 top tabs
// (add_submenu_page over sn_admin_top_tabs()). The desktop-mode icons and the
// Cmd+K nav map kept hardcoding the RETIRED slugs — sn-identity, sn-login,
// sn-cron, sn-rss… admin.php looks each up in $_registered_pages, doesn't find
// it, and wp_die()s. The message is WP CORE's, not desktop-mode's and not
// ours, which is exactly why no surface here ever noticed.
//
// And the legacy redirect cannot rescue them: sn_admin_maybe_redirect_legacy()
// is called from INSIDE sn_theme_options_page() — the render callback of a page
// that no longer exists. The rescue lives in the room that burned down. A
// legacy URL only redirects if its slug is still registered; these aren't.
//
// So: never hardcode a page slug here. Route every link through the canonical
// resolver the redirect itself uses, which always lands on the registered
// parent (page=sn-theme-options&tab=…). This test pins the PROPERTY — every
// emitted link resolves to a registered page — rather than a list of slugs
// that would rot the same way the last one did.
$sn_registered = array_merge(
	array( 'sn-theme-options' ),
	array_column( sn_admin_top_tabs(), 'slug' )
);
$sn_targets = array();
foreach ( ( $GLOBALS['__localized']['snDesktopData']['pages'] ?? array() ) as $k => $u ) {
	$sn_targets[ "pages.$k" ] = $u;
}
foreach ( $GLOBALS['__dm_icons'] as $id => $args ) {
	$sn_targets[ "icon.$id" ] = $args['url'] ?? '';
}
ok( count( $sn_targets ) >= 10, 'sanity: the nav map + icons were captured (' . count( $sn_targets ) . ' links)' );
foreach ( $sn_targets as $sn_label => $sn_url ) {
	$sn_slug = preg_match( '/[?&]page=([a-z0-9-]+)/', (string) $sn_url, $m ) ? $m[1] : '';
	// index.php?page=sn-analytics is legitimately registered — via
	// add_dashboard_page(), under WP's Dashboard menu, not the SN menu.
	$sn_ok = in_array( $sn_slug, $sn_registered, true )
		|| ( 'sn-analytics' === $sn_slug && false !== strpos( (string) $sn_url, 'index.php?page=' ) );
	ok( $sn_ok, "$sn_label → a REGISTERED page (got '" . ( $sn_slug ?: 'NONE' ) . "')" );
}

// "It loads" is NOT the property. Every link above now says page=sn-theme-options,
// so the assertions would pass identically if all nine dumped the user on the
// Dashboard — a link that resolves and goes to the wrong place. I wrote exactly
// that test first and it went green. Assert the DESTINATION.
$sn_expect = array(
	'pages.dashboard'    => 'admin.php?page=sn-theme-options&tab=dashboard',
	'pages.identity'     => 'admin.php?page=sn-theme-options&tab=site&sub=identity-and-seo#sn-sec-identity',
	'pages.login'        => 'admin.php?page=sn-theme-options&tab=security&sub=login',
	'pages.cloudflare'   => 'admin.php?page=sn-theme-options&tab=connections&sub=cloudflare',
	'pages.cron'         => 'admin.php?page=sn-theme-options&tab=connections&sub=cron',
	'pages.insights'     => 'admin.php?page=sn-theme-options&tab=monitoring&sub=insights',
	'pages.rss'          => 'admin.php?page=sn-theme-options&tab=monitoring&sub=rss',  // v10.46.0: RSS moved to Measurement
	'pages.reading_time' => 'admin.php?page=sn-theme-options&tab=content', // v10.24.0: the cleanup tool retired in v10.0.0 — Content default, no ghost sub.
	// NOT an SN tab: add_dashboard_page() puts it under index.php. The resolver
	// alone sends it to tab=dashboard — loads fine, wrong page.
	'pages.analytics'    => 'index.php?page=sn-analytics',
	'icon.sn-icon-identity' => 'admin.php?page=sn-theme-options&tab=site&sub=identity-and-seo#sn-sec-identity',
);
foreach ( $sn_expect as $sn_label => $sn_want ) {
	$sn_got = (string) ( $sn_targets[ $sn_label ] ?? '' );
	ok( false !== strpos( $sn_got, $sn_want ),
		"$sn_label lands on its REAL destination (" . $sn_want . ')' );
}

echo "\n── v9.53.2: the normalizer must run LAST, or it doesn't run at all ──\n";
// Same lesson as the skip, one level up. v9.53.1 made the normalizer
// unconditional over the tools it SEES — but it was registered at the default
// priority 10, so it only ever saw the tools that existed at priority 10.
// desktop_mode_ai_tools is a public, documented, "Stable" filter whose whole
// stated purpose is "injecting synthetic command tools". Anything hooking it
// later than us lands its tools downstream of the normalizer, and ONE
// non-conformant tool 400s the entire assistant — not just its own tool.
//
// We cannot know who else hooks this filter or at what priority. So don't
// guess: run last. PHP_INT_MAX is the only priority that cannot be outrun.
//
// This is deliberately defensive beyond our own abilities. A third-party
// plugin's bad schema kills Ask AI for the whole SITE, and normalizing it costs
// a few array ops and weakens nothing (execute-time validation is untouched).
// It is the same fix we proposed upstream in WordPress/desktop-mode#362.
$GLOBALS['__filters']['desktop_mode_ai_tools'][ 999 ][] = static function ( $tools ) {
	// A late-registering plugin injecting a synthetic tool — exactly what the
	// filter's own docblock invites. All three killer shapes at once.
	$tools[] = array(
		'type'       => 'function',
		'name'       => 'late_injected_tool',
		'parameters' => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
			'anyOf'      => array( array( 'required' => array( 'x' ) ) ),
		),
	);
	return $tools;
};
$late = apply_filters( 'desktop_mode_ai_tools', array() );
$injected = null;
foreach ( $late as $t ) {
	if ( 'late_injected_tool' === ( $t['name'] ?? '' ) ) { $injected = $t; }
}
ok( null !== $injected, 'the late-injected tool reaches the tool list (harness sanity — the filter honours priority)' );
ok( 'object' === ( $injected['parameters']['type'] ?? null ),
	'a tool injected by a LATER filter still has its union type normalized (we run after it)' );
ok( ! isset( $injected['parameters']['anyOf'] ),
	'a tool injected by a LATER filter still has its top-level anyOf stripped' );
ok( ( $injected['parameters']['properties'] ?? null ) instanceof stdClass,
	'a tool injected by a LATER filter still has empty properties cast to {} not []' );
unset( $GLOBALS['__filters']['desktop_mode_ai_tools'][ 999 ] );

// Nested combinators are FINE — the provider only rejects them at the TOP
// level, and a property's oneOf is a real constraint worth keeping.
$nested = apply_filters( 'desktop_mode_ai_tools', array(
	array( 'name' => 'x', 'parameters' => array(
		'type'       => array( 'object', 'null' ),
		'properties' => array( 'mode' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ) ),
	) ),
) );
ok( isset( $nested[0]['parameters']['properties']['mode']['oneOf'] ),
	'a NESTED oneOf inside a property is left intact (only the top level is constrained)' );

// v9.53.1 — THE THIRD VIOLATION, and the end of the skip.
//   tools.12 …type: Input should be 'object'                        (v9.52.5)
//   tools.29 …: does not support oneOf/allOf/anyOf at the top level (v9.53.0)
//   tools.30 …properties: Input should be an object                 (this)
// An ability that takes no arguments naturally writes 'properties' => array().
// PHP has no empty-map literal, so that encodes to JSON `[]`, and the provider
// requires `{}`. 13 abilities across the plugin + theme declare exactly that.
//
// The normalizer already handled it. The SKIP was the bug — twice now. It asked
// "is this one of the wrong shapes I know about?" and skipped anything that
// wasn't, so each newly-discovered shape sailed through untouched and the 400
// simply moved to the next tool. Enumerating what's broken cannot work; the
// list is the provider's, not ours, and we learn it one 400 at a time.
// v9.53.1 deletes the skip: ALWAYS normalize. The function is idempotent and
// costs a few array ops on a payload we already build per request.
$props_tools = apply_filters( 'desktop_mode_ai_tools', array(
	// The exact shape the old skip called "conformant" and shipped broken.
	array( 'type' => 'function', 'name' => 'get_theme_version', 'parameters' => array(
		'type'                 => 'object',
		'properties'           => array(),
		'additionalProperties' => false,
	) ),
) );
ok( is_object( $props_tools[0]['parameters']['properties'] ),
	'an EMPTY properties array is cast to an object — PHP array() encodes as [], the provider needs {}' );
ok( json_encode( $props_tools[0]['parameters']['properties'] ) === '{}',
	'…and it actually serialises to {} (the thing the provider checks)' );
ok( ( $props_tools[0]['parameters']['additionalProperties'] ?? null ) === false,
	'casting empty properties preserves the rest of the schema' );

// A NON-empty properties map must survive untouched — it already encodes as an
// object, and rewriting it would be gratuitous.
$keep = apply_filters( 'desktop_mode_ai_tools', array(
	array( 'name' => 'x', 'parameters' => array( 'type' => 'object', 'properties' => array( 'a' => array( 'type' => 'string' ) ) ) ),
) );
ok( ( $keep[0]['parameters']['properties']['a']['type'] ?? '' ) === 'string',
	'a non-empty properties map passes through unchanged' );

// v9.53.1 — CONFORMANCE, checked against every shape our repos ACTUALLY declare.
// Three releases each fixed one rule and claimed victory; each time the 400 just
// moved to the next tool. So stop asserting individual fixes and assert the
// PROPERTY: every real shape, after normalization, satisfies all three rules at
// once. Each rule below cost a live 400 to learn.
function sn_anthropic_conformance( $schema ) {
	$s = (array) $schema;
	if ( ( $s['type'] ?? null ) !== 'object' ) { return 'type is not the literal "object"'; }
	foreach ( array( 'oneOf', 'allOf', 'anyOf' ) as $k ) {
		if ( isset( $s[ $k ] ) ) { return "top-level $k"; }
	}
	// The check that matters most, and the subtlest: an empty PHP array
	// serialises to [] and the provider demands {}.
	if ( isset( $s['properties'] ) && is_array( $s['properties'] ) && array() === $s['properties'] ) {
		return 'properties encodes as [] not {}';
	}
	return true;
}

$real_shapes = array(
	'union type + props'       => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'range' => array( 'type' => array( 'string', 'integer' ) ) ), 'additionalProperties' => false ),
	'union type + EMPTY props' => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
	'object + EMPTY props'     => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
	'object + props + anyOf'   => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'slug' => array( 'type' => 'string' ) ), 'anyOf' => array( array( 'required' => array( 'post_id' ) ), array( 'required' => array( 'slug' ) ) ), 'additionalProperties' => false ),
	'object + props (already ok)' => array( 'type' => 'object', 'properties' => array( 'q' => array( 'type' => 'string' ) ) ),
	'empty schema'             => array(),
);
foreach ( $real_shapes as $label => $shape ) {
	$verdict = sn_anthropic_conformance( sn_mcp_normalize_schema( $shape ) );
	ok( true === $verdict, "normalized shape is provider-conformant: $label" . ( true === $verdict ? '' : " [$verdict]" ) );
}

// Idempotence is what makes "always normalize, never skip" safe: a conformant
// schema in must be an identical schema out.
$already = array( 'type' => 'object', 'properties' => array( 'q' => array( 'type' => 'string' ) ) );
ok( sn_mcp_normalize_schema( sn_mcp_normalize_schema( $already ) ) === sn_mcp_normalize_schema( $already ),
	'sn_mcp_normalize_schema() is idempotent — normalizing twice equals normalizing once' );

// THE GUARD. v9.52.5 shipped claiming "Ask AI works" after testing the fix
// against a FIXTURE. The real tool list had a SECOND violation class the
// fixture didn't contain, so the 400 merely moved (tools.12 → tools.29) and the
// claim was false. This scans what the plugin's abilities ACTUALLY declare at
// the top level of their input_schema and fails if any of it is a class the
// normalizer doesn't handle — so the next ability to use an exotic top-level
// keyword ($ref, not, if/then/else, const…) trips the build here instead of
// silently killing the Copilot for every user.
$handled = array( 'oneOf', 'allOf', 'anyOf' ); // + a union `type`, handled separately
$risky   = array( '$ref', 'not', 'if', 'then', 'else' );
$unhandled = array();
foreach ( glob( __DIR__ . '/../inc/*.php' ) as $abil_file ) {
	$src = (string) file_get_contents( $abil_file );
	if ( strpos( $src, "'input_schema'" ) === false ) { continue; }
	foreach ( $risky as $kw ) {
		// Only flag a top-level-looking occurrence inside an input_schema block.
		if ( preg_match( "/'input_schema'\s*=>\s*array\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*'" . preg_quote( $kw, '/' ) . "'\s*=>/s", $src ) ) {
			$unhandled[] = basename( $abil_file ) . ':' . $kw;
		}
	}
}
ok( empty( $unhandled ),
	'no ability declares a top-level input_schema keyword the normalizer cannot handle'
		. ( $unhandled ? ' [' . implode( ', ', array_unique( $unhandled ) ) . ']' : '' ) );

// Degenerate inputs must never fatal a whole Copilot request.
$weird = apply_filters( 'desktop_mode_ai_tools', array( 'not-an-array', array( 'name' => 'x', 'parameters' => 'not-an-array' ) ) );
ok( is_array( $weird ) && count( $weird ) === 2, 'malformed tool entries pass through without fataling' );

// And the abilities themselves must KEEP their union — the fix is at the
// boundary, not in the contract. Changing these would break the GET/null path.
$analytics_src = file_get_contents( __DIR__ . '/../inc/abilities-analytics.php' );
ok( strpos( $analytics_src, "array( 'object', 'null' )" ) !== false,
	'the abilities KEEP their ["object","null"] union — normalization happens at the boundary, not by rewriting contracts MCP/REST depend on' );

echo "\n── v9.59.0: the Copilot tool list is PRUNED — ours only, matched by stripped name ──\n";
/**
 * VERBATIM copy of desktop-mode's ability→tool-name transform
 * (includes/ai-copilot/abilities.php:99, v0.9.5). desktop-mode strips the
 * namespace and normalizes the slug BEFORE our filter sees the tool, so
 * `signal-noise/export-audit-log` arrives as `export_audit_log`. The prune must
 * match that stripped name or it silently matches nothing — this is the shape the
 * real callee produces, not one we imagined. Production calls desktop-mode's OWN
 * function (so it can't drift); this copy lets the standalone suite exercise it.
 */
function desktop_mode_ai_ability_tool_name( $ability_name ) {
	$slug = (string) $ability_name;
	$pos  = strpos( $slug, '/' );
	if ( false !== $pos ) {
		$slug = substr( $slug, $pos + 1 );
	}
	$slug = strtolower( str_replace( '-', '_', $slug ) );
	$slug = preg_replace( '/[^a-z0-9_]+/', '_', $slug );
	return trim( (string) $slug, '_' );
}

ok( desktop_mode_ai_ability_tool_name( 'signal-noise/export-audit-log' ) === 'export_audit_log',
	'sanity: the tool-name transform strips the namespace and underscores the slug (the real shape the prune must match)' );

// Feed the STRIPPED names the filter actually receives (as the existing normalizer
// test above does with search_posts / get_analytics_summary).
$prune_in    = array(
	array( 'type' => 'function', 'name' => 'pattern_adoption_suggest',      'parameters' => array( 'type' => 'object', 'properties' => array() ) ), // ours — prune
	array( 'type' => 'function', 'name' => 'export_audit_log',              'parameters' => array( 'type' => 'object', 'properties' => array() ) ), // ours — prune
	array( 'type' => 'function', 'name' => 'block_migrations_suggest',      'parameters' => array( 'type' => 'object', 'properties' => array() ) ), // ours — prune
	array( 'type' => 'function', 'name' => 'get_analytics_summary',         'parameters' => array( 'type' => 'object', 'properties' => array() ) ), // ours — EARNS its rent, keep
	array( 'type' => 'function', 'name' => 'search_posts',                  'parameters' => array( 'type' => 'object', 'properties' => array() ) ), // desktop-mode's own, keep
	array( 'type' => 'function', 'name' => 'get_active_template_structure', 'parameters' => array( 'type' => 'object', 'properties' => array() ) ), // the THEME's, keep
);
$prune_out   = apply_filters( 'desktop_mode_ai_tools', $prune_in );
$prune_names = array_map( static function ( $t ) { return is_array( $t ) ? ( $t['name'] ?? '' ) : ''; }, $prune_out );

ok( ! in_array( 'pattern_adoption_suggest', $prune_names, true ),
	'pattern_adoption_suggest is pruned — needs a scan-generated block fingerprint, unusable from natural language' );
ok( ! in_array( 'export_audit_log', $prune_names, true ),
	'export_audit_log is pruned — an export/download action; get-audit-log already answers the readable question' );
ok( ! in_array( 'block_migrations_suggest', $prune_names, true ),
	'block_migrations_suggest is pruned — needs a scan-generated block fingerprint, unusable from natural language' );
ok( in_array( 'get_analytics_summary', $prune_names, true ),
	'a tool that EARNS its rent survives the prune (we cut the narrow ones, not the useful ones)' );
ok( in_array( 'search_posts', $prune_names, true ),
	"desktop-mode's OWN tool is never pruned — we prune only ours" );
ok( in_array( 'get_active_template_structure', $prune_names, true ),
	"the THEME's tool is never pruned either — not ours to cut" );
ok( count( $prune_out ) === 3,
	'exactly the three approved tools were removed; the other three survive' );

echo "\n── v9.59.0: the analytics-vocabulary appendix (every word is rent) ──\n";
ok( isset( $GLOBALS['__filters']['desktop_mode_ai_system_prompt_appendix'] ),
	'the system-prompt appendix filter is registered' );
$appendix = apply_filters( 'desktop_mode_ai_system_prompt_appendix', '' );
ok( is_string( $appendix ) && '' !== $appendix,
	'we contribute a system-prompt appendix' );
// EVERY WORD IS RENT — this ships on every turn, forever. The cap is enforced,
// not intended; a future edit that needs more room must argue for it here.
ok( strlen( $appendix ) <= 600,
	'the appendix is <= 600 chars (~150 tokens/turn, forever) — every word is rent [' . strlen( $appendix ) . ']' );
ok( strpos( $appendix, 'suspect' ) !== false,
	'it names the traffic classes the tools return but never define (human/suspect/bot)' );
ok( strpos( $appendix, 'null' ) !== false,
	'it states that null means never-measured, not zero — the distinction the payloads preserve' );
// It STACKS onto whatever came before — desktop-mode concatenates appendices
// across plugins, so we must append, never replace.
$stacked = apply_filters( 'desktop_mode_ai_system_prompt_appendix', 'PRIOR-PLUGIN-TEXT' );
ok( strpos( $stacked, 'PRIOR-PLUGIN-TEXT' ) === 0,
	'the appendix STACKS onto a prior plugin\'s text (appends, never replaces)' );

echo "\n── v9.59.0 safety lock: destructive commands must NEVER be aiCallable ──\n";
// The v2.5.5 policy exposes safe commands to Ask AI (aiCallable: true) and
// WITHHOLDS the destructive ones on purpose. Nothing tested that until now, so a
// future edit could silently hand cache-purging / row-deletion to the AI. Lock it.
//
// Comments are stripped first: each destructive block carries an "aiCallable
// INTENTIONALLY OMITTED" COMMENT right where the flag would go, and a naive
// substring match would read that prose as the flag (the green-CI/dead-code shape
// this repo keeps hitting). strip_js_comments() is the same guard the mount
// contract uses below.
$aic_js = strip_js_comments( (string) file_get_contents( __DIR__ . '/../assets/desktop-mode.js' ) );

/**
 * null  → the slug does not exist (guards against a typo'd, vacuous assertion)
 * true  → the command is aiCallable
 * false → the command is NOT aiCallable
 *
 * aiCallable, when present, always sits between `slug:` and `run:`, so the search
 * is scoped to that window — a neighbouring command's flag can't leak in.
 */
$aic_state = static function ( $slug ) use ( $aic_js ) {
	if ( ! preg_match( '/slug:\s*\'' . preg_quote( $slug, '/' ) . '\'(.*?)\brun:/s', $aic_js, $m ) ) {
		return null;
	}
	return strpos( $m[1], 'aiCallable' ) !== false;
};

// Destructive / irreversible: purge every cache, delete template-override rows,
// or both at once. An AI must never reach these from a chat turn.
foreach ( array( 'sn-cmd-purge-caches', 'sn-cmd-clear-overrides', 'sn-cmd-full-reset' ) as $danger ) {
	$state = $aic_state( $danger );
	ok( null !== $state,
		"the destructive command `$danger` still exists in the source (not a typo — the exclusion below is real, not vacuous)" );
	ok( false === $state,
		"`$danger` is NOT aiCallable — a destructive, irreversible action must never be invocable by Ask AI" );
}

// Positive control: the safe commands ARE aiCallable, so the exclusions above
// redden because a command is withheld — not because the matcher never matches.
foreach ( array( 'sn-cmd-force-check', 'sn-cmd-nav-dashboard' ) as $safe ) {
	ok( true === $aic_state( $safe ),
		"the safe command `$safe` IS aiCallable (positive control — proves the matcher can see the flag)" );
}

echo "\n── living-tree filter ──\n";
ok( isset( $GLOBALS['__filters']['desktop_mode_living_tree_traffic'] ), 'living-tree filter is registered' );
$GLOBALS['__totals'] = array( '*' => array( 'views' => 1234, 'visits' => 900, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$traffic = apply_filters( 'desktop_mode_living_tree_traffic', 0 );
ok( $traffic === 1234, 'living-tree filter returns the 14-day human view total' );
ok( is_int( $traffic ), 'living-tree filter returns an int (desktop-mode types it int)' );

echo "\n── v10.43.0: OpenStation dual registration — all 6 filter hooks ──\n";
// Post-#475 OpenStation renames every one of these (source-verified — see
// docs/openstation-compat.md). No shim exists upstream, so every one must be
// registered under BOTH names to work on either release line.
$os_filter_pairs = array(
	'desktop_mode_dock_placement'             => 'openstation_dock_placement',
	'desktop_mode_dock_items'                 => 'openstation_dock_items',
	'desktop_mode_ai_tools'                   => 'openstation_ai_tools',
	'desktop_mode_ai_system_prompt_appendix'  => 'openstation_ai_system_prompt_appendix',
	'desktop_mode_plugins_window_icon_url'    => 'openstation_plugins_window_icon_url',
	'desktop_mode_living_tree_traffic'        => 'openstation_living_tree_traffic',
);
foreach ( $os_filter_pairs as $old => $new ) {
	ok( isset( $GLOBALS['__filters'][ $old ] ), "$old is registered (pre-rename name)" );
	ok( isset( $GLOBALS['__filters'][ $new ] ), "$new is ALSO registered (post-#475 name — dual registration)" );
}

echo "\n── v10.43.0: the post-#475 hook names actually fire the same behavior ──\n";
/**
 * The test harness's apply_filters() stub only ever threads a SINGLE value
 * arg through (see its definition near the top of this file) — it cannot
 * drive a 2-arg filter like dock_placement / plugins_window_icon_url. Pull
 * the registered callback directly and call it with its real signature
 * instead, mirroring how $GLOBALS['__filters'][hook][priority][] stores it.
 */
function first_filter_cb( $hook ) {
	$by_priority = $GLOBALS['__filters'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) {
			return $cb;
		}
	}
	return null;
}

$dock_placement_cb = first_filter_cb( 'openstation_dock_placement' );
ok( is_callable( $dock_placement_cb ), 'openstation_dock_placement carries a callable' );
ok( 'hidden' === call_user_func( $dock_placement_cb, 'dock', 'sn-theme-options' ),
	'openstation_dock_placement hides the SN auto-imported menu item, same as the old name' );

$dock_items_via_new = apply_filters( 'openstation_dock_items', array() );
ok( is_array( $dock_items_via_new ) && 1 === count( $dock_items_via_new ) && 'signal-noise' === ( $dock_items_via_new[0]['id'] ?? '' ),
	'openstation_dock_items builds the same single "signal-noise" dock entry as the old name' );

$icon_url_cb = first_filter_cb( 'openstation_plugins_window_icon_url' );
ok( is_callable( $icon_url_cb ), 'openstation_plugins_window_icon_url carries a callable' );
ok( 'https://ps.w.org/fallback/assets/icon.svg' === call_user_func( $icon_url_cb, 'https://ps.w.org/fallback/assets/icon.svg', 'not-us' ),
	'openstation_plugins_window_icon_url passes through unchanged for a foreign slug, same as the old name' );

$GLOBALS['__totals'] = array( '*' => array( 'views' => 42, 'visits' => 10, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$traffic_via_new = apply_filters( 'openstation_living_tree_traffic', 0 );
ok( 42 === $traffic_via_new, 'openstation_living_tree_traffic returns the same live total as the old name' );

echo "\n── v10.43.0: the appendix guard avoids compounding under a hypothetical double-fire ──\n";
// The real callsite fires this filter TWICE per ordinary request (primary
// run + composed-reply leg), each starting from a FRESH $appendix — so a
// naive once-per-request flag would wrongly suppress the second legitimate
// call. The content-marker guard instead only trips when OUR OWN text is
// already present in the SAME $appendix value being threaded through.
$fresh_call_1 = apply_filters( 'desktop_mode_ai_system_prompt_appendix', '' );
$fresh_call_2 = apply_filters( 'openstation_ai_system_prompt_appendix', '' ); // a second, independent, FRESH call
ok( $fresh_call_1 === $fresh_call_2,
	'two independent fresh calls (as real per-request usage does) each get the full vocabulary appendix — the guard does not remember across separate $appendix values' );

// A hypothetical double-fire for the SAME event threads the SAME $appendix
// value through both hook names in sequence — that must not double the text.
$double_fired = apply_filters( 'desktop_mode_ai_system_prompt_appendix', '' );
$double_fired = apply_filters( 'openstation_ai_system_prompt_appendix', $double_fired );
ok( substr_count( $double_fired, 'Signal & Noise analytics vocabulary.' ) === 1,
	'threading the SAME appendix value through both hook names appends the vocabulary marker exactly ONCE, not twice' );

echo "\n── REJECT #11 LOW: the appendix filter is future-proofed with accepted_args=2 ──\n";
// search.php:1594 — apply_filters( 'openstation_ai_system_prompt_appendix', '', $ctx_for_filter )
// — a 2nd arg this callback doesn't use TODAY but cheaply future-proofs
// against needing it later without a signature-registration change.
ok( 2 === ( $GLOBALS['__filter_args']['desktop_mode_ai_system_prompt_appendix'] ?? null ),
	'the pre-rename appendix hook is registered with accepted_args=2' );
ok( 2 === ( $GLOBALS['__filter_args']['openstation_ai_system_prompt_appendix'] ?? null ),
	'the post-#475 appendix hook is ALSO registered with accepted_args=2' );

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
	'sn-uptime'          => 'desktop-mode-widget-uptime.js',
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

// ── v10.1.0: the Machine Readers tile (owner rule: new surfaces get a DM
// surface where earned — this one is earned; the readership data is a glance).
$mr_js = strip_js_comments( (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-machine-readers.js' ) );
ok( false !== strpos( $mr_js, "window.desktopModeWidgets['sn-machine-readers']" ), 'tile assigns the PHP-declared mount global (not wp.desktop.registerWidget)' );
ok( false !== strpos( $mr_js, 'return function teardown' ), 'tile returns a teardown' );
ok( false !== strpos( $mr_js, 'AbortController' ), 'tile aborts its fetch on teardown (the site-views precedent)' );
ok( false === strpos( $mr_js, 'innerHTML' ), 'tile never uses innerHTML — worker-derived strings reach the DOM as text only' );
ok( false !== strpos( $mr_js, '/signal-noise/v1/desktop/machine-readers' ), 'tile reads its own desktop route, not the localize' );
// v10.27.0: the additive ai_surfaces field (per-surface split for AI-training
// families). The tile renders it when present but MUST NOT assume it exists —
// an older cached payload or a widget build that predates the field has to
// keep working, so the render path guards on presence, never indexes blind.
ok( false !== strpos( $mr_js, 'ai_surfaces' ), 'tile reads payload.ai_surfaces' );
ok(
	1 === preg_match( '/payload\.ai_surfaces\s*&&\s*payload\.ai_surfaces\.length/', $mr_js )
		|| 1 === preg_match( '/payload\.ai_surfaces\s*\|\|\s*\[\s*\]/', $mr_js ),
	'the ai_surfaces render is guarded (truthy + length check, or a safe default), not a blind index'
);
$dm_src = dm_integration_src();
ok( false !== strpos( $dm_src, "'/desktop/machine-readers'" ), 'the desktop route is registered' );
ok( false !== strpos( $dm_src, "'machine_readers' => snt_desktop_admin_url" ), 'the pages map carries the tab link for the tile footer' );

echo "\n── Machine Readers tile: Sensor section gone, Purposes in, crawler drift stays loud ──\n";
// Version now lives on Deploy Status's Rights signals row. Rendering it here
// is duplication. The crawler-list verdict must NOT go blind: one amber line
// only when the verdict is not the healthy 'in sync' (the three verdicts
// the builder emits are 'in sync' | 'drift' | 'check failed', or null).
ok( false === strpos( $mr_js, "section( 'Sensor' )" ), 'tile no longer paints a Sensor section (version lives on Deploy Status)' );
ok( false === strpos( $mr_js, "'Version'" ), 'tile no longer renders the sensor Version row' );
ok( false !== strpos( $mr_js, "section( 'Purposes' )" ), 'tile renders a Purposes section from payload.purposes' );
ok( false !== strpos( $mr_js, "section( 'Top families' )" ), 'Top families is unchanged' );
ok( false !== strpos( $mr_js, "section( 'Declared AI-training' )" ), 'Declared AI-training is unchanged' );
ok(
	1 === preg_match( '/payload\.purposes\s*&&\s*payload\.purposes\.length/', $mr_js ),
	'Purposes is guarded on a truthy non-empty array — null (never-measured) paints no heading'
);
ok( false !== strpos( $mr_js, '.slice( 0, 4 )' ) || false !== strpos( $mr_js, '.slice(0, 4)' ) || false !== strpos( $mr_js, '.slice(0,4)' ),
	'Purposes is capped at 4 rows — a tile is a glance' );
ok( false !== strpos( $mr_js, 'crawler_list' ), 'tile still reads payload.crawler_list — drift must not go blind' );
ok( false !== strpos( $mr_js, "'in sync'" ), 'the healthy crawler-list verdict is the literal the builder emits' );
ok( false !== strpos( $mr_js, 'color:#d29922' ), 'the crawler-list warning reuses the existing amber warning idiom' );

echo "\n── Site Views tile: forecast gone, Top pages list of 3 ──\n";
$views_js = strip_js_comments( (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-views.js' ) );
ok( false === strpos( $views_js, 'function forecastBlock' ), 'forecastBlock is gone — the interval cried wolf on this site\'s volumes' );
ok( false === strpos( $views_js, "'Next 7 days'" ), 'the Next 7 days heading is gone with the forecast block' );
ok( false !== strpos( $views_js, "'Top pages'" ), 'tile renders a Top pages list' );
ok( false !== strpos( $views_js, 'payload.top_paths' ), 'tile reads the additive top_paths array' );
ok( false !== strpos( $views_js, 'payload.top_path' ), 'tile still knows the old single top_path key (cached-payload fallback)' );
ok(
	1 === preg_match( '/payload\.top_paths\s*&&\s*payload\.top_paths\.length/', $views_js ),
	'top_paths render is guarded — an older cached payload without the array falls through'
);
ok( false === strpos( $views_js, 'forecastBlock( payload.forecast )' ),
	'the widget no longer calls forecastBlock on payload.forecast — the producer still ships the key' );

echo "\n── v10.43.0: the OpenStation compat prelude aliases both surfaces ──\n";
$compat_path = __DIR__ . '/../assets/desktop-mode-os-compat.js';
ok( file_exists( $compat_path ), 'assets/desktop-mode-os-compat.js exists' );
$compat_js = strip_js_comments( (string) file_get_contents( $compat_path ) );
ok( false !== strpos( $compat_js, 'window.desktopModeWidgets = widgets' ) && false !== strpos( $compat_js, 'window.openStationWidgets = widgets' ),
	'the widget-mount registry is aliased onto the SAME object under both names' );
ok( false !== strpos( $compat_js, 'window.wp.desktop = window.wp.os' ) && false !== strpos( $compat_js, 'window.wp.os = window.wp.desktop' ),
	'the public API namespace is aliased both directions (whichever the shell installed wins)' );

echo "\n── v10.43.0: the file-drop JS filter is dual-registered ──\n";
$dropzone_js = strip_js_comments( (string) file_get_contents( __DIR__ . '/../assets/desktop-dropzone.js' ) );
ok( false !== strpos( $dropzone_js, "addFilter( 'desktop-mode.drop.files-detected'" ),
	'desktop-dropzone.js registers the pre-rename JS filter name' );
ok( false !== strpos( $dropzone_js, "addFilter( 'os.drop.files-detected'" ),
	'desktop-dropzone.js ALSO registers the post-#475 JS filter name (os.drop.files-detected — src/os-file-drop/hooks.ts:19)' );
ok( false !== strpos( $dropzone_js, 'WeakSet' ),
	'desktop-dropzone.js guards against a hypothetical double-fire delivering the same files array to both names' );

echo "\n── REJECT #11 MEDIUM: every widget file is self-sufficient — no reliance on the external prelude having run first ──\n";
// openstation_resolve_script_payload() (upstream payload.php:1371-1449)
// resolves only the handle's own src, never walks deps; server-sync/
// command-sync inject one bare <script src="..."> tag per URL. A mid-session
// shell activation under a post-rename shell can therefore load a widget
// file BEFORE assets/desktop-mode-os-compat.js ever runs, leaving
// window.openStationWidgets (the global upstream actually reads) empty even
// though the widget wrote to window.desktopModeWidgets. Each widget file
// must alias both globals onto the SAME object ITSELF, not depend on the
// external prelude.
foreach ( array_keys( $sn_widget_js ) as $file ) {
	$code = strip_js_comments( file_get_contents( __DIR__ . '/../assets/' . $file ) );
	ok( strpos( $code, 'window.desktopModeWidgets = window.desktopModeWidgets || {};' ) === false,
		"$file no longer uses the order-dependent single-name prologue" );
	ok( strpos( $code, 'window.openStationWidgets' ) !== false,
		"$file's own prologue aliases window.openStationWidgets — self-sufficient, does not rely on the external prelude" );
	ok( strpos( $code, 'window.desktopModeWidgets' ) !== false,
		"$file still writes window.desktopModeWidgets too (pre-rename line stays working)" );
}

echo "\n── REJECT #11 MEDIUM: desktop-mode.js's gate is self-sufficient under EITHER naming family ──\n";
// assets/desktop-mode.js:23's gate previously required window.wp.desktop
// specifically. Under a post-rename shell where the external compat prelude
// hasn't run yet (same lazy-injection gap as the widgets above), only
// window.wp.os exists and the whole file no-ops — every Cmd+K command dead
// until reload. The gate must accept EITHER name and self-alias so the
// unchanged window.wp.desktop.* call sites below it keep working.
$dm_gate_js = strip_js_comments( (string) file_get_contents( __DIR__ . '/../assets/desktop-mode.js' ) );
ok( strpos( $dm_gate_js, 'window.wp.os' ) !== false,
	'desktop-mode.js references window.wp.os — the gate no longer requires wp.desktop exclusively' );
ok( strpos( $dm_gate_js, 'window.wp.desktop = window.wp.desktop || window.wp.os' ) !== false
	|| 1 === preg_match( '/window\.wp\.desktop\s*=\s*window\.wp\.desktop\s*\|\|\s*window\.wp\.os/', $dm_gate_js ),
	'desktop-mode.js self-aliases window.wp.desktop from window.wp.os when only the post-#475 name exists — self-sufficient, not order-dependent on the external prelude' );

echo "\n── REJECT #11 LOW: the REST icon-URL belt dual-writes BOTH field keys ──\n";
// Desktop Mode's REST field KEY is 'desktop_mode_icon_url'; post-#475
// OpenStation renames the field itself to 'openstation_icon_url' — distinct
// from the 'desktop_mode_plugins_window_icon_url' FILTER dual-registered
// above, which feeds the field's own get_callback (its VALUE) but cannot
// rename the JSON KEY the REST response actually carries. The belt below
// only ever wrote the pre-rename key, so on a post-#475 install its
// "ALWAYS override" promise silently did nothing to the field the response
// actually returns.
if ( ! defined( 'SN_GH_PLUGIN_BASENAME' ) ) {
	define( 'SN_GH_PLUGIN_BASENAME', 'signal-and-noise-tools/signal-and-noise-tools.php' );
}
$icon_belt_cb = first_filter_cb( 'rest_prepare_plugin' );
ok( is_callable( $icon_belt_cb ), 'the rest_prepare_plugin icon-URL belt carries a callable' );

$item_ours = array( '_file' => SN_GH_PLUGIN_BASENAME );

$resp_dm = new WP_REST_Response( array( 'desktop_mode_icon_url' => 'https://ps.w.org/wrong/assets/icon.svg' ) );
call_user_func( $icon_belt_cb, $resp_dm, $item_ours, null );
$data_dm = $resp_dm->get_data();
ok( isset( $data_dm['desktop_mode_icon_url'] ) && false === strpos( $data_dm['desktop_mode_icon_url'], 'ps.w.org' ),
	'the pre-rename REST field key (desktop_mode_icon_url) is overridden to our canonical URL' );

$resp_os = new WP_REST_Response( array( 'openstation_icon_url' => 'https://ps.w.org/wrong/assets/icon.svg' ) );
call_user_func( $icon_belt_cb, $resp_os, $item_ours, null );
$data_os = $resp_os->get_data();
ok( isset( $data_os['openstation_icon_url'] ) && false === strpos( $data_os['openstation_icon_url'], 'ps.w.org' ),
	'REJECT #11 LOW: the post-#475 REST field key (openstation_icon_url) is ALSO overridden — the belt dual-writes both keys' );

echo "\n── v10.43.1 (REJECT #12): the inline DOM patch is REMOVED, not re-selectored ──\n";
// f2faa4b "fixed" the wp.org button hider's selector (a[href*=…] →
// wpd-button/os-button). Adversarial review proved that was ALSO dead: the
// Installed-view detail panel — button AND Name cells — renders inside an
// OPEN shadow root (WordPress/openstation src/ui/core/component.ts:88,
// wpd-table.ts:1404-1433), which a document.body-scoped querySelectorAll()
// cannot traverse and whose internal mutations a document.body-scoped
// MutationObserver never sees. The honest fix is removal, not a third
// selector. Fire the hook and prove nothing prints.
ob_start();
fire( 'admin_print_footer_scripts' );
$footer_html = ob_get_clean();
ok( false === strpos( $footer_html, 'id="sn-desktop-mode-installed-view-patch"' ),
	'the inline patch script no longer prints — admin_print_footer_scripts was removed wholesale' );
ok( '' === trim( $footer_html ), 'admin_print_footer_scripts now prints NOTHING for this surface' );

// Absence pins against the SOURCE FILE, not just the (now-empty) hook
// output — a re-add anywhere else in the file must still fail these.
ok( false === strpos( $dm_src, "querySelectorAll('wpd-button, os-button')" ),
	'the wpd-button/os-button custom-element loop stays gone — it was shadow-DOM unreachable, never a working fix' );
// NOT a bare `strpos($dm_src, 'a[href*="wordpress.org')` pin here: the
// replacement docblock above intentionally NAMES that original dead selector
// in prose, as history — a substring pin would collide with its own
// documentation. "no <script> tag prints" (above) already proves no code
// executes it; `.wpd-button` below stays a real pin because nothing in the
// new docblock's prose needs that exact class-selector spelling.
ok( false === strpos( $dm_src, '.wpd-button' ),
	'the old ".wpd-button" CLASS selector stays gone — wpd-button was always a TAG name, never a class' );
ok( false === strpos( $dm_src, "indexOf('WordPress.org')" ),
	'the visible-label match is gone — it shared the same document.body-scoped, shadow-blind query' );
ok( false === strpos( $dm_src, 'dataset.snHidden' ),
	'the hidden-button idempotency marker is gone with the loop it guarded' );
ok( false === strpos( $dm_src, 'new MutationObserver' ),
	'the document.body-scoped MutationObserver is gone — it could never see mutations inside the shadow root either' );

// The DOM Name-decode half chased the SAME shadow-rooted table (Name cells
// render alongside the button, same shadow root) — equally unreachable, and
// never the working fix. Removed too, rather than left running against text
// nodes it can never see.
ok( false === strpos( $dm_src, "LITERAL = 'Signal &amp; Noise Tools'" ),
	'the DOM Name-decode LITERAL constant is gone — it targeted the same unreachable shadow-rooted table' );
ok( false === strpos( $dm_src, "DECODED = 'Signal & Noise Tools'" ),
	'the DOM Name-decode DECODED constant is gone too' );
ok( false === strpos( $dm_src, 'n.textContent===LITERAL' ),
	'the DOM Name-decode leaf-node match-and-replace is gone' );
ok( false === strpos( $dm_src, "SLUG = 'signal-and-noise-tools'" ),
	'the unused SLUG var stays removed' );

echo "\n── v10.43.1: rest_prepare_plugin is the ONLY surviving Name/icon fix — pinned directly for the first time ──\n";
// This filter (inc/desktop-mode-integration.php, since v2.1.6/v2.1.7) was
// ALWAYS the working fix — it edits the REST payload on the wire, before
// Desktop Mode ever renders into its shadow-rooted table, so there is
// nothing for a DOM patch to reach or defend. It had zero coverage anywhere
// in this repo until now; with the dead DOM patch gone, it is the entire
// fix for this surface and earns real pins.
ok( isset( $GLOBALS['__filters']['rest_prepare_plugin'][10][0] ),
	'rest_prepare_plugin is registered at priority 10' );
$rpp_cb = $GLOBALS['__filters']['rest_prepare_plugin'][10][0];
ok( is_callable( $rpp_cb ), 'the rest_prepare_plugin callback is callable' );

// SN_GH_PLUGIN_BASENAME is already defined by the REST icon-URL belt block
// above (guarded define) — this section reuses the same constant.

// Wrong basename — scoping test. Must pass through completely untouched.
$other_res = new WP_REST_Response( array( 'name' => 'Foo &amp; Bar' ) );
$other_out = $rpp_cb( $other_res, array( '_file' => 'some-other-plugin/some-other-plugin.php' ), null );
ok( $other_out->get_data()['name'] === 'Foo &amp; Bar',
	'a DIFFERENT plugin\'s Name is left encoded/untouched — the filter is strictly basename-scoped' );
ok( $other_out->set_data_calls === 0, 'an out-of-scope response is never even written back' );

// Our plugin, dirty on every field: encoded Name, encoded Author, and an
// icon_url upstream already populated with ITS OWN (wrong-for-us) guess.
$item     = array( '_file' => SN_GH_PLUGIN_BASENAME );
$dirty_res = new WP_REST_Response( array(
	'name'                  => 'Signal &amp; Noise Tools',
	'author'                => 'Juan &amp; Team',
	'desktop_mode_icon_url' => 'https://ps.w.org/some-stale/icon.svg',
) );
$fixed = $rpp_cb( $dirty_res, $item, null );
$data  = $fixed->get_data();
ok( $data['name'] === 'Signal & Noise Tools',
	'our plugin\'s Name is decoded — the WORKING fix, never the removed DOM patch' );
ok( $data['author'] === 'Juan & Team', 'our plugin\'s Author is decoded too — same wp_kses-encoding path' );
ok( $data['desktop_mode_icon_url'] === plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' ),
	'desktop_mode_icon_url is unconditionally overridden with our own canonical icon (v2.1.7 always-override fix), even though upstream had already populated it' );
ok( $fixed->set_data_calls === 1, 'a dirty response is written back exactly once' );

// Already-clean response (nothing encoded, icon already canonical) — the
// dirty flag must skip the write entirely, not just happen to look right.
// v10.43.0 dual-writes BOTH the pre-rename and post-#475 icon-URL keys
// (see the REJECT #11 LOW belt test above) — a truly clean fixture must
// carry both already-canonical, or the openstation_icon_url half alone
// trips the dirty flag and this pin goes false-negative.
$canonical_icon = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
$clean_res = new WP_REST_Response( array(
	'name'                  => 'Signal & Noise Tools',
	'desktop_mode_icon_url' => $canonical_icon,
	'openstation_icon_url'  => $canonical_icon,
) );
$clean_out = $rpp_cb( $clean_res, $item, null );
ok( $clean_out->get_data()['name'] === 'Signal & Noise Tools', 'an already-clean Name round-trips unchanged' );
ok( $clean_out->set_data_calls === 0, 'an already-clean response is never re-written — the dirty flag prevents a wasted set_data() call' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
