<?php
/**
 * Signal & Noise Tools -- tests for the contrast USAGE tier.
 *
 * The tier exists because two scans that were individually correct both missed
 * a live failure: the arithmetic tier because it scores token pairs rather than
 * rendered ones, and the theme-side original because it required an enclosing
 * token-painted surface and the failing component had none. So the headline
 * test here is not "the parser parses" — it is the PROVENANCE CHIP REGRESSION
 * (Group 4), a component with a hardcoded colour, no background of its own, and
 * a 3.49:1 ratio against the page it lands on. If that group ever goes quiet,
 * this module has stopped earning its place.
 *
 * Every ratio below is hand-derived from the WCAG formula and pinned as a
 * literal. The tests never recompute an expectation with the code under test —
 * that only proves the code agrees with itself.
 *
 * Run: php tests/health-contrast-usage.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );

function sn_health_normalize_hex( $color ) {
	$hex = strtolower( trim( (string) $color ) );
	if ( preg_match( '/^#[0-9a-f]{3}$/', $hex ) ) {
		return '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
	}
	return preg_match( '/^#[0-9a-f]{6}$/', $hex ) ? $hex : '';
}

require_once __DIR__ . '/../inc/health-contrast-tokens.php';
require_once __DIR__ . '/../inc/health-contrast-usage.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$void    = array( 'kind' => 'literal', 'value' => '#ffffff' );
$palette = array(
	'root'          => array( 'void' => '#ffffff', 'asphalt' => '#f5f5f5', 'blood' => '#e00404', 'bone' => '#000000' ),
	'High Contrast' => array( 'void' => '#ffffff', 'asphalt' => '#e0e0e0', 'blood' => '#e00404', 'bone' => '#000000' ),
);

// ─── Group 1: rule parsing ─────────────────────────────────────────
echo "\nGroup 1: rule parsing\n";
$rules = sn_health_contrast_usage_rules( '/* c */ .a { color: red; } @media x { .b { color: blue; } }' );
ok( count( $rules ) >= 1, 'parses rules and strips comments' );
$sels = array_column( $rules, 'sel' );
ok( ! in_array( '@media x', $sels, true ), 'at-rule preludes are not treated as selectors' );

// ─── Group 2: colour reading ───────────────────────────────────────
echo "\nGroup 2: colour reading\n";
$tok = sn_health_contrast_usage_read_color( 'color: var(--wp--preset--color--blood);', 'color' );
ok( 'token' === $tok['kind'] && 'blood' === $tok['value'], 'reads a token reference' );

$lit = sn_health_contrast_usage_read_color( 'color:#1F9D55;', 'color' );
ok( $lit && 'literal' === $lit['kind'] && '#1f9d55' === $lit['value'], 'reads and normalises a literal hex' );

ok( null === sn_health_contrast_usage_read_color( 'background: transparent;', 'background' ), 'transparent is not a surface' );
ok( null === sn_health_contrast_usage_read_color( 'color: currentColor;', 'color' ), 'currentColor is unscoreable, not guessed' );
ok( null === sn_health_contrast_usage_read_color( 'color: color-mix(in srgb, #fff 40%, transparent);', 'color' ), 'color-mix() is unscoreable' );
ok( null === sn_health_contrast_usage_read_color( 'border-color:#000;', 'color' ), 'border-color is not text colour' );
ok( null === sn_health_contrast_usage_read_color( 'background-image: url(x.png);', 'background' ), 'a background image is not a colour' );

// var() FALLBACKS. `var(--wp--preset--color--void, #fff)` is the form
// that is both safe (renders when the preset is undefined) and scoreable. Before
// this, the regex demanded a bare `var(--token)` and returned null on the
// fallback form, so a component written the safe way became invisible to the
// scan — the checker had to be widened BEFORE any stylesheet could adopt it.
//
// The TOKEN is what gets scored, never the fallback: every sheet in
// sn_health_contrast_usage_sources() loads in theme context, where the presets
// ARE defined, so the fallback is the branch a reader never takes. Scoring it
// would report a colour nobody sees.
$fb = sn_health_contrast_usage_read_color( 'background: var(--wp--preset--color--void, #fff);', 'background' );
ok( $fb && 'token' === $fb['kind'] && 'void' === $fb['value'], 'a token with a hex fallback reads as the TOKEN (the branch that actually renders)' );

