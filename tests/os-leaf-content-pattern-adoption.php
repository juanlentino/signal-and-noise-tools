<?php
/**
 * Native window leaf: Content → Pattern Adoption (apps/sn-dashboard/parts/leaves/content-pattern-adoption.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * names and the same sn_action, every candidate row must carry the same
 * Suggest / Dismiss data attributes the classic row carries, every readout
 * (count pill, queue heading, titles, links, patterns, empty note) must be
 * printed for the same fixture, and none of wp-admin's markup may survive.
 *
 * The names-parity assertions below compare snt_leaf_names() on the classic
 * form against the kit form. The harness's wp_nonce_field() stub emits only
 * `_wpnonce` — a real WordPress request also carries `_wp_http_referer`, which
 * the classic form has and the kit form (snt_kit_form()) does not. That field
 * is not part of this parity claim: the shared pipeline
 * (inc/openstation-host-pipelines.php) reads `_wpnonce` only, so the fields it
 * actually reads are the ones being compared here.
 *
 * Run: php tests/os-leaf-content-pattern-adoption.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// Redeclared unconditionally (not the harness's function_exists-guarded
// version) so it binds at compile time and can be driven from a global — the
// sibling pattern in tests/os-leaf-content-tags.php, tests/os-leaf-connections-indexnow.php
// and tests/os-leaf-tools-trust.php.
function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; }

// The leaf's reader: the current user's cached scan, fed from a fixture.
$GLOBALS['__pa_scan'] = null;
if ( ! function_exists( 'snt_pattern_adoption_last_scan' ) ) {
	function snt_pattern_adoption_last_scan() { return $GLOBALS['__pa_scan']; }
}

require SNT_PATH . 'inc/pattern-adoption-admin.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-pattern-adoption.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** The Suggest / Dismiss contract: every data attribute the script reads, as sorted unique `name=value` pairs. */
function snt_pa_data_attrs( $html ) {
	preg_match_all( '/\s(data-(?:snt-suggest|snt-dismiss|check|post-id|fingerprint|pattern-type))="([^"]*)"/', (string) $html, $m );
	$pairs = array();
	foreach ( $m[1] as $i => $name ) { $pairs[] = $name . '=' . $m[2][ $i ]; }
	$pairs = array_values( array_unique( $pairs ) );
	sort( $pairs );
	return $pairs;
}

function snt_pa_scan( array $candidates ) {
	$counts = array( 'pull_quote' => 0, 'steps_enumerated' => 0, 'posts_affected' => 0 );
	$posts  = array();
	foreach ( $candidates as $c ) {
		$counts[ 'pull-quote' === $c['pattern_type'] ? 'pull_quote' : 'steps_enumerated' ]++;
		$posts[ $c['post_id'] ] = true;
	}
	$counts['posts_affected'] = count( $posts );
	return array( 'candidates' => $candidates, 'counts' => $counts, 'posts_examined' => 12, 'scanned_at' => 1757000000 );
}

$rich = array(
	array( 'post_id' => 41, 'pattern_type' => 'pull-quote', 'block_fingerprint' => 'a1b2c3', 'block_path' => '0/2', 'post_title' => 'Two kinds of provenance', 'permalink' => 'https://example.test/notes/two-kinds-of-provenance/' ),
	array( 'post_id' => 41, 'pattern_type' => 'steps-enumerated', 'block_fingerprint' => 'd4e5f6', 'block_path' => '0/5', 'post_title' => 'Two kinds of provenance', 'permalink' => 'https://example.test/notes/two-kinds-of-provenance/' ),
	array( 'post_id' => 58, 'pattern_type' => 'pull-quote', 'block_fingerprint' => '0f9e8d', 'block_path' => '0/1', 'post_title' => 'Signal over noise', 'permalink' => '' ),
);

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/pattern-adoption'] ), 'the painter is registered under content/pattern-adoption' );

