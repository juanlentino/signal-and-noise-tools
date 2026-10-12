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
	ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false !== strpos( $kit, 'name="action" value="sn_block_migrations_scan"' ), "$label: the scan form is an os-form dispatching post with the hidden sn_action" );
	ok( false !== strpos( $kit, 'Scans published and scheduled posts for structural issues' ) && false !== strpos( $kit, 'heading="Block migrations"' ), "$label: the section heading and the intro survive" );
	ok( array() === snt_leaf_folds( $kit ), "$label: no fold: " . implode( ',', snt_leaf_folds( $kit ) ) );
}

// ── No scan yet: the first-run button label, and nothing to count or review.
$GLOBALS['__last_scan'] = null;
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, 'submit-label="Scan for migrations"' ), 'no scan yet: the button says Scan for migrations' );
ok( false === strpos( $kit, '<os-badge' ) && false === strpos( $kit, '<os-table' ) && false === strpos( $kit, '<os-empty-state' ), 'no scan yet: no count pill, no queue, no empty note (as on the classic leaf)' );

// ── An empty envelope (array(), not a real scan): must read exactly like "no scan yet",
// not like a clean scan — the trap a naive is_array() check falls into.
$GLOBALS['__last_scan'] = array();
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, 'submit-label="Scan for migrations"' ), 'empty envelope: reads as never-scanned, button says Scan for migrations' );
ok( false === strpos( $kit, '<os-badge' ) && false === strpos( $kit, '<os-table' ) && false === strpos( $kit, '<os-empty-state' ), 'empty envelope: no count pill, no queue, no empty note; array() is falsy, same as the classic `if ( $last_scan )` check' );

// ── A clean scan: the zero pill in the ok tone, Re-scan, the empty note, no queue.
$GLOBALS['__last_scan'] = bm_envelope( array() );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, '<os-badge tone="success">0 candidates</os-badge>' ), 'clean scan: the count pill says 0 candidates in the ok tone' );
ok( false !== strpos( $kit, 'submit-label="Re-scan"' ), 'clean scan: the button says Re-scan' );
ok( false !== strpos( $kit, '<os-empty-state heading="No heading skips found: no post has an h3 or h4 before its first h2."' ) && false === strpos( $kit, '<os-table' ), 'clean scan: the empty note says what the detector checks (2026-10-12: "All headings have valid hierarchy" claimed more), and no queue is painted' );
$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
ok( false !== strpos( $classic, 'No heading skips found: no post has an h3 or h4 before its first h2.' ) && false === strpos( $classic, 'valid hierarchy' ), 'clean scan: the classic twin says the same' );
ok( false !== strpos( $kit, '<p class="snt-prose">Scanned <os-relative-time datetime="2025-09-06T08:00:00Z">1 hour ago</os-relative-time>. A scan&#039;s list is kept for an hour; Re-scan lists what is left.</p>' ) && false !== strpos( $classic, 'Scanned 1 hour ago. A scan&#039;s list is kept for an hour; Re-scan lists what is left.' ), 'a scan says when it ran and how long its list is kept, on both surfaces' );
ok( 0 === strpos( $kit, snt_leaf_row() . '<os-section heading="Block migrations" stack>' ) && false !== strpos( $kit, '<os-section heading="What it looks for" stack><p class="snt-prose">Scans published and scheduled posts' ) && 1 === preg_match( '#</os-grid><os-section heading="Candidates" stack><os-empty-state#', $kit ), 'layout: the status box pairs with What it looks for; the clean note sits under the pair' );

