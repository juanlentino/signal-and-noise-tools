<?php
/**
 * Native window leaf: Site -> Broken links
 * (apps/sn-dashboard/parts/leaves/site-broken-links.php).
 *
 * The oracle is the classic leaf (inc/admin-render-sections.php through
 * sn_admin_render_broken_links_section()): across the default paint and the
 * editing paint (`sn_edit` names a broken path) the kit must carry the same
 * field names and sn_action values, and every reading (the broken-path count,
 * each path's hits, last date, referrer and slug suggestion, the probe count,
 * the busiest probes, the whole-log clear) must be painted, a hostile path must
 * be escaped, and none of wp-admin's markup may survive, in the rich, the
 * editing, the hostile, the empty, the probes-only and the capped state.
 *
 * 2026-10-11 (owner rule: no collapsible sections): tiles, one finding, one kit
 * table of broken paths with visible row actions, ONE create-redirect region
 * below it for the path `sn_edit` names, then the probe ledger, open.
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
function form_block( $html, $submit ) { return preg_match( '/<os-form[^>]*submit-label="' . preg_quote( $submit, '/' ) . '"[^>]*>.*?<\/os-form>/s', $html, $m ) ? $m[0] : ''; }
function editing( $path ) { return snt_leaf_paint( 'site', 'broken-links', array( 'params' => array( 'sn_edit' => $path ) ) ); }
function union( array $a, array $b ) { $u = array_values( array_unique( array_merge( $a, $b ) ) ); sort( $u ); return $u; }
/** The table whose first column is labelled $first ('Path') and that has $n columns. */
function table_of( $html, $n ) { foreach ( snt_leaf_tables( $html ) as $t ) { if ( $n === count( $t['columns'] ) ) { return $t; } } return array( 'columns' => array(), 'data' => array(), 'slots' => array() ); }
/** Every `<os-form` inside a table slot cell: a write belongs in the ONE region below the table, never in a row. */
function slot_forms( $html ) { $n = 0; foreach ( snt_leaf_tables( $html ) as $t ) { foreach ( $t['slots'] as $slot ) { $n += substr_count( $slot, '<os-form' ); } } return $n; }
function styled( $html ) { return false !== strpos( (string) preg_replace( '/<os-grid [^>]*>/', '', $html ), ' style="' ); }
// 2026.10.4 live check of #2020: dates in the site's own date format, never a raw Y-m-d.
$GLOBALS['__options']['date_format'] = 'F j, Y';
$t0 = 1725000000; $t1 = $t0 + DAY_IN_SECONDS; $d0 = gmdate( 'F j, Y', $t0 ); $d1 = gmdate( 'F j, Y', $t1 );
// The broken-links leaf owns the three 404 actions, plus redirect_add (a
// suggestion accepted IS a redirect created).
$all_actions = array( 'redirect_404_clear', 'redirect_404_clear_probes', 'redirect_404_delete', 'redirect_add' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/broken-links'] ), 'the painter is registered under site/broken-links' );

// ── Rich state: two broken paths (both with a slug suggestion, since v10.47.0
// a path is broken BECAUSE the suggester matched it), thirty probes.
$log = array(
	'/notes/desing-tokens' => entry( 7, $t0, 'https://ref.example/page' ),
	'/contact-us'          => entry( 2, $t1 ),
);
for ( $i = 1; $i <= 30; $i++ ) { $log[ '/probe-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ] = entry( $i, $t0 ); }
fixture( array( '/first' => array( 'to' => '/notes/design-tokens', 'status' => 301, 'created_at' => $t0 ) ), $log );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed  = editing( '/notes/desing-tokens' );
ok( '' !== $kit && '' !== $kit_ed, 'the kit leaf paints, default and editing' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ), 'no fold in the default or the editing paint: ' . implode( ',', array_merge( snt_leaf_folds( $kit ), snt_leaf_folds( $kit_ed ) ) ) );
ok( snt_leaf_names( $classic ) === union( snt_leaf_names( $kit ), snt_leaf_names( $kit_ed ) ), 'field names match the classic forms across both paints: ' . implode( ',', union( snt_leaf_names( $kit ), snt_leaf_names( $kit_ed ) ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( $all_actions === union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) && snt_leaf_actions( $classic ) === $all_actions, 'the four actions match the classic leaf across both paints: ' . implode( ',', union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) ) );
ok( array( 'redirect_404_clear', 'redirect_404_clear_probes' ) === snt_leaf_actions( $kit ), '...the default paint offers only the two bulk actions: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( in_array( 'redirect_add', snt_leaf_actions( $kit_ed ), true ) && in_array( 'redirect_404_delete', snt_leaf_actions( $kit_ed ), true ), '...the editing paint adds create and dismiss for the open path: ' . implode( ',', snt_leaf_actions( $kit_ed ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ) && ! styled( $kit_ed ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// The reading: two tiles (broken, with attention; probes, without), then the finding.
ok( false !== strpos( $kit, '<os-stat value="2" label="Broken paths" caption="9 hits; needs a look" swatch data-tone="warning">' ), 'the broken tile reads 2 paths, 9 hits, and says it needs a look in words, not only by the swatch (WCAG 1.4.1)' );
ok( false !== strpos( $kit, '<os-stat value="30" label="Automated probes" caption="465 hits">' ), 'the probe tile reads 30 probes, 465 hits, without one' );
ok( false !== strpos( $kit, '<p class="snt-prose">2 broken paths took 9 hits; the busiest, /notes/desing-tokens, took 7 hits.</p>' ), 'the finding names the busiest path, and its unit (2026.10.4: "took 7")' );
ok( false !== strpos( $classic, '2 broken paths took 9 hits; the busiest, /notes/desing-tokens, took 7 hits.' ) && false === strpos( $classic, 'below' ), 'the classic twin says the same finding, and no "Add a target below"' );
ok( before( $kit, '<os-stat', 'heading="Broken paths"' ), 'reading before ledger' );

// The ledger: one table, busiest first, every figure the per-path fold held.
$broken = table_of( $kit, 5 );
// 2026.10.4 live check: no Referrer column (it pushed Fix past the half-row box); the referrer rides under the path.
ok( array( 'Path', 'Hits', 'Last seen', 'Suggested target', 'Actions' ) === array_column( $broken['columns'], 'label' ), 'the broken table has labelled columns, no Referrer column: ' . implode( ',', array_column( $broken['columns'], 'label' ) ) );
$r0 = $broken['data'][0] ?? array(); $r1 = $broken['data'][1] ?? array();
ok( '/notes/desing-tokens' === ( $r0['path']['text'] ?? '' ) && '/contact-us' === ( $r1['path']['text'] ?? '' ), 'broken paths paint busiest first' );
ok( '<span class="snt-break">/notes/desing-tokens</span><span class="snt-cell-sub">from ref.example</span>' === snt_leaf_cell_html( $broken, $r0['path'] ?? null ) && '<span class="snt-break">/contact-us</span><span class="snt-cell-sub">no referrer recorded</span>' === snt_leaf_cell_html( $broken, $r1['path'] ?? null ), 'with a referrer in the list, each path carries its own under it ("from ref.example", or "no referrer recorded")' );
ok( false === strpos( $kit, 'No referrer was recorded' ), '...and the sentence does not claim none' );
ok( 1 === preg_match( '/\.snt-break\s*\{\s*overflow-wrap:\s*anywhere;/', preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) ) ), 'a long path or target breaks inside its cell, so the table fits its box and Fix is never cut off' );
// The Last seen cell never wraps ("2026-10-" over "10" when the lists pair): a nowrap span in a slotted cell.
ok( '<span class="snt-nowrap">' . $d0 . '</span>' === snt_leaf_cell_html( $broken, $r0['last'] ?? null ) && $d0 === ( $r0['last']['text'] ?? '' ), 'Last seen is the site\'s date format ("' . $d0 . '"), in a cell that does not wrap' );
$css_nowrap = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) );
ok( 1 === preg_match( '/\.snt-nowrap\s*\{\s*white-space:\s*nowrap;/', $css_nowrap ), 'the sheet keeps .snt-nowrap on one line' );
ok( false !== strpos( $classic, 'last <span class="sn-nowrap">' . $d0 . '</span>' ) && false === strpos( $classic, gmdate( 'Y-m-d', $t0 ) ), 'the classic twin says the same date, in the site\'s format, unwrapped' );
ok( 1 === preg_match( '/\.sn-nowrap\s*\{\s*white-space:\s*nowrap;/', (string) file_get_contents( SNT_PATH . 'assets/admin.css' ) ), '...and the classic sheet keeps it on one line' );
ok( 7 === ( $r0['hits'] ?? null ) && '/notes/design-tokens' === ( $r0['suggested']['text'] ?? '' ) && '<span class="snt-break">/notes/design-tokens</span>' === snt_leaf_cell_html( $broken, $r0['suggested'] ?? null ), 'a row carries hits, last date and the slug suggestion (a breakable cell): ' . wp_json_encode( $r0 ) );
ok( 2 === ( $r1['hits'] ?? null ) && $d1 === ( $r1['last']['text'] ?? '' ) && '/contact' === ( $r1['suggested']['text'] ?? '' ), '...for every path' );
// 2026.10.4 live check: when no listed path has a referrer (23 of 24 live), the
// table says nothing per row and the box's sentence says it once.
fixture( array(), array( '/notes/desing-tokens' => entry( 7, $t0 ), '/contact-us' => entry( 2, $t1 ) ) );
$noref = snt_leaf_paint( 'site', 'broken-links' );
$noref_t = table_of( $noref, 5 );
ok( false !== strpos( $noref, '<p class="snt-prose">2 broken paths took 9 hits; the busiest, /notes/desing-tokens, took 7 hits. No referrer was recorded for any of them.</p>' ) && false === strpos( $noref, 'snt-cell-sub' ) && false === strpos( $noref, 'none recorded' ) && 5 === count( $noref_t['columns'] ), 'no referrer anywhere: said once in the sentence, no column and no line under each path' );
fixture( array(), array( '/contact-us' => entry( 2, $t1 ) ) );
ok( false !== strpos( snt_leaf_paint( 'site', 'broken-links' ), 'took 2 hits. No referrer was recorded for it.</p>' ), 'one path with none: "No referrer was recorded for it."' );
fixture( array( '/first' => array( 'to' => '/notes/design-tokens', 'status' => 301, 'created_at' => $t0 ) ), $log ); // the rich state again