$fbt = sn_health_contrast_usage_read_color( 'color:var(--wp--preset--color--signal,#ff4c47);', 'color' );
ok( $fbt && 'token' === $fbt['kind'] && 'signal' === $fbt['value'], 'no space after the comma is the same declaration' );

$fbw = sn_health_contrast_usage_read_color( 'color: var( --wp--preset--color--blood , #e00404 );', 'color' );
ok( $fbw && 'token' === $fbw['kind'] && 'blood' === $fbw['value'], 'whitespace around the token and the fallback does not hide it' );

// The limit stated out loud, so widening the regex does not quietly widen the
// CLAIM. A non-preset custom property has no palette entry to resolve, and its
// fallback is NOT what renders when the property is defined somewhere in the
// cascade — which is exactly the case in this plugin's own sheets
// (`--sn-signal: var(--wp--preset--color--signal,#ff4c47)`). Guessing the
// fallback there would invent a colour. Unscoreable stays unscoreable.
ok( null === sn_health_contrast_usage_read_color( 'color: var(--sn-signal, #ff4c47);', 'color' ), 'a NON-preset var with a fallback stays unscoreable — its fallback is not what renders' );
$fbn = sn_health_contrast_usage_read_color( 'color: var(--wp--preset--color--void, var(--sn-x));', 'color' );
ok( $fbn && 'token' === $fbn['kind'] && 'void' === $fbn['value'], 'a NESTED var() fallback changes nothing — the outer preset token is still what renders' );

// ─── Group 2b2: the admin exclusion, by fact rather than by filename ──
// The module excludes admin stylesheets on purpose: wp-admin supplies its own
// palette and background, so scoring admin rules against theme tokens invents
// failures no reader can meet. That exclusion was implemented as a FILENAME
// substring check for "admin" — a proxy for the real property, which is which
// hook enqueues the sheet.
//
// The proxy leaked, and it was caught by the live report: uptime-status.css is
// admin-only and not named "admin", so TWO of the three pairings the contrast
// report flagged were wp-admin status colours (#00a32a at 3.35:1, #dba617 at
// 2.22:1) scored against a public page they never appear on.

/**
 * Every plugin stylesheet, at any depth, derived HERE rather than borrowed from
 * sn_health_contrast_usage_plugin_sheets(). A guard built from the producer's
 * own population can only ever confirm the producer's assumption - which is
 * exactly how assets/css/prov-verify.css went unscored while this suite was
 * green (issue #988).
 *
 * @return string[] absolute paths, sorted.
 */
function sn_hcu_all_plugin_css() {
	$base  = realpath( __DIR__ . '/../assets' );
	$found = array();
	if ( ! $base ) { return $found; }
	$walk = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $walk as $f ) {
		if ( $f->isFile() && 'css' === strtolower( $f->getExtension() ) ) { $found[] = (string) $f->getPathname(); }
	}
	sort( $found );
	return $found;
}

echo "\nGroup 2b2: admin-only sheets are excluded by FACT, not by filename\n";

$sources = sn_health_contrast_usage_sources();
$scanned = array();
foreach ( $sources as $label => $path ) { $scanned[ basename( $path ) ] = true; }