// ── Candidates: the pill in the warn tone, the collapsed queue, every row's readouts.
$GLOBALS['__last_scan'] = $rich;
$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
$kit     = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $kit, '<os-badge tone="warning">2 candidates</os-badge>' ), 'candidates: the count pill says 2 candidates in the warn tone' );
// 2026-10-11 (owner rule, no folds): the queue is open; the count its closed
// "Review 2 candidates" heading carried is the finding sentence over the table.
ok( false !== strpos( $kit, '<p class="snt-prose">2 candidates in 2 posts: 1 h3 → h2, 1 h4 → h2.</p>' ) && false !== strpos( $classic, '2 candidates in 2 posts: 1 h3 → h2, 1 h4 → h2.' ) && false === strpos( $classic, '<details' ), 'candidates: the finding counts the candidates, the posts and each heading jump, on both surfaces; the classic no longer folds the queue' );
ok( false === strpos( $kit, 'snt-ledger-more' ) && false !== strpos( $kit, '<os-table' ) && strpos( $kit, 'submit-label="Re-scan"' ) < strpos( $kit, '<os-section heading="Candidates"' ), 'candidates: under the cap, no "+N more"; Re-scan sits with the count, the queue follows at full width' );
// 17.9.0 (#1624): an os-table. Its header is the component's own, so the
// hand-rolled role=columnheader row is gone (the issue's inverted pin).
$bm_tables = snt_leaf_tables( $kit );
ok( 1 === count( $bm_tables ) && array( 'Post', 'Issue', 'Action' ) === array_column( $bm_tables[0]['columns'], 'label' ) && 0 === substr_count( $kit, 'role="columnheader"' ) && false !== strpos( $kit, 'data-snt-stack-on-phone' ), 'candidates: one os-table of Post, Issue, Action; no hand-rolled header row; stacks on a phone' );
ok( false !== strpos( $kit, '<os-code>Deep note</os-code>' ) && false !== strpos( $kit, '<os-code>Second note</os-code>' ), 'candidates: each post title is kit code' );
// 14.7.5: a permalink is same-origin, so it is a DOOR (a window), not a target=_blank anchor (that relaunched the installed PWA).
ok( false !== strpos( $kit, '<p class="snt-hint"><os-button class="snt-link snt-link--url" variant="link" os-action="door" os-arg-url="https://example.test/notes/deep-note/">https://example.test/notes/deep-note/</os-button></p>' ), 'candidates: the permalink is a door opening the note as a window, demoted in a hint paragraph (matching classic <small>)' );
ok( 1 === substr_count( $kit, 'class="snt-link' ), 'candidates: a candidate without a permalink paints no link (one link for two rows)' );
ok( false !== strpos( $kit, '<os-badge tone="warning">h3 → h2</os-badge>' ) && false !== strpos( $kit, '<os-badge tone="warning">h4 → h2</os-badge>' ), 'candidates: the issue pills read h3 → h2 and h4 → h2' );
ok( false !== strpos( $kit, '<os-button variant="secondary" data-snt-suggest="1" data-check="block_migrations_heading_skip" data-post-id="12" data-fingerprint="' . $fp_a . '" data-migration-type="heading-hierarchy-skip">Suggest</os-button>' ) && false === strpos( $kit, ' disabled' ) && false === strpos( $kit, ' title=' ), 'candidates: Suggest carries the exact data contract the shared suggest script reads, enabled (the os-cluster is the cell the script paints into)' );
ok( false !== strpos( $kit, '<os-button variant="ghost" data-snt-block-migrations-dismiss="1" data-post-id="34" data-fingerprint="' . $fp_b . '" data-migration-type="heading-hierarchy-skip">Dismiss</os-button>' ), 'candidates: Dismiss carries the exact data contract the shared dismiss handler reads' );
ok( substr_count( $classic, 'data-snt-suggest="1"' ) === substr_count( $kit, 'data-snt-suggest="1"' ) && substr_count( $classic, 'data-snt-block-migrations-dismiss="1"' ) === substr_count( $kit, 'data-snt-block-migrations-dismiss="1"' ) && 2 === substr_count( $kit, 'data-snt-suggest="1"' ), 'candidates: one Suggest and one Dismiss per row, the same count as the classic table' );
// Identity, not position: every slot cell carries an os-key derived from the
// candidate's type:fingerprint, so a morph follows a row a dismissal moved.
$bm_keys = array();
if ( preg_match_all( '/<div slot="(r[0-9a-f]{12})-action" class="snt-cell" os-key="\\1-action">/', $kit, $bm_m ) ) {
	$bm_keys = $bm_m[1];
}
ok( 2 === count( $bm_keys ) && 2 === count( array_unique( $bm_keys ) ), 'candidates: every row\'s cells carry an os-key from its identity, so a morph moves rows instead of rebuilding them' );
ok( false === strpos( $kit, 'os-action="refresh"' ) && false === strpos( $kit, '>Refresh<' ), 'candidates: no in-queue Refresh (the title bar has one; Dismiss removes its own os-row now)' );
ok( false === strpos( $kit, 'Suggest opens its editor inside the classic table cell' ), 'candidates: no hint sends the operator to the classic page: Suggest runs here' );

// ── The pill reads counts.heading_hierarchy_skip; the queue heading counts the rows — two sources, as on the classic leaf.
$GLOBALS['__last_scan'] = bm_envelope( $rich['candidates'], 5 );
$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
$kit     = snt_leaf_paint( 'content', 'block-migrations' );
ok( false !== strpos( $classic, '5 candidates' ) && false !== strpos( $classic, '2 candidates in 2 posts' ) && false !== strpos( $kit, '>5 candidates<' ) && false !== strpos( $kit, '<p class="snt-prose">2 candidates in 2 posts' ), 'the pill counts from counts.heading_hierarchy_skip and the queue finding from the rows, same two sources as the classic leaf' );
ok( array() === snt_leaf_folds( $kit ), 'two-sources paint: no fold' );

