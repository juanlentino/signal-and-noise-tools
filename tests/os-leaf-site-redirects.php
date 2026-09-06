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
$all_actions = array( 'redirect_404_clear', 'redirect_404_clear_probes', 'redirect_404_delete', 'redirect_add', 'redirect_delete', 'redirect_update' );

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

// The rail: status, probes, broken paths, clear.
ok( false !== strpos( $kit, 'aria-label="Broken links (404s)"' ), 'the rail keeps its accessible name' );
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
ok( false !== strpos( $kit, 'heading="/notes/design-tokens&lt;script&gt;"' ) && false !== strpos( $kit, '<os-code>/y&quot;&gt;&lt;script&gt;z&lt;/script&gt;</os-code>' ), 'the hostile broken path is a section and the hostile probe is listed — both escaped' );
ok( false === strpos( $kit, '<script>' ) && substr_count( $kit, '&lt;script&gt;' ) >= 6, 'hostile source, target, broken path and probe path never reach the markup raw' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives (hostile): ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Empty state: nothing to redirect, nothing broken — only the add form.
fixture( array(), array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
ok( array( 'redirect_add' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty: only redirect_add is offered, with the same names as the classic leaf' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, '<b>No broken links</b>' ) && false !== strpos( $kit, '>Clean</os-badge>' ) && false === strpos( $kit, 'automated probe' ) && false === strpos( $kit, 'Show the probed paths' ), 'empty: the clean status paints, no probe bucket, no fold' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives (empty): ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Probes only: clean status, the bucket with its dismiss, no clear-log.
fixture( array(), array( '/probe-a' => entry( 4, $t0 ), '/probe-b' => entry( 1, $t0 ), '/probe-c' => entry( 9, $t0 ) ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_redirects_section' );
$kit     = snt_leaf_paint( 'site', 'redirects' );
ok( array( 'redirect_404_clear_probes', 'redirect_add' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'probes only: dismiss-probes and add are offered, clear-log is not — as on the classic leaf' );
ok( false !== strpos( $kit, '<b>No broken links</b>' ) && false !== strpos( $kit, '<b>3 automated probes</b><br>14 hits on paths' ) && before( $kit, '<os-code>/probe-c</os-code>', '<os-code>/probe-a</os-code>' ) && false === strpos( $kit, '…and' ), 'probes only: clean status beside the bucket, busiest probe first, nothing elided' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, ' style="' ), 'no wp-admin markup survives (probes only): ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
