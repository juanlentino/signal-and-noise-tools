<?php
/**
 * Native window leaf: Tools → Trust checks (apps/sn-dashboard/parts/leaves/tools-trust.php).
 *
 * A pure readout leaf — no forms, no sn_action, no side effects. The oracle
 * is the classic leaf (inc/integrity-trust-admin.php): same four checks, same
 * glance cards, same "no scan" / "scan ran N ago" fallbacks, same per-check
 * readings and findings, same public-side links, no wp-admin markup, and the
 * same all-output-suppressing gate on a non-manage_options user.
 *
 * Run: php tests/os-leaf-tools-trust.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// Redeclared UNCONDITIONALLY (hoisted at compile time, ahead of the harness's
// guarded default) so the gate can be driven from a global.
function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; }

// The leaf's own reader.
$GLOBALS['__scan'] = null;
function sn_health_last_scan() { return $GLOBALS['__scan']; }

require SNT_PATH . 'inc/admin-glance.php';
// The real skip predicate (sn_health_check_is_skipped()), never a stub: a
// skipped check must read "not measured" through the same rule Health uses.
require SNT_PATH . 'inc/health-summary.php';
require SNT_PATH . 'inc/integrity-trust-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-trust.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// 2026-10-11 (owner rule: no collapsible sections): every paint this suite
// makes goes through here, so every state it paints is pinned fold-free.
function trust_paint() {
	static $n = 0;
	$kit   = snt_leaf_paint( 'tools', 'trust' );
	$folds = snt_leaf_folds( $kit );
	ok( array() === $folds, 'paint #' . ( ++$n ) . ' is fold-free' . ( $folds ? ': ' . implode( ',', $folds ) : '' ) );
	return $kit;
}
// check label => its "Latest reading" cell, read from the checks table.
function trust_readings( $kit ) {
	foreach ( snt_leaf_tables( $kit ) as $t ) {
		if ( isset( $t['data'][0]['check'] ) ) {
			return array_column( $t['data'], 'reading', 'check' );
		}
	}
	return array();
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/trust'] ), 'the painter is registered under tools/trust' );

// ── State 1: no scan at all.
$GLOBALS['__scan'] = null;
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = trust_paint();
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array() === snt_leaf_names( $kit ), 'no field names in either (a readout leaf): ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $kit ), 'no sn_action in either' );
ok( array() === \snt_leaf_classic_markers( $kit ), 'no wp-admin markup: ' . implode( ',', \snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $classic, 'No health scan has run yet' ) && false !== strpos( $kit, 'No health scan has run yet' ), 'no-scan state: classic and kit both explain there is no reading' );
ok( false !== strpos( $kit, 'not run' ) && substr_count( $kit, 'not run' ) >= 4, 'no-scan state: all four checks read "not run" (' . substr_count( $kit, 'not run' ) . ' found)' );
ok( false !== strpos( $kit, '<os-stat' ) && false !== strpos( $kit, '<os-table' ) && false !== strpos( $kit, 'Measurement' ), 'no-scan state paints kit stats + a kit table naming Measurement → Health' );

// ── State 2: a scan exists but no checks were recorded (still "not run" per check, different intro line).
$GLOBALS['__scan'] = array( 'scanned_at' => time() - 3600 );
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = trust_paint();
ok( false !== strpos( $classic, 'this leaf never scans on its own' ) && false !== strpos( $kit, 'ran <os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time>: this leaf never scans on its own' ), '#1596: scan-with-no-checks state: classic shows the "ran N ago" line, the kit an os-relative-time' );
ok( substr_count( $kit, 'not run' ) >= 4, 'scan-with-no-checks state: still four "not run" readings' );

// ── State 3: all four checks present and clear.
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 7200,
	'checks'     => array(
		'provenance_integrity' => array( 'count' => 0 ),
		'ledger_ci'            => array( 'count' => 0 ),
		'rights_signals'       => array( 'count' => 0 ),
		'rights_anchored'      => array( 'count' => 0 ),
	),
);
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = trust_paint();
ok( 4 === substr_count( $classic, 'sn-pill--ok">clear' ) && array( 'clear' ) === array_values( array_unique( trust_readings( $kit ) ) ) && 4 === count( trust_readings( $kit ) ), 'clear state: classic and kit both read "clear" on all four rows' );
ok( false === strpos( $kit, 'heading="Provenance integrity"' ) && false === strpos( $kit, 'need a look.' ), 'clear state: no check gets a findings section when nothing needs a look' );

// ── Check labels and blurbs: neither renderer may drop a check's identity or
// what it proves — a refuter mutation showed these were never pinned.
foreach ( array( 'Provenance integrity', 'Ledger CI', 'Rights signals', 'Rights anchoring' ) as $label ) {
	ok( false !== strpos( $classic, $label ) && false !== strpos( $kit, $label ), "check label $label printed by both" );
}
ok(
	false !== strpos( $kit, 'Payload hash, the live .json twin' )
	&& false !== strpos( $kit, 'verifies itself daily' )
	&& false !== strpos( $kit, 'robots Content-Signal lines' )
	&& false !== strpos( $kit, 'newest ledger record' ),
	'all four "What it proves" blurbs are printed'
);

// ── State 4: a rich fixture — mixed states, findings beyond the cap, and a hostile fixture value.
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 300,
	'checks'     => array(
		'provenance_integrity' => array(
			'count'    => 5,
			'findings' => array(
				array( 'subject_label' => 'note-1', 'subject_url' => 'https://example.test/note-1', 'note' => 'hash mismatch' ),
				array( 'subject_label' => 'note-2', 'subject_url' => 'https://example.test/note-2', 'note' => 'twin missing' ),
				array( 'subject_label' => '"><script>y</script>', 'subject_url' => '', 'note' => '<script>x</script>' ),
				array( 'subject_label' => 'note-4', 'subject_url' => '', 'note' => 'ledger key stale' ),
				array( 'subject_label' => 'note-5', 'subject_url' => '', 'note' => 'anchor absent' ),
			),
		),
		'ledger_ci'       => array( 'count' => 0 ),
		'rights_signals'  => array( 'count' => 1, 'findings' => array( array( 'subject_label' => '', 'note' => 'robots line missing' ) ) ),
		// rights_anchored deliberately absent: exercises the "not run" branch inside a rich fixture.
	),
);
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = trust_paint();
ok( false !== strpos( $classic, '5 findings' ) && false !== strpos( $kit, '5 findings' ), 'rich fixture: the 5-finding count reads the same on both' );
ok( false !== strpos( $classic, '+2 more on Health' ) && false !== strpos( $kit, '+2 more on Health' ), 'rich fixture: both defer the 2 findings past the 3-cap to Health' );
// 2026-10-11: the findings sat in an always-open os-disclosure per check. Each
// check with findings is an open section now: its count as the finding, the
// shown findings as a ledger (subject, note), the rest deferred to Health.
$readings = trust_readings( $kit );
ok( '5 findings' === ( $readings['Provenance integrity'] ?? '' ) && '1 finding' === ( $readings['Rights signals'] ?? '' ) && 'clear' === ( $readings['Ledger CI'] ?? '' ) && 'not run' === ( $readings['Rights anchoring'] ?? '' ), 'rich fixture: the checks table reads 5 findings / clear / 1 finding / not run' );
$at_pub  = strpos( $kit, 'heading="The public side"' );
$at_prov = strpos( $kit, 'heading="Provenance integrity"' );
$at_sig  = strpos( $kit, 'heading="Rights signals"' );
ok( false !== $at_pub && false !== $at_prov && false !== $at_sig && $at_pub < $at_prov && $at_prov < $at_sig, 'rich fixture: each check with findings is an open section under the paired row, in check order' );
// 2026-10-12: the per-check sections share a row instead of stacking at full
// width (each is capped at three findings; a skipped one is a sentence).
$tail = substr( $kit, (int) strrpos( $kit, 'heading="The public side"' ) );
ok( 1 === preg_match( '/<os-grid[^>]*>\s*<os-section[^>]*heading="Provenance integrity".*heading="Rights signals"/s', $tail ), 'rich fixture: the two findings sections sit side by side in one grid' );
ok( false !== strpos( $kit, '5 findings need a look.' ) && false !== strpos( $kit, '1 finding needs a look.' ), 'rich fixture: each findings section states its count as the finding' );
ok( false === strpos( $kit, 'heading="Ledger CI"' ) && false === strpos( $kit, 'heading="Rights anchoring"' ), 'rich fixture: a clear check and a not-run check get no findings section' );
$ledger = array();
foreach ( snt_leaf_tables( $kit ) as $t ) {
	if ( isset( $t['data'][0]['note'] ) ) {
		$ledger[] = $t;
	}
}
ok( 2 === count( $ledger ) && 3 === count( $ledger[0]['data'] ) && 'hash mismatch' === $ledger[0]['data'][0]['note'] && 'ledger key stale' !== $ledger[0]['data'][2]['note'], 'rich fixture: the provenance ledger shows the first 3 findings, the cap the classic uses' );
ok( isset( $ledger[0]['data'][0]['subject'] ) && false !== strpos( snt_leaf_cell_html( $ledger[0], $ledger[0]['data'][0]['subject'] ), 'https://example.test/note-1' ), 'rich fixture: a finding subject with a URL is a live link in its ledger cell' );
ok( false !== strpos( $kit, '1 finding' ), 'rich fixture: the singular "1 finding" reading is used for rights_signals' );
ok( substr_count( $classic, 'not run' ) === substr_count( $kit, 'not run' ) && substr_count( $kit, 'not run' ) >= 1, 'rich fixture: rights_anchored (absent from the scan) still reads "not run" (' . substr_count( $kit, 'not run' ) . ' occurrences, matching classic)' );
ok( false !== strpos( $kit, 'hash mismatch' ) && false !== strpos( $kit, 'twin missing' ) && false !== strpos( $kit, 'robots line missing' ), 'rich fixture: the shown finding notes are printed' );
ok( false !== strpos( $kit, 'https://example.test/note-1' ), 'rich fixture: a finding with a subject URL keeps it as a live link' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'rich fixture: the hostile finding is escaped, not executed' );
ok(
	false !== strpos( $kit, 'needs a look' ) && false !== strpos( $kit, 'holding' ) && false !== strpos( $kit, 'no reading' ),
	'the glance captions carry all three pill texts'
);
ok(
	3 === substr_count( $kit, 'data-tone="warning"' ),
	'the two warn checks and the not-run check each carry a warning swatch (' . substr_count( $kit, 'data-tone="warning"' ) . ' found)'
);

// ── State 4b: a check that ran and SKIPPED (count 0 plus a `skipped`
// reason, the shape health-check-rights-anchored.php and
// health-check-rights-signals.php report). Unknown is never zero: it reads
// "not measured" with its reason on both surfaces, never "clear"/"holding".
$skip_reason       = 'Probe skipped: the site host failed the SSRF guard. The check will retry on the next scan.';
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 600,
	'checks'     => array(
		'provenance_integrity' => array( 'count' => 0 ),
		'ledger_ci'            => array( 'count' => 0, 'skipped' => null ),
		'rights_signals'       => array( 'count' => 0 ),
		'rights_anchored'      => array( 'count' => 0, 'findings' => array(), 'skipped' => $skip_reason ),
	),
);
$classic  = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit      = trust_paint();
$readings = trust_readings( $kit );
ok( 'not measured' === ( $readings['Rights anchoring'] ?? '' ) && 'clear' === ( $readings['Ledger CI'] ?? '' ), 'skipped: the kit row reads "not measured" (a null skip still reads clear: the check ran)' );
ok( false !== strpos( $kit, '<os-stat value="not measured" label="Rights anchoring" caption="skipped this scan" swatch data-tone="neutral">' ), 'skipped: the kit tile reads "not measured", neutral tone (not ok)' );
ok( false === strpos( $kit, 'label="Rights anchoring" caption="holding"' ) && false === strpos( $kit, '<os-stat value="clear" label="Rights anchoring"' ), 'skipped: the kit never reads the skipped check as clear or holding' );
ok( false !== strpos( $kit, 'heading="Rights anchoring"' ) && false !== strpos( $kit, 'Not measured this scan. ' . $skip_reason ), 'skipped: the kit paints the reason in the check\'s own section' );
$classic_row = (string) substr( $classic, (int) strpos( $classic, 'Rights anchoring</strong>' ), 600 );
$classic_row = (string) substr( $classic_row, 0, (int) strpos( $classic_row, '</tr>' ) );
ok( false !== strpos( $classic_row, 'not measured' ) && false !== strpos( $classic_row, $skip_reason ) && false === strpos( $classic_row, 'clear' ), 'skipped: the classic row reads "not measured" with the reason, never clear' );
ok( false !== strpos( $classic, '<p class="sn-glance-card__label">Rights anchoring</p><p class="sn-glance-card__value">not measured</p>' ) && false === strpos( $classic, '<p class="sn-glance-card__label">Rights anchoring</p><p class="sn-glance-card__value">clear</p>' ), 'skipped: the classic glance card reads "not measured", never clear' );

// A count with no finding notes stored: the count alone, never an empty table.
$GLOBALS['__scan'] = array( 'scanned_at' => time(), 'checks' => array( 'ledger_ci' => array( 'count' => 2 ) ) );
$kit               = trust_paint();
ok( false !== strpos( $kit, '2 findings need a look.' ) && 1 === substr_count( $kit, '<os-table' ), 'findings without notes: the section states the count and paints no empty ledger (the checks table is the only table)' );

// ── The public-side links always print, in every state.
ok( false !== strpos( $kit, 'Verification docket' ) && false !== strpos( $kit, home_url( '/verify/' ) ), 'the public verification docket link is printed' );
ok( false !== strpos( $kit, 'github.com/juanlentino/signal-and-noise-provenance' ), 'the public ledger link is printed' );
foreach ( array( 'Verification docket', 'Public ledger', 'TDM policy', 'RSL license' ) as $label ) {
	ok( false !== strpos( $classic, $label ) && false !== strpos( $kit, $label ), "public link $label printed by both" );
}
// 2026-10-11: each link is followed by a colon, not ". per-note ..." (a period
// then a lowercase word, left by an old em-dash swap).
ok( 4 === preg_match_all( '/(<\/os-button>|<\/a>): [a-z]/', $kit ) && 4 === preg_match_all( '/<\/a>: [a-z]/', $classic ) && false === strpos( $kit . $classic, '. per-note' ), 'each public link is followed by a colon, never a period before a lowercase word' );

// ── State 5: the gate. The classic leaf prints NOTHING at all (not even a wrapper); the kit leaf explains why.
$GLOBALS['__can'] = false;
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = trust_paint();
ok( '' === $classic, 'gated: the classic leaf prints nothing for a non-manage_options user' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'cannot manage options' ), 'gated: the kit leaf explains the gate instead of painting blank' );
$GLOBALS['__can'] = true;


// ── Siblings pair; a stack is for steps. ──
// Measured live 2026-09-10; see the painter for the per-leaf numbers.
$kitP = trust_paint();
ok(
	1 === substr_count( $kitP, snt_leaf_row() ),
	'the sibling sections are painted in 1 paired row(s) -- ' . substr_count( $kitP, snt_leaf_row() )
);
// Ordering, not a regex extract: `(.*?)</div>` stops at the first NESTED
// close, so it cannot delimit a row that contains divs. Position is enough --
// the row opens, then both siblings follow, with no third section between.
$posRow = strpos( $kitP, snt_leaf_row() );
$posA   = strpos( $kitP, 'What these four watch' );
$posB   = strpos( $kitP, 'The public side' );
ok(
	false !== $posRow && false !== $posA && false !== $posB && $posRow < $posA && $posA < $posB,
	'...and the row opens before both siblings, in order'
);
// ── Live-shaped preview: a scan that carries more than the four trust
// checks (the leaf reads only its four, by key), one trust check skipped with
// the live reason, one with findings past the 3-cap ("+N more on Health").
// Written only when SNT_LEAF_PREVIEW_DIR is set.
$prov = array();
for ( $i = 1; $i <= 7; $i++ ) {
	$prov[] = array( 'subject_label' => 'notes/field-note-' . $i, 'subject_url' => 'https://juanlentino.test/notes/field-note-' . $i . '/', 'note' => 0 === $i % 2 ? 'The live .json twin no longer matches the signed payload hash.' : 'The ledger record names a key file the ledger no longer carries.' );
}
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 3 * 3600,
	'checks'     => array(
		'missing_alt'          => array( 'count' => 2 ),
		'broken_links'         => array( 'count' => 0 ),
		'provenance_integrity' => array( 'count' => 7, 'findings' => $prov ),
		'stale_posts'          => array( 'count' => 4 ),
		'ledger_ci'            => array( 'count' => 0, 'skipped' => null ),
		'rights_signals'       => array( 'count' => 0 ),
		'contrast_tokens'      => array( 'count' => 0 ),
		'rights_anchored'      => array( 'count' => 0, 'findings' => array(), 'skipped' => 'Probe skipped: the public ledger index was unreachable. An outage is a gap in evidence, never evidence of drift: the check will retry on the next scan.' ),
	),
);
$kit = trust_paint();
ok( false !== strpos( $kit, '+4 more on Health' ) && 'not measured' === ( trust_readings( $kit )['Rights anchoring'] ?? '' ) && 4 === count( trust_readings( $kit ) ), 'live-shaped: four trust rows of eight checks, 3 findings shown and "+4 more on Health", the skipped check not measured' );
snt_leaf_preview( 'tools-trust', $kit );

// ── 2026-10-11 (code review): a finding's subject_url can come from outside
// the site (Ledger CI reads GitHub's html_url), and snt_kit_link() does not
// filter a scheme. Only http(s) becomes a link; anything else is plain text.
$GLOBALS['__scan'] = array(
	'scanned_at' => time() - 300,
	'checks'     => array(
		'ledger_ci' => array( 'count' => 2, 'findings' => array(
			array( 'subject_label' => 'run 41', 'subject_url' => 'javascript:alert(1)', 'note' => 'failed' ),
			array( 'subject_label' => 'run 42', 'subject_url' => 'https://github.com/o/r/actions/runs/42', 'note' => 'failed' ),
		) ),
	),
);
$kit_js = trust_paint();
ok( false === stripos( $kit_js, 'javascript:' ) && false !== strpos( $kit_js, 'run 41' ) && false !== strpos( $kit_js, 'https://github.com/o/r/actions/runs/42' ), 'a non-http(s) subject_url paints the subject as text, never a javascript: link; an https one stays a link' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