$cell = snt_leaf_cell_html( $broken, $r0['actions'] ?? null );
ok( '<span id="snt-row-' . substr( md5( '/notes/desing-tokens' ), 0, 12 ) . '" data-snt-focus><os-button variant="ghost" os-action="go" aria-label="Fix /notes/desing-tokens" os-arg-sub="broken-links" os-arg-sn_edit="/notes/desing-tokens" os-arg-anchor="snt-edit-region">Fix</os-button></span>' === $cell, 'a row carries ONE visible action, Fix, a go button naming its path, in its row anchor: ' . substr( $cell, 0, 200 ) );
ok( 0 === slot_forms( $kit ) && 0 === slot_forms( $kit_ed ), 'no table cell holds a form, default or editing: ' . slot_forms( $kit ) . ' / ' . slot_forms( $kit_ed ) );
ok( false === strpos( $kit, 'submit-label="Create redirect"' ) && false === strpos( $kit, 'submit-label="Dismiss"' ) && false === strpos( $kit, 'name="source"' ) && false === strpos( $kit, 'quieter paths' ), 'the default paint stacks no form at all, and no "+N more" under the cap' );

// The editing paint: ONE region below the table, create (prefilled) and dismiss.
ok( 1 === substr_count( $kit_ed, 'submit-label="Create redirect"' ) && false !== strpos( $kit_ed, 'heading="Fixing /notes/desing-tokens"' ) && before( $kit_ed, '<os-table', 'heading="Fixing' ) && before( $kit_ed, 'heading="Fixing', 'heading="Automated probes"' ), 'editing paints ONE region, right below the broken table' );
ok( false !== strpos( $kit_ed, 'description="7 hits, last ' . $d0 . ', from ref.example."' ), '...its description carries the hits, the last date and the referring host' );
$create = form_block( $kit_ed, 'Create redirect' );
ok( false !== strpos( $create, '<os-field-row label="Redirect to" hint="Suggested from your published slugs (closest match); review before creating."><os-text-field name="target" type="text" value="/notes/design-tokens"' ), 'the create form is prefilled with the slug suggestion and says so' );
ok( false !== strpos( $create, '<input type="hidden" name="source" value="/notes/desing-tokens">' ) && false !== strpos( $create, 'name="action" value="sn_redirect_add"' ), '...and posts redirect_add with the path hidden' );
$dismiss = form_block( $kit_ed, 'Dismiss' );
ok( '' !== $dismiss && false !== strpos( $dismiss, '<input type="hidden" name="source" value="/notes/desing-tokens"><input type="hidden" name="action" value="sn_redirect_404_delete">' ) && false === strpos( $dismiss, 'os-confirm' ) && before( $kit_ed, 'submit-label="Create redirect"', 'submit-label="Dismiss"' ), 'Dismiss lives in the region too: unconfirmed (redirect_404_delete), carrying the path, after the create form' );
ok( 1 === preg_match( '/<os-form class="snt-form snt-form--inline"[^>]*submit-label="Dismiss"/', $kit_ed ), '2026-10-16: Dismiss is the compact one-button form, not a full-width form row' );
ok( false !== strpos( $kit_ed, '<p class="snt-hint">Dismiss removes the path from the log without a redirect.</p>' ) && 2 === substr_count( $kit_ed, 'name="source" value="/notes/desing-tokens"' ) && false === strpos( $kit_ed, 'name="source" value="/contact-us"' ), '...said in one line; only the open path is bound, to its create and its dismiss form' );
ok( false !== strpos( $kit_ed, '<os-button variant="ghost" os-action="go" os-arg-sub="broken-links" os-arg-anchor="snt-row-' . substr( md5( '/notes/desing-tokens' ), 0, 12 ) . '">Cancel</os-button>' ), 'Cancel is a go that clears the param and lands back on the row\'s Fix' );
ok( false !== strpos( editing( '/contact-us' ), 'description="2 hits, last ' . $d1 . ', no referrer recorded."' ), 'a path with no referrer says so in its region' );
$unknown = editing( '/nope' );
ok( false === strpos( $unknown, 'Create redirect' ) && false === strpos( $unknown, 'submit-label="Dismiss"' ) && false === strpos( $unknown, 'Fixing' ) && array() === snt_leaf_folds( $unknown ), 'an unknown sn_edit paints no region' );