// ── Never scanned: the form alone, labelled for a first scan.
$classic = snt_leaf_classic_html( 'sn_admin_render_pattern_adoption_section' );
$kit     = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'pattern_adoption_scan' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is pattern_adoption_scan, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the scan form is an os-form dispatching post on the shared pipeline' );
ok( false !== strpos( $kit, 'submit-label="Scan for opportunities"' ) && false !== strpos( $classic, 'Scan for opportunities' ), 'before the first scan the button reads "Scan for opportunities"' );
ok( false === strpos( $kit, '<os-badge' ) && false === strpos( $kit, '<os-disclosure' ) && false === strpos( $kit, '<os-empty-state' ), 'before the first scan there is no count, no queue and no empty note — as on the classic leaf' );
ok( false !== strpos( $kit, '<os-section heading="Pattern adoption">' ) && false !== strpos( $kit, '<p class="snt-prose">Scans existing /notes posts' ), 'the heading and the intro prose are printed' );

// ── Scanned, nothing found: the count reads zero and the empty note is printed.
$GLOBALS['__pa_scan'] = snt_pa_scan( array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_pattern_adoption_section' );
$kit     = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( false !== strpos( $kit, '<os-badge tone="success">0 opportunities</os-badge>' ) && false !== strpos( $classic, '0 opportunities' ), 'zero found: the count pill reads "0 opportunities" in the ok tone' );
ok( false !== strpos( $kit, 'submit-label="Re-scan opportunities"' ) && false !== strpos( $classic, 'Re-scan opportunities' ), 'after a scan the button reads "Re-scan opportunities"' );
ok( false !== strpos( $kit, '<os-empty-state heading="No opportunities found." description="All eligible blocks are either already pattern-upgraded or have been dismissed.">' ), 'zero found: the empty note survives as the kit empty state' );
ok( false === strpos( $kit, '<os-disclosure' ), 'zero found: no review queue is painted' );

// ── Scanned, three candidates across two posts: every readout, every attribute.
$GLOBALS['__pa_scan'] = snt_pa_scan( $rich );
$classic = snt_leaf_classic_html( 'sn_admin_render_pattern_adoption_section' );
$kit     = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'with a queue the names and the action still match the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'with a queue no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-badge tone="warning">3 opportunities</os-badge>' ) && false !== strpos( $classic, '3 opportunities' ), 'the count pill reads "3 opportunities" in the warn tone' );
ok( false !== strpos( $kit, '<os-disclosure heading="Review 3 candidates">' ) && false === strpos( $kit, '<os-disclosure heading="Review 3 candidates" open' ) && false !== strpos( $classic, 'Review 3 candidates' ), 'the queue is a closed disclosure headed "Review 3 candidates"' );
ok( 3 === substr_count( $kit, '<os-card compact>' ) && 3 === substr_count( $classic, '<tr>' ) - 1, 'one card per candidate, as one row per candidate' );
ok( 3 === substr_count( $kit, '<dt class="snt-kv__k">Post</dt>' ) && 3 === substr_count( $kit, '<dt class="snt-kv__k">Pattern</dt>' ) && 3 === substr_count( $kit, '<dt class="snt-kv__k">Action</dt>' ), 'the three column labels (Post, Pattern, Action) label every card' );
ok( 2 === substr_count( $kit, '<os-code>Two kinds of provenance</os-code>' ) && 1 === substr_count( $kit, '<os-code>Signal over noise</os-code>' ), 'every post title is printed as inline code' );
ok( 2 === substr_count( $kit, '<a class="snt-link" href="https://example.test/notes/two-kinds-of-provenance/" target="_blank" rel="noopener noreferrer">https://example.test/notes/two-kinds-of-provenance/</a>' ) && 2 === substr_count( $kit, 'class="snt-link"' ) && 2 === substr_count( $classic, '<a href=' ), 'a permalink is an external link, and a candidate without one paints no link (2 of 3, as on the classic leaf)' );
ok( 2 === substr_count( $kit, '<os-badge tone="warning">pull-quote</os-badge>' ) && 1 === substr_count( $kit, '<os-badge tone="warning">steps-enumerated</os-badge>' ), 'every pattern type is a warn badge' );
ok( snt_pa_data_attrs( $classic ) === snt_pa_data_attrs( $kit ) && 0 < count( snt_pa_data_attrs( $kit ) ), 'the Suggest / Dismiss data attributes match the classic row exactly: ' . implode( ' ', snt_pa_data_attrs( $kit ) ) );
ok( 1 === preg_match( '/<os-button[^>]*data-fingerprint="d4e5f6"[^>]*data-snt-suggest="1" data-check="pattern_adoption_steps_enumerated" disabled[^>]*>Suggest<\/os-button>/', $kit ) && 1 === preg_match( '/<os-button[^>]*data-fingerprint="a1b2c3"[^>]*data-check="pattern_adoption_pull_quote" disabled[^>]*>Suggest<\/os-button>/', $kit ), 'Suggest carries the per-pattern check key and is disabled (the window paints no cell for its editor)' );
ok( 1 === preg_match( '/<os-button variant="secondary" data-post-id="58" data-fingerprint="0f9e8d" data-pattern-type="pull-quote" data-snt-dismiss="1">Dismiss<\/os-button>/', $kit ) && 3 === substr_count( $kit, 'data-snt-dismiss="1"' ), 'Dismiss is enabled on every row, carrying post id, fingerprint and pattern type' );
ok( false !== strpos( $kit, 'run Suggest and Apply from the classic Content' ), 'the queue says where Suggest and Apply run' );

// ── Scanned with an empty-array envelope: classic tests truthiness, not
// is_array(), so this must read exactly like "never scanned".
$GLOBALS['__pa_scan'] = array();
$kit = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( false === strpos( $kit, '<os-badge' ) && false === strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'submit-label="Scan for opportunities"' ), 'an empty-array scan envelope reads as never-scanned, like the classic truthiness gate' );