foreach ( array( 'uptime-status.css', 'audit-log.css', 'machine-readers.css' ) as $sheet ) {
	ok( ! isset( $scanned[ $sheet ] ), "$sheet is EXCLUDED — it is enqueued on admin_enqueue_scripts only" );
}
// Over-reach guard. Excluding a front-end sheet is the DANGEROUS direction: a
// false positive is noisy, but silently shrinking coverage hides real defects.
ok( isset( $scanned['provenance-front.css'] ), 'provenance-front.css is still SCANNED — it is a wp_enqueue_scripts sheet, and dropping it would hide the chip this module exists to catch' );
// #988: the producer enumerated only the TOP of assets/, so a front-end sheet
// in a subdirectory was never scored while every line above stayed green. These
// three pin the DEPTH of the population, not just its size - a count alone
// cannot tell a full set from a truncated one.
$plugin_sheets = sn_health_contrast_usage_plugin_sheets();
$nested        = array_filter( array_keys( $plugin_sheets ), function ( $rel ) { return false !== strpos( $rel, '/' ); } );
ok( ! empty( $nested ), 'the producer reaches sheets in SUBDIRECTORIES of assets/ (' . count( $nested ) . ' found) - a top-level-only glob reds here' );
ok( count( $plugin_sheets ) === count( sn_hcu_all_plugin_css() ),
	'the producer enumerates every .css under assets/ - producer ' . count( $plugin_sheets ) . ' vs independently walked ' . count( sn_hcu_all_plugin_css() ) );
ok( isset( $scanned['prov-verify.css'] ),
	'prov-verify.css is SCANNED - it is served on the public template_redirect verify route, and it was silently omitted until v13.95.3' );
ok( count( $sources ) >= 10, 'the source set did not collapse — ' . count( $sources ) . ' sheets still scanned' );

// THE DRIFT TEST, and the load-bearing one. The exclusion is a hand-kept list,
// so the guard cannot be the list itself — it has to be something that fails
// when reality moves. Derive the truth from the plugin's own source: a sheet is
// admin-only when every file that references it hooks admin_enqueue_scripts and
// none hooks wp_enqueue_scripts. A new admin sheet then REDS this line instead
// of quietly seeding false positives into a report-only check nobody re-reads.
$php = glob( __DIR__ . '/../inc/*.php' );
$derived_admin = array();
foreach ( sn_hcu_all_plugin_css() as $css ) {
	$base = basename( $css );
	$hooks = array();
	$seen  = false;
	foreach ( $php as $file ) {
		$src = (string) file_get_contents( $file );
		// Comments stripped first: machine-readers-admin.php mentions
		// provenance-admin.css in PROSE, and a reference inside a comment is not
		// an enqueue. Match the BARE basename, not 'assets/<name>' — the real
		// call sites build the path by concatenation (`$base . 'x.css'`), which
		// an 'assets/'-anchored search silently misses. This test caught that
		// false negative in its own derivation before the list was written.
		$src = preg_replace( '!/\*.*?\*/!s', '', $src );
		$src = preg_replace( '!//[^\n]*!', '', $src );
		if ( false === strpos( $src, $base ) ) { continue; }
		$seen = true;
		if ( preg_match_all( "/add_action\(\s*'(admin_enqueue_scripts|wp_enqueue_scripts|enqueue_block_assets|login_enqueue_scripts)'/", $src, $m ) ) {
			foreach ( $m[1] as $h ) { $hooks[ $h ] = true; }
		}
	}
	if ( $seen && isset( $hooks['admin_enqueue_scripts'] ) && ! isset( $hooks['wp_enqueue_scripts'] ) ) {
		$derived_admin[] = $base;
	}
}
sort( $derived_admin );
$excluded_now = array();
foreach ( sn_hcu_all_plugin_css() as $css ) {
	if ( ! isset( $scanned[ basename( $css ) ] ) ) { $excluded_now[] = basename( $css ); }
}
sort( $excluded_now );
ok( $derived_admin === $excluded_now,
	'DRIFT: the excluded set EQUALS the admin-enqueued set derived from source — derived [' . implode( ', ', $derived_admin ) . '] vs excluded [' . implode( ', ', $excluded_now ) . ']' );
ok( count( $derived_admin ) >= 4, 'the derivation actually found admin sheets (' . count( $derived_admin ) . ') — a derivation returning nothing would make the line above vacuously true' );

// ─── Group 2c: at-rule context ─────────────────────────────────────
// The parser lifts inner rules out of `@media` and drops the condition, so a
// conditional declaration was scored as if it always applied. Measured against
// the live theme sheets: 8 colour declarations sit inside `@media` today, and
// none of them is mis-scored — the two scoreable ones are the SAME colour under
// complementary width queries. So this is not a live defect; it is two
// demonstrated false-positive generators waiting for the next print stylesheet.
echo "\nGroup 2c: at-rule context is carried, and print is not the screen\n";