// The probes: one finding, a capped ledger, the bulk dismiss. Open.
$probe_line = '30 automated probes made 465 hits on paths that resemble nothing published here and that nothing here links to: scanner traffic, not broken links, so no action is needed.';
ok( false !== strpos( $kit, '<p class="snt-prose">' . $probe_line . '</p>' ) && false !== strpos( $kit, 'heading="Automated probes"' ), 'the probe section counts the probes and their hits in one sentence' );
ok( false !== strpos( $classic, $probe_line ), 'the classic twin says the same probe sentence' );
$probes = table_of( $kit, 2 );
ok( array( 'Path', 'Hits' ) === array_column( $probes['columns'], 'label' ) && 25 === count( $probes['data'] ), 'the probe ledger lists 25 of 30 -- listed ' . count( $probes['data'] ) );
$listed = array_column( $probes['data'], 'path' );
ok( '/probe-0030' === ( $listed[0] ?? '' ) && 30 === ( $probes['data'][0]['hits'] ?? null ) && in_array( '/probe-0006', $listed, true ) && ! in_array( '/probe-0005', $listed, true ), '...the 25 busiest, with their hits' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+5 more probed paths, the quietest; the list is capped, not complete.</p>' ), '...and counts the rest out loud' );
preg_match( '/<os-button[^>]*os-arg-action="sn_redirect_404_clear_probes"[^>]*>/', $kit, $m );
ok( isset( $m[0] ) && false !== strpos( $m[0], 'os-confirm="Dismiss every automated probe from the log? Genuinely broken paths are kept."' ) && false !== strpos( $m[0], 'os-confirm-label="Dismiss probes"' ) && false === strpos( $m[0], 'os-confirm-danger' ), 'Dismiss all probes confirms with its label and is not marked danger' );
preg_match( '/<os-button[^>]*os-arg-action="sn_redirect_404_clear"[^>]*>/', $kit, $m );
ok( isset( $m[0] ) && false !== strpos( $m[0], 'os-confirm="Clear the entire 404 log?"' ) && false !== strpos( $m[0], 'os-confirm-label="Clear"' ) && before( $kit, 'heading="Automated probes"', 'os-arg-action="sn_redirect_404_clear"' ), 'Clear 404 log confirms with its label, last' );

// ── Escaping: hostile probe paths, and a hostile BROKEN path (one the suggester matches).
fixture( array(), array( '/y"><script>alert1</script>' => entry( 3, $t0 ), '/p"><script>alert2</script>' => entry( 1, $t0 ), '/notes/design-tokens"><b>' => entry( 2, $t0 ) ) );
$kit    = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed = editing( '/notes/design-tokens"><b>' );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit . $kit_ed, '"><b>' ), 'hostile 404 paths never reach the markup raw' );
ok( substr_count( $kit, '&lt;script&gt;' ) >= 2, '...they are escaped, not dropped' );
// The fixture must REACH the markup for the escaping pins to mean anything:
// v13.109.9 rejects any path containing a parenthesis, and `alert(1)` has two.
ok( in_array( '/y"><script>alert1</script>', array_column( table_of( $kit, 2 )['data'], 'path' ), true ) && '/notes/design-tokens"><b>' === ( table_of( $kit, 5 )['data'][0]['path']['text'] ?? '' ), '...and they REACHED the probe and broken tables, so the pins are not vacuous' );
ok( false !== strpos( $kit_ed, 'heading="Fixing /notes/design-tokens&quot;&gt;&lt;b&gt;"' ), '...the region heading escapes it' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ) && array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ), 'no fold and no wp-admin markup (hostile)' );

