<?php
/**
 * Native window leaf: Content → Block Migrations
 * (apps/sn-dashboard/parts/leaves/content-block-migrations.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * names and the same sn_action in every state (no scan yet / a clean scan /
 * a scan with candidates), every readout the classic prints must be printed
 * (the count pill, the intro, the button label, the empty note, the review
 * queue with post, permalink, issue and the two buttons' data contract), a
 * hostile candidate must be escaped, and none of wp-admin's markup survives.
 *
 * Run: php tests/os-leaf-content-block-migrations.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own reader: the cached scan envelope, as the detector answers it.
$GLOBALS['__last_scan'] = null;
if ( ! function_exists( 'snt_block_migrations_last_scan' ) ) {
	function snt_block_migrations_last_scan() { return $GLOBALS['__last_scan']; }
}

require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/block-migrations-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-block-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** @return array<string,mixed> A scan envelope shaped as snt_block_migrations_compute() shapes it. */
function bm_envelope( array $candidates, $count = null ) {
	return array(
		'candidates' => $candidates,
		'counts'     => array( 'heading_hierarchy_skip' => null === $count ? count( $candidates ) : (int) $count, 'posts_affected' => count( $candidates ) ),
		'scanned_at' => 1757145600,
	);
}
/** @return array<string,mixed> One candidate as the walker mints it. */
function bm_candidate( $post_id, $title, $permalink, $level, $fp ) {
	return array(
		'post_id'           => $post_id,
		'migration_type'    => 'heading-hierarchy-skip',
		'block_fingerprint' => $fp,
		'block_path'        => '0/3',
		'post_title'        => $title,
		'permalink'         => $permalink,
		'current_level'     => $level,
		'target_level'      => 2,
	);
}
$fp_a = str_repeat( 'a', 32 );
$fp_b = str_repeat( 'b', 32 );
$rich = bm_envelope( array(
	bm_candidate( 12, 'Deep note', 'https://example.test/notes/deep-note/', 3, $fp_a ),
	bm_candidate( 34, 'Second note', '', 4, $fp_b ),
) );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/block-migrations'] ), 'the painter is registered under content/block-migrations' );

// ── Every state: the same field names, the same one action, none of wp-admin's markup.
// 'empty envelope' is a literal array() (no keys at all) — distinct from 'clean scan'
// (a real envelope whose candidates list happens to be empty). Classic tests
// `if ( $last_scan )`, so array() is falsy and must read as "never scanned".
foreach ( array( 'no scan yet' => null, 'empty envelope' => array(), 'clean scan' => bm_envelope( array() ), 'candidates' => $rich ) as $label => $envelope ) {
	$GLOBALS['__last_scan'] = $envelope;
	$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
	$kit     = snt_leaf_paint( 'content', 'block-migrations' );
	ok( '' !== $kit, "$label: the kit leaf paints" );
	ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), "$label: field names match the classic form: " . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
	ok( array( 'block_migrations_scan' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), "$label: the one action is block_migrations_scan, as on the classic leaf" );
	ok( array() === snt_leaf_classic_markers( $kit ), "$label: no wp-admin markup survives: " . implode( ',', snt_leaf_classic_markers( $kit ) ) );
	ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false !== strpos( $kit, 'name="sn_action" value="block_migrations_scan"' ), "$label: the scan form is an os-form dispatching post with the hidden sn_action" );
	ok( false !== strpos( $kit, 'Scans published and scheduled posts for structural issues' ) && false !== strpos( $kit, 'heading="Block migrations"' ), "$label: the section heading and the intro survive" );
}

// ── No scan yet: the first-run button label, and nothing to count or review.
$GLOBALS['__last_scan'] = null;
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, 'submit-label="Scan for migrations"' ), 'no scan yet: the button says Scan for migrations' );
ok( false === strpos( $kit, '<os-badge' ) && false === strpos( $kit, '<os-disclosure' ) && false === strpos( $kit, '<os-empty-state' ), 'no scan yet: no count pill, no queue, no empty note (as on the classic leaf)' );

// ── An empty envelope (array(), not a real scan): must read exactly like "no scan yet",
// not like a clean scan — the trap a naive is_array() check falls into.
$GLOBALS['__last_scan'] = array();
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, 'submit-label="Scan for migrations"' ), 'empty envelope: reads as never-scanned, button says Scan for migrations' );
ok( false === strpos( $kit, '<os-badge' ) && false === strpos( $kit, '<os-disclosure' ) && false === strpos( $kit, '<os-empty-state' ), 'empty envelope: no count pill, no queue, no empty note — array() is falsy, same as the classic `if ( $last_scan )` check' );

// ── A clean scan: the zero pill in the ok tone, Re-scan, the empty note, no queue.
$GLOBALS['__last_scan'] = bm_envelope( array() );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, '<os-badge tone="success">0 candidates</os-badge>' ), 'clean scan: the count pill says 0 candidates in the ok tone' );
ok( false !== strpos( $kit, 'submit-label="Re-scan"' ), 'clean scan: the button says Re-scan' );
ok( false !== strpos( $kit, '<os-empty-state heading="No migrations needed. All headings have valid hierarchy."' ) && false === strpos( $kit, '<os-disclosure' ), 'clean scan: the empty note is the kit empty state and no queue is painted' );

