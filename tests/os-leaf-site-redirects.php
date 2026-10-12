<?php
/**
 * Native window leaf: Site → Redirects (apps/sn-dashboard/parts/leaves/site-redirects.php).
 *
 * The oracle is the classic leaf (inc/redirects-admin.php through
 * sn_admin_render_redirects_section()): across the default paint and the
 * editing paint (`sn_edit` names a rule) the kit forms must carry the same
 * field names and the same three sn_action values, every reading (the rules
 * newest first, each rule's target, type and added date, the add form) must be
 * painted, a hostile path must be escaped, and none of wp-admin's markup may
 * survive, in the rich, the editing, the hostile, the empty and the capped state.
 *
 * 2026-10-11 (owner rule: no collapsible sections): one kit table of rules with
 * visible row actions, ONE edit region below it for the rule `sn_edit` names,
 * the add form open otherwise. Never a fold, never a stack of forms.
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
function form_block( $html, $submit ) { return preg_match( '/<os-form[^>]*submit-label="' . preg_quote( $submit, '/' ) . '"[^>]*>.*?<\/os-form>/s', $html, $m ) ? $m[0] : ''; }
function editing( $source ) { return snt_leaf_paint( 'site', 'redirects', array( 'params' => array( 'sn_edit' => $source ) ) ); }
function union( array $a, array $b ) { $u = array_values( array_unique( array_merge( $a, $b ) ) ); sort( $u ); return $u; }
/** The rules table: the first os-table the leaf paints. */
function rules_table( $html ) { $t = snt_leaf_tables( $html ); return $t[0] ?? array( 'columns' => array(), 'data' => array(), 'slots' => array() ); }
/** No inline style, except the tile row's item count (snt_kit_grid() paints it; the kit owns that). */
/** Every `<os-form` inside a table slot cell: a write belongs in the ONE region below the table, never in a row. */
function slot_forms( $html ) { $n = 0; foreach ( snt_leaf_tables( $html ) as $t ) { foreach ( $t['slots'] as $slot ) { $n += substr_count( $slot, '<os-form' ); } } return $n; }
function styled( $html ) { return false !== strpos( (string) preg_replace( '/<os-grid [^>]*>/', '', $html ), ' style="' ); }
$t0 = 1725000000; $t1 = $t0 + DAY_IN_SECONDS; $d0 = gmdate( 'Y-m-d', $t0 ); $d1 = gmdate( 'Y-m-d', $t1 );
// v13.109.8: the three redirect_404_* actions moved to the broken-links leaf.
$all_actions = array( 'redirect_add', 'redirect_delete', 'redirect_update' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/redirects'] ), 'the painter is registered under site/redirects' );

// ── Rich state: two rules (/first a 301 on site, /second a 302 off site).
fixture(
	array(
		'/first'  => array( 'to' => '/notes/design-tokens', 'status' => 301, 'created_at' => $t0 ),
		'/second' => array( 'to' => 'https://example.com/moved', 'status' => 302, 'created_at' => $t1 ),
	),
	array( '/notes/desing-tokens' => entry( 7, $t0 ) )
);
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
$kit_ed  = editing( '/first' );
ok( '' !== $kit && '' !== $kit_ed, 'the kit leaf paints, default and editing' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ), 'no fold in the default or the editing paint: ' . implode( ',', array_merge( snt_leaf_folds( $kit ), snt_leaf_folds( $kit_ed ) ) ) );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_names( $classic ) === snt_leaf_names( $kit_ed ), 'field names match the classic forms in both paints: ' . implode( ',', snt_leaf_names( $kit ) ) . ' / ' . implode( ',', snt_leaf_names( $kit_ed ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( $all_actions === union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) && snt_leaf_actions( $classic ) === union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ), 'the three actions match the classic leaf across both paints: ' . implode( ',', union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) ) );
ok( array( 'redirect_add' ) === snt_leaf_actions( $kit ), '...the default paint offers only add: ' . implode( ',', snt_leaf_actions( $kit ) ) );
// 2026-10-12 (edit in place): the add form stays while a rule is open, so editing offers all three.
ok( array( 'redirect_add', 'redirect_delete', 'redirect_update' ) === snt_leaf_actions( $kit_ed ), '...the editing paint offers update and delete, and add still: ' . implode( ',', snt_leaf_actions( $kit_ed ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ) && ! styled( $kit_ed ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<p class="snt-prose">' ) && false !== strpos( $kit, '<os-code>/new-page</os-code>' ) && false !== strpos( $kit, '<strong>Site → Broken links</strong>' ) && false === strpos( $kit, 'sidebar' ), 'the intro survives as prose with inline code, and names where the 404 log lives now' );

// The reading: tiles, then one finding sentence built from the data.
ok( false !== strpos( $kit, '<os-stat value="2" label="Rules"' ) && false !== strpos( $kit, '<os-stat value="1" label="Permanent" caption="301"' ) && false !== strpos( $kit, '<os-stat value="1" label="Temporary" caption="302"' ), 'the tiles read 2 rules: 1 permanent, 1 temporary' );
ok( false !== strpos( $kit, '<p class="snt-prose">1 of the 2 rules sends readers to another site; the rest stay on this one.</p>' ), 'the finding counts the rules that leave the site' );
ok( false !== strpos( $kit, 'heading="Redirect rules"' ) && before( $kit, '<os-stat', '<os-table' ), 'reading before ledger, in one section' );

// The ledger: one table, newest first, every figure the fold used to hold.
$table = rules_table( $kit );
$cols  = array_column( $table['columns'], 'label' );
ok( array( 'From', 'To', 'Type', 'Added', 'Actions' ) === $cols, 'the rules table has labelled columns: ' . implode( ',', $cols ) );
ok( 2 === count( $table['data'] ) && '/second' === ( $table['data'][0]['from'] ?? '' ) && '/first' === ( $table['data'][1]['from'] ?? '' ), 'one row per rule, newest first (/second before /first)' );
$row = $table['data'][0] ?? array();
ok( 'https://example.com/moved' === ( $row['to'] ?? '' ) && '302 temporary' === ( $row['type'] ?? '' ) && $d1 === ( $row['added'] ?? '' ), 'a row carries the target, the type and the added date: ' . wp_json_encode( $row ) );
ok( '/notes/design-tokens' === ( $table['data'][1]['to'] ?? '' ) && '301 permanent' === ( $table['data'][1]['type'] ?? '' ) && $d0 === ( $table['data'][1]['added'] ?? '' ), '...for every rule' );
$cell = snt_leaf_cell_html( $table, $row['actions'] ?? null );
ok( '<span id="snt-row-' . substr( md5( '/second' ), 0, 12 ) . '" data-snt-focus><os-button variant="ghost" os-action="go" aria-label="Edit /second" os-arg-sub="redirects" os-arg-sn_edit="/second" os-arg-anchor="snt-edit-region">Edit</os-button></span>' === $cell, 'a row carries ONE visible action, Edit, a go button naming its rule, in its row anchor: ' . substr( $cell, 0, 200 ) );
ok( 0 === slot_forms( $kit ) && 0 === slot_forms( $kit_ed ), 'no table cell holds a form, default or editing: ' . slot_forms( $kit ) . ' / ' . slot_forms( $kit_ed ) );
ok( false === strpos( $kit, 'name="source" value="/second"' ) && false === strpos( $kit, 'name="source" value="/first"' ), 'the default paint binds no rule to any form: no stack of forms' );
ok( false === strpos( $kit, 'submit-label="Save changes"' ) && false === strpos( $kit, 'snt-ledger-more' ), '...no edit form and no "+N more" under the cap' );
ok( false !== strpos( $kit, 'placeholder="/old-page"' ) && false !== strpos( $kit, 'hint="The path to match, e.g. /old-page. Trailing slash and query string are ignored."' ) && false !== strpos( $kit, 'placeholder="/new-page  or  https://example.com/page"' ) && false !== strpos( $kit, 'submit-label="Add redirect"' ) && false !== strpos( $kit, 'heading="Add a redirect"' ) && before( $kit, '<os-table', 'heading="Add a redirect"' ), 'the add form sits open below the table, with both placeholders, the hint and the Add redirect submit' );

// The editing paint: ONE edit region below the table, for the rule sn_edit names.
ok( 1 === substr_count( $kit_ed, 'submit-label="Save changes"' ) && false !== strpos( $kit_ed, 'heading="Editing /first"' ) && before( $kit_ed, '<os-table', 'heading="Editing /first"' ), 'editing paints ONE edit region, below the table, headed by the rule' );
ok( false !== strpos( $kit_ed, 'description="Now a 301 to /notes/design-tokens; added ' . $d0 . '."' ), '...its description states the rule as it stands' );
$edit = form_block( $kit_ed, 'Save changes' );
ok( false !== strpos( $edit, '<input type="hidden" name="source" value="/first">' ) && false !== strpos( $edit, 'name="action" value="sn_redirect_update"' ) && false !== strpos( $edit, '<os-text-field name="target" type="text" value="/notes/design-tokens"' ) && false !== strpos( $edit, '<os-field-row label="Redirects to">' ), 'the edit form posts redirect_update with the source hidden and the current target filled' );
ok( false !== strpos( $edit, '<os-select name="status" value="301"><os-option value="301">301 permanent</os-option><os-option value="302">302 temporary</os-option></os-select>' ), 'the type select offers 301/302 with the current one selected' );
ok( false !== strpos( $kit_ed, '<os-button variant="ghost" os-action="go" os-arg-sub="redirects" os-arg-anchor="snt-row-' . substr( md5( '/first' ), 0, 12 ) . '">Cancel</os-button>' ), 'Cancel is a go that clears the param and lands back on the row\'s Edit' );
$delete = form_block( $kit_ed, 'Delete' );
ok( '' !== $delete && false !== strpos( $delete, '<os-form os-confirm-title="Delete this redirect?" os-confirm-label="Delete" class="snt-form" os-action="post" submit-label="Delete"' ) && false !== strpos( $delete, 'os-confirm="This redirect will stop working immediately." os-confirm-danger>' ) && false !== strpos( $delete, 'name="action" value="sn_redirect_delete"' ) && false !== strpos( $delete, '<input type="hidden" name="source" value="/first">' ) && before( $kit_ed, 'heading="Editing /first"', 'submit-label="Delete"' ), 'Delete lives in the edit region: it confirms as the classic button did (title, label, question, danger) and carries the rule' );
ok( 1 === substr_count( $kit_ed, 'heading="Add a redirect"' ) && 2 === substr_count( $kit_ed, '<input type="hidden" name="source" value="/first">' ) && false === strpos( $kit_ed, 'name="source" value="/second"' ), 'while editing the add form stays; only /first is bound, to its update and its delete form' );
$unknown = editing( '/nope' );
ok( false === strpos( $unknown, 'submit-label="Save changes"' ) && false === strpos( $unknown, 'submit-label="Delete"' ) && false === strpos( $unknown, 'Editing' ) && false !== strpos( $unknown, 'heading="Add a redirect"' ) && array() === snt_leaf_folds( $unknown ), 'an unknown sn_edit paints no edit region and the add form instead' );

// v13.109.8: the 404 log is its own leaf now (tests/os-leaf-site-broken-links.php).
ok( false === strpos( $kit, 'redirect_404_' ) && false === strpos( $kit_ed, 'redirect_404_' ), 'the redirects leaf paints NO 404 action' );
ok( false === strpos( $kit, 'broken paths' ) && false === strpos( $kit, 'automated probes' ), '...and none of the 404 copy' );
ok( false === strpos( $kit, '<aside' ), '...and no rail at all: Pattern B is full width' );

// ── Escaping: a hostile source and target, in the table and in the edit heading.
$evil = '/x"><script>alert(1)</script>';
fixture( array( $evil => array( 'to' => '"><script>t</script>', 'status' => 301, 'created_at' => $t0 ) ), array() );
$kit    = snt_leaf_paint( 'site', 'redirects' );
$kit_ed = editing( $evil );
$table  = rules_table( $kit );
ok( $evil === ( $table['data'][0]['from'] ?? '' ) && '"><script>t</script>' === ( $table['data'][0]['to'] ?? '' ), 'the hostile rule REACHED the table (so the pins below are not vacuous)' );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit_ed, '<script>' ), 'hostile source and target never reach the markup raw, default or editing' );
ok( false !== strpos( $kit_ed, 'heading="Editing /x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"' ), '...the edit heading escapes it' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ) && array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ), 'no fold and no wp-admin markup (hostile)' );