// ── Empty state: nothing broken, nothing probed: two zero tiles, measured.
fixture( array(), array() );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
$empty_line = 'No broken links. A path lands here when it resembles a published URL, or when something on this site links to it; automated probes are counted separately.';
ok( false !== strpos( $kit, '<os-stat value="0" label="Broken paths" caption="clean">' ) && false !== strpos( $kit, '<p class="snt-prose">' . $empty_line . '</p>' ), 'empty: the clean reading and its sentence paint, "or something on this site links to it" restored to agree with the intro' );
ok( false !== strpos( $classic, $empty_line ), 'empty: the classic twin says the same sentence' );
ok( false === strpos( $kit, 'heading="Automated probes"' ) && false === strpos( $kit, '<os-table' ), '...with no probe section and no empty table' );
ok( array() === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_folds( $kit ), 'empty: no action is offered, the classic leaf agrees, no fold' );

// ── Probes only: clean broken reading beside the probe ledger; dismiss offered, clear-log not.
fixture( array(), array( '/probe-a' => entry( 4, $t0 ), '/probe-b' => entry( 1, $t0 ), '/probe-c' => entry( 9, $t0 ) ) );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
ok( false !== strpos( $kit, '<os-stat value="0" label="Broken paths" caption="clean">' ) && false !== strpos( $kit, '<os-stat value="3" label="Automated probes" caption="14 hits">' ), 'probes only: clean broken tile beside the probe tile' );
ok( 3 === count( table_of( $kit, 2 )['data'] ) && false === strpos( $kit, 'snt-ledger-more' ), '...the probe ledger lists all 3, no "+N more" under the cap' );
ok( false !== strpos( $kit, 'redirect_404_clear_probes' ) && false === strpos( $kit, 'os-arg-action="sn_redirect_404_clear"' ), 'dismiss-probes is offered, clear-log is not' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_folds( $kit ), '...the classic leaf agrees on the action set, no fold' );
ok( false === strpos( $kit, 'os-arg-action="sn_redirect_update"' ) && false === strpos( $kit, 'os-arg-action="sn_redirect_delete"' ) && false === strpos( $kit, '<aside' ), 'the split, from this side: no redirect edit/delete action, no rail' );

