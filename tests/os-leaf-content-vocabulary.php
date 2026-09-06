<?php
/**
 * Native window leaf: Content → Vocabulary (apps/sn-dashboard/parts/leaves/content-vocabulary.php).
 *
 * The oracle is the classic leaf (inc/ml-drift-admin.php behind the
 * sn_admin_render_drift_section() delegator): a pure readout with no form and
 * no action, so parity is "every number, year, share and state sentence the
 * classic prints is in the kit output" — and none of wp-admin's markup.
 *
 * The "module not loaded" state is pinned by painting BEFORE the reader is
 * declared: the stub below is conditional, so it binds only when reached.
 *
 * Run: php tests/os-leaf-content-vocabulary.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/ml-drift-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-vocabulary.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Every year, count and share token the classic leaf prints, as text. @param string $html @return string[] */
function snt_vocab_tokens( $html ) {
	$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
	preg_match_all( '/[+-]?\d+%|\b\d+\b/', $text, $m );
	return array_values( array_unique( $m[0] ) );
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/vocabulary'] ), 'the painter is registered under content/vocabulary' );

// ── State 0: the module is not loaded (the reader does not exist yet).
ok( ! function_exists( 'snt_ml_drift_report' ), 'precondition: the reader is absent before the stub binds' );
$classic = snt_leaf_classic_html( 'sn_admin_render_drift_section' );
$kit     = snt_leaf_paint( 'content', 'vocabulary' );
ok( false !== strpos( $classic, 'The drift module is not loaded.' ) && false !== strpos( $kit, 'The drift module is not loaded.' ), 'not loaded: both leaves say the module is not loaded' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'heading="Vocabulary drift"' ), 'not loaded: the kit paints it as an empty state inside the Vocabulary drift section' );
ok( false !== strpos( $classic, 'Pure corpus statistics' ) && false !== strpos( $kit, 'description="How the published corpus&#039;s vocabulary moved between years' ) && false !== strpos( $kit, 'this mirror faces the writer, never a model."' ), 'not loaded: the intro prose the classic prints is the section description' );

// ── The reader, bound now; then the real module for its floor constant.
if ( ! function_exists( 'snt_ml_drift_report' ) ) {
	function snt_ml_drift_report() { return $GLOBALS['__drift']; }
}
require SNT_PATH . 'inc/ml-drift.php';
ok( defined( 'SNT_ML_DRIFT_MIN_DOCS' ) && SNT_ML_DRIFT_MIN_DOCS > 0, 'the shipped floor constant is the one both leaves print: ' . SNT_ML_DRIFT_MIN_DOCS );