// ── Empty state: nothing to redirect: the finding says so, the add form waits.
fixture( array(), array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
ok( array( 'redirect_add' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty: only redirect_add is offered, with the same names as the classic leaf' );
ok( false !== strpos( $kit, '<p class="snt-prose">No redirect rules yet; add the first one below.</p>' ) && false === strpos( $kit, '<os-table' ), 'empty: one sentence, no empty table' );
ok( false === strpos( $kit, 'No broken links' ), 'empty: the 404 status belongs to the other leaf now' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ), 'no fold and no wp-admin markup (empty)' );

// ── The rule list is capped, newest first, and says so out loud.
$many = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$many[ '/rule-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ] = array( 'to' => '/target-' . $i, 'status' => 301, 'created_at' => $t0 + $i );
}
fixture( $many, array() );
$kit     = snt_leaf_paint( 'site', 'redirects' );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$table   = rules_table( $kit );
ok( 60 === count( $many ), 'sanity: the fixture built 60 redirect rules -- ' . count( $many ) );
ok( SN_REDIRECT_LIST_CAP === count( $table['data'] ) && SN_REDIRECT_LIST_CAP === substr_count( $kit, 'os-arg-sn_edit="/rule-' ), 'exactly SN_REDIRECT_LIST_CAP (' . SN_REDIRECT_LIST_CAP . ') rules are listed of 60 -- listed ' . count( $table['data'] ) );
ok( '/rule-0060' === ( $table['data'][0]['from'] ?? '' ) && ! in_array( '/rule-0001', array_column( $table['data'], 'from' ), true ), '...NEWEST first: the most recent rules are kept, the settled tail is dropped' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+10 older rules are not listed. Showing the 50 most recent of 60 redirects.</p>' ), '...and the "+N more" line states the true total' );
ok( false !== strpos( $kit, '<os-stat value="60" label="Rules"' ), '...the tile counts every rule, not the listed ones' );
ok( false !== strpos( $classic, 'Showing the 50 most recent of 60 redirects' ), '...and the classic leaf caps identically' );
ok( in_array( 'redirect_add', snt_leaf_actions( $kit ), true ) && false !== strpos( $kit, 'Add a redirect' ), 'the add form is still reachable below a capped list' );

// ── #1228: a redirect with no created_at must not render "1970-01-01", on either side.
fixture( array( '/no-date' => array( 'to' => '/somewhere', 'status' => 301 ) ), array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
ok( false === strpos( $classic, '1970-01-01' ) && false !== strpos( $classic, 'Added date unknown' ), 'classic: a missing created_at says the date is unknown' );
ok( false === strpos( $kit . editing( '/no-date' ), '1970-01-01' ) && 'unknown' === ( rules_table( $kit )['data'][0]['added'] ?? '' ) && false !== strpos( editing( '/no-date' ), 'added date unknown.' ), 'kit: the Added cell and the edit description say unknown, never the epoch' );

// ── Live-shaped preview: 39 rules, mixed 301/302, some off site (the live count, 2026-09-10).
$live = array();
foreach ( array(
	'/about-me|/about|301', '/cv|/about/cv|301', '/resume|/about/cv|301', '/contact-me|/contact|301', '/blog|/notes|301',
	'/blog/provenance-over-detection|/notes/provenance-over-detection|301', '/blog/provenance-as-substrate|/notes/provenance-as-substrate|301',
	'/papers/provenance-over-detection|https://ssrn.com/abstract=0000001|302', '/papers/provenance-as-substrate|https://ssrn.com/abstract=0000002|302',
	'/notes/two-kinds-of-provenance-draft|/notes/two-kinds-of-provenance|301', '/provhub|/notes/provenance-without-institutions|302',
	'/projects|/work|301', '/projects/signal-and-noise|/work/signal-and-noise|301', '/work/reverbeat|https://reverbeat.example|302',
	'/uses-2023|/uses|301', '/now-2025|/now|301', '/es/sobre-mi|/es/about|301', '/es/contacto|/contact|301',
	'/tag/mir|/topics/music-information-retrieval|301', '/tag/provenance|/topics/provenance|301', '/tag/ai-music|/topics/ai-and-music|301',
	'/category/essays|/notes|301', '/feed/notes|/feed|301', '/rss|/feed|301', '/notes/design-tokens-v1|/notes/design-tokens|301',
	'/notes/weighted-identity|/notes/weighted-identity-frameworks|301', '/talks/aes-2024|/notes/aes-2024-talk|301', '/talks|/notes/talks|302',
	'/newsletter|/subscribe|302', '/links|/elsewhere|301', '/github|https://github.com/juanlentino|302',
	'/linkedin|https://www.linkedin.com/in/juanlentino|302', '/scholar|https://scholar.google.com/citations?user=example|302',
	'/verify|/provenance/verify|301', '/provenance/verify-v1|/provenance/verify|301', '/colophon-old|/colophon|301',
	'/press-kit|/about/press|301', '/notes/attribution-is-a-data-problem-2|/notes/attribution-is-a-data-problem|301', '/login|/sn-login|302',
) as $i => $spec ) {
	list( $src, $to, $code ) = explode( '|', $spec );
	$live[ $src ] = array( 'to' => $to, 'status' => (int) $code, 'created_at' => $t0 + $i * 3 * DAY_IN_SECONDS );
}
fixture( $live, array() );
$kit    = snt_leaf_paint( 'site', 'redirects' );
$kit_ed = editing( '/talks/aes-2024' );
ok( 39 === count( $live ) && 39 === count( rules_table( $kit )['data'] ) && false === strpos( $kit, 'snt-ledger-more' ), 'live-shaped: 39 rules, all listed, no "+N more" under the cap' );
ok( false !== strpos( $kit, '<os-stat value="39" label="Rules"' ) && false !== strpos( $kit, '<os-stat value="29" label="Permanent"' ) && false !== strpos( $kit, '<os-stat value="10" label="Temporary"' ) && false !== strpos( $kit, '6 of the 39 rules send readers to another site' ), 'live-shaped: the tiles and the finding read 39 = 29 + 10, 6 off site' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ) && 1 === substr_count( $kit_ed, 'submit-label="Save changes"' ) && false !== strpos( $kit_ed, 'heading="Editing /talks/aes-2024"' ) && 1 === substr_count( $kit_ed, 'submit-label="Delete"' ) && 0 === slot_forms( $kit ), 'live-shaped: fold-free, no form in any row, and editing paints ONE region (update + delete)' );
snt_leaf_preview( 'site-redirects', $kit );
snt_leaf_preview( 'site-redirects-editing', $kit_ed );
$focus_problems = snt_leaf_edit_focus_problems( $kit, $kit_ed );
ok( array() === $focus_problems, 'a11y: Edit lands the keyboard on the one region, Cancel lands it back on the list: ' . implode( '; ', $focus_problems ) );

// ── 2026-10-12 (owner: "it's weird to click in 'edit' and something else
// opens"): the editor opens UNDER its row, not below the whole table.
$ip_def   = $kit;
$ip_ed    = $kit_ed;
$ip_table = rules_table( $kit );
$ip_keys  = array_map( static function ( $v ) { return is_array( $v ) ? (string) ( $v['text'] ?? '' ) : (string) $v; }, array_column( $ip_table['data'], 'from' ) );
$ip_at    = array_search( '/talks/aes-2024', $ip_keys, true );
$ip_split = snt_leaf_split_problems( $ip_ed, 'from', '/talks/aes-2024', false === $ip_at ? null : ( $ip_keys[ $ip_at + 1 ] ?? null ) );
ok( false !== $ip_at && array() === $ip_split, 'edit in place: the editor opens under its row, the row marked selected, the table resuming with the next row after it: ' . implode( '; ', $ip_split ) );
ok( false !== strpos( $ip_ed, '>Editing</os-badge>' ), 'edit in place: the open row says Editing in words, not only by its accent bar' );
ok( 1 === preg_match( '/os-arg-anchor="(snt-row-[0-9a-f]{12})"[^>]*>Cancel/', $ip_ed, $ip_m ) && false !== strpos( $ip_def, 'id="' . $ip_m[1] . '" data-snt-focus' ), 'edit in place: Cancel lands back on the open row\'s own button' );
ok( false !== strpos( $ip_table['attrs'], 'class="snt-table-full"' ), 'edit in place: the list is not boxed in an inner scroller (the app caps os-table at 440px)' );
ok( 1 === substr_count( $ip_ed, 'submit-label="Add redirect"' ) && strpos( $ip_ed, 'submit-label="Add redirect"' ) > strrpos( $ip_ed, '</os-table>' ), 'edit in place: "Add a redirect" stays, after the whole list, while a rule is open' );
ok( 39 === array_sum( array_map( static function ( $t ) { return count( $t['data'] ); }, snt_leaf_tables( $ip_ed ) ) ) && 38 === substr_count( $ip_ed, '>Edit</os-button>' ), 'edit in place: the two halves hold the same 39 rules, nothing cut; the other 38 keep their Edit' );

// ── 2026-10-11 (code review): the host keeps a `go` param to 200 characters
// (snt_os_host_params(), substr( ..., 0, 200 )), and a path has no length cap.
// The row's Edit must still open its rule when the source is longer.
$long = '/archive/' . str_repeat( 'a-very-long-slug-', 15 );
fixture( array( $long => array( 'to' => '/notes/design-tokens', 'status' => 301, 'created_at' => $t0 ) ), array() );
$kit_long = snt_leaf_paint( 'site', 'redirects' );
preg_match( '/os-arg-sn_edit="([^"]*)"/', $kit_long, $m_long );
$sent = substr( html_entity_decode( $m_long[1] ?? '', ENT_QUOTES ), 0, 200 ); // What the host keeps.
$kit_long_ed = editing( $sent );
ok( strlen( $long ) > 200 && false !== strpos( $kit_long_ed, 'Editing ' . $long ) && false !== strpos( $kit_long_ed, 'name="source" value="' . $long . '"' ),'a ' . strlen( $long ) . '-character source still opens its edit region after the host cuts the param at 200' );

// Review on #2005: each Edit names its rule for a screen reader, its visible word first.
ok( false !== strpos( (string) ( $GLOBALS['__redir_kit'] ?? snt_leaf_paint( 'site', 'redirects' ) ), 'aria-label="Edit /' ), 'each Edit button is named "Edit /path" (label in name: the visible word first)' );

// 2026-10-11: no type label is a period before a word (an old em-dash swap read "301. Permanent").
ok( false === strpos( (string) file_get_contents( __DIR__ . '/../apps/sn-dashboard/parts/leaves/site-redirects-parts.php' ) . (string) file_get_contents( __DIR__ . '/../inc/redirects-admin.php' ), '301. Permanent' ), 'the redirect types read "301 permanent", on both twins' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