// ── The broken list is CAPPED busiest first, and says so out loud.
$GLOBALS['__published'] = array();
$many = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$slug = '/notes/alpha-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT );
	$GLOBALS['__published'][ $i ] = 'https://example.test' . $slug;
	$many[ $slug . 'x' ] = entry( 100 - $i, $t0 ); // One extra character: similar enough to suggest, different enough to 404.
}
fixture( array(), $many );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed  = editing( '/notes/alpha-0001x' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
$broken  = table_of( $kit, 5 );
$paths   = array_column( array_column( $broken['data'], 'path' ), 'text' );
ok( 30 === count( $many ) && false !== strpos( $kit, '<os-stat value="0" label="Automated probes"' ), 'sanity: 30 paths, and the suggester classified every one as BROKEN, not as a probe' );
ok( SN_404_LIST_CAP === count( $paths ) && 1 === count( snt_leaf_tables( $kit ) ), 'exactly SN_404_LIST_CAP (' . SN_404_LIST_CAP . ') paths are listed, not all 30 -- listed ' . count( $paths ) );
ok( '/notes/alpha-0001x' === ( $paths[0] ?? '' ) && ! in_array( '/notes/alpha-0030x', $paths, true ), '...the BUSIEST are the ones kept, and the quietest are the ones dropped' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+5 quieter paths are not listed. Showing the 25 busiest of 30 broken paths. Clear the log or act on these to see the rest.</p>' ), '...and the "+N more" line says how many were not shown' );
ok( false !== strpos( $kit, '<os-stat value="30" label="Broken paths"' ), 'the tile still states the TRUE total, not the listed count' );
ok( false !== strpos( $classic, 'Showing the 25 busiest of 30 broken paths' ), '...and the classic leaf caps identically -- one constant, read by both' );
ok( snt_leaf_actions( $classic ) === union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) && array() === snt_leaf_folds( $kit ), '...with the same action set on both sides under the cap, no fold' );

// ── Live-shaped preview: 31 broken paths (typos of published slugs, mixed
// referrers) and 40 scanner probes that pass the capture filter.
$slugs = array(
	'design-tokens', 'provenance-over-detection', 'provenance-as-substrate', 'two-kinds-of-provenance', 'weighted-identity-frameworks',
	'attribution-is-a-data-problem', 'music-information-retrieval-ethics', 'contributor-identity', 'the-credits-problem', 'liner-notes-are-metadata',
	'session-musicians-and-credit', 'detection-is-an-arms-race', 'watermarks-are-not-provenance', 'c2pa-for-audio', 'a-ledger-of-hands',
	'why-isrc-is-not-enough', 'the-producer-question', 'sampling-and-lineage', 'stems-as-evidence', 'consent-at-the-session', 'who-played-on-this',
	'metadata-debt', 'royalty-routing', 'the-orphaned-recording', 'archives-and-attribution', 'notes-on-mir-evaluation', 'beat-tracking-in-practice',
	'onset-detection-notes', 'chord-recognition-limits', 'source-separation-ethics', 'datasets-and-permission',
);
$refs = array( 'https://www.google.com/', '', 'https://news.ycombinator.com/item?id=1', 'https://example.test/notes/design-tokens', '', 'https://bsky.app/profile/example' );
$GLOBALS['__published'] = array();
$live = array();
foreach ( $slugs as $i => $slug ) {
	$GLOBALS['__published'][ $i + 1 ] = 'https://example.test/notes/' . $slug;
	$typo = substr( $slug, 0, 3 ) . $slug[4] . $slug[3] . substr( $slug, 5 ); // Two letters transposed: a mistyped link.
	$live[ '/notes/' . $typo ] = entry( 140 - 4 * $i, $t0 + $i * HOUR_IN_SECONDS, $refs[ $i % count( $refs ) ] );
}
foreach ( array(
	'/remote/login', '/owa/auth/logon', '/sslvpn_logon', '/global-protect/login', '/cf_scripts/scripts/ajax', '/autodiscover/autodiscover',
	'/ecp/default', '/mifs/user', '/vpn/index', '/hudson', '/manager/html', '/console/login', '/geoserver/web', '/portal/redlion', '/sdk',
	'/nmaplowercheck1700000000', '/evox/about', '/ab2g', '/ab2h', '/systembc/password', '/cgi/login', '/developmentserver/metadatauploader',
	'/webui', '/jasperserver/login', '/static/admin', '/assets/admin', '/simple', '/remote/fgt_lang', '/aaa9', '/aab8', '/t4', '/teorema505',
	'/version', '/info', '/owncloud/status', '/nextcloud/login', '/zabbix/index', '/grafana/login', '/kibana/app', '/portainer/api',
) as $i => $probe ) {
	$live[ $probe ] = entry( 1 + ( $i * 7 ) % 23, $t0 );
}
fixture( array(), $live );
$data   = \SignalNoise\OpenStationHost\Dashboard\Leaves\redirects_data();
$kit    = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed = editing( (string) array_key_first( $data['broken'] ) );
ok( 31 === count( $data['broken'] ) && 40 === count( $data['probes'] ), 'live-shaped sanity: 31 broken and 40 probes survive the capture filter and the partition -- ' . count( $data['broken'] ) . ' / ' . count( $data['probes'] ) );
ok( false !== strpos( $kit, '<os-stat value="31" label="Broken paths"' ) && false !== strpos( $kit, '<os-stat value="40" label="Automated probes"' ), 'live-shaped: the tiles read 31 broken and 40 probes' );
ok( 25 === count( table_of( $kit, 5 )['data'] ) && false !== strpos( $kit, '+6 quieter paths are not listed.' ) && 25 === count( table_of( $kit, 2 )['data'] ) && false !== strpos( $kit, '+15 more probed paths' ), 'live-shaped: both ledgers capped at 25, each with its "+N more"' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ) && 1 === substr_count( $kit_ed, 'submit-label="Create redirect"' ) && 1 === substr_count( $kit_ed, 'submit-label="Dismiss"' ) && 0 === slot_forms( $kit ), 'live-shaped: fold-free, no form in any row, and editing paints ONE region (create + dismiss)' );
snt_leaf_preview( 'site-broken-links', $kit );
snt_leaf_preview( 'site-broken-links-editing', $kit_ed );
// Seen live with real data (2026-10-12): Save, the second form and Cancel
// each took a row. Cancel sits in the primary form's own action row.
ok( 1 === preg_match( '#<os-form[^>]*>(?:(?!</os-form>).)*<span slot="footer-leading"><os-button[^>]*>Cancel</os-button></span>(?:(?!</os-form>).)*name="action" value="[a-z_]*redirect_add"#s', $kit_ed ), 'Cancel sits beside the submit, in the editor form\'s footer' );
$probes_at = strpos( $kit, '<os-section heading="Automated probes"' );
ok( false !== $probes_at && false !== strpos( substr( $kit, $probes_at ), 'class="snt-table-full"' ), 'the probes list shows every row too: with only Broken paths in full, the pair measured 1,153px against 644px live (1.79x, past the 1.5x rule)' );
$focus_problems = snt_leaf_edit_focus_problems( $kit, $kit_ed );
ok( array() === $focus_problems, 'a11y: Edit lands the keyboard on the one region, Cancel lands it back on the list: ' . implode( '; ', $focus_problems ) );

