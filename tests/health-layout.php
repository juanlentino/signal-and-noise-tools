<?php
/**
 * Standalone test: Health sub-tab open-and-wide layout contract (v6.44.0,
 * reshaped v8.0.1, pattern-adoption extracted in v10.46.0, IA rebuilt v10.83.0).
 *
 * Order contract (v10.83.0): first-glance hero (sn_admin_glance_grid) → the
 * capped Run-scan card → full-width finding tables for checks WITH issues →
 * the Reports section (report-only payloads) → the COLLAPSED passing
 * disclosure (<details class="sn-health-passing">, names grouped by family).
 * No .sn-shell / .sn-shell__rail. Also unit-tests snt_health_glance_cards().
 *
 * The v10.83.0 assertions that matter most:
 *   - a report-only check renders its PAYLOAD (it shipped in v10.82.0 with no
 *     home in admin at all — a green chip was its entire representation), and
 *   - it does NOT appear among the passing chips, because "pass" is a verdict
 *     a check that cannot fail must not be able to earn.
 *
 * Run: php tests/health-layout.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
// ECHOES, never returns — the callee's real shape (the esc_html_e stub-drift
// trap, bitten before: a returning stub greens markup the live site never has).
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); } }
// The usage renderer's row cap normally arrives with inc/health-contrast-usage.php,
// which this layout fixture deliberately does not load (it fakes the SCAN, not
// the producers). Same value as the real constant.
if ( ! defined( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS' ) ) { define( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS', 25 ); }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
// __() is the plain translate — no escaping. A renderer that composes with
// sprintf() and escapes the RESULT needs this rather than esc_html__(), which
// would escape the template before the values land in it. Stubbing only the
// escaping variants made a legitimate renderer fatal.
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $one, $many, $n, $d = null ) { return (string) ( 1 === (int) $n ? $one : $many ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '3 hours'; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); } }
if ( ! function_exists( 'snt_ai_is_available' ) ) { function snt_ai_is_available() { return false; } }

$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 3600,
	'elapsed_ms' => 800,
	'checks'     => array(
		'missing_alt'    => array(
			'label'    => 'Missing alt text',
			'count'    => 2,
			'fix_hint' => 'Add descriptive alt text.',
			'findings' => array(
				array( 'subject_label' => 'img-1', 'subject_url' => 'https://x/1', 'note' => 'no alt', 'edit_url' => 'https://x/edit/1' ),
				array( 'subject_label' => 'img-2', 'note' => 'no alt' ),
			),
		),
		'orphaned_media' => array(
			'label'    => 'Orphaned media',
			'count'    => 0,
			'fix_hint' => '',
			'findings' => array(),
		),
		// v10.83.0: a report-only check in the fixture. Zero findings AND a
		// report payload — the shape that used to collapse into a pass chip.
		'contrast_tokens' => array(
			'label'    => 'Contrast (token arithmetic, report only)',
			'count'    => 0,
			'fix_hint' => 'Report only — no action from this check.',
			'findings' => array(),
			'report'   => array(
				'coverage'        => 'Arithmetic tier only: every theme-token pair scored as WOULD-fail/pass if rendered together.',
				'thresholds'      => array( 'aa_body' => 4.5, 'aa_large' => 3.0 ),
				'tokens'          => array( 'ink' => '#111111', 'paper' => '#ffffff', 'rust' => '#b3421a' ),
				'pairs'           => array(
					array( 'pair' => 'rust / ink', 'ratio' => 2.87, 'aa_body' => false, 'aa_large' => false ),
					array( 'pair' => 'rust / paper', 'ratio' => 5.12, 'aa_body' => true, 'aa_large' => true ),
					array( 'pair' => 'ink / paper', 'ratio' => 18.88, 'aa_body' => true, 'aa_large' => true ),
				),
				'would_fail_body' => 1,
			),
		),
	),
);
if ( ! function_exists( 'sn_health_last_scan' ) ) { function sn_health_last_scan() { return $GLOBALS['__scan']; } }
// v8.0.1: marker stub. DELIBERATELY KEPT after the v10.46.0 extraction — with
// the function defined, "no marker in the output" proves the Health tab stopped
// CALLING it, which a missing function would prove nothing about.
if ( ! function_exists( 'snt_pattern_adoption_render_opportunities_section' ) ) {
	function snt_pattern_adoption_render_opportunities_section() { echo '<div class="sn-fieldset">SNT-OPPS-MARKER</div>'; }
}

require_once __DIR__ . '/../inc/health-summary.php'; // finding-total + flagged-checks accessors the glance hero shares
require_once __DIR__ . '/../inc/admin-glance.php';
// v10.83.0: the IA render modules the tab now delegates to.
require_once __DIR__ . '/../inc/health-check-families.php';
require_once __DIR__ . '/../inc/health-render-passing.php';
require_once __DIR__ . '/../inc/health-render-reports.php';
require_once __DIR__ . '/../inc/health-checks-admin.php';

function he_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Unit: snt_health_glance_cards() ────────────────────────────────────────
echo "Unit: snt_health_glance_cards()\n";
$cards = snt_health_glance_cards( $GLOBALS['__scan'] );
he_assert( 3 === count( $cards ), 'a scan yields exactly 3 hero cards' );
he_assert( 'Findings' === $cards[0]['label'] && '2 findings' === $cards[0]['value'], 'Findings card sums the per-check counts' );
he_assert( 'warn' === $cards[0]['pill']['kind'], 'Findings card pills warn when issues exist' );
// v10.83.0: 3 checks run, 1 flagged, 1 report-only → 1 passed out of 3. The
// DENOMINATOR still counts every check (sn_health_check_total is untouched, so
// no other surface has to be re-derived); the report-only check simply leaves
// the numerator, and the meta line says where it went.
he_assert( 'Checks passed' === $cards[1]['label'] && '1 / 3' === $cards[1]['value'], 'Checks-passed counts real passes over every check run' );
he_assert( false !== strpos( (string) $cards[1]['meta_html'], '1 report-only check not counted' ), 'the report-only check is NAMED, not silently absorbed into the ratio' );
he_assert( 'Last scan' === $cards[2]['label'] && false !== strpos( $cards[2]['value'], 'ago' ), 'Last-scan card shows the age' );
$nocards = snt_health_glance_cards( null );
he_assert( 1 === count( $nocards ) && 'no scan' === $nocards[0]['value'] && 'warn' === $nocards[0]['pill']['kind'], 'no scan → a single warn "no scan" card' );

// ─── Test A: with findings — hero + full-width table + pass board, no shell ──
echo "\nTest A: Health with findings (hero → run-scan → Findings table → pass board)\n";
ob_start();
sn_health_render_admin_tab();
$html = ob_get_clean();

he_assert( false === strpos( $html, 'sn-shell' ), 'no two-column shell/rail — full-width layout' );
he_assert( false !== strpos( $html, '<div class="sn-glance">' ), 'leads with the first-glance hero' );
he_assert( false !== strpos( $html, 'value="health_scan"' ), 'run-scan control present' );
he_assert( false !== strpos( $html, 'name="_wpnonce"' ), 'run-scan form is nonce-protected' );
he_assert( false !== strpos( $html, '<h2 class="sn-section-h">Findings</h2>' ), 'Findings section heading present' );
he_assert( false !== strpos( $html, 'Missing alt text' ), 'finding card for the failing check' );
he_assert( false !== strpos( $html, '<table class="widefat striped' ), 'full-width finding table present' );

// v10.46.0: Opportunities left for Content → Pattern Adoption, so the v8.0.1
// pairing goes with it. NOTE the stub above IS defined — so this asserts the tab
// genuinely stopped calling it, not merely that the function is absent.
he_assert( false === strpos( $html, 'sn-health-actions' ), 'the paired action-row wrapper is GONE (one child would stretch edge to edge)' );
he_assert( false === strpos( $html, 'SNT-OPPS-MARKER' ), 'the Health tab no longer renders the pattern-adoption card, even though the fn exists' );
he_assert( false === strpos( $html, 'HEAD probes' ), 'run-scan intro is the one-line copy (long paragraph gone)' );

// ── v10.83.0: passing checks collapse into a <details> disclosure ───────────
he_assert( false !== strpos( $html, '<details class="sn-fieldset sn-health-passing">' ), 'passing checks are a COLLAPSED <details>, not an open strip' );
he_assert( 1 === preg_match( '/<details class="sn-fieldset sn-health-passing">/', $html ) && 0 === preg_match( '/<details[^>]*sn-health-passing[^>]*\sopen/', $html ), 'the disclosure is CLOSED by default — a healthy site reads as one line' );
he_assert( false !== strpos( $html, '<summary class="sn-health-passing__summary">' ), 'the summary is a real <summary> (keyboard + SR disclosure semantics for free)' );
he_assert( false !== strpos( $html, '1 of 3 checks passing · 1 report-only' ), 'the summary names the report-only gap instead of counting it as a pass' );
he_assert( false !== strpos( $html, '<span class="sn-badge">Orphaned media</span>' ), 'passing check appears as a name chip inside the disclosure' );
he_assert( false === strpos( $html, '<span class="sn-badge">Contrast (token arithmetic, report only)</span>' ), 'the report-only check is NOT a pass chip' );
he_assert( false === strpos( $html, '<h2 class="sn-section-h">Passing checks</h2>' ), 'old pass-board section heading gone' );
he_assert( false === strpos( $html, '>clear<' ), 'no per-check "clear" pass cards remain' );

// Family grouping inside the disclosure.
he_assert( false !== strpos( $html, 'sn-health-passing__family-label' ), 'passing names are grouped under family labels' );
he_assert( false !== strpos( $html, '>Content</h3>' ), 'the family label names the family (orphaned_media → Content)' );

// ── v10.83.0: the Reports section — the whole point of this change ──────────
echo "\nTest A2: report-only payloads render (contrast_tokens had NO admin home before)\n";
he_assert( false !== strpos( $html, '<h2 class="sn-section-h">Reports</h2>' ), 'Reports section heading present' );
he_assert( false !== strpos( $html, 'sn-health-report__coverage' ), 'the coverage sentence renders — it is the honesty contract, not preamble' );
he_assert( false !== strpos( $html, 'Arithmetic tier only' ), 'the coverage TEXT is the report payload\'s own, verbatim' );
he_assert( false !== strpos( $html, '1 of 3 token pairs would fall below 4.5:1' ), 'the headline states the would-fail count as a proportion' );
he_assert( false !== strpos( $html, 'would fall below' ), 'wording is WOULD-fail — this tier scores pairs, it does not observe renders' );
he_assert( false !== strpos( $html, '<code>rust / ink</code>' ), 'the worst pair appears in the table' );
he_assert( false !== strpos( $html, '2.87:1' ), 'the ratio renders to 2dp' );
// Worst-first ordering survives the render (the check sorts; the renderer must not resort).
$worst_at = strpos( $html, 'rust / ink' );
$best_at  = strpos( $html, 'ink / paper' );
he_assert( is_int( $worst_at ) && is_int( $best_at ) && $worst_at < $best_at, 'pairs render worst-first — the reader meets the risk before the reassurance' );
// WCAG 1.4.1 inside the contrast report itself: the verdict must survive with
// the colour removed. Pills carry a glyph AND screen-reader words; the class is
// the third channel, not the only one.
he_assert( false !== strpos( $html, '<span class="screen-reader-text">would fail </span>' ), 'a failing verdict says "would fail" in text, not only in amber' );
he_assert( false !== strpos( $html, '<span class="screen-reader-text">would pass </span>' ), 'a passing verdict says "would pass" in text, not only in green' );
he_assert( false !== strpos( $html, '<span aria-hidden="true">✕</span>' ), 'a sighted reader who cannot separate the hues gets a glyph' );
he_assert( false !== strpos( $html, '<span aria-hidden="true">✓</span>' ), 'and the pass glyph too' );
he_assert( false !== strpos( $html, 'sn-swatch' ), 'the token legend carries colour swatches' );
he_assert( false !== strpos( $html, 'background-color:#b3421a' ), 'a swatch carries its palette hex (the one legitimate inline style — the value IS the data)' );
he_assert( false !== strpos( $html, '>ink</span>' ), 'the legend names token SLUGS, not just hexes' );

$glance_at   = strpos( $html, '<div class="sn-glance">' );
$scan_at     = strpos( $html, 'value="health_scan"' );
$findings_at = strpos( $html, '<h2 class="sn-section-h">Findings</h2>' );
$reports_at  = strpos( $html, '<h2 class="sn-section-h">Reports</h2>' );
$passing_at  = strpos( $html, 'sn-health-passing' );
he_assert( is_int( $glance_at ) && is_int( $scan_at ) && $glance_at < $scan_at, 'hero precedes the run-scan card' );
he_assert( is_int( $findings_at ) && $scan_at < $findings_at, 'run-scan precedes the findings' );
he_assert( is_int( $reports_at ) && $findings_at < $reports_at, 'findings (which demand action) precede reports (which do not)' );
he_assert( is_int( $passing_at ) && $reports_at < $passing_at, 'reports precede the passing disclosure (which asks nothing)' );

// ─── Test A3 (IA increment H1): the usage FAILURE TABLE folds ───────────────
// The headline, palette line, limits sentence, and conditional stay OPEN —
// the honesty layer is not collapsible. Only the row table (and its remainder
// line) sits behind an explicit closed <details>, summary carrying the count.
echo "\nTest A3: usage failure table behind a closed disclosure (H1)\n";
$GLOBALS['__scan']['checks']['contrast_tokens']['report']['usage'] = array(
	'scanned'  => 4,
	'pairings' => 12,
	'palettes' => array( 'root' ),
	'failures' => array(
		array( 'selector' => '.sn-x', 'source' => 'x.css', 'pair' => 'rust on paper', 'ratio' => 3.1, 'palette' => 'root', 'literal' => false, 'anchored' => true ),
		array( 'selector' => '.sn-y', 'source' => 'y.css', 'pair' => 'rust on bone', 'ratio' => 4.2, 'palette' => 'root', 'literal' => false, 'anchored' => true ),
	),
	'conditional' => array(),
);
ob_start();
sn_health_render_admin_tab();
$html3 = ob_get_clean();
he_assert( false !== strpos( $html3, '<details class="sn-health-contrast-usage' ), 'the usage table sits inside its own details' );
he_assert( false === strpos( $html3, '<details class="sn-health-contrast-usage" open' ), 'and it is CLOSED by default — the headline carries the verdict, the table is the evidence' );
$d_at = strpos( $html3, '<details class="sn-health-contrast-usage' );
$h_at = strpos( $html3, 'fall below body-text AA' );
$l_at = strpos( $html3, 'Reads stylesheet declarations at rest' );
he_assert( is_int( $h_at ) && is_int( $d_at ) && $h_at < $d_at, 'the failing-count HEADLINE renders before (outside) the fold' );
he_assert( is_int( $l_at ) && $l_at < $d_at, 'the limits sentence renders before (outside) the fold — a reader who trusts the headline still meets the caveat' );
$sum = substr( $html3, $d_at, 300 );
he_assert( false !== strpos( $sum, '2' ) && false !== strpos( $sum, 'failing' ), 'the summary names the failing count — a closed fold may never hide THAT there is something inside' );
$t_at = strpos( $html3, '.sn-x' );
$dc_at = strpos( $html3, '</details>', $d_at );
he_assert( is_int( $t_at ) && is_int( $dc_at ) && $d_at < $t_at && $t_at < $dc_at, 'the failure rows live inside the fold' );
unset( $GLOBALS['__scan']['checks']['contrast_tokens']['report']['usage'] );

// ─── Test B: NO scan — hero shows the no-scan card, no tables ────────────────
echo "\nTest B: Health with no scan — no-scan hero, no tables\n";
$GLOBALS['__scan'] = null;
ob_start();
sn_health_render_admin_tab();
$html2 = ob_get_clean();
he_assert( false === strpos( $html2, 'sn-shell' ), 'no shell on the no-scan path' );
he_assert( false !== strpos( $html2, 'no scan' ), 'hero shows the no-scan card' );
he_assert( false !== strpos( $html2, '>Run scan<' ), 'run-scan button reads "Run scan" before any scan' );
he_assert( false === strpos( $html2, '<table class="widefat striped' ), 'no finding tables without a scan' );
he_assert( false === strpos( $html2, '<h2 class="sn-section-h">Findings</h2>' ), 'no Findings section without a scan' );
he_assert( false === strpos( $html2, 'SNT-OPPS-MARKER' ), 'no pattern-adoption card on the no-scan path either (it lives on its own leaf now)' );
he_assert( false === strpos( $html2, 'sn-health-passing' ), 'no passing strip without a scan' );
he_assert( false === strpos( $html2, '<h2 class="sn-section-h">Reports</h2>' ), 'no Reports section without a scan' );

// ─── Test C: clean scan — pass board only, no Findings section/table ─────────
echo "\nTest C: clean scan — pass board only, no Findings section\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 600,
	'elapsed_ms' => 300,
	'checks'     => array(
		'missing_alt' => array( 'label' => 'Missing alt text', 'count' => 0, 'fix_hint' => '', 'findings' => array() ),
	),
);
ob_start();
sn_health_render_admin_tab();
$html3 = ob_get_clean();
he_assert( false !== strpos( $html3, 'all clear' ), 'hero pills all-clear when nothing is found' );
he_assert( false === strpos( $html3, '<h2 class="sn-section-h">Findings</h2>' ), 'no Findings section when all checks pass' );
he_assert( false === strpos( $html3, '<table class="widefat striped' ), 'no finding table when clean' );
he_assert( false !== strpos( $html3, 'sn-health-passing' ), 'passing disclosure present for the clean check' );
he_assert( false !== strpos( $html3, 'All 1 check passing' ), 'all-clear heading (singular check)' );
he_assert( false === strpos( $html3, 'report-only' ), 'no report-only suffix when the scan has no report checks' );
he_assert( false !== strpos( $html3, '<span class="sn-badge">Missing alt text</span>' ), 'clean check named as a chip' );
he_assert( false !== strpos( $html3, 'sn-pill--ok' ), 'disclosure carries the single ok pill (not one per check)' );
he_assert( false === strpos( $html3, '<h2 class="sn-section-h">Reports</h2>' ), 'no Reports section when nothing packs a report' );

// ─── Test D: a report-only check ALONE — Reports renders, no passing chip ────
// The v10.82.0 shipping state in miniature: one check, zero findings, a full
// payload. Before v10.83.0 this rendered as a single green chip and nothing else.
echo "\nTest D: report-only check alone — payload rendered, never chipped as a pass\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 60,
	'elapsed_ms' => 40,
	'checks'     => array(
		'contrast_tokens' => array(
			'label'    => 'Contrast (token arithmetic, report only)',
			'count'    => 0,
			'fix_hint' => '',
			'findings' => array(),
			'report'   => array(
				'coverage'        => 'Arithmetic tier only.',
				'thresholds'      => array( 'aa_body' => 4.5, 'aa_large' => 3.0 ),
				'tokens'          => array( 'ink' => '#111111', 'paper' => '#ffffff' ),
				'pairs'           => array( array( 'pair' => 'ink / paper', 'ratio' => 18.88, 'aa_body' => true, 'aa_large' => true ) ),
				'would_fail_body' => 0,
			),
		),
	),
);
ob_start();
sn_health_render_admin_tab();
$html4 = ob_get_clean();
he_assert( false !== strpos( $html4, '<h2 class="sn-section-h">Reports</h2>' ), 'Reports section renders for a report-only-only scan' );
he_assert( false !== strpos( $html4, 'ink / paper' ), 'its pair table renders' );
he_assert( false === strpos( $html4, 'sn-health-passing' ), 'NO passing disclosure — nothing actually passed, so the card would be a lie' );
he_assert( false === strpos( $html4, '<h2 class="sn-section-h">Findings</h2>' ), 'no Findings section (it raises none by design)' );
$cards4 = snt_health_glance_cards( $GLOBALS['__scan'] );
he_assert( '0 / 1' === $cards4[1]['value'], 'hero reads 0 / 1 passed — the report-only check is not a pass' );

// ─── Test E: an UNKNOWN report-only check still gets a home ──────────────────
// The regression this whole change exists to prevent: a report payload with no
// bespoke renderer must degrade, never disappear.
echo "\nTest E: a report with no bespoke renderer degrades, never disappears\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 60,
	'elapsed_ms' => 40,
	'checks'     => array(
		'some_future_report' => array(
			'label'    => 'A future report',
			'count'    => 0,
			'fix_hint' => '',
			'findings' => array(),
			'report'   => array( 'coverage' => 'Measures a thing not yet renderable.', 'blob' => array( 1, 2, 3 ) ),
		),
	),
);
ob_start();
sn_health_render_admin_tab();
$html5 = ob_get_clean();
he_assert( false !== strpos( $html5, 'A future report' ), 'the unknown report-only check is NAMED on the tab' );
he_assert( false !== strpos( $html5, 'Measures a thing not yet renderable.' ), 'its coverage sentence still renders (the fallback)' );
he_assert( false !== strpos( $html5, 'no detail view yet' ), 'the fallback says plainly that the detail is unrendered' );
he_assert( false === strpos( $html5, 'sn-health-passing' ), 'and it is still not counted as a pass' );

// ─── Test F: a report check that ALSO reports findings lands in ONE bucket ──
// Defensive: today's only report-only check hardcodes zero findings, but
// sn_health_pack_check() does not enforce that. A future report with count>0
// must not render in Findings AND Reports at once.
echo "\nTest F: a report payload wins the bucket — never both sections\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 60,
	'elapsed_ms' => 40,
	'checks'     => array(
		'contrast_tokens' => array(
			'label'    => 'Contrast (token arithmetic, report only)',
			'count'    => 2,
			'fix_hint' => '',
			'findings' => array( array( 'subject_label' => 'LEAKED-INTO-FINDINGS', 'note' => 'x' ) ),
			'report'   => array( 'coverage' => 'Arithmetic tier only.', 'pairs' => array( array( 'pair' => 'a / b', 'ratio' => 1.5, 'aa_body' => false, 'aa_large' => false ) ) ),
		),
	),
);
ob_start();
sn_health_render_admin_tab();
$html6 = ob_get_clean();
he_assert( false === strpos( $html6, 'LEAKED-INTO-FINDINGS' ), 'a check carrying a report never renders a findings table too' );
he_assert( false === strpos( $html6, '<h2 class="sn-section-h">Findings</h2>' ), 'no Findings section for a report-bucket check' );
he_assert( false !== strpos( $html6, '<h2 class="sn-section-h">Reports</h2>' ), 'it renders in Reports, exactly once' );

// ─── Test G: link isolation (ML pipeline #8) — its first surface ────────────
// The renderer consumes only the PUBLISHED ENVELOPE SHAPE; it never calls
// snt_ml_link_isolation(), which lives on a separate unmerged branch. So this
// suite builds the envelope by hand — that independence is the point, not a
// shortcut, and it is what lets the two land in either order.
//
// THE LOAD-BEARING ASSERTION IS isolated_total. The producer caps `isolated`
// and publishes the true total beside it precisely so a capped list cannot
// read as "that is all there is". A renderer that showed the rows and dropped
// the total would discard the one field keeping the surface honest — silently.
echo "\nTest G: link isolation — the capped list never poses as the whole truth\n";
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 60,
	'elapsed_ms' => 90,
	'checks'     => array(
		'link_isolation' => array(
			'label'    => 'Link isolation (notes nothing links to)',
			'count'    => 0,
			'fix_hint' => '',
			'findings' => array(),
			'report'   => array(
				'coverage'       => 'Inbound links from other PUBLISHED notes only.',
				'isolated'       => array(
					array( 'post_id' => 11, 'title' => 'Stranded both ways', 'slug' => 'stranded', 'outbound_count' => 0 ),
					array( 'post_id' => 12, 'title' => 'Dead end', 'slug' => 'dead-end', 'outbound_count' => 3 ),
				),
				'isolated_count' => 2,
				'isolated_total' => 47,
				'posts_scanned'  => 120,
				'truncated'      => true,
			),
		),
	),
);
ob_start();
sn_health_render_admin_tab();
$html7 = ob_get_clean();
he_assert( false !== strpos( $html7, '47 of 120 published notes have no inbound link' ), 'the headline states the TRUE total (47), never the capped row count (2)' );
he_assert( false !== strpos( $html7, 'Showing 2 of 47 isolated notes' ), 'the truncation line names both numbers explicitly' );
he_assert( false !== strpos( $html7, 'the list is capped, not complete' ), 'and says in words that the list is not the whole set' );
he_assert( false !== strpos( $html7, 'Inbound links from other PUBLISHED notes only.' ), 'its coverage sentence renders like every other report' );
he_assert( false !== strpos( $html7, 'Stranded both ways' ), 'isolated notes are listed' );
he_assert( false !== strpos( $html7, '>both ways</span>' ), 'a note isolated in BOTH directions is marked as more stranded than a dead end' );
he_assert( false === strpos( $html7, 'no detail view yet' ), 'it uses its bespoke renderer, not the degrading fallback' );
he_assert( false === strpos( $html7, 'sn-health-passing' ), 'and it is not counted as a passing check' );

// The dropped-total regression, stated as its own case: an envelope WITHOUT
// isolated_total must fall back to the row count, never to silence.
echo "\nTest G2: an older envelope with no isolated_total degrades honestly\n";
$GLOBALS['__scan']['checks']['link_isolation']['report'] = array(
	'coverage'      => 'Inbound links only.',
	'isolated'      => array( array( 'post_id' => 11, 'title' => 'Only one', 'slug' => 'one', 'outbound_count' => 1 ) ),
	'posts_scanned' => 9,
);
ob_start();
sn_health_render_admin_tab();
$html8 = ob_get_clean();
he_assert( false !== strpos( $html8, '1 of 9 published notes have no inbound link' ), 'falls back to the row count when the producer omitted the total' );
he_assert( false === strpos( $html8, 'the list is capped' ), 'and does NOT claim truncation it cannot know about' );

// ─── CSS contract: every class the render emits has stylesheet backing ──────
echo "\nCSS contract: classes exist in assets/admin.css (enqueued, never inlined)\n";
$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );

// ── The stylesheet must PARSE, not merely contain the strings ──────────────
// A substring search for a selector proves the characters are on disk. It
// proves nothing about whether a browser ever reaches that rule. This suite
// learned it the hard way in v10.83.0: an editing slip left a comment
// paragraph unwrapped, so a stray `*/` corrupted the block and Chrome silently
// dropped most of the new disclosure styling — while every strpos() assertion
// below stayed green, because the class names were all still present as text.
//
// A stray `*/` outside a comment is the unambiguous signature of that slip, so
// walk the delimiters instead of trusting a regex. This is the repo's standing
// lesson in CSS form: a rule's PRESENCE is not its APPLICATION.
$in_comment = false;
$stray_at   = 0;
$cursor     = 0;
$css_len    = strlen( $css );
while ( $cursor < $css_len ) {
	if ( $in_comment ) {
		$close = strpos( $css, '*/', $cursor );
		if ( false === $close ) {
			$stray_at = -1; // Unterminated comment — everything after it is dead.
			break;
		}
		$in_comment = false;
		$cursor     = $close + 2;
		continue;
	}
	$open  = strpos( $css, '/*', $cursor );
	$close = strpos( $css, '*/', $cursor );
	if ( false !== $close && ( false === $open || $close < $open ) ) {
		// A comment terminator reached while in CODE: nothing opened it.
		$stray_at = substr_count( substr( $css, 0, $close ), "\n" ) + 1;
		break;
	}
	if ( false === $open ) {
		break;
	}
	$in_comment = true;
	$cursor     = $open + 2;
}
he_assert(
	0 === $stray_at,
	-1 === $stray_at
		? 'admin.css has an UNTERMINATED comment — everything after it is dead CSS'
		: ( 0 === $stray_at
			? 'admin.css comment delimiters are balanced — no orphaned `*/` silently killing rules'
			: "admin.css has a STRAY `*/` at line {$stray_at} — the rules after it are dead in a browser even though their text is on disk" )
);
// v10.46.0: the action-row rules are DEAD once the wrapper is gone — this
// suite's call site was their only one, so their absence is the contract now.
he_assert( false === strpos( $css, '.sn-health-actions {' ), '.sn-health-actions grid CSS is REMOVED with its only call site' );
he_assert( false === strpos( $css, '.sn-health-actions .sn-fieldset' ), 'the action-row uncap rules are removed too' );
he_assert( false !== strpos( $css, '.sn-health-passing' ), '.sn-health-passing uncap CSS exists' );
// v10.83.0: the new IA's classes. A class the render emits with no rule behind
// it is an unstyled element in production — the .sn-badge lesson (v4.1.1).
foreach ( array(
	'.sn-health-passing__summary',
	'.sn-health-passing__family-label',
	'.sn-health-passing__names',
	'.sn-health-reports .sn-fieldset',
	'.sn-health-reports__intro',
	'.sn-health-report__coverage',
	'.sn-health-report__headline',
	'.sn-health-report__tokens',
	'.sn-badge--token',
	'.sn-swatch',
) as $cls ) {
	he_assert( false !== strpos( $css, $cls ), "CSS rule exists for {$cls}" );
}
// The disclosure must be operable by keyboard: <details>/<summary> handles the
// toggle, but list-style:none removes the default marker, so a focus style and
// a replacement caret both have to be real rules, not assumptions.
he_assert( false !== strpos( $css, '.sn-health-passing__summary:focus-visible' ), 'the summary has a visible focus style (list-style:none strips the default affordance)' );
he_assert( false !== strpos( $css, '.sn-health-passing[open] .sn-health-passing__summary::before' ), 'the caret reflects the open state' );
// And no inline LAYOUT crept into the PHP (colour swatches are the one
// data-driven exception, asserted positively in Test A2).
$render_php = (string) file_get_contents( __DIR__ . '/../inc/health-render-passing.php' )
	. (string) file_get_contents( __DIR__ . '/../inc/health-render-reports.php' )
	. (string) file_get_contents( __DIR__ . '/../inc/health-render-contrast.php' );
he_assert( 0 === preg_match( '/style="(?!background-color:)/', $render_php ), 'no inline style attributes in the render modules except the palette swatch' );
// Non-vacuity: the regex above must actually be looking at something. If the
// files stopped containing ANY style attribute the assertion would pass while
// proving nothing, so pin that the one permitted exception is present.
he_assert( 1 === preg_match( '/style="background-color:/', $render_php ), 'the swatch IS the one style attribute present (so the rule above is not vacuous)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
