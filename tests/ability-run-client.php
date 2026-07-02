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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