$r = sn_health_contrast_usage_rules( '.a{color:#111111}@media (max-width:600px){.b{color:#222222}}' );
$by = array();
foreach ( $r as $one ) { $by[ $one['sel'] ] = $one; }
ok( isset( $by['.a'] ) && '' === $by['.a']['at'], 'a top-level rule carries an EMPTY at-rule context' );
ok( isset( $by['.b'] ) && false !== strpos( $by['.b']['at'], 'max-width' ), 'a rule inside @media carries its prelude as context' );

// SHAPE 1 — print. This tier measures what a reader sees ON SCREEN. A print
// colour scored against a screen surface is a failure nobody can meet:
// #cccccc on #ffffff is 1.61:1, and it would have been reported.
$printed = sn_health_contrast_usage_rules( '.card{background:#ffffff}@media print{.card{color:#cccccc}}' );
ok( 1 === count( $printed ), 'a print-only rule is DROPPED — this tier measures the screen, and #cccccc on #ffffff would have read 1.61:1' );
ok( '' === $printed[0]['at'], 'and the surviving rule is the unconditional one' );

ok( 2 === count( sn_health_contrast_usage_rules( '@media screen,print{.a{color:#111111}}@media only screen{.b{color:#222222}}' ) ),
	'a query that lists screen ALONGSIDE print is kept — dropping it would lose a rule that does apply' );

// SHAPE 2 — mutually exclusive conditions. The fg exists only above 601px, the
// surface only below 600px. They can never co-occur, so anchoring one to the
// other invents a pairing that renders nowhere.
$excl  = sn_health_contrast_usage_rules( '@media (max-width:600px){.x{background:#111111}}@media (min-width:601px){.x{color:#222222}}' );
$pairs = sn_health_contrast_usage_pairings( $excl, sn_health_contrast_usage_surfaces( $excl ), $void, 'probe.css' );
ok( 1 === count( $pairs ), 'the pairing still exists — the colour is real and must be scored against SOMETHING' );
ok( $pairs && false === $pairs[0]['anchored'], 'but it is UNANCHORED: a surface from an incompatible at-rule context cannot be its background' );
ok( $pairs && '#ffffff' === $pairs[0]['bg']['value'], 'so it falls back to the document background rather than inventing a co-occurrence' );

// The two compatible cases must keep working, or the guard has over-reached.
$same  = sn_health_contrast_usage_rules( '@media (min-width:601px){.y{background:#000000}.y .t{color:#222222}}' );
$psame = sn_health_contrast_usage_pairings( $same, sn_health_contrast_usage_surfaces( $same ), $void, 'probe.css' );
ok( $psame && true === $psame[0]['anchored'], 'a surface in the SAME at-rule context still anchors' );

$uncond = sn_health_contrast_usage_rules( '.z{background:#000000}@media (min-width:601px){.z .t{color:#222222}}' );
$puncond = sn_health_contrast_usage_pairings( $uncond, sn_health_contrast_usage_surfaces( $uncond ), $void, 'probe.css' );
ok( $puncond && true === $puncond[0]['anchored'], 'an UNCONDITIONAL surface anchors a conditional colour — it applies at every width' );

// ─── Group 3: surface derivation ───────────────────────────────────
echo "\nGroup 3: surface derivation excludes pseudo-elements and states\n";
$css = '.card { background: var(--wp--preset--color--asphalt); }'
	. '.card::before { background: var(--wp--preset--color--blood); }'
	. '.card:hover { background: var(--wp--preset--color--bone); }';
$surfaces = sn_health_contrast_usage_surfaces( sn_health_contrast_usage_rules( $css ) );
ok( isset( $surfaces['.card'] ) && 'asphalt' === $surfaces['.card']['value'], 'the element\'s own background is the surface' );
ok( ! isset( $surfaces['.card::before'] ), 'a pseudo-element rail is NOT a surface (the blood-on-blood false positive)' );
ok( ! isset( $surfaces['.card:hover'] ), 'a hover background is NOT a resting surface (the ~60 false positives)' );
ok( 1 === count( $surfaces ), 'exactly one surface derived from three rules' );