// ── Escaping: a hostile candidate never reaches the markup raw.
$GLOBALS['__last_scan'] = bm_envelope( array( bm_candidate( 7, '"><script>x</script>', 'https://example.test/?q="><script>y</script>', 3, '"><b>z' ) ) );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '<os-code>&quot;&gt;&lt;script&gt;x&lt;/script&gt;</os-code>' ), 'a hostile post title is escaped' );
ok( false !== strpos( $kit, 'os-arg-url="https://example.test/?q=&quot;&gt;&lt;script&gt;y&lt;/script&gt;"' ) && false === strpos( $kit, '<script>' ), 'a hostile permalink is escaped in the attribute (now the door arg)' );
ok( false !== strpos( $kit, 'data-fingerprint="&quot;&gt;&lt;b&gt;z"' ) && false === strpos( $kit, 'data-fingerprint=""><b>z' ), 'a hostile fingerprint is escaped in the button data contract' );
ok( array() === snt_leaf_classic_markers( $kit ) && array() === snt_leaf_folds( $kit ), 'hostile: no wp-admin markup survives, no fold: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── A disallowed-scheme permalink: classic's esc_url() blanks it; the kit leaf must too.
$GLOBALS['__last_scan'] = bm_envelope( array( bm_candidate( 9, 'Scheme candidate', 'javascript:alert(1)', 3, str_repeat( 'c', 32 ) ) ) );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( false === strpos( $kit, 'href="javascript:' ) && false === strpos( $kit, 'class="snt-link' ) && array() === snt_leaf_folds( $kit ), 'a javascript: permalink is blanked, not linked, matching esc_url(); no fold' );

// ── The cap: 25 rows painted, the rest counted; at the cap, no "+N more".
/** @return array<string,mixed> An envelope of $n candidates over ceil($n/2) posts, h3 and h4 alternating. */
function bm_many( $n ) {
	$out = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$out[] = bm_candidate( 500 + intdiv( $i, 2 ), 'Note ' . intdiv( $i, 2 ), 'https://example.test/notes/note-' . intdiv( $i, 2 ) . '/', 0 === $i % 2 ? 3 : 4, sprintf( '%032d', $i ) );
	}
	return bm_envelope( $out );
}
$GLOBALS['__last_scan'] = bm_many( 25 );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( 25 === count( snt_leaf_tables( $kit )[0]['data'] ?? array() ) && false === strpos( $kit, 'snt-ledger-more' ) && array() === snt_leaf_folds( $kit ), 'exactly 25 candidates: every row painted, no "+N more" line, no fold' );
$GLOBALS['__last_scan'] = bm_many( 30 );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( 25 === count( snt_leaf_tables( $kit )[0]['data'] ?? array() ) && 25 === substr_count( $kit, 'data-snt-suggest="1"' ) && false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+5 more candidates; the list is capped, not complete. Work these, then re-scan to list the rest.</p>' ), '30 candidates: 25 rows, each with its Suggest, and "+5 more" said out loud' );
ok( false !== strpos( $kit, '30 candidates in 15 posts: 15 h3 → h2, 15 h4 → h2.' ) && array() === snt_leaf_folds( $kit ), '30 candidates: the finding counts all 30, not the 25 painted; no fold' );
$classic = snt_leaf_classic_html( 'sn_admin_render_block_migrations_section' );
ok( 25 === substr_count( $classic, 'data-snt-suggest="1"' ) && false !== strpos( $classic, '+5 more candidates; the list is capped, not complete. Work these, then re-scan to list the rest.' ) && false !== strpos( $classic, '30 candidates in 15 posts' ), 'classic twin: the same finding, 25 rows and the same "+5 more" line' );

// ── Live-shaped preview: ~20 heading skips over a dozen posts.
$GLOBALS['__last_scan'] = bm_many( 20 );
$kit = snt_leaf_paint( 'content', 'block-migrations' );
ok( 20 === count( snt_leaf_tables( $kit )[0]['data'] ?? array() ) && false === strpos( $kit, 'snt-ledger-more' ) && array() === snt_leaf_folds( $kit ) && false !== strpos( $kit, '20 candidates in 10 posts' ), 'live-shaped: 20 candidates, all painted under the cap, no fold' );
snt_leaf_preview( 'content-block-migrations', $kit );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
