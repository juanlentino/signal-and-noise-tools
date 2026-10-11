<?php
/**
 * Native window leaf: Monitoring → Health (apps/sn-dashboard/parts/leaves/monitoring-health.php).
 *
 * The oracle is the classic leaf (`sn_health_render_admin_tab()`,
 * inc/health-checks-admin.php): the kit leaf must carry the same one action
 * (`health_scan`), no field names at all (the classic form has none besides
 * the shared nonce), the same hero numbers, the same finding/passing/skipped
 * readouts for a rich fixture, and none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-monitoring-health.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own reader: sn_health_last_scan(), fixture-controlled. ──
$GLOBALS['__health_scan'] = null;
function sn_health_last_scan() {
	return $GLOBALS['__health_scan'];
}

// ── The AI gate both leaves read, fixture-controlled. ──
$GLOBALS['__ai'] = false;
function snt_ai_is_available() {
	return $GLOBALS['__ai'];
}

// The Suggest / Suggest-all data attributes a blob carries, sorted: the same
// oracle the pattern-adoption suite uses to pin the kit row to the classic cell.
function snt_health_suggest_attrs( $html ) {
	preg_match_all( '/\s(data-(?:snt-suggest|snt-suggest-all|snt-suggest-all-max|check|attachment-id|post-id|image-src))="([^"]*)"/', (string) $html, $m );
	$pairs = array();
	foreach ( $m[1] as $i => $name ) { $pairs[] = $name . '=' . $m[2][ $i ]; }
	$pairs = array_values( array_unique( $pairs ) );
	sort( $pairs );
	return $pairs;
}

// ── Pure accessor + family/surface modules (no WordPress DB calls). ──
require SNT_PATH . 'inc/health-check-families.php';
require SNT_PATH . 'inc/health-check-surfaces.php';
require SNT_PATH . 'inc/health-summary.php';
require SNT_PATH . 'inc/admin-glance.php';

// ── The classic renderer + its section modules. ──
require SNT_PATH . 'inc/health-checks-admin.php';
require SNT_PATH . 'inc/health-render-findings.php';
require SNT_PATH . 'inc/health-render-passing.php';
require SNT_PATH . 'inc/health-render-reports.php';

// ── The kit leaf. ──
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-health.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++; echo "PASS: $m\n";
	} else {
		$fail++; echo "FAIL: $m\n";
	}
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['monitoring/health'] ), 'the painter is registered under monitoring/health' );

// ── State 1: no scan yet. Hero shows the "no scan" card, the form offers
// "Run scan", and nothing below it renders (mirrors the classic early return).
$classic = snt_leaf_classic_html( 'sn_health_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'health' );
ok( '' !== $kit, 'the kit leaf paints with no scan' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array() === snt_leaf_names( $kit ), 'no scan: both forms carry only the action + its nonce (the run-scan form has no real fields)' );
ok( array( 'health_scan' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'no scan: the one action is health_scan, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no scan: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'no scan' ) && false !== strpos( $kit, 'Run scan' ), 'no scan: the hero reads "no scan" and the button reads "Run scan"' );
ok( false === strpos( $kit, 'Findings' ) && false === strpos( $kit, 'Also scanned' ), 'no scan: no section below the form renders (mirrors the classic early return)' );
ok( array() === snt_leaf_folds( $kit ), 'no scan: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );

// ── State 2: a rich scan. Two health-surfaced faults (one with a hidden-row
// remainder), one health-surfaced passing check, one skipped check, plus one
// worklist-surfaced and one integrity-surfaced check for the "elsewhere" index.
$findings_alpha = array();
for ( $i = 0; $i < 52; $i++ ) {
	$findings_alpha[] = array(
		'subject_label' => 'Post ' . $i,
		'subject_url'   => 'https://example.test/post-' . $i,
		'note'          => 'missing width',
		'edit_url'      => 'https://example.test/wp-admin/post.php?post=' . $i . '&action=edit',
	);
}
$GLOBALS['__health_scan'] = array(
	'scanned_at' => time() - 3600,
	'elapsed_ms' => 812,
	'checks'     => array(
		'test_alpha_check' => array(
			'label'     => 'Alpha check',
			'count'     => 52,
			'fix_hint'  => 'Add alt text to every image.',
			'findings'  => $findings_alpha,
		),
		'test_beta_check'  => array(
			'label'  => 'Beta check',
			'count'  => 1,
			'findings' => array(
				array( 'subject_label' => 'Malicious <b>title</b>', 'note' => '"><script>x</script>', 'edit_url' => '' ),
			),
		),
		'missing_alt'      => array(
			'label'    => 'Missing alt',
			'count'    => 2,
			'findings' => array(
				array( 'subject_type' => 'attachment', 'subject_id' => 77, 'subject_label' => 'hero.jpg', 'note' => 'no alt', 'edit_url' => 'https://example.test/wp-admin/post.php?post=77&action=edit' ),
				array( 'subject_type' => 'inline_svg', 'subject_id' => 78, 'subject_label' => 'logo.svg', 'note' => 'no title', 'edit_url' => '' ),
			),
		),
		'test_gamma_check' => array(
			'label' => 'Gamma check',
			'count' => 0,
		),
		'test_delta_check' => array(
			'label'   => 'Delta check',
			'count'   => 0,
			'skipped' => 'AI provider not configured',
			'fix_hint' => 'Configure an AI provider.',
		),
		'link_opportunities' => array(
			'label' => 'Link opportunities',
			'count' => 4,
		),
		'contrast_tokens'    => array(
			'label'  => 'Contrast tokens',
			'count'  => 0,
			'report' => array( 'coverage' => 'Every published palette pair.' ),
		),
	),
);

$classic = snt_leaf_classic_html( 'sn_health_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'health' );

ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array() === snt_leaf_names( $kit ), 'rich scan: still just the action + its nonce on either leaf' );
ok( array( 'health_scan' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'rich scan: still the one health_scan action' );
ok( array() === snt_leaf_classic_markers( $kit ), 'rich scan: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'Re-run scan' ), 'rich scan: the button relabels to "Re-run scan"' );
ok( false !== strpos( $kit, '55 findings' ), 'rich scan: the hero total is 55 findings (52 + 1 + 2, worklist-surfaced link_opportunities excluded)' );
ok( false !== strpos( $kit, '812ms' ), 'rich scan: the last-scan elapsed time is formatted (812ms)' );
ok( false !== strpos( $kit, 'Alpha check' ) && false !== strpos( $kit, '52 findings' ), 'rich scan: the Alpha check card shows its label and its 52-finding count' );
ok( false !== strpos( $kit, '+2 more findings' ), 'rich scan: the 52-row table caps at 50 and names the remainder' );
ok( false !== strpos( $kit, 'Gamma check' ), 'rich scan: the zero-finding, non-skipped Gamma check appears in the passing disclosure' );
ok( false !== strpos( $kit, 'heading="Could not run"' ) && false !== strpos( $kit, '1 check produced no evidence' ) && false !== strpos( $kit, 'Delta check' ) && false !== strpos( $kit, 'AI provider not configured' ), 'rich scan: the skipped Delta check names itself and its reason, under an open "Could not run" section that counts it (was the "1 check could not run" fold heading)' );
ok( false !== strpos( $kit, 'Also scanned, shown elsewhere' ) && false !== strpos( $kit, 'Link opportunities (4)' ) && false !== strpos( $kit, 'Contrast tokens' ), 'rich scan: the elsewhere index names the worklist- and integrity-surfaced checks' );
ok( false === strpos( $kit, 'Findings') || ( false === strpos( $kit, 'Link opportunities') || strpos($kit, 'Link opportunities') > strpos($kit, 'Also scanned') ), 'rich scan: link_opportunities (worklist-surfaced) is not double-counted into the on-tab Findings section' );

// ── 2026-10-11, owner rule: no folds. Every figure the passing and skipped
// folds carried is painted open: the summary line, each family and its chips,
// each skipped check with "not measured" and its reason (never "clear" or 0).
ok( array() === snt_leaf_folds( $kit ), 'rich scan: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, '<os-section heading="Passing checks" stack><p class="snt-prose">1 of 5 checks passing, across 1 family.</p>' ), 'rich scan: the passing summary (the old fold heading) is the open section\'s lead sentence' );
ok( false !== strpos( $kit, '<os-fact label="Other checks"><span class="snt-kv__v"><os-cluster gap="6"><os-chip>Gamma check</os-chip></os-cluster></span></os-fact>' ), 'rich scan: a passing family is a fact: the family as its label, its chips as its value (the spec-sheet band)' );
ok( false !== strpos( $kit, '<os-section heading="Could not run" stack><p class="snt-prose">1 check produced no evidence either way this scan, so it reads not measured and is not counted as passed.</p>' ), 'rich scan: the skipped count (the old fold heading) is the open section\'s lead sentence' );
$hx_delta = preg_match( '#<os-fact label="Delta check">(.*?)</os-fact>#s', $kit, $hx_m ) ? $hx_m[1] : '';
ok( false !== strpos( $hx_delta, 'not measured' ) && false !== strpos( $hx_delta, 'AI provider not configured' ) && false !== strpos( $hx_delta, 'Configure an AI provider.' ), 'rich scan: the skipped Delta check reads not measured, with its reason and its fix hint: ' . strip_tags( $hx_delta ) );
ok( '' !== $hx_delta && false === stripos( $hx_delta, 'clear' ) && false === stripos( $hx_delta, 'holding' ) && 0 === preg_match( '/>\s*0\s*</', $hx_delta ) && false === strpos( $kit, '<os-chip>Delta check</os-chip>' ), 'rich scan: the skipped check never reads clear, holding or 0, and is not a passing chip' );
ok( strpos( $kit, 'heading="Could not run"' ) < strpos( $kit, 'heading="Passing checks"' ), 'rich scan: the checks that could not run read before the passing ones' );
ok( 1 === substr_count( $kit, 'class="snt-hint snt-more"' ), 'rich scan: exactly one "+N more" line, under the one capped table (Alpha, 52 rows); Beta (1) and Missing alt (2) carry none' );

// ── The advisory card (unreachable from health_data(): every advisory key is
// worklist-surfaced) still paints a capped ledger, never a fold.
$hx_adv_rows = array();
for ( $i = 0; $i < 60; $i++ ) {
	$hx_adv_rows[] = array( 'subject_label' => 'Tag ' . $i, 'note' => 'undescribed', 'edit_url' => '' );
}
$hx_adv = \SignalNoise\OpenStationHost\Dashboard\Leaves\health_finding_card_html( 'tag_hygiene', array( 'label' => 'Tag hygiene', 'count' => 60, 'fix_hint' => 'Describe each tag.', 'findings' => $hx_adv_rows ), true, false );
$hx_adv_t = snt_leaf_tables( $hx_adv );
ok( array() === snt_leaf_folds( $hx_adv ) && 1 === count( $hx_adv_t ) && 50 === count( $hx_adv_t[0]['data'] ) && false !== strpos( $hx_adv, '+10 more advisories: re-run scan after fixing the top batch.' ) && false !== strpos( $hx_adv, '>60 advisories</os-badge>' ), 'advisory card: no fold; the count (was the "Show 60 advisories" fold heading) on the badge, a ledger of the first 50 and a "+10 more" line' );
ok( false !== strpos( $hx_adv, '<p class="snt-prose">Describe each tag.</p>' ) && strpos( $hx_adv, 'Describe each tag.' ) < strpos( $hx_adv, '<os-table' ), 'advisory card: the ledger is led by its sentence' );
$hx_adv3 = \SignalNoise\OpenStationHost\Dashboard\Leaves\health_finding_card_html( 'tag_hygiene', array( 'label' => 'Tag hygiene', 'count' => 3, 'findings' => array_slice( $hx_adv_rows, 0, 3 ) ), true, false );
ok( array() === snt_leaf_folds( $hx_adv3 ) && false === strpos( $hx_adv3, 'snt-more' ), 'advisory card at or under the cap: no "+N more" line' );

// ── Escaping: a hostile subject/note never reaches the markup raw.
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, '<b>title</b>' ), 'rich scan: a hostile finding subject/note is escaped' );

// ── The rows: the Block Migrations shape (os-row + os-cluster), Edit as a
// link, never the raw URL as text; no AI column without a provider.
// 17.9.0 (#1624): the rows are an os-table now; read its columns and cells.
$hx_tables = snt_leaf_tables( $kit );
$hx_labels = array_map( static function ( $t ) { return array_column( $t['columns'], 'label' ); }, $hx_tables );
ok( 3 === count( $hx_tables ) && array( array( 'Subject', 'Note', 'Edit' ) ) === array_values( array_unique( $hx_labels, SORT_REGULAR ) ) && false === strpos( $kit, 'AI fix' ), 'no provider: three check tables, each Subject, Note, Edit, no AI fix column' );
/** The row of $table whose subject reads $subject, or null. */
function hx_row( array $table, $subject ) {
	foreach ( $table['data'] as $row ) {
		if ( is_array( $row['subject'] ?? null ) && $subject === ( $row['subject']['text'] ?? null ) ) {
			return $row;
		}
	}
	return null;
}
/** The first row in any table whose subject reads $subject: [table, row] or [null, null]. */
function hx_find( array $tables, $subject ) {
	foreach ( $tables as $t ) {
		$r = hx_row( $t, $subject );
		if ( null !== $r ) {
			return array( $t, $r );
		}
	}
	return array( null, null );
}
// #1624 acceptance: a real table, no hand-rolled header, a card on a phone.
ok( false !== strpos( $kit, '<os-table' ) && 0 === substr_count( $kit, 'role="columnheader"' ) && 0 === substr_count( $kit, 'role="row"' ), '#1624: the findings are an os-table, with no hand-rolled header row (the passing-checks chip row is not a table, see #1600)' );
ok( 3 === substr_count( $kit, 'data-snt-stack-on-phone' ), '#1624: every findings table is marked to stack on a phone' );
list( $hx_t, $hx_r ) = hx_find( $hx_tables, 'Post 0' );
ok( null !== $hx_r && '<os-code>Post 0</os-code>' === snt_leaf_cell_html( $hx_t, $hx_r['subject'] ) && 'missing width' === $hx_r['note'] && '<os-button class="snt-link" variant="link" os-action="door" os-arg-url="https://example.test/wp-admin/post.php?post=0&amp;action=edit">Edit</os-button>' === snt_leaf_cell_html( $hx_t, $hx_r['edit'] ), 'no provider: a finding is a row of subject, note and an Edit door (the URL is a link, not text)' );
ok( false === strpos( $kit, 'data-snt-suggest' ) && false !== strpos( $classic, 'Missing alt' ) && false === strpos( $classic, 'data-snt-suggest' ), 'no provider: no Suggest button on either leaf' );