// ─── Group 4: THE PROVENANCE CHIP REGRESSION ───────────────────────
// A component with a hardcoded colour and NO background of its own. Its
// contrast is a property of placement, which is why the arithmetic tier
// cannot see it and why a surface-requiring scan drops it entirely.
// #1f9d55 on #ffffff = 3.49:1, hand-derived, below the 4.5 floor.
echo "\nGroup 4: the provenance chip — no background, hardcoded colour\n";
$chip  = sn_health_contrast_usage_rules( '.sn-prov-chip__state--confirmed { color: #1f9d55; font-size: .72rem; }' );
$pairs = sn_health_contrast_usage_pairings( $chip, sn_health_contrast_usage_surfaces( $chip ), $void, 'plugin/provenance-front.css' );
ok( 1 === count( $pairs ), 'a backgroundless component still yields a pairing' );
ok( false === $pairs[0]['anchored'], 'it is marked unanchored — scored against the page background' );
ok( 'literal' === $pairs[0]['fg']['kind'], 'its colour is recorded as a hardcoded literal' );

$ratio = sn_health_contrast_ratio( '#1f9d55', '#ffffff' );
ok( abs( $ratio - 3.49 ) < 0.01, 'confirmed-green on white is 3.49:1 (hand-derived), i.e. below AA' );

// ─── Group 5: anchored pairings ────────────────────────────────────
echo "\nGroup 5: anchored pairings resolve to the enclosing surface\n";
$nested = sn_health_contrast_usage_rules(
	'.panel { background: var(--wp--preset--color--asphalt); }'
	. '.panel-note { color: var(--wp--preset--color--blood); }'
);
$np = sn_health_contrast_usage_pairings( $nested, sn_health_contrast_usage_surfaces( $nested ), $void, 't' );
ok( 1 === count( $np ) && true === $np[0]['anchored'], 'a BEM child anchors to its parent surface' );
ok( 'asphalt' === $np[0]['bg']['value'], 'it resolves to asphalt, not the page background' );

$same = sn_health_contrast_usage_rules( '.x { color: var(--wp--preset--color--bone); background: var(--wp--preset--color--bone); }' );
ok( 0 === count( sn_health_contrast_usage_pairings( $same, array(), $void, 't' ) ), 'identical fg/bg is skipped, not reported as 1.00:1' );

// ─── Group 6: per-palette scoring — the false-green that started this ──
echo "\nGroup 6: the same pairing scored under every palette\n";
// blood #e00404 on asphalt: 4.60:1 at root (passes), 3.80:1 under High
// Contrast's #e0e0e0 (fails). Root-only scoring is how this was missed.
ok( abs( sn_health_contrast_ratio( '#e00404', '#f5f5f5' ) - 4.60 ) < 0.01, 'blood on root asphalt is 4.60:1 — passes' );
ok( abs( sn_health_contrast_ratio( '#e00404', '#e0e0e0' ) - 3.80 ) < 0.01, 'blood on High Contrast asphalt is 3.80:1 — fails' );

$resolved_root = sn_health_contrast_usage_resolve( array( 'kind' => 'token', 'value' => 'asphalt' ), $palette['root'] );
$resolved_hc   = sn_health_contrast_usage_resolve( array( 'kind' => 'token', 'value' => 'asphalt' ), $palette['High Contrast'] );
ok( '#f5f5f5' === $resolved_root && '#e0e0e0' === $resolved_hc, 'a token resolves differently per palette' );

$literal_root = sn_health_contrast_usage_resolve( array( 'kind' => 'literal', 'value' => '#1f9d55' ), $palette['root'] );
$literal_hc   = sn_health_contrast_usage_resolve( array( 'kind' => 'literal', 'value' => '#1f9d55' ), $palette['High Contrast'] );
ok( $literal_root === $literal_hc, 'a hardcoded literal is palette-invariant — the fidelity half of the bug' );

