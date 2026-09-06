<?php
/**
 * Native window leaf: Content → Uses Page (apps/sn-dashboard/parts/leaves/content-uses.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * names (one label + one items field per group, plus the template's spare
 * row) and the same sn_action, in the live, the prefilled and the empty
 * state, print every readout the classic prints, escape a hostile document,
 * and carry none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-content-uses.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The theme's live file groups — the prefill source before the first save.
$GLOBALS['__theme_groups'] = array(
	array( 'label' => 'Interface', 'items' => array( array( 'name' => 'SSL UF8', 'note' => 'Advanced DAW controller' ) ) ),
);
if ( ! function_exists( 'sn_uses_groups' ) ) { function sn_uses_groups() { return $GLOBALS['__theme_groups']; } }

require SNT_PATH . 'inc/now-page.php';            // sn_now_parse_sections(), the shared grammar.
require SNT_PATH . 'inc/uses-page.php';           // sn_uses_page_get(), sn_uses_parse_groups().
require SNT_PATH . 'inc/admin-forms/resume-page.php'; // sn_rsm_input(), sn_rsm_controls().
require SNT_PATH . 'inc/admin-forms/uses-page.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-uses.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function uses_stored( $raw, $updated = '2026-07-10' ) { $GLOBALS['__options']['sn_uses_page'] = array( 'raw' => $raw, 'updated' => $updated ); }
function uses_unstored() { unset( $GLOBALS['__options']['sn_uses_page'] ); }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/uses'] ), 'the painter is registered under content/uses' );

// ── Live state: a stored override with two groups.
uses_stored( "## Interface\n- SSL UF8 | Advanced DAW controller\n\n## Audio\n- Neumann U87 | Vocal mic\n- Cables\n" );
$classic = snt_leaf_classic_html( 'sn_admin_render_uses_section' );
$kit     = snt_leaf_paint( 'content', 'uses' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'uses_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is uses_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the form is an os-form dispatching post through the shared action table (no pipeline declared, as the classic posts to the current URL)' );
ok( false !== strpos( $kit, 'submit-label="Save uses page"' ), 'the submit is "Save uses page"' );
ok( false !== strpos( $kit, 'heading="Uses page"' ), 'the "Uses page" heading survives as the section heading' );
ok( false !== strpos( $kit, 'href="https://example.test/about/uses"' ) && false !== strpos( $kit, '>/about/uses</a>' ), 'the /about/uses link is painted' );
ok( false !== strpos( $kit, 'the live ' ) && false !== strpos( $kit, 'Last saved: <os-code>2026-07-10</os-code>.' ), 'live: the intro names the live page and the save stamp as kit code' );
ok( false !== strpos( $kit, 'name="uses[groups][0][label]" type="text" value="Interface"' ) && false !== strpos( $kit, 'name="uses[groups][1][label]" type="text" value="Audio"' ), 'both group labels are prefilled, indexed' );
ok( false !== strpos( $kit, 'name="uses[groups][0][items]" value="SSL UF8 | Advanced DAW controller"' ), 'the first group\'s items collapse to one "name | note" line' );
ok( false !== strpos( $kit, "name=\"uses[groups][1][items]\" value=\"Neumann U87 | Vocal mic\nCables\"" ), 'the second group\'s items are one line each, a note-less item without a pipe' );
ok( 3 === substr_count( $kit, '<os-card compact>' ) && 3 === substr_count( $classic, 'data-rsm-row' ) && 1 === substr_count( $classic, '<template' ), 'one card per group plus the spare: three, as the classic paints two rows plus the template\'s row' );
ok( strpos( $kit, 'uses[groups][1][items]' ) < strpos( $kit, 'uses[groups][__U__][label]' ) && 1 === substr_count( $kit, 'uses[groups][__U__][label]' ), 'the spare card is painted once, after the groups, under the template\'s own index' );
ok( false !== strpos( $kit, 'name="uses[groups][__U__][label]" type="text" value=""' ) && false !== strpos( $kit, 'name="uses[groups][__U__][items]" value=""' ), 'the spare card is blank' );
ok( false !== strpos( $kit, 'label="Group label"' ) && false !== strpos( $kit, 'placeholder="Interface"' ), 'the label field keeps its label and placeholder' );
ok( false !== strpos( $kit, 'label="Items — one per line, name | note"' ) && false !== strpos( $kit, 'rows="5"' ) && false !== strpos( $kit, "placeholder=\"SSL UF8 | Advanced DAW controller\nAnother thing\"" ), 'the items textarea keeps its label, rows and placeholder' );
ok( false !== strpos( $kit, 'hint="The note after | is optional. A note with no name is refused at save rather than filed under a blank entry."' ), 'the per-card helper survives as the field hint' );
ok( false !== strpos( $kit, 'Each card is one gear group' ) && false !== strpos( $kit, 'never silently blanked' ), 'the form-level helper survives as a hint' );
ok( false !== strpos( $kit, 'name="sn_action" value="uses_save"' ) && false !== strpos( $kit, 'name="_wpnonce"' ), 'the action and the nonce ride as hidden fields' );
ok( false === strpos( $kit, '__I__' ) && false === strpos( $kit, 'data-rsm' ), 'no JS-template plumbing leaks into the kit markup' );

// ── Escaping: a hostile stored document never reaches the markup raw.
uses_stored( "## \"Quo<b>ted\"\n- x \"quotes\" & <tags> | <i>note</i>\n" );
$kit = snt_leaf_paint( 'content', 'uses' );
ok( false === strpos( $kit, 'Quo<b>ted' ) && false !== strpos( $kit, 'Quo&lt;b&gt;ted' ), 'a hostile group label is escaped' );
ok( false === strpos( $kit, '<i>note' ) && false !== strpos( $kit, '&lt;tags&gt; | &lt;i&gt;note&lt;/i&gt;' ), 'a hostile item name and note are escaped' );

// ── Prefilled state: nothing stored, the theme's file groups fill the form.
uses_unstored();
$classic = snt_leaf_classic_html( 'sn_admin_render_uses_section' );
$kit     = snt_leaf_paint( 'content', 'uses' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'prefilled: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( false !== strpos( $kit, 'prefilled from the current live list' ) && false === strpos( $kit, 'Last saved' ), 'prefilled: the intro says so and shows no save stamp' );
ok( false !== strpos( $kit, 'name="uses[groups][0][label]" type="text" value="Interface"' ) && false !== strpos( $kit, 'value="SSL UF8 | Advanced DAW controller"' ), 'prefilled: the theme\'s group is in the form' );

// ── Stored but unparseable: the classic falls back to the theme's groups too.
uses_stored( "no headers here\n- orphan item\n" );
$classic = snt_leaf_classic_html( 'sn_admin_render_uses_section' );
$kit     = snt_leaf_paint( 'content', 'uses' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && false !== strpos( $kit, 'prefilled from the current live list' ) && false !== strpos( $kit, 'value="Interface"' ), 'unparseable store: prefilled from the theme, like the classic' );

// ── Empty state: nothing stored and no theme groups — only the spare card.
uses_unstored();
$GLOBALS['__theme_groups'] = array();
$classic = snt_leaf_classic_html( 'sn_admin_render_uses_section' );
$kit     = snt_leaf_paint( 'content', 'uses' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( 1 === substr_count( $kit, '<os-card compact>' ) && false === strpos( $kit, 'uses[groups][0]' ), 'empty: only the spare card is painted' );
ok( array( 'uses_save' ) === snt_leaf_actions( $kit ), 'empty: the save is still offered (zero rows clears the override)' );

// ── Reorder instruction: the hint names the only owner-side way to reorder groups.
uses_stored( "## Interface\n- SSL UF8 | Advanced DAW controller\n\n## Audio\n- Neumann U87 | Vocal mic\n- Cables\n" );
$kit = snt_leaf_paint( 'content', 'uses' );
ok( false !== strpos( $kit, 'To reorder groups, move the text between cards.' ), 'the hint tells the owner how to reorder groups' );

// ── A non-array group entry from a malformed theme reader is still painted, like the classic.
$GLOBALS['__theme_groups'] = array( 'oops', array( 'label' => 'Audio', 'items' => array( array( 'name' => 'U87', 'note' => '' ) ) ) );
uses_unstored();
$classic = snt_leaf_classic_html( 'sn_admin_render_uses_section' );
$kit     = snt_leaf_paint( 'content', 'uses' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'malformed theme groups: field names match the classic form even with a non-array entry: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