// ── State 1: no published notes.
$GLOBALS['__drift'] = array( 'ok' => true, 'years' => array(), 'pairs' => array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_drift_section' );
$kit     = snt_leaf_paint( 'content', 'vocabulary' );
ok( false !== strpos( $classic, 'No published notes yet' ) && false !== strpos( $kit, 'No published notes yet — the mirror has nothing to reflect.' ), 'empty corpus: the mirror has nothing to reflect, on both leaves' );
ok( false === strpos( $kit, '<os-stat' ) && false === strpos( $kit, '<os-table' ), 'empty corpus: no ledger and no table are painted' );
ok( false !== strpos( $kit, 'description="How the published corpus&#039;s vocabulary moved between years' ) && false !== strpos( $kit, 'this mirror faces the writer, never a model."' ), 'empty corpus: the intro prose the classic prints is the section description' );

// ── State 2: one year only.
$GLOBALS['__drift'] = array( 'ok' => true, 'years' => array( array( 'year' => 2024, 'docs' => 7 ) ), 'pairs' => array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_drift_section' );
$kit     = snt_leaf_paint( 'content', 'vocabulary' );
ok( false !== strpos( $classic, '2024: 7 notes' ) && false !== strpos( $kit, '<os-stat value="7" label="2024" caption="notes">' ), 'one year: the ledger stat carries 2024 and 7 notes' );
ok( false !== strpos( $classic, 'Only one year holds published notes' ) && false !== strpos( $kit, 'Only one year holds published notes so far — drift needs two to compare.' ), 'one year: both leaves say drift needs two' );
$GLOBALS['__drift'] = array( 'ok' => true, 'years' => array( array( 'year' => 2024, 'docs' => 1 ) ), 'pairs' => array() );
ok( false !== strpos( snt_leaf_paint( 'content', 'vocabulary' ), '<os-stat value="1" label="2024" caption="notes">' ), 'one year, one note: the classic always says "notes", even at 1 — the caption stays plural' );

// ── State 3: the rich corpus — a thin pair, then a measured pair with movement.
$rich = array(
	'ok'    => true,
	'years' => array( array( 'year' => 2023, 'docs' => 3 ), array( 'year' => 2024, 'docs' => 7 ), array( 'year' => 2025, 'docs' => 9 ) ),
	'pairs' => array(
		array( 'from' => 2023, 'to' => 2024, 'verdict' => 'thin', 'docs' => array( 'before' => 3, 'after' => 7 ), 'risen' => array(), 'fallen' => array(), 'entered' => array(), 'silenced' => array() ),
		array(
			'from' => 2024, 'to' => 2025, 'verdict' => 'ok', 'docs' => array( 'before' => 7, 'after' => 9 ),
			'risen'    => array( array( 'term' => 'provenance', 'before' => 0.25, 'after' => 0.5, 'delta' => 0.25 ) ),
			'fallen'   => array(),
			'entered'  => array( array( 'term' => 'agent', 'after' => 0.4 ) ),
			'silenced' => array( array( 'term' => 'roadmap', 'before' => 0.3 ) ),
		),
	),
);
$GLOBALS['__drift'] = $rich;
$classic = snt_leaf_classic_html( 'sn_admin_render_drift_section' );
$kit     = snt_leaf_paint( 'content', 'vocabulary' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic leaf: [' . implode( ',', snt_leaf_names( $kit ) ) . '] (classic: [' . implode( ',', snt_leaf_names( $classic ) ) . '])' );
ok( array() === snt_leaf_actions( $classic ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'no sn_action on either leaf: a pure readout' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $classic, 'Pure corpus statistics' ) && false !== strpos( $kit, 'description="How the published corpus&#039;s vocabulary moved between years' ) && false !== strpos( $kit, 'this mirror faces the writer, never a model."' ), 'rich corpus: the intro prose the classic prints is the section description' );
ok( false === strpos( $kit, '<form' ) && false === strpos( $kit, '<os-form' ) && false === strpos( $kit, 'os-action=' ), 'no form and no dispatch is offered, as on the classic leaf' );

$missing = array();
foreach ( snt_vocab_tokens( $classic ) as $token ) {
	if ( false === strpos( html_entity_decode( $kit, ENT_QUOTES, 'UTF-8' ), $token ) ) {
		$missing[] = $token;
	}
}
ok( array() === $missing && count( snt_vocab_tokens( $classic ) ) >= 10, 'every year, count and share the classic prints is in the kit output (' . count( snt_vocab_tokens( $classic ) ) . ' tokens; missing: ' . implode( ',', $missing ) . ')' );

ok( false !== strpos( $kit, '<os-stat value="3" label="2023" caption="notes">' ) && false !== strpos( $kit, '<os-stat value="7" label="2024" caption="notes">' ) && false !== strpos( $kit, '<os-stat value="9" label="2025" caption="notes">' ), 'the year ledger is three stats: 2023/3, 2024/7, 2025/9' );
ok( false !== strpos( $kit, 'heading="2023 → 2024"' ) && false !== strpos( $kit, 'heading="2024 → 2025"' ), 'each pair is its own section, named from → to' );
$thin_text = sprintf( 'Too few notes to speak (3 and 7; the mirror needs %d on each side). Not the same as &quot;no drift&quot; — this pair was not measured.', SNT_ML_DRIFT_MIN_DOCS );
ok( false !== strpos( $classic, sprintf( 'the mirror needs %d on each side', SNT_ML_DRIFT_MIN_DOCS ) ) && false !== strpos( $kit, $thin_text ), 'the thin pair names both sizes and the floor, on both leaves' );
$thin_section = substr( $kit, strpos( $kit, 'heading="2023 → 2024"' ), strpos( $kit, 'heading="2024 → 2025"' ) - strpos( $kit, 'heading="2023 → 2024"' ) );
ok( false !== strpos( $thin_section, '<os-notice tone="warning"' ) && false !== strpos( $thin_section, '<os-badge tone="warning">Not measured</os-badge>' ) && false === strpos( $thin_section, '<os-table' ) && false === strpos( $thin_section, 'held still' ), 'the thin pair is a warning notice with a badge — no table, and never "held still"' );

$moved = substr( $kit, strpos( $kit, 'heading="2024 → 2025"' ) );
foreach ( array( 'Rose', 'Fell', 'Entered', 'Went silent' ) as $col ) {
	ok( false !== strpos( $moved, '<h4 class="snt-col__h">' . $col . '</h4>' ), 'the measured pair paints the ' . $col . ' column' );
}
ok( 3 === substr_count( $moved, '<os-table' ) && 1 === substr_count( $moved, '<p class="snt-list__empty">—</p>' ), 'three lists are tables, the empty one (Fell) is the em-dash' );
$decoded = html_entity_decode( $moved, ENT_QUOTES, 'UTF-8' );
ok( false !== strpos( $decoded, '{"term":"provenance","share":"+25%"}' ) && false !== strpos( $decoded, '{"term":"agent","share":"40%"}' ) && false !== strpos( $decoded, '{"term":"roadmap","share":"30%"}' ), 'rows carry the term and the share exactly as the classic prints them (+25%, 40%, 30%)' );
ok( false !== strpos( $classic, '+25%' ) && false !== strpos( $classic, '>40%<' ) && false !== strpos( $classic, '>30%<' ), 'oracle check: the classic leaf really prints +25%, 40% and 30%' );
ok( false !== strpos( $decoded, '"label":"Change"' ) && false !== strpos( $decoded, '"label":"Share"' ) && false !== strpos( $decoded, '"align":"end"' ), 'delta lists are headed Change, entered/silenced Share, shares aligned end' );

// ── Per-column slice oracle: prove each row lands under ITS OWN heading, not
// merely somewhere in the pair — the axis a same-shape em-dash fixture cannot
// exercise (silenced was array() in every prior fixture here).
$col_html = function ( $html, $name ) {
	$start = strpos( $html, '<h4 class="snt-col__h">' . $name . '</h4>' );
	$next  = strpos( $html, '<h4 class="snt-col__h">', $start + 1 );
	return html_entity_decode( substr( $html, $start, false === $next ? null : $next - $start ), ENT_QUOTES, 'UTF-8' );
};
ok( false !== strpos( $col_html( $moved, 'Rose' ), '{"term":"provenance","share":"+25%"}' ), 'the Rose column carries provenance, not another column\'s row' );
ok( false !== strpos( $col_html( $moved, 'Entered' ), '{"term":"agent","share":"40%"}' ) && false === strpos( $col_html( $moved, 'Entered' ), 'roadmap' ), 'the Entered column carries agent (the "after" share), never the silenced term' );
ok( false !== strpos( $col_html( $moved, 'Went silent' ), '{"term":"roadmap","share":"30%"}' ) && false === strpos( $col_html( $moved, 'Went silent' ), 'agent' ), 'the Went silent column carries roadmap (the "before" share), never the entered term' );
preg_match_all( '/<h4 class="snt-col__h">([^<]+)</', $moved, $cols_m );
ok( array( 'Rose', 'Fell', 'Entered', 'Went silent' ) === $cols_m[1], 'the four columns paint in the classic\'s order: Rose, Fell, Entered, Went silent' );

// ── State 4: a measured pair that held still.
$still = $rich;
$still['pairs'] = array( array( 'from' => 2024, 'to' => 2025, 'verdict' => 'ok', 'docs' => array( 'before' => 7, 'after' => 9 ), 'risen' => array(), 'fallen' => array(), 'entered' => array(), 'silenced' => array() ) );
$GLOBALS['__drift'] = $still;
$classic = snt_leaf_classic_html( 'sn_admin_render_drift_section' );
$kit     = snt_leaf_paint( 'content', 'vocabulary' );
ok( false !== strpos( $classic, 'The vocabulary held still across this pair.' ) && false !== strpos( $kit, '<p class="snt-prose">The vocabulary held still across this pair.</p>' ), 'held still: both leaves say so' );
ok( false === strpos( $kit, '<os-table' ) && false === strpos( $kit, 'Too few notes' ) && false === strpos( $kit, '<os-notice' ), 'held still: no table, no notice, and never the thin verdict' );

// ── Escaping: a hostile term never reaches the markup raw.
$hostile = $rich;
$hostile['pairs'][1]['risen'][0]['term'] = '"><script>x</script>';
$GLOBALS['__drift'] = $hostile;
$kit = snt_leaf_paint( 'content', 'vocabulary' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile term is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