ok( null === sn_health_contrast_usage_resolve( array( 'kind' => 'token', 'value' => 'ghost' ), $palette['root'] ), 'an unknown token resolves to null rather than a guess' );

// ─── Group 7: negative control ─────────────────────────────────────
// A scan that only ever fires is as useless as one that never does.
echo "\nGroup 7: negative control — passing pairings stay quiet\n";
$good = sn_health_contrast_usage_rules( '.ok { background: var(--wp--preset--color--void); color: var(--wp--preset--color--bone); }' );
$gp   = sn_health_contrast_usage_pairings( $good, sn_health_contrast_usage_surfaces( $good ), $void, 't' );
ok( 1 === count( $gp ), 'the passing pairing is still detected' );
ok( 21.0 === round( sn_health_contrast_ratio( '#000000', '#ffffff' ), 1 ), 'and scores 21:1, so it will never be reported as a failure' );

// ─── Group 8: containment is not substring matching ────────────────
echo "\nGroup 8: containment\n";
ok( sn_health_contrast_usage_contains( '.card-title', '.card' ), 'BEM-ish child is contained' );
ok( sn_health_contrast_usage_contains( '.card .note', '.card' ), 'descendant is contained' );
ok( ! sn_health_contrast_usage_contains( '.cardboard', '.card-' ), 'a different class is not contained' );

// ─── Group 9: the card's shape — usage leads, arithmetic collapses ──
// Owner decision (2026-08-11): the arithmetic count misled as a headline three
// times; it now lives inside <details> with the count in the <summary> as a
// palette-drift tripwire. This group pins the ORDER and the WRAPPER, because
// nothing else does — the scanner tests above cannot see presentation.
echo "\nGroup 9: renderer — usage tier leads, arithmetic tier collapses\n";
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); } }
require_once __DIR__ . '/../inc/health-render-reports.php';

$report = array(
	'pairs'           => array(
		array( 'pair' => 'void / asphalt', 'ratio' => 1.32, 'aa_body' => false, 'aa_large' => false ),
		array( 'pair' => 'bone / void', 'ratio' => 21.0, 'aa_body' => true, 'aa_large' => true ),
	),
	'tokens'          => array( 'void' => '#ffffff', 'asphalt' => '#f5f5f5', 'bone' => '#000000' ),
	'thresholds'      => array( 'aa_body' => 4.5, 'aa_large' => 3.0 ),
	'would_fail_body' => 1,
	'usage'           => array( 'failures' => array(), 'palettes' => array( 'root' ), 'scanned' => 3, 'pairings' => 7 ),
);
ob_start();
sn_health_render_contrast_report( $report );
$html = (string) ob_get_clean();

$usage_at   = strpos( $html, 'Usage tier' );
$details_at = strpos( $html, '<details' );
$table_at   = strpos( $html, 'Token pair' );
ok( false !== $usage_at && false !== $details_at && $usage_at < $details_at, 'usage tier renders BEFORE the arithmetic <details>' );
ok( false !== $table_at && $table_at > $details_at && $table_at < strpos( $html, '</details>' ), 'the arithmetic pair table is inside the <details> wrapper' );
ok( 1 === preg_match( '/<summary>[^<]*1[^<]*2[^<]*<\/summary>/s', $html ), 'the summary carries the tripwire count (1 of 2) without expanding' );

// Empty-palette degradation: the usage tier must not be held hostage by an
// unreadable arithmetic palette — it renders first, then arithmetic bails.
ob_start();
sn_health_render_contrast_report( array( 'pairs' => array(), 'usage' => $report['usage'] ) );
$empty_html = (string) ob_get_clean();
ok( false !== strpos( $empty_html, 'Usage tier' ) && false === strpos( $empty_html, '<details' ), 'no readable palette: usage still renders, no empty arithmetic disclosure' );