// ── With a provider: the AI fix column paints on the supported check only,
// with the classic cell's exact data contract on a kit button in the cluster.
$GLOBALS['__ai'] = true;
$classic = snt_leaf_classic_html( 'sn_health_render_admin_tab' );
$kit     = snt_leaf_paint( 'monitoring', 'health' );
$GLOBALS['__ai'] = false;
ok( array() === snt_leaf_classic_markers( $kit ), 'provider: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
$hx_tables = snt_leaf_tables( $kit );
$hx_ai     = array_filter( $hx_tables, static function ( $t ) { return in_array( 'AI fix', array_column( $t['columns'], 'label' ), true ); } );
ok( 3 === count( $hx_tables ) && 1 === count( $hx_ai ) && 1 === substr_count( $classic, '>AI fix<' ), 'provider: the AI fix column paints on the one supported check (missing_alt), as on the classic leaf' );
$hx_ai_t = reset( $hx_ai );
$hx_77   = null;
foreach ( $hx_ai_t['data'] as $hx_row ) {
	if ( false !== strpos( snt_leaf_cell_html( $hx_ai_t, $hx_row['ai'] ?? null ), 'data-attachment-id="77"' ) ) {
		$hx_77 = $hx_row;
	}
}
ok( null !== $hx_77 && '<os-button variant="secondary" data-snt-suggest="1" data-check="missing_alt" data-attachment-id="77">Suggest</os-button>' === snt_leaf_cell_html( $hx_ai_t, $hx_77['ai'] ), 'provider: the attachment finding paints Suggest in its AI fix cell, with the classic data contract' );
$hx_svg = hx_row( $hx_ai_t, 'logo.svg' );
ok( null !== $hx_svg && '' === snt_leaf_cell_html( $hx_ai_t, $hx_svg['ai'] ) && 1 === substr_count( $kit, 'data-snt-suggest="1"' ), 'provider: the inline_svg finding paints an empty AI fix cell, the classic no-button path' );
ok( snt_health_suggest_attrs( $classic ) === snt_health_suggest_attrs( $kit ) && 0 < count( snt_health_suggest_attrs( $kit ) ), 'provider: the Suggest / Suggest-all data attributes match the classic leaf exactly: ' . implode( ' ', snt_health_suggest_attrs( $kit ) ) );
ok( 1 === preg_match( '/<os-card class="snt-check"><header><h3>Missing alt<\/h3><os-badge[^>]*>2 findings<\/os-badge><os-button variant="secondary" data-snt-suggest-all="1" data-snt-suggest-all-max="50">Suggest all 2<\/os-button><\/header>/', $kit ), 'provider: the check is an os-card whose header row carries the title, the badge and Suggest all 2 with the batch cap the shared script reads (#1600)' );
ok( 1 === preg_match( '/<os-section heading="Accessibility" stack>/', $kit ) && 1 === preg_match( '/<os-section heading="Other checks" stack>/', $kit ) && false === strpos( $kit, 'heading="Findings"' ), '#1600: each family is its own os-section (Accessibility for missing_alt, Other checks for the test keys); no Findings wrapper, so no section nests in a section' );
ok( false !== strpos( $kit, '<os-fact label="Other checks"><span class="snt-kv__v"><os-cluster gap="6"><os-chip>Gamma check</os-chip></os-cluster></span></os-fact>' ) && false === strpos( $kit, '<os-row' ), '#1600, 2026-10-11: a passing family is a fact of the family label and an os-cluster of chips, open (was an os-row inside a fold)' );
ok( array() === snt_leaf_folds( $kit ), 'provider: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );

// ── A javascript: edit_url is blanked, not painted as a door, the way the
// classic cell's esc_url() blanks it (the Block Migrations permalink rule).
$GLOBALS['__health_scan']['checks']['test_beta_check']['findings'][0]['edit_url'] = 'javascript:alert(1)';
$kit = snt_leaf_paint( 'monitoring', 'health' );
ok( false === strpos( $kit, 'javascript:' ), 'a javascript: edit_url is blanked, not linked, matching esc_url()' );
ok( array() === snt_leaf_folds( $kit ), 'javascript edit_url: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );

// ── Live-shaped preview. The live scan (2026-10-11) holds 18 Health-surface
// checks in 7 families; the brief's volume is ~60, so 42 extra keys pad it
// (an unmapped key lands in Other checks, the family map is not a fixture).
// Advisories cannot reach this surface (every advisory key is worklist-
// surfaced), so the capped table here is a fault check with 64 findings.
$hx_live = array();
foreach ( array( 'missing_alt' => 'Missing alt', 'broken_links' => 'Broken internal links', 'stale_posts' => 'Stale posts', 'drift_time_phrases' => 'Time-relative drift', 'color_drift' => 'Color drift', 'roadmap_drift' => 'Roadmap drift', 'search_titles' => 'Search-shaped titles', 'jev_notes' => 'Jev notes', 'cf_security_headers' => 'Security headers', 'cf_edge_posture' => 'Edge posture', 'machine_reader_liveness' => 'Machine-reader liveness', 'analytics_integrity' => 'Analytics integrity', 'analytics_ingest' => 'Analytics ingest', 'gsc_history_stalled' => 'Search Console history', 'zenodo_doi' => 'Zenodo DOIs', 'plugin_registry' => 'Plugin registry', 'wp_cron_request_path' => 'Cron request path', 'theme_presets' => 'Theme presets' ) as $hx_k => $hx_l ) {
	$hx_live[ $hx_k ] = array( 'label' => $hx_l, 'count' => 0, 'skipped' => null );
}
for ( $i = 1; $i <= 42; $i++ ) {
	$hx_live[ sprintf( 'extra_check_%02d', $i ) ] = array( 'label' => sprintf( 'Extra check %02d', $i ), 'count' => 0, 'skipped' => null );
}
$hx_broken = array();
for ( $i = 1; $i <= 64; $i++ ) {
	$hx_broken[] = array( 'subject_label' => 'Note ' . $i, 'note' => '404 at /notes/gone-' . $i . '/', 'edit_url' => 'https://example.test/wp-admin/post.php?post=' . $i . '&action=edit' );
}
$hx_live['broken_links']  = array( 'label' => 'Broken internal links', 'count' => 64, 'fix_hint' => 'Point each link at a live page or remove it; the scan re-checks on the next run.', 'findings' => $hx_broken );
$hx_live['search_titles'] = array( 'label' => 'Search-shaped titles', 'count' => 3, 'fix_hint' => 'Give each note a title tag a reader would type.', 'findings' => array( array( 'subject_label' => 'Two kinds of provenance', 'note' => 'no query-shaped subtitle', 'edit_url' => '' ), array( 'subject_label' => 'Signal over noise', 'note' => 'no query-shaped subtitle', 'edit_url' => '' ), array( 'subject_label' => 'The quiet ledger', 'note' => 'no query-shaped subtitle', 'edit_url' => '' ) ) );
$hx_live['theme_presets'] = array( 'label' => 'Theme presets', 'count' => 1, 'fix_hint' => 'Match theme.json to the palette the theme paints.', 'findings' => array( array( 'subject_label' => 'theme.json', 'note' => 'accent preset differs from the painted accent', 'edit_url' => '' ) ) );
$hx_live['extra_check_07'] = array( 'label' => 'Extra check 07', 'count' => 2, 'findings' => array( array( 'subject_label' => 'Item A', 'note' => 'flagged', 'edit_url' => '' ), array( 'subject_label' => 'Item B', 'note' => 'flagged', 'edit_url' => '' ) ) );
$hx_live['drift_time_phrases']['skipped']  = 'The AI provider refused the first 2 of 2 calls (Bad Request (400)), so no post was judged. The check stopped there instead of asking once per post, and retries on the next scan.';
$hx_live['drift_time_phrases']['fix_hint'] = 'Restore AI provider credit, then re-run the scan.';
$hx_live['color_drift']['skipped']         = 'Theme palette unavailable: skipping.';
$hx_live['cf_security_headers']['skipped'] = 'Filtered off: this host is not behind Cloudflare.';
$hx_live['link_opportunities'] = array( 'label' => 'Link opportunities', 'count' => 12 );
$hx_live['external_links']     = array( 'label' => 'External links', 'count' => 9 );
$hx_live['contrast_tokens']    = array( 'label' => 'Contrast tokens', 'count' => 0, 'report' => array( 'coverage' => 'Every published palette pair.' ) );
$GLOBALS['__health_scan'] = array( 'scanned_at' => time() - 3600, 'elapsed_ms' => 23071, 'checks' => $hx_live );
$kit = snt_leaf_paint( 'monitoring', 'health' );
ok( array() === snt_leaf_folds( $kit ), 'live-shaped: no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( 1 === substr_count( $kit, 'class="snt-hint snt-more"' ) && false !== strpos( $kit, '+14 more findings: re-run scan after fixing the top batch.' ), 'live-shaped: the 64-finding check caps at 50 and counts the other 14 out loud' );
ok( false !== strpos( $kit, '53 of 60 checks passing, across 6 families.' ) && false !== strpos( $kit, '3 checks produced no evidence either way this scan, so they read not measured' ) && 3 === substr_count( $kit, '>not measured</os-badge>' ), 'live-shaped: 53 of 60 pass in six families; the three that could not run each read not measured' );
snt_leaf_preview( 'monitoring-health', $kit );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
