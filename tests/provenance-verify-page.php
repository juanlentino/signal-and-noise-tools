<?php
/**
 * Standalone harness for the /provenance/verify page seed + retrofit
 * migration. Mirrors the idiom in tests/provenance-render.php: function_exists-
 * guarded WP stubs backed by $GLOBALS, run via `php tests/provenance-verify-page.php`.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', 'test' );
}

$GLOBALS['__pv_options']     = array();
$GLOBALS['__pv_pages']       = array(); // path => page object ( ->ID, ->post_content )
$GLOBALS['__pv_inserts']     = 0;       // wp_insert_post call count
$GLOBALS['__pv_last_insert'] = null;    // last wp_insert_post args

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__pv_options'][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
		return $GLOBALS['__pv_pages'][ $path ] ?? null; }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	// Record the insert; return a fixed synthetic ID. Tests seed existing
	// pages manually into __pv_pages to exercise the idempotent path.
	function wp_insert_post( $args = array(), $wp_error = false ) {
		++$GLOBALS['__pv_inserts'];
		$GLOBALS['__pv_last_insert'] = $args;
		return 500; }
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

require_once SNT_PATH . 'inc/content-surfaces.php';
require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0;
$fail = 0;
function vp_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function vp_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance verify-page suite\n\nTask A1: fresh insert\n";
$GLOBALS['__pv_pages']       = array(
	SN_PROVENANCE_SLUG => (object) array( 'ID' => 100, 'post_content' => 'pillar' ),
);
$GLOBALS['__pv_inserts']     = 0;
$GLOBALS['__pv_last_insert'] = null;

$new_id = sn_ensure_verify_page();
vp_eq( 1, $GLOBALS['__pv_inserts'], 'one page inserted when the verify child is absent' );
vp_eq( 500, $new_id, 'returns the new page ID' );
$ins = $GLOBALS['__pv_last_insert'];
vp_eq( SN_VERIFY_SLUG, $ins['post_name'] ?? null, 'inserted page slug is "verify"' );
vp_eq( 100, $ins['post_parent'] ?? null, 'inserted page parent = the provenance page ID' );
vp_eq( 'page-provenance', $ins['page_template'] ?? null, 'inherits the page-provenance sibling template' );
vp_eq( 'publish', $ins['post_status'] ?? null, 'published' );
vp_eq( 'page', $ins['post_type'] ?? null, 'is a page' );
vp_true( false !== strpos( (string) ( $ins['post_content'] ?? '' ), '[sn_provenance_verify]' ), 'body carries the [sn_provenance_verify] shortcode' );
vp_true( '' !== trim( (string) ( $ins['post_excerpt'] ?? '' ) ), 'has a non-empty excerpt' );

echo "\nTask A2: idempotent — existing verify child left untouched\n";
$GLOBALS['__pv_pages'][ SN_PROVENANCE_SLUG . '/' . SN_VERIFY_SLUG ] = (object) array( 'ID' => 200, 'post_content' => 'existing' );
$GLOBALS['__pv_inserts'] = 0;
$existing_id = sn_ensure_verify_page();
vp_eq( 0, $GLOBALS['__pv_inserts'], 'no second insert when the page already exists' );
vp_eq( 200, $existing_id, 'returns the existing page ID' );

echo "\nTask A3: retrofit migration runs once\n";
// Run 1: flag unset, parent present, child absent -> ensures + flags.
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_pages']   = array(
	SN_PROVENANCE_SLUG => (object) array( 'ID' => 100, 'post_content' => 'pillar' ),
);
$GLOBALS['__pv_inserts'] = 0;
sn_migrate_verify_page_seed();
vp_eq( 1, $GLOBALS['__pv_inserts'], 'first run inserts the verify page' );
vp_true( (bool) get_option( SN_PROV_VERIFY_PAGE_MIGR_OPT ), 'migration flag is set after the first run' );

// Run 2: flag now set -> no-op even though the child looks absent again.
$GLOBALS['__pv_pages']   = array(
	SN_PROVENANCE_SLUG => (object) array( 'ID' => 100, 'post_content' => 'pillar' ),
);
$GLOBALS['__pv_inserts'] = 0;
sn_migrate_verify_page_seed();
vp_eq( 0, $GLOBALS['__pv_inserts'], 'second run is a no-op (flag gates it out)' );

echo "\nTask A4: migration bails cleanly when the provenance parent is missing\n";
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_pages']   = array(); // no parent yet
$GLOBALS['__pv_inserts'] = 0;
sn_migrate_verify_page_seed();
vp_eq( 0, $GLOBALS['__pv_inserts'], 'no insert while the parent page is absent' );
vp_true( (bool) get_option( SN_PROV_VERIFY_PAGE_MIGR_OPT ), 'flag still set so we stop scanning every admin_init' );

// ── v9.87.0: the proof-walk section ships in the docket markup ──
$sn_walk_src = (string) file_get_contents( __DIR__ . '/../inc/provenance-verify.php' );
vp_true( false !== strpos( $sn_walk_src, 'data-role="walk"' ), 'proof-walk section present in the docket markup' );
vp_true( false !== strpos( $sn_walk_src, 'data-role="walk-steps"' ), 'walk steps list present' );
vp_true( false !== strpos( $sn_walk_src, 'Proof walk' ), 'walk heading present' );
vp_true( 1 === preg_match( '/<section class="sn-verify-walk"[^>]*hidden/', $sn_walk_src ), 'walk hidden until the docket fills it' );
$sn_walk_js = (string) file_get_contents( __DIR__ . '/../assets/js/prov-verify.js' );
vp_true( false !== strpos( $sn_walk_js, 'renderProofWalk' ) && false !== strpos( $sn_walk_js, 'deriveProofWalk' ), 'docket JS renders the core-derived walk' );

// ── v10.49.0: the verdict band, and the composition it leads ──
// The band must lead the page in the DOM, not just on screen: the previous
// layout put the intake form above every result, and the obvious fix (CSS
// `order`) would desynchronize visual order from focus order. Pin the source
// order instead, so a later "just reorder it in CSS" cannot pass silently.
$sn_v_form_at    = strpos( $sn_walk_src, 'data-role="paste-form"' );
$sn_v_verdict_at = strpos( $sn_walk_src, 'data-role="verdict"' );
vp_true( false !== $sn_v_verdict_at, 'verdict band present in the shell' );
vp_true( false !== $sn_v_verdict_at && $sn_v_verdict_at < $sn_v_form_at, 'verdict band precedes the intake form in the DOM' );
vp_true( 1 === preg_match( '/<section class="sn-verify-verdict"[^>]*hidden/', $sn_walk_src ), 'verdict band hidden until a run starts' );
foreach ( array( 'verdict-word', 'verdict-line', 'verdict-meta', 'tally' ) as $sn_v_role ) {
	vp_true( false !== strpos( $sn_walk_src, 'data-role="' . $sn_v_role . '"' ), "verdict role \"$sn_v_role\" present" );
}
// One tally segment per check, each keyed to the docket row it indexes.
foreach ( array( 'signature', 'content-hash', 'live-match', 'anchor' ) as $sn_v_key ) {
	vp_true(
		1 === preg_match( '/class="sn-verify-tally-seg" data-check="' . preg_quote( $sn_v_key, '/' ) . '"/', $sn_walk_src ),
		"tally segment for \"$sn_v_key\""
	);
}
// The band is derived from the docket on every settle — never written once
// and left to drift out of agreement with the rows beneath it.
vp_true( false !== strpos( $sn_walk_js, 'deriveOverallVerdict' ), 'page JS derives the verdict from the pure core' );
vp_true( false !== strpos( $sn_walk_js, 'paintVerdict' ), 'page JS repaints the band as checks settle' );
$sn_v_core = (string) file_get_contents( __DIR__ . '/../assets/js/prov-verify-core.js' );
vp_true( false !== strpos( $sn_v_core, 'deriveOverallVerdict:' ), 'the core exports deriveOverallVerdict' );

// The compare block gets its OWN form class: three labelled fields pushed
// through .sn-verify-form (a one-field bar) is what made it wrap into a
// stack of orphaned labels.
vp_true( false !== strpos( $sn_walk_src, 'class="sn-verify-cmp-form"' ), 'compare form has its own layout class' );
vp_true( false !== strpos( $sn_walk_src, 'data-role="compare-form"' ), 'compare form keeps its JS hook' );
foreach ( array( 'sn-compare-uid', 'sn-compare-a', 'sn-compare-b' ) as $sn_v_id ) {
	vp_true( false !== strpos( $sn_walk_src, 'id="' . $sn_v_id . '"' ), "compare field #$sn_v_id preserved for prov-verify-diff.js" );
}

// Every class the stylesheet is asked to style must actually be emitted by
// the shell or by one of the two renderers — the exact drift that left the
// compare block unstyled for three minor versions.
$sn_v_css = (string) file_get_contents( __DIR__ . '/../assets/css/prov-verify.css' );
$sn_v_diff_js = (string) file_get_contents( __DIR__ . '/../assets/js/prov-verify-diff.js' );
$sn_v_emitted = $sn_walk_src . $sn_walk_js . $sn_v_diff_js;
foreach (
	array(
		'sn-verify-verdict-word',
		'sn-verify-tally-seg',
		'sn-verify-tab',
		'sn-verify-tab-badge',
		'sn-verify-sec-lede',
		'sn-verify-walk-source',
		'sn-verify-walk-value',
		'sn-verify-cmp-field',
		'sn-verify-compare-diff',
		'sn-verify-compare-label-add',
	) as $sn_v_class
) {
	vp_true( false !== strpos( $sn_v_css, '.' . $sn_v_class ), "stylesheet styles .$sn_v_class" );
	vp_true( false !== strpos( $sn_v_emitted, $sn_v_class ), ".$sn_v_class is actually emitted by the shell or a renderer" );
}

// The diff run classes are CONCATENATED ('sn-verify-compare-' + run.op), so
// no literal class string exists to grep for — the pair has to be checked
// against the op vocabulary the core actually emits. Styling the html tag
// names (-ins) or a guessed -eq instead of the real -add/-same silently
// styles nothing, which is exactly how this block went three versions
// without any rules at all.
vp_true( false !== strpos( $sn_v_diff_js, "'sn-verify-compare-' + run.op" ), 'diff run classes are built from the core op name' );
foreach ( array( 'same', 'del', 'add' ) as $sn_v_op ) {
	vp_true( 1 === preg_match( "/op: '" . $sn_v_op . "'/", $sn_v_core ), "the core emits the \"$sn_v_op\" diff op" );
	vp_true( false !== strpos( $sn_v_css, '.sn-verify-compare-' . $sn_v_op ), "stylesheet styles the \"$sn_v_op\" diff run" );
}
foreach ( array( 'ins', 'eq' ) as $sn_v_ghost ) {
	vp_true( false === strpos( $sn_v_css, '.sn-verify-compare-' . $sn_v_ghost ), "no rule for .sn-verify-compare-$sn_v_ghost (never emitted)" );
}
// The v9.87.0 proof walk styled its rules against a var that was never
// declared, so they fell back to the initial value and drew nothing.
vp_true( false !== strpos( $sn_v_css, '--rule:' ), 'the rule colour custom property is declared, not just referenced' );
vp_true( false === strpos( $sn_v_css, 'var(--sn-verify-rule' ), 'nothing still reads the undeclared --sn-verify-rule' );
// Every custom property the stylesheet READS must also be DECLARED in it —
// the general form of the v9.87.0 bug, not just that one variable's name.
preg_match_all( '/var\(\s*(--[a-z0-9-]+)/i', $sn_v_css, $sn_v_reads );
foreach ( array_unique( $sn_v_reads[1] ) as $sn_v_prop ) {
	vp_true( false !== strpos( $sn_v_css, $sn_v_prop . ':' ), "custom property $sn_v_prop is declared, not only read" );
}
// The disagreement styling reads an attribute the walk renderer must write.
vp_true( false !== strpos( $sn_walk_js, "setAttribute( 'data-source'" ), 'walk renderer mirrors the witness source onto data-source' );

// ── v10.49.0: the section nav ──
$sn_v_tabs_js = (string) file_get_contents( __DIR__ . '/../assets/js/prov-verify-tabs.js' );
vp_true( false !== strpos( $sn_walk_src, 'prov-verify-tabs.js' ), 'the tab script is emitted by the shell' );
vp_true( false !== strpos( $sn_walk_src, 'role="tablist"' ), 'the nav is a real tablist' );

// Every tab must point at a panel that EXISTS and names it back — a dangling
// aria-controls is an unreachable panel that still looks navigable.
preg_match_all( '/role="tab"[^>]*id="([^"]+)"[^>]*aria-controls="([^"]+)"/', $sn_walk_src, $sn_v_tabs, PREG_SET_ORDER );
vp_eq( 3, count( $sn_v_tabs ), 'three tabs in the nav' );
foreach ( $sn_v_tabs as $sn_v_t ) {
	vp_true( 1 === preg_match( '/id="' . preg_quote( $sn_v_t[2], '/' ) . '"[^>]*role="tabpanel"/', $sn_walk_src ), "tab {$sn_v_t[1]} controls an existing panel" );
	vp_true( 1 === preg_match( '/id="' . preg_quote( $sn_v_t[2], '/' ) . '"[^>]*aria-labelledby="' . preg_quote( $sn_v_t[1], '/' ) . '"/', $sn_walk_src ), "panel {$sn_v_t[2]} is labelled by its tab" );
}
// Exactly one tab is selected in the shipped markup, and its panel is the
// only one not hidden — so a page whose tab script never loads still shows
// the checks rather than nothing.
vp_eq( 1, substr_count( $sn_walk_src, 'aria-selected="true"' ), 'exactly one tab ships selected' );
vp_true( 1 === preg_match( '/id="sn-panel-checks"(?![^>]*hidden)/', $sn_walk_src ), 'the selected panel ships visible' );
// Keyboard contract: a tablist without arrow keys and a roving tabindex is
// a row of buttons wearing tab roles.
foreach ( array( 'ArrowRight', 'ArrowLeft', 'Home', 'End', 'tabIndex' ) as $sn_v_k ) {
	vp_true( false !== strpos( $sn_v_tabs_js, $sn_v_k ), "tab script implements $sn_v_k" );
}
// The walk's two look-alike hidden states must stay on two elements: the
// panel (owned by the nav) and the inner section (owned by the verifier).
vp_true( 1 === preg_match( '/id="sn-panel-walk"[^>]*role="tabpanel"/', $sn_walk_src ), 'the walk panel wraps rather than replaces the walk section' );
vp_true( false !== strpos( $sn_walk_src, 'data-role="walk-empty"' ), 'the walk panel has an empty state' );
vp_true( false !== strpos( $sn_v_tabs_js, 'walk-empty' ), 'the tab script syncs the walk empty state' );
// The compare panel still carries every hook prov-verify-diff.js binds to.
vp_true( 1 === preg_match( '/id="sn-panel-compare"[^>]*data-role="compare"/', $sn_walk_src ), 'the compare panel keeps its data-role="compare" hook' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
