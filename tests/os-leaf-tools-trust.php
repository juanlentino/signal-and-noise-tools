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
require SNT_PATH . 'inc/integrity-trust-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-trust.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/trust'] ), 'the painter is registered under tools/trust' );

// ── State 1: no scan at all.
$GLOBALS['__scan'] = null;
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = snt_leaf_paint( 'tools', 'trust' );
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
$kit     = snt_leaf_paint( 'tools', 'trust' );
ok( false !== strpos( $classic, 'this leaf never scans on its own' ) && false !== strpos( $kit, 'this leaf never scans on its own' ), 'scan-with-no-checks state: classic and kit both show the "ran N ago" line' );
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
$kit     = snt_leaf_paint( 'tools', 'trust' );
ok( substr_count( $classic, 'clear' ) === substr_count( $kit, 'clear' ) && substr_count( $kit, 'clear' ) >= 4, 'clear state: classic and kit both read "clear" four times (' . substr_count( $kit, 'clear' ) . ')' );
ok( false === strpos( $kit, '<os-disclosure' ), 'clear state: no findings disclosure is painted when nothing needs a look' );

// ── Check labels and blurbs: neither renderer may drop a check's identity or
// what it proves — a refuter mutation showed these were never pinned.
foreach ( array( 'Provenance triangle', 'Ledger CI', 'Rights signals', 'Rights anchoring' ) as $label ) {
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
$kit     = snt_leaf_paint( 'tools', 'trust' );
ok( false !== strpos( $classic, '5 findings' ) && false !== strpos( $kit, '5 findings' ), 'rich fixture: the 5-finding count reads the same on both' );
ok( false !== strpos( $classic, '+2 more on Health' ) && false !== strpos( $kit, '+2 more on Health' ), 'rich fixture: both defer the 2 findings past the 3-cap to Health' );
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

// ── The public-side links always print, in every state.
ok( false !== strpos( $kit, 'Verification docket' ) && false !== strpos( $kit, home_url( '/verify/' ) ), 'the public verification docket link is printed' );
ok( false !== strpos( $kit, 'github.com/juanlentino/signal-and-noise-provenance' ), 'the public ledger link is printed' );
foreach ( array( 'Verification docket', 'Public ledger', 'TDM policy', 'RSL licence' ) as $label ) {
	ok( false !== strpos( $classic, $label ) && false !== strpos( $kit, $label ), "public link $label printed by both" );
}

// ── State 5: the gate. The classic leaf prints NOTHING at all (not even a wrapper); the kit leaf explains why.
$GLOBALS['__can'] = false;
$classic = snt_leaf_classic_html( 'snt_trust_render_section' );
$kit     = snt_leaf_paint( 'tools', 'trust' );
ok( '' === $classic, 'gated: the classic leaf prints nothing for a non-manage_options user' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'cannot manage options' ), 'gated: the kit leaf explains the gate instead of painting blank' );
$GLOBALS['__can'] = true;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
