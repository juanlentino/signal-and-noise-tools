<?php
/**
 * Shared ability run client (v7.7.2) — verb map + transport guard.
 *
 * The abilities run controller enforces HTTP verb by annotation
 * (validate_request_method: readonly => GET, destructive+idempotent => DELETE,
 * else POST) and, for GET/DELETE, reads the RAW `input` query param — a JSON
 * string fails rest_validate_value_from_schema against object schemas, but
 * PHP bracket syntax (input[key]=value) arrives as a decoded array and
 * validates. The v6.39.2 audit fixed the ANNOTATIONS to be truthful but never
 * migrated the JS callers' verbs, and v7.7.0 repeated the class (the
 * force-check 405 the owner hit). This module ends the class:
 *
 *   - PHP (inc/ability-run-client.php): snt_ability_verb() derives the verb
 *     from annotations exactly like the controller; snt_ability_verb_map()
 *     builds slug→verb from the LIVE registry; the map is localized onto the
 *     'snt-ability-run' script so client verbs can never drift from server
 *     annotations again.
 *   - JS (assets/snt-ability-run.js): window.sntAbilityRun( slug, input )
 *     picks the verb from the map, sends POST input as JSON body and
 *     GET/DELETE input as bracket-encoded query params.
 *   - GUARD: '/wp-abilities/' may appear ONLY in assets/snt-ability-run.js.
 *     Every other script calls sntAbilityRun with a slug. A raw apiFetch with
 *     a hardcoded verb is exactly the drift vector this kills.
 *
 * @since 7.7.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ────────────────────────────────────────────────────────
$GLOBALS['__test_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
function __( $s, $d = null ) { return $s; }

// Minimal ability object mirroring WP_Ability's accessors used by the map.
class SN_Test_Ability {
	private $name; private $meta;
	public function __construct( $name, $meta ) { $this->name = $name; $this->meta = $meta; }
	public function get_name() { return $this->name; }
	public function get_meta() { return $this->meta; }
}
$GLOBALS['__test_abilities'] = array();
function wp_get_abilities() { return $GLOBALS['__test_abilities']; }

// ─── Load the SUT ────────────────────────────────────────────────────
$module = __DIR__ . '/../inc/ability-run-client.php';
$module_exists = file_exists( $module );
if ( $module_exists ) {
	require_once $module;
}
$runner_js = __DIR__ . '/../assets/snt-ability-run.js';

// ─── Harness ─────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function t( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}
function t_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

echo "Ability run client suite — plugin v7.7.2\n";

// ════ Group A: verb derivation mirrors the run controller ════════════
echo "\nGroup A: snt_ability_verb (mirror of validate_request_method)\n";
t( $module_exists, 'A.1 inc/ability-run-client.php exists' );
if ( function_exists( 'snt_ability_verb' ) ) {
	t_eq( 'GET', snt_ability_verb( array( 'readonly' => true ) ), 'A.2 readonly → GET' );
	t_eq( 'GET', snt_ability_verb( array( 'readonly' => true, 'destructive' => true, 'idempotent' => true ) ), 'A.3 readonly wins over destructive+idempotent (controller checks readonly first)' );
	t_eq( 'DELETE', snt_ability_verb( array( 'destructive' => true, 'idempotent' => true ) ), 'A.4 destructive + idempotent → DELETE' );
	t_eq( 'POST', snt_ability_verb( array( 'destructive' => true, 'idempotent' => false ) ), 'A.5 destructive alone → POST' );
	t_eq( 'POST', snt_ability_verb( array( 'destructive' => true ) ), 'A.6 destructive without idempotent key → POST' );
	t_eq( 'POST', snt_ability_verb( array( 'idempotent' => true ) ), 'A.7 idempotent alone → POST' );
	t_eq( 'POST', snt_ability_verb( array() ), 'A.8 no annotations → POST' );
	t_eq( 'POST', snt_ability_verb( array( 'readonly' => false ) ), 'A.9 readonly=false → POST (empty() semantics like the controller)' );
} else {
	for ( $i = 2; $i <= 9; $i++ ) { t( false, "A.$i snt_ability_verb available" ); }
}

// ════ Group B: verb map from the live registry ═══════════════════════
echo "\nGroup B: snt_ability_verb_map\n";
$GLOBALS['__test_abilities'] = array(
	'signal-noise/read-thing'   => new SN_Test_Ability( 'signal-noise/read-thing', array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ) ),
	'signal-noise/delete-thing' => new SN_Test_Ability( 'signal-noise/delete-thing', array( 'annotations' => array( 'destructive' => true, 'idempotent' => true ) ) ),
	'signal-noise/write-thing'  => new SN_Test_Ability( 'signal-noise/write-thing', array( 'annotations' => array( 'idempotent' => false ) ) ),
	'signal-noise/bare-thing'   => new SN_Test_Ability( 'signal-noise/bare-thing', array() ),
	'other-plugin/foreign'      => new SN_Test_Ability( 'other-plugin/foreign', array( 'annotations' => array( 'readonly' => true ) ) ),
);
if ( function_exists( 'snt_ability_verb_map' ) ) {
	$map = snt_ability_verb_map();
	t_eq( 'GET', $map['signal-noise/read-thing'] ?? null, 'B.1 readonly ability mapped to GET' );
	t_eq( 'DELETE', $map['signal-noise/delete-thing'] ?? null, 'B.2 destructive+idempotent mapped to DELETE' );
	t_eq( 'POST', $map['signal-noise/write-thing'] ?? null, 'B.3 mutating ability mapped to POST' );
	t_eq( 'POST', $map['signal-noise/bare-thing'] ?? null, 'B.4 no-annotations ability mapped to POST (missing meta tolerated)' );
	t( ! isset( $map['other-plugin/foreign'] ), 'B.5 foreign namespaces excluded (we only vouch for our own)' );
} else {
	for ( $i = 1; $i <= 5; $i++ ) { t( false, "B.$i snt_ability_verb_map available" ); }
}

// ════ Group C: script registration wiring ════════════════════════════
echo "\nGroup C: registration hooks\n";
t( isset( $GLOBALS['__test_actions']['admin_enqueue_scripts'] ) && count( $GLOBALS['__test_actions']['admin_enqueue_scripts'] ) >= 1, 'C.1 registers on admin_enqueue_scripts' );
t( isset( $GLOBALS['__test_actions']['enqueue_block_editor_assets'] ) && count( $GLOBALS['__test_actions']['enqueue_block_editor_assets'] ) >= 1, 'C.2 registers on enqueue_block_editor_assets (the ai-* editor buttons ride there)' );
t( file_exists( $runner_js ), 'C.3 assets/snt-ability-run.js exists' );

// ════ Group D: transport guard — no raw run-path callers ═════════════
// '/wp-abilities/' may appear ONLY in the runner. A consumer hardcoding the
// path (and therefore a verb) is the exact drift vector that produced the
// v6.39.2→v7.7.x 405 class: annotations changed server-side, clients kept
// their frozen verbs. Slugs-only call sites cannot drift.
echo "\nGroup D: assets/*.js transport guard\n";
$offenders = array();
foreach ( glob( __DIR__ . '/../assets/*.js' ) as $js ) {
	$base = basename( $js );
	if ( 'snt-ability-run.js' === $base ) {
		continue;
	}
	if ( false !== strpos( (string) file_get_contents( $js ), '/wp-abilities/' ) ) {
		$offenders[] = $base;
	}
}
t( array() === $offenders, 'D.1 /wp-abilities/ appears only in snt-ability-run.js' . ( $offenders ? ' (offenders: ' . implode( ', ', $offenders ) . ')' : '' ) );

// Every consumer that runs abilities does it through the runner.
$consumers = array(
	'command-palette.js', 'desktop-mode.js', 'desktop-mode-widget.js',
	'desktop-mode-widget-actions.js', 'desktop-mode-widget-rss.js',
	'cron-dashboard.js', 'health-suggest-actions.js', 'ai-excerpt.js',
	'ai-meta-description.js', 'ai-og-card-title.js', 'prepop-notice.js',
);
foreach ( $consumers as $base ) {
	$src = (string) file_get_contents( __DIR__ . '/../assets/' . $base );
	t( false !== strpos( $src, 'sntAbilityRun' ), "D.2 $base dispatches via sntAbilityRun" );
}

// The runner itself: bracket-encodes GET/DELETE input (never a JSON string —
// the controller returns the raw query param and a string fails object
// schemas), JSON body for POST.
$runner_src = file_exists( $runner_js ) ? (string) file_get_contents( $runner_js ) : '';
t( false !== strpos( $runner_src, 'input[' ), 'D.3 runner bracket-encodes query input for GET/DELETE' );
t( false === strpos( $runner_src, 'JSON.stringify( input )' ) && false === strpos( $runner_src, 'JSON.stringify(input)' ), 'D.4 runner never sends input as a JSON query string' );

// v8.0.4: the v7.7.1 audit's noted fragility — the audit-summary toast used
// pct_delta bare (every sibling field carries a || 0 fallback), so a
// degenerate summary payload rendered "undefined%". Contract: the toast
// derives a numeric pct with a fallback before concatenating the % sign.
$dm_src = (string) file_get_contents( __DIR__ . '/../assets/desktop-mode.js' );
t( false !== strpos( $dm_src, 'Number( s.last_7d_vs_prior.pct_delta ) || 0' ), 'D.5 audit-summary toast derives pct with a numeric fallback' );
t( false === strpos( $dm_src, "s.last_7d_vs_prior.pct_delta + '%" ), 'D.6 no bare pct_delta concatenation remains (the undefined% path)' );

// v13.36.0: OpenStation's shell ⌘K replays palette contributors into the
// shell document; upstream's all_deps() bails wholesale when ANY site
// contributor has an unregistered dep, replaying us without our chain. The
// palette script must therefore guard the runner global LOUDLY (a named
// throw reaches openstation#712's "Command /x failed:" surface) — and the
// guard must sit BEFORE the call, not after it.
$cp_src   = (string) file_get_contents( __DIR__ . '/../assets/command-palette.js' );
$guard_at = strpos( $cp_src, "typeof window.sntAbilityRun !== 'function'" );
$call_at  = strpos( $cp_src, 'window.sntAbilityRun( name, input )' );
t( false !== $guard_at && false !== $call_at && $guard_at < $call_at, 'D.7 command-palette.js guards the runner global before calling it' );
t( false !== strpos( $cp_src, 'snt-ability-run.js did not load' ), 'D.8 the guard names the missing sibling script in its error' );

// 15.7.1: a POST always carries an input object. The runner used to drop an
// empty {} (no body at all), so the controller validated null and every
// write ability typed plain 'object' with no required list refused the call
// ("input is not of type object" — the SN Anchors widget's Sweep now,
// 2026-09-17). Group E in abilities-categories.php exempted write abilities
// on the premise that POST always carries a body; this pin makes the
// premise true instead of assumed.
t( false !== strpos( $runner_src, "data = { input: hasInput ? input : {} };" ), 'D.9 runner sends an input object on every POST, {} when the caller gave none' );
t( false === strpos( $runner_src, "if ( hasInput ) {\n\t\t\tif ( 'POST' === verb )" ), 'D.10 the POST body is no longer gated on a non-empty input' );

// 15.8.1: the run-path hands an ability's output back AS IS. A widget that
// reads `res.data` sees undefined unless the ability wraps itself. The queue
// widget must read the bare payload (and tolerate the wrapped one).
$qw_src = (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-queue.js' );
t( false !== strpos( $qw_src, "typeof res.data === 'object' ? res.data : res" ), 'D.11 the queue widget reads the bare run-path payload, wrapped or not' );
t( false !== strpos( $qw_src, "Array.isArray( data.next )" ), 'D.12 the queue widget recognises the payload by its own keys, not by an envelope' );

// ════ Group E: the transport is the shell's fetch inside the station (#1601) ═
// Through wp.apiFetch a native window's ability calls were invisible to the
// shell: the title bar's status ring never moved for Suggest or Apply, the
// 401/403 fast path never saw the response. wp.os.fetch (Stable) is "every
// HTTP call from a plugin"; the classic pages and the editor iframe have no
// shell, so wp.apiFetch stays behind a typeof guard.
echo "\nGroup E: wp.os.fetch inside the station, wp.apiFetch elsewhere (#1601)\n";
t( false !== strpos( $runner_src, 'window.wp.os.fetch(' ) && false !== strpos( $runner_src, 'window.wp.apiFetch(' ), 'E.1 the runner sends through wp.os.fetch and still through wp.apiFetch' );
t( false !== strpos( $runner_src, "'function' === typeof window.wp.os.fetch" ), 'E.2 the shell seam is a typeof guard (the same script loads on classic pages)' );
t( 1 === preg_match( '/silent: !! \( options && options\.silent \)/', $runner_src ), 'E.3 options.silent is forwarded, so a timer never lights a window the owner did not touch' );
t( false !== strpos( $runner_src, 'if ( ! res.ok ) { throw body; }' ) && false !== strpos( $runner_src, 'res.json()' ), 'E.4 the shell branch keeps wp.apiFetch\'s contract: parsed JSON resolves, the parsed WP_Error body rejects' );
t( false !== strpos( $runner_src, "cfg.root || '/wp-json/'" ) && 1 === preg_match( "/-1 === ROOT\.indexOf\( '\?' \) \? '\?' : '&'/", $runner_src ), 'E.5 the URL is built on the localized rest_url() root and joins a query with & when the root already carries ? (plain permalinks)' );

// The localizer hands the runner rest_url() beside the verb map.
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', __DIR__ . '/../' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '0.0.0' ); }
if ( ! function_exists( 'wp_script_is' ) ) { function wp_script_is( $h, $l = 'enqueued' ) { return false; } }
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script() { return true; } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $p = '', $f = '' ) { return 'https://example.test/wp-content/plugins/x/' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $h, $n, $d ) { $GLOBALS['__test_localized'][ $h ][ $n ] = $d; return true; } }
$GLOBALS['__test_localized'] = array();
if ( function_exists( 'snt_ability_run_client_register' ) ) {
	snt_ability_run_client_register();
}
$l10n = $GLOBALS['__test_localized']['snt-ability-run']['sntAbilityRunData'] ?? array();
t_eq( 'https://example.test/wp-json/', $l10n['root'] ?? null, 'E.6 sntAbilityRunData carries rest_url() as root (openStationConfig.restUrl is the same value; the runner stays shell-agnostic)' );
t( is_array( $l10n['verbs'] ?? null ), 'E.7 the verb map still rides the same object' );

// Every widget that refreshes on a timer or at mount passes silent: true. A
// desktop widget is not a window; through the focused-window default its
// poll would breathe the ring of whatever window the owner last clicked.
// Pinned per slug: a file-wide regex could not tell which of the anchors
// widget's two calls carried the flag (the click-driven sweep must not).
$silent_calls = array(
	'desktop-mode-widget.js'         => "window.sntAbilityRun( 'get-deploy-status', undefined, { signal: controller ? controller.signal : undefined, silent: true } )",
	'desktop-mode-widget-uptime.js'  => "window.sntAbilityRun( 'uptime-status', { detail: true }, { signal: controller ? controller.signal : undefined, silent: true } )",
	'desktop-mode-widget-rss.js'     => "window.sntAbilityRun( 'get-rss-stats', undefined, { silent: true } )",
	'desktop-mode-widget-queue.js'   => "window.sntAbilityRun( 'content-queue', undefined, { silent: true } )",
	'desktop-mode-widget-cache.js'   => "window.sntAbilityRun( 'cache-freshness', undefined, { silent: true } )",
	'desktop-mode-widget-anchors.js' => "window.sntAbilityRun( 'anchor-status', {}, { silent: true } )",
);
foreach ( $silent_calls as $base => $call ) {
	$src = (string) file_get_contents( __DIR__ . '/../assets/' . $base );
	t( false !== strpos( $src, $call ), "E.8 $base passes silent: true on its background refresh call" );
}
t( false !== strpos( (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-anchors.js' ), "window.sntAbilityRun( 'anchor-sweep', {} )" ), 'E.8b the anchors Sweep, a click, stays loud (no silent flag)' );

// A refused response whose body is not JSON (an HTML 503 from Varnish, a
// challenge page, the WAF's 403 page) rejected with a raw SyntaxError, code
// undefined; apiFetch normalizes it to code invalid_json. The catch sits on
// res.json() itself, before the ok test, so a JSON WP_Error is never masked.
t( false !== strpos( $runner_src, 'res.json().catch( function () {' ) && false !== strpos( $runner_src, "throw { code: 'invalid_json', message: 'The response is not a valid JSON response.', data: { status: res.status } };" ), 'E.9 a non-JSON body rejects as apiFetch does (code invalid_json) and carries the HTTP status' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