// ── 2026-10-12 (owner: "it's weird to click in 'edit' and something else
// opens"): the editor opens UNDER its row, not below the whole table.
$ip_def   = $kit;
$ip_ed    = editing( (string) array_keys( $data['broken'] )[3] );
$ip_table = table_of( $kit, 5 );
$ip_keys  = array_map( static function ( $v ) { return is_array( $v ) ? (string) ( $v['text'] ?? '' ) : (string) $v; }, array_column( $ip_table['data'], 'path' ) );
$ip_at    = array_search( (string) array_keys( $data['broken'] )[3], $ip_keys, true );
$ip_split = snt_leaf_split_problems( $ip_ed, 'path', (string) array_keys( $data['broken'] )[3], false === $ip_at ? null : ( $ip_keys[ $ip_at + 1 ] ?? null ) );
ok( false !== $ip_at && array() === $ip_split, 'edit in place: the editor opens under its row, the row marked selected, the table resuming with the next row after it: ' . implode( '; ', $ip_split ) );
ok( false !== strpos( $ip_ed, '>Editing</os-badge>' ), 'edit in place: the open row says Editing in words, not only by its accent bar' );
ok( 1 === preg_match( '/os-arg-anchor="(snt-row-[0-9a-f]{12})"[^>]*>Cancel/', $ip_ed, $ip_m ) && false !== strpos( $ip_def, 'id="' . $ip_m[1] . '" data-snt-focus' ), 'edit in place: Cancel lands back on the open row\'s own button' );
ok( false !== strpos( $ip_table['attrs'], 'class="snt-table-full"' ), 'edit in place: the list is not boxed in an inner scroller (the app caps os-table at 440px)' );
ok( false !== strpos( $ip_ed, '+6 quieter paths are not listed.' ) && 25 === count( snt_leaf_tables( $ip_ed )[0]['data'] ) + count( snt_leaf_tables( $ip_ed )[1]['data'] ), 'edit in place: the halves hold the same 25 paths and the "+6" line still counts the rest' );
// 2026-10-12 (measured live, foreground window): Broken paths 578px and
// Automated probes 644px stacked full width, 1.11x, so they pair on one row.
// With a path open its editor and the resumed table make that side far
// taller than the probes (past 1.5x), so the editing paint stacks them.
$pair_open = snt_leaf_row();
$pair_at   = strpos( $kit, $pair_open );
$pair_end  = false !== $pair_at ? strpos( $kit, '</os-grid>', $pair_at ) : false;
$pair      = false !== $pair_end ? substr( $kit, $pair_at, $pair_end - $pair_at ) : '';
ok( false !== strpos( $pair, 'heading="Broken paths"' ) && false !== strpos( $pair, 'heading="Automated probes"' ), 'Broken paths and Automated probes share one row (heights within 1.5x)' );
ok( false === strpos( $ip_ed, $pair_open ) && strpos( $ip_ed, 'heading="Automated probes"' ) > strpos( $ip_ed, 'id="snt-edit-region"' ), 'with a path open they stack: the editor under its row at full width, the probes after the list' );

// Review on #2005: each Fix names its path for a screen reader, its visible word first.
ok( false !== strpos( $kit, 'aria-label="Fix /' ), 'each Fix button is named "Fix /path" (label in name: the visible word first)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
