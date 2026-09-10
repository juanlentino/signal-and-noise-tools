<?php
/**
 * Native window leaf: Site -> Broken links
 * (apps/sn-dashboard/parts/leaves/site-broken-links.php).
 *
 * The oracle is the classic leaf (inc/admin-render-sections.php through
 * sn_admin_render_broken_links_section()): the kit must carry the same
 * sn_action values, and every readout (the broken-links status, the probe
 * bucket, one section per broken path with its slug suggestion, the busiest-
 * probes fold, the whole-log clear) must be painted, a hostile path must be
 * escaped, and none of wp-admin's markup may survive -- in the rich, the
 * empty and the probes-only state.
 *
 * It also pins the SPLIT from this side: this leaf paints no redirect
 * edit/delete action and no rail. The redirects half is pinned by
 * tests/os-leaf-site-redirects.php.
 *
 * Run: php tests/os-leaf-site-broken-links.php
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
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-broken-links.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fixture( array $redirects, array $log ) { $GLOBALS['__options']['sn_redirects'] = $redirects; $GLOBALS['__options']['sn_404_log'] = $log; }
function entry( $count, $ts, $referer = '' ) { return array( 'count' => $count, 'first_seen' => $ts, 'last_seen' => $ts, 'referer' => $referer ); }
function before( $html, $a, $b ) { return false !== strpos( $html, $a ) && false !== strpos( $html, $b ) && strpos( $html, $a ) < strpos( $html, $b ); }
$t0 = 1725000000; $t1 = $t0 + DAY_IN_SECONDS; $d0 = gmdate( 'Y-m-d', $t0 ); $d1 = gmdate( 'Y-m-d', $t1 );
// The broken-links leaf owns the three 404 actions, plus redirect_add (a
// suggestion accepted IS a redirect created).
$all_actions = array( 'redirect_404_clear', 'redirect_404_clear_probes', 'redirect_404_delete', 'redirect_add' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/broken-links'] ), 'the painter is registered under site/broken-links' );

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
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( $all_actions === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the six actions match the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// The redirect manager: newest first, each an edit form + a confirmed delete form.
function form_block( $html, $submit ) { return preg_match( '/<os-form[^>]*submit-label="' . preg_quote( $submit, '/' ) . '"[^>]*>.*?<\/os-form>/s', $html, $m ) ? $m[0] : ''; }

// The rail: status, probes, broken paths, clear.
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>2 broken paths</b>' ) && false !== strpos( $kit, '>Attention</os-badge>' ) && false !== strpos( $kit, 'Add a target below to redirect it' ), 'two broken paths paint the warning status with the Attention badge' );
ok( before( $kit, 'heading="/notes/desing-tokens"', 'heading="/contact-us"' ), 'broken paths paint busiest first' );
ok( false !== strpos( $kit, '<p class="snt-hint">7 hits · last ' . $d0 . ' · from <os-code>ref.example</os-code></p>' ) && false !== strpos( $kit, '<p class="snt-hint">2 hits · last ' . $d1 . '</p>' ), 'each broken path shows its hits, its last date and its referring host' );
ok( false !== strpos( $kit, '<os-field-row label="Redirect to" hint="Suggested from your published slugs (closest match) — review before creating."><os-text-field name="target" type="text" value="/notes/design-tokens"' ) && false !== strpos( $kit, 'value="/contact"' ), 'the create form is prefilled with the slug suggestion and says so' );
$create  = form_block( $kit, 'Create redirect' );
$dismiss = form_block( $kit, 'Dismiss' );
ok( '' !== $create && false !== strpos( $create, '<input type="hidden" name="source" value="/notes/desing-tokens">' ) && false !== strpos( $create, 'name="sn_action" value="redirect_add"' ) && '' !== $dismiss && false !== strpos( $dismiss, '<input type="hidden" name="source" value="/notes/desing-tokens"><input type="hidden" name="sn_action" value="redirect_404_delete">' ) && false === strpos( $dismiss, 'os-confirm' ), 'a broken path offers Create redirect (redirect_add) and an unconfirmed Dismiss (redirect_404_delete), both carrying the path' );
ok( false !== strpos( $kit, '<b>30 automated probes</b><br>465 hits on paths that match nothing published here' ) && false !== strpos( $kit, 'tone="neutral"' ), 'the probe bucket counts the probes and their hits without an attention tone' );
ok( false !== strpos( $kit, '<os-disclosure heading="Show the probed paths">' ) && false !== strpos( $kit, '<li><os-code>/probe-0030</os-code> <span class="snt-hint">30×</span></li>' ) && false !== strpos( $kit, '<os-code>/probe-0006</os-code>' ) && false === strpos( $kit, '<os-code>/probe-0005</os-code>' ) && false !== strpos( $kit, '<li class="snt-hint">…and 5 more</li>' ), 'the fold lists the 25 busiest probes and counts the rest' );
preg_match( '/<os-button[^>]*os-arg-action="redirect_404_clear_probes"[^>]*>/', $kit, $m );
ok( isset( $m[0] ) && false !== strpos( $m[0], 'os-confirm="Dismiss every automated probe from the log? Genuinely broken paths are kept."' ) && false !== strpos( $m[0], 'os-confirm-label="Dismiss probes"' ) && false === strpos( $m[0], 'os-confirm-danger' ), 'Dismiss all probes confirms with its label and is not marked danger' );
preg_match( '/<os-button[^>]*os-arg-action="redirect_404_clear"[^>]*>/', $kit, $m );
ok( isset( $m[0] ) && false !== strpos( $m[0], 'os-confirm="Clear the entire 404 log?"' ) && false !== strpos( $m[0], 'os-confirm-label="Clear"' ), 'Clear 404 log confirms with its label' );

// ── Escaping: a hostile broken path and probe path never reach the markup raw.
fixture(
	array(),
	array(
		'/y"><script>alert1</script>'  => entry( 3, $t0 ),
		'/p"><script>alert2</script>'  => entry( 1, $t0 ),
	)
);
$kit = snt_leaf_paint( 'site', 'broken-links' );
ok( false === strpos( $kit, '<script>' ), 'hostile 404 paths never reach the markup raw' );
ok( substr_count( $kit, '&lt;script&gt;' ) >= 2, '...they are escaped, not dropped' );
// The fixture must REACH the markup for the escaping pin above to mean anything:
// v13.109.9 rejects any path containing a parenthesis, and `alert(1)` has two.
ok(
	false !== strpos( $kit, '<os-code>/y&quot;&gt;&lt;script&gt;alert1&lt;/script&gt;</os-code>' ),
	'...and the hostile path REACHED the markup (in the probe fold), so the pin above is not vacuous'
);
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives (hostile)' );

// ── Empty state: nothing broken, nothing probed — the clean status only.
fixture( array(), array() );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, '<b>No broken links</b>' ), 'empty: the clean status paints' );
ok( false === strpos( $kit, 'automated probes' ), '...with no probe bucket' );
ok( array() === snt_leaf_actions( $kit ) || array( 'redirect_add' ) === snt_leaf_actions( $kit ), 'empty: no destructive action is offered' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '...and the classic leaf agrees' );

// ── Probes only: clean status beside the bucket, dismiss offered, clear-log not.
fixture( array(), array( '/probe-a' => entry( 4, $t0 ), '/probe-b' => entry( 1, $t0 ), '/probe-c' => entry( 9, $t0 ) ) );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
ok( false !== strpos( $kit, '<b>No broken links</b>' ) && false !== strpos( $kit, '<b>3 automated probes</b>' ), 'probes only: clean status beside the bucket' );
ok( false !== strpos( $kit, 'redirect_404_clear_probes' ) && false === strpos( $kit, 'os-arg-action="redirect_404_clear"' ), 'dismiss-probes is offered, clear-log is not' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '...and the classic leaf agrees on the action set' );

// The split, from this side.
ok( false === strpos( $kit, 'os-arg-action="redirect_update"' ) && false === strpos( $kit, 'os-arg-action="redirect_delete"' ), 'the broken-links leaf paints NO redirect edit/delete action' );
ok( false === strpos( $kit, '<aside' ), 'and no rail: Pattern B is full width' );


// ── The list is FOLDED and CAPPED. ──
// A section per broken path is the right shape for one and the wrong shape for
// twenty. Measured live 2026-09-10: 20 paths x ~450px painted a 9,101px leaf --
// nine screens of scrolling to reach the Clear button, because each path
// carried a whole create-redirect form open by default. Now each path is one
// line that opens on demand, and the list is capped busiest-first.
fixture( array(), array( '/notes/desing-tokens' => entry( 7, $t0, 'https://ref.example/page' ) ) );
$kit = snt_leaf_paint( 'site', 'broken-links' );
ok(
	false !== strpos( $kit, '<os-disclosure heading="/notes/desing-tokens" hint="7 hits · last ' . $d0 . '">' ),
	'a broken path is ONE LINE: a disclosure headed by the path, hinted with its hits and last date'
);
ok(
	before( $kit, '<os-disclosure heading="/notes/desing-tokens"', 'submit-label="Create redirect"' ),
	'...and the create form lives INSIDE the fold, not open on the page'
);

// Thirty broken paths: each a near-miss of a published slug, so each is broken
// rather than a probe. The cap must list 25 and count all 30.
$GLOBALS['__published'] = array();
$many = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$slug = '/notes/alpha-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT );
	$GLOBALS['__published'][ $i ] = 'https://example.test' . $slug;
	// One transposed character: similar enough to suggest, different enough to 404.
	$many[ $slug . 'x' ] = entry( 100 - $i, $t0 );
}
fixture( array(), $many );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );

// Sanity on the FIXTURE, not on the leaf: 30 paths, and every one classified
// BROKEN rather than a probe -- a probe would be bucketed and never listed, so
// a fixture that silently produced probes would make the cap test vacuous.
ok( 30 === count( $many ), 'sanity: the fixture built 30 paths -- ' . count( $many ) );
ok(
	false === strpos( $kit, 'automated probes' ),
	'...and the suggester classified every one as BROKEN, not as a probe'
);
ok(
	SN_404_LIST_CAP === substr_count( $kit, '<os-disclosure heading="/notes/alpha-' ),
	'exactly SN_404_LIST_CAP (' . SN_404_LIST_CAP . ') paths are listed, not all 30 -- listed ' . substr_count( $kit, '<os-disclosure heading="/notes/alpha-' )
);
ok(
	false !== strpos( $kit, '<os-disclosure heading="/notes/alpha-0001x"' ) && false === strpos( $kit, '<os-disclosure heading="/notes/alpha-0030x"' ),
	'...the BUSIEST are the ones kept, and the quietest are the ones dropped'
);
ok(
	false !== strpos( $kit, 'Showing the 25 busiest of 30 broken paths' ),
	'...and the overflow says how many were not shown'
);
// The cap must never make the log look smaller than it is.
ok( false !== strpos( $kit, '<b>30 broken paths</b>' ), 'the status still states the TRUE total, not the listed count' );
ok(
	false !== strpos( $classic, 'Showing the 25 busiest of 30 broken paths' ),
	'...and the classic leaf caps identically -- one constant, read by both'
);
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '...with the same action set on both sides under the cap' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );