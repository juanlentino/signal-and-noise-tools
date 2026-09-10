<?php
/**
 * Native window leaf: Site → Redirects (apps/sn-dashboard/parts/leaves/site-redirects.php).
 *
 * The oracle is the classic leaf (inc/redirects-admin.php through
 * sn_admin_render_redirects_section()): the kit forms must carry the same field
 * names and the same six sn_action values, every readout (redirects newest
 * first, the add form, the broken-links status, the probe bucket, one section
 * per broken path with its slug suggestion, the whole-log clear) must be
 * painted, a hostile path must be escaped, and none of wp-admin's markup may
 * survive — in the rich, the empty and the probes-only state.
 *
 * Run: php tests/os-leaf-site-redirects.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers: the published slug set the 404 suggester ranks.
$GLOBALS['__published'] = array( 1 => 'https://example.test/notes/design-tokens', 2 => 'https://example.test/contact' );
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $args = array() ) { return array_keys( $GLOBALS['__published'] ); } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return $GLOBALS['__published'][ (int) $id ] ?? ''; } }

require SNT_PATH . 'inc/redirects-store.php';
require SNT_PATH . 'inc/redirects-404-log.php';
require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/redirects-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-redirects.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fixture( array $redirects, array $log ) { $GLOBALS['__options']['sn_redirects'] = $redirects; $GLOBALS['__options']['sn_404_log'] = $log; }
function entry( $count, $ts, $referer = '' ) { return array( 'count' => $count, 'first_seen' => $ts, 'last_seen' => $ts, 'referer' => $referer ); }
function before( $html, $a, $b ) { return false !== strpos( $html, $a ) && false !== strpos( $html, $b ) && strpos( $html, $a ) < strpos( $html, $b ); }
$t0 = 1725000000; $t1 = $t0 + DAY_IN_SECONDS; $d0 = gmdate( 'Y-m-d', $t0 ); $d1 = gmdate( 'Y-m-d', $t1 );
// v13.109.8: the three redirect_404_* actions moved to the broken-links leaf.
$all_actions = array( 'redirect_add', 'redirect_delete', 'redirect_update' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/redirects'] ), 'the painter is registered under site/redirects' );

// ── Rich state: two redirects, two broken paths (both with a slug suggestion —
// since v10.47.0 a path is broken BECAUSE the suggester matched it), thirty probes.
$log = array(
	'/notes/desing-tokens' => entry( 7, $t0, 'https://ref.example/page' ),
	'/contact-us'          => entry( 2, $t1 ),
);
for ( $i = 1; $i <= 30; $i++ ) { $log[ '/probe-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ] = entry( $i, $t0 ); }
fixture(
	array(
		'/first'  => array( 'to' => '/notes/design-tokens', 'status' => 301, 'created_at' => $t0 ),
		'/second' => array( 'to' => 'https://example.com/moved', 'status' => 302, 'created_at' => $t1 ),
	),
	$log
);
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( $all_actions === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the six actions match the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<p class="snt-prose">' ) && false !== strpos( $kit, '<os-code>/new-page</os-code>' ) && false !== strpos( $kit, '<strong>404 log</strong>' ), 'the intro survives as prose with inline code' );

// The redirect manager: newest first, each an edit form + a confirmed delete form.
function form_block( $html, $submit ) { return preg_match( '/<os-form[^>]*submit-label="' . preg_quote( $submit, '/' ) . '"[^>]*>.*?<\/os-form>/s', $html, $m ) ? $m[0] : ''; }
ok( before( $kit, 'heading="/second"', 'heading="/first"' ), 'redirects paint newest first (/second before /first)' );
ok( false !== strpos( $kit, '<os-section heading="/second" description="Added ' . $d1 . '" stack>' ), 'a redirect is a section headed by its source, described by its creation date' );
ok( false !== strpos( $kit, '<os-text-field name="target" type="text" value="https://example.com/moved"' ) && false !== strpos( $kit, '<os-field-row label="Redirects to">' ), 'the target field carries the current destination' );
ok( false !== strpos( $kit, '<os-select name="status" value="302"><os-option value="301">301. Permanent</os-option><os-option value="302">302. Temporary</os-option></os-select>' ), 'the type select offers 301/302 with the current one selected' );
$edit_row   = form_block( $kit, 'Save changes' );
$delete_row = form_block( $kit, 'Delete' );
ok( '' !== $edit_row && false !== strpos( $edit_row, '<input type="hidden" name="source" value="/second">' ) && false !== strpos( $edit_row, 'name="sn_action" value="redirect_update"' ), 'the edit form posts redirect_update with the source hidden' );
ok( '' !== $delete_row && false !== strpos( $delete_row, '<os-form os-confirm-title="Delete this redirect?" os-confirm-label="Delete" class="snt-form" os-action="post" submit-label="Delete"' ) && false !== strpos( $delete_row, 'os-confirm="This redirect will stop working immediately." os-confirm-danger>' ) && false !== strpos( $delete_row, 'name="sn_action" value="redirect_delete"' ) && false !== strpos( $delete_row, '<input type="hidden" name="source" value="/second">' ), 'the delete form confirms as the classic button did — title, label, question, danger — and carries its own source' );
ok( 2 === substr_count( $kit, '<input type="hidden" name="source" value="/second">' ) && 2 === substr_count( $kit, '<input type="hidden" name="source" value="/first">' ), 'every redirect row binds its own source to BOTH its edit and its delete form' );
ok( false !== strpos( $kit, 'placeholder="/old-page"' ) && false !== strpos( $kit, 'hint="The path to match, e.g. /old-page. Trailing slash and query string are ignored."' ) && false !== strpos( $kit, 'placeholder="/new-page  or  https://example.com/page"' ) && false !== strpos( $kit, 'submit-label="Add redirect"' ) && false !== strpos( $kit, 'heading="Add a redirect"' ), 'the add form carries both placeholders, the hint and the Add redirect submit' );

// v13.109.8: the 404 log is its own leaf now (tests/os-leaf-site-broken-links.php).
// Pin the SPLIT itself, not just what remains: a redirects leaf that quietly
// starts painting 404 rows again would otherwise pass everything above.
ok( false === strpos( $kit, 'redirect_404_' ), 'the redirects leaf paints NO 404 action' );
ok( false === strpos( $kit, 'broken paths' ) && false === strpos( $kit, 'automated probes' ), '...and none of the 404 copy' );
ok( false === strpos( $kit, '<aside' ), '...and no rail at all: Pattern B is full width' );

// ── Escaping: a hostile source, target, broken path (one the suggester matches,
// so it paints as a section + two hidden fields) and probe path never reach the markup raw.
fixture(
	array( '/x"><script>alert(1)</script>' => array( 'to' => '"><script>t</script>', 'status' => 301, 'created_at' => $t0 ) ),
	array(
		'/notes/design-tokens<script>' => entry( 3, $t0, 'https://"><script>r</script>/' ),
		'/y"><script>z</script>'       => entry( 1, $t0 ),
	)
);
$kit = snt_leaf_paint( 'site', 'redirects' );
ok( false !== strpos( $kit, 'heading="/y&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"' ) || false !== strpos( $kit, '&lt;script&gt;' ), 'the hostile redirect source is escaped in its section heading' );
ok( false === strpos( $kit, '<script>' ) && substr_count( $kit, '&lt;script&gt;' ) >= 2, 'hostile source and target never reach the markup raw' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives (hostile): ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Empty state: nothing to redirect, nothing broken — only the add form.
fixture( array(), array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
ok( array( 'redirect_add' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty: only redirect_add is offered, with the same names as the classic leaf' );
ok( false === strpos( $kit, 'No broken links' ), 'empty: the 404 status belongs to the other leaf now' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives (empty): ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// v13.109.8: the probes-only scenario moved to tests/os-leaf-site-broken-links.php.


// ── The rule list is capped too. ──
// Smaller than the 404 log today, but it grows the same way and for the same
// reason: one edit form per rule. Newest first, so the cap drops the settled
// tail rather than the rules being worked on.
$many = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$many[ '/rule-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ] = array(
		'to' => '/target-' . $i, 'status' => 301, 'created_at' => $t0 + $i,
	);
}
fixture( $many, array() );
$kit     = snt_leaf_paint( 'site', 'redirects' );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
ok( 60 === count( $many ), 'sanity: the fixture built 60 redirect rules -- ' . count( $many ) );
ok(
	SN_REDIRECT_LIST_CAP === substr_count( $kit, 'name="source" value="/rule-' ) / 2,
	'exactly SN_REDIRECT_LIST_CAP (' . SN_REDIRECT_LIST_CAP . ') rules are listed of 60 -- listed ' . ( substr_count( $kit, 'name="source" value="/rule-' ) / 2 )
);
ok(
	false !== strpos( $kit, 'value="/rule-0060"' ) && false === strpos( $kit, 'value="/rule-0001"' ),
	'...NEWEST first: the most recent rules are kept, the settled tail is dropped'
);
ok( false !== strpos( $kit, 'Showing the 50 most recent of 60 redirects' ), '...and the overflow states the true total' );
ok( false !== strpos( $classic, 'Showing the 50 most recent of 60 redirects' ), '...and the classic leaf caps identically' );
ok(
	in_array( 'redirect_add', snt_leaf_actions( $kit ), true ) && false !== strpos( $kit, 'Add a redirect' ),
	'the add form is still reachable below a capped list -- actions: ' . implode( ',', snt_leaf_actions( $kit ) )
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