// ── One candidate: the singular strings.
$GLOBALS['__pa_scan'] = snt_pa_scan( array( $rich[2] ) );
$kit = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( false !== strpos( $kit, '>1 opportunity</os-badge>' ) && false !== strpos( $kit, 'heading="Review 1 candidate"' ), 'one candidate: "1 opportunity" and "Review 1 candidate"' );

// ── Escaping: a hostile title, permalink and fingerprint never reach the markup raw.
$GLOBALS['__pa_scan'] = snt_pa_scan( array( array( 'post_id' => 7, 'pattern_type' => 'pull-quote', 'block_fingerprint' => 'fp"onmouseover="z', 'block_path' => '0/0', 'post_title' => '"><script>x</script>', 'permalink' => 'https://example.test/"><script>y</script>' ) ) );
$kit = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;x&lt;/script&gt;' ) && false !== strpos( $kit, 'href="https://example.test/&quot;&gt;&lt;script&gt;' ), 'a hostile title and permalink are escaped' );
ok( false !== strpos( $kit, 'data-fingerprint="fp&quot;onmouseover=&quot;z"' ) && false === strpos( $kit, 'onmouseover="z"' ), 'a hostile fingerprint is escaped inside the data attribute' );

// ── A javascript: permalink scheme must be blanked, the way esc_url() blanks
// it classically — but, like the classic leaf, the text itself still prints
// (esc_url() only blanks the href; it does not remove the visible URL).
$GLOBALS['__pa_scan'] = snt_pa_scan( array( array( 'post_id' => 9, 'pattern_type' => 'pull-quote', 'block_fingerprint' => 'abc123', 'block_path' => '0/0', 'post_title' => 'Scheme test', 'permalink' => 'javascript:alert(1)' ) ) );
$kit = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( false === strpos( $kit, 'href="javascript:' ), 'a javascript: permalink scheme is blanked, not linked' );
ok( false !== strpos( $kit, 'javascript:alert(1)' ) && false === strpos( $kit, '<a' ), 'the rejected-scheme permalink still prints as unlinked text, as the classic leaf does' );

// ── The capability gate: the classic leaf's bare `return` (a non-admin sees
// nothing at all) must be provably reachable, not merely assumed true.
$GLOBALS['__can'] = false;
$kit = snt_leaf_paint( 'content', 'pattern-adoption' );
ok( '<os-empty-state heading="This account cannot manage options."></os-empty-state>' === $kit, 'a non-admin gets the refusal empty state and no form' );
$GLOBALS['__can'] = true;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