// ── Candidates: the pill in the warn tone, the collapsed queue, every row's readouts.
$GLOBALS['__last_scan'] = $rich;
$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
$kit     = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, '<os-badge tone="warning">2 candidates</os-badge>' ), 'candidates: the count pill says 2 candidates in the warn tone' );
ok( false !== strpos( $kit, '<os-disclosure heading="Review 2 candidates">' ) && false === strpos( $kit, '<os-disclosure heading="Review 2 candidates" open' ), 'candidates: the queue is a disclosure headed Review 2 candidates, closed by default like the classic details' );
ok( false !== strpos( $kit, '<span col="5" class="snt-col__h" role="columnheader">Post</span>' ) && false !== strpos( $kit, '<span col="2" class="snt-col__h" role="columnheader">Issue</span>' ) && false !== strpos( $kit, '<span col="5" class="snt-col__h" role="columnheader">Action</span>' ), 'candidates: the three column labels are painted on the classic column proportions (40/20/40), rounded to the 12-column grid (5/2/5), with role=columnheader restoring what dropping <th scope=col> lost' );
ok( false !== strpos( $kit, '<os-code>Deep note</os-code>' ) && false !== strpos( $kit, '<os-code>Second note</os-code>' ), 'candidates: each post title is kit code' );
ok( false !== strpos( $kit, '<p class="snt-hint"><a class="snt-link" href="https://example.test/notes/deep-note/" target="_blank" rel="noopener noreferrer">https://example.test/notes/deep-note/</a></p>' ), 'candidates: the permalink is an external link opened in a new tab, demoted in a hint paragraph (matching classic <small>)' );
ok( 1 === substr_count( $kit, 'class="snt-link"' ), 'candidates: a candidate without a permalink paints no link (one link for two rows)' );
ok( false !== strpos( $kit, '<os-badge tone="warning">h3 → h2</os-badge>' ) && false !== strpos( $kit, '<os-badge tone="warning">h4 → h2</os-badge>' ), 'candidates: the issue pills read h3 → h2 and h4 → h2' );
ok( false !== strpos( $kit, '<os-button variant="secondary" data-snt-suggest="1" data-check="block_migrations_heading_skip" disabled title="Suggest runs on the classic Content → Block Migrations page." data-post-id="12" data-fingerprint="' . $fp_a . '" data-migration-type="heading-hierarchy-skip">Suggest</os-button>' ), 'candidates: Suggest carries the exact data contract the shared suggest script reads, disabled with a title explaining why (the flow needs a table cell no window row paints)' );
ok( false !== strpos( $kit, '<os-button variant="ghost" data-snt-block-migrations-dismiss="1" data-post-id="34" data-fingerprint="' . $fp_b . '" data-migration-type="heading-hierarchy-skip">Dismiss</os-button>' ), 'candidates: Dismiss carries the exact data contract the shared dismiss handler reads' );
ok( substr_count( $classic, 'data-snt-suggest="1"' ) === substr_count( $kit, 'data-snt-suggest="1"' ) && substr_count( $classic, 'data-snt-block-migrations-dismiss="1"' ) === substr_count( $kit, 'data-snt-block-migrations-dismiss="1"' ) && 2 === substr_count( $kit, 'data-snt-suggest="1"' ), 'candidates: one Suggest and one Dismiss per row, the same count as the classic table' );
ok( 2 === substr_count( $kit, 'os-key="heading-hierarchy-skip:' ), 'candidates: every row carries an os-key so a morph moves rows instead of rebuilding them' );
ok( false !== strpos( $kit, 'os-action="refresh"' ) && false !== strpos( $kit, '>Refresh<' ), 'candidates: Refresh (an ADDITION not present on the classic leaf) lets the operator repaint after a Dismiss the shared script cannot remove from a kit row' );
ok( false !== strpos( $kit, '<p class="snt-hint">Suggest opens its editor inside the classic table cell' ), 'candidates: a hint inside the disclosure tells the operator Suggest and Apply only run on the classic page — visible without reading the port report' );

// ── The pill reads counts.heading_hierarchy_skip; the queue heading counts the rows — two sources, as on the classic leaf.
$GLOBALS['__last_scan'] = bm_envelope( $rich['candidates'], 5 );
$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
$kit     = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $classic, '5 candidates' ) && false !== strpos( $classic, 'Review 2 candidates' ) && false !== strpos( $kit, '>5 candidates<' ) && false !== strpos( $kit, 'heading="Review 2 candidates"' ), 'the pill counts from counts.heading_hierarchy_skip and the queue heading from the rows, same as the classic leaf' );

// ── Escaping: a hostile candidate never reaches the markup raw.
$GLOBALS['__last_scan'] = bm_envelope( array( bm_candidate( 7, '"><script>x</script>', 'https://example.test/?q="><script>y</script>', 3, '"><b>z' ) ) );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '<os-code>&quot;&gt;&lt;script&gt;x&lt;/script&gt;</os-code>' ), 'a hostile post title is escaped' );
ok( false !== strpos( $kit, 'href="https://example.test/?q=&quot;&gt;&lt;script&gt;y&lt;/script&gt;"' ), 'a hostile permalink is escaped in the attribute' );
ok( false !== strpos( $kit, 'data-fingerprint="&quot;&gt;&lt;b&gt;z"' ) && false === strpos( $kit, 'data-fingerprint=""><b>z' ), 'a hostile fingerprint is escaped in the button data contract' );
ok( array() === snt_leaf_classic_markers( $kit ), 'hostile: no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── A disallowed-scheme permalink: classic's esc_url() blanks it; the kit leaf must too.
$GLOBALS['__last_scan'] = bm_envelope( array( bm_candidate( 9, 'Scheme candidate', 'javascript:alert(1)', 3, str_repeat( 'c', 32 ) ) ) );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false === strpos( $kit, 'href="javascript:' ) && false === strpos( $kit, 'class="snt-link"' ), 'a javascript: permalink is blanked, not linked, matching esc_url()' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