// ─── Group 10: BOTH CHIP GENERATIONS ────────────────────────────────
// The corpus pins the old hexes as failures the scan MUST find and the
// shipped replacements as passes it MUST clear. Pinning only one generation
// leaves the suite unable to tell "the scan works" from "the scan is broken
// in the direction that happens to agree with today's stylesheet".
echo "\nGroup 10: both chip generations — old must fail, new must pass\n";

$old = array( 'confirmed' => '#1f9d55', 'pending' => '#c98a12', 'muted' => '#6b7280' );
$new = array( 'confirmed' => '#12703a', 'pending' => '#7a5200', 'muted' => '#5b6270' );

foreach ( $old as $state => $hex ) {
	$on_white = sn_health_contrast_ratio( $hex, '#ffffff' );
	if ( 'muted' === $state ) {
		// The per-palette argument in one row: passes on white, fails on the
		// palette the site actually serves.
		ok( $on_white >= 4.5, "old $state ($hex) passes on white at " . round( $on_white, 2 ) . ':1' );
		ok( sn_health_contrast_ratio( $hex, '#e0e0e0' ) < 4.5, "old $state FAILS on served asphalt — invisible to a white-only scan" );
	} else {
		ok( $on_white < 4.5, "old $state ($hex) fails on white at " . round( $on_white, 2 ) . ':1 — the scan must find this' );
	}
}

foreach ( $new as $state => $hex ) {
	ok( sn_health_contrast_ratio( $hex, '#ffffff' ) >= 4.5, "shipped $state ($hex) clears AA on white" );
	ok( sn_health_contrast_ratio( $hex, '#e0e0e0' ) >= 4.5, "shipped $state ($hex) clears AA on served asphalt too" );
}

// The margin lesson, pinned so a future "tidy-up" cannot quietly undo it: the
// rejected candidates sat at 4.50-4.55 on asphalt. A value at 4.50 against a
// 4.5 threshold has no margin, and is exactly the one nobody re-measures.
$rejected = array( '#16723e', '#855b0c', '#5e6470' );
foreach ( $rejected as $hex ) {
	$r = sn_health_contrast_ratio( $hex, '#e0e0e0' );
	ok( $r >= 4.5 && $r < 4.6, "rejected candidate $hex sits at " . round( $r, 2 ) . ':1 on asphalt — passing, but with no margin' );
}
foreach ( $new as $hex ) {
	ok( sn_health_contrast_ratio( $hex, '#e0e0e0' ) > 4.6, 'the shipped value keeps real margin on asphalt' );
}

// ─── Group 11: the document background reads fallbacks too ──────────
// The SECOND copy of the token regex. Widening only the declaration reader
// would leave the document background — the surface every unanchored pairing
// is scored against — still blind to the same syntax, so one sheet adopting
// the safe form could silently change what everything else is measured on.
// Two regexes for one concept is the defect; until they are merged, they are
// pinned together.
echo "\nGroup 11: the document background reads var() fallbacks too\n";
if ( ! function_exists( 'wp_get_global_styles' ) ) {
	function wp_get_global_styles( $path = array() ) {
		return $GLOBALS['__global_styles_bg'];
	}
}
$GLOBALS['__global_styles_bg'] = 'var(--wp--preset--color--bone)';
$dbg                           = sn_health_contrast_usage_document_background();
ok( $dbg && 'token' === $dbg['kind'] && 'bone' === $dbg['value'], 'a bare token still reads as the token' );

$GLOBALS['__global_styles_bg'] = 'var(--wp--preset--color--bone, #f4f1ea)';
$dbg                           = sn_health_contrast_usage_document_background();
ok( $dbg && 'token' === $dbg['kind'] && 'bone' === $dbg['value'], 'a token WITH a fallback reads as the token, not as null and not as the fallback' );

$GLOBALS['__global_styles_bg'] = '#FFFFFF';
$dbg                           = sn_health_contrast_usage_document_background();
ok( $dbg && 'literal' === $dbg['kind'] && '#ffffff' === $dbg['value'], 'a plain hex is still read and normalised' );

$GLOBALS['__global_styles_bg'] = '';
ok( null === sn_health_contrast_usage_document_background(), 'an empty global-styles background is unscoreable, not guessed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
