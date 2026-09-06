<?php
/**
 * Native window leaf: Content → Now Page (apps/sn-dashboard/parts/leaves/content-now.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * names (the stored cards AND the `__G__` template card the classic clones
 * client-side) and the same sn_action, in the configured and the empty
 * state, and none of wp-admin's markup. The round trip is pinned too: the
 * names the kit paints, expanded the way the window expands a form, feed
 * `sn_now_rows_to_text()` back into the very document that was read.
 *
 * Run: php tests/os-leaf-content-now.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The classic card's helpers (sn_rsm_input / sn_rsm_controls) live in the
// Resume leaf's form file; the data layer in inc/now-page.php; the serializer
// and the window's form expansion for the round-trip pin.
require SNT_PATH . 'inc/now-page.php';
require SNT_PATH . 'inc/admin-forms/resume-page.php';
require SNT_PATH . 'inc/admin-forms/now-page.php';
require SNT_PATH . 'inc/admin-post-actions/content.php';
require SNT_PATH . 'inc/openstation-host-pipelines.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-now.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Count the section cards in a kit paint. */
function now_cards( $html ) { return substr_count( (string) $html, '<os-card' ); }

/** The values the window would post from a kit paint: every named field's painted value, entity-decoded. */
function now_posted_from( $html ) {
	preg_match_all( '/<(?:os-text-field|os-textarea|input)\b[^>]*\sname="([^"]+)"[^>]*\svalue="([^"]*)"/', (string) $html, $m, PREG_SET_ORDER );
	$values = array();
	foreach ( $m as $hit ) {
		$values[ html_entity_decode( $hit[1], ENT_QUOTES, 'UTF-8' ) ] = html_entity_decode( $hit[2], ENT_QUOTES, 'UTF-8' );
	}
	return $values;
}

/** The handler's own normalisation of posted now[groups] (sn_handle_now_save, inc/admin-post-actions/content.php). */
function now_groups_from( array $posted ) {
	$groups = array();
	foreach ( (array) ( $posted['now']['groups'] ?? array() ) as $k => $g ) {
		$g            = is_array( $g ) ? $g : array();
		$g['items']   = sn_content_items_normalize( $g['items'] ?? array() );
		$groups[ $k ] = $g;
	}
	return $groups;
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/now'] ), 'the painter is registered under content/now' );

// ── Configured state: two stored sections.
$raw = "## Building\n- Thing one\n- Thing two\n\n## Reading\n- A book about signals";
$GLOBALS['__options'][ SN_NOW_PAGE_OPTION ] = array( 'raw' => $raw, 'updated' => '2026-09-01' );
$classic = snt_leaf_classic_html( 'sn_admin_render_now_section' );
$kit     = snt_leaf_paint( 'content', 'now' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( in_array( 'now[groups][__G__][label]', snt_leaf_names( $kit ), true ) && in_array( 'now[groups][1][items]', snt_leaf_names( $kit ), true ), 'the names cover both stored cards and the template card' );
ok( array( 'now_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is now_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the form is an os-form dispatching post through the shared sn_action table (no pipeline override)' );
ok( false !== strpos( $kit, 'submit-label="Save now page"' ), 'the submit button reads "Save now page"' );
ok( false !== strpos( $kit, '<os-section heading="Now page"' ), 'the "Now page" heading is the section heading' );
ok( false !== strpos( $kit, 'editor for the live' ) && false !== strpos( $kit, 'href="https://example.test/now"' ) && false !== strpos( $kit, 'target="_blank"' ), 'the configured intro links the live /now page in a new tab' );
ok( false !== strpos( $kit, 'Last saved: <os-code>2026-09-01</os-code>' ), 'the last-saved stamp is inline kit code' );
ok( false !== strpos( $kit, 'never silently blanked' ) && false !== strpos( $kit, 'Incomplete cards are refused at save' ), 'the helper text survives as a hint' );
ok( false !== strpos( $kit, '<os-text-field name="now[groups][0][label]" type="text" value="Building" placeholder="Building"' ), 'card 0: the label is a kit text field carrying "Building"' );
ok( false !== strpos( $kit, '<os-textarea name="now[groups][0][items]" value="Thing one' . "\n" . 'Thing two" rows="5"' ), 'card 0: the items are a 5-row kit textarea, one item per line' );
ok( false !== strpos( $kit, 'value="Reading"' ) && false !== strpos( $kit, '<os-textarea name="now[groups][1][items]" value="A book about signals" rows="5"' ), 'card 1: label and items are painted' );
ok( false !== strpos( $kit, '<os-field-row label="Section label"' ) && false !== strpos( $kit, '<os-field-row label="Items — one per line"' ), 'both field labels read as the classic ones' );
ok( false !== strpos( $kit, 'placeholder="One line about what you are doing' . "\n" . 'Another line"' ), 'the two-line items placeholder survives' );
ok( 3 === now_cards( $kit ), 'two stored cards plus the one new card: ' . now_cards( $kit ) );
ok( false !== strpos( $kit, '<os-text-field name="now[groups][__G__][label]" type="text" value="" placeholder="Building"' ) && false !== strpos( $kit, '<os-textarea name="now[groups][__G__][items]" value="" rows="5"' ), 'the new card is empty under the template names' );
ok( false !== strpos( $kit, 'hint="New section: fill in a label' ), 'the new card explains itself where the "+ Add section" button was' );
ok( false !== strpos( $kit, 'To remove a section, clear its label and its items' ), 'the row controls\' replacement is explained' );
ok( false === strpos( $kit, 'sn-rsm-' ) && false === strpos( $kit, '<template' ) && false === strpos( $kit, 'data-rsm' ), 'no repeatable-row script hooks survive' );

// ── Round trip: the PAINTED fields (name + value, as the window would post
// them), expanded as the window expands a form, serialize back to the stored
// document; the blank new card is pruned.
$posted = snt_os_host_expand( now_posted_from( $kit ) );
ok( 'now_save' === ( $posted['sn_action'] ?? '' ) && isset( $posted['_wpnonce'] ), 'the painted form posts sn_action=now_save and the nonce' );
ok( isset( $posted['now']['groups']['0']['label'], $posted['now']['groups']['__G__']['items'] ) && 3 === count( $posted['now']['groups'] ), 'the kit names expand to the now[groups] shape the handler reads, string key included' );
ok( $raw === sn_now_rows_to_text( now_groups_from( $posted ) ), 'saving the painted cards unchanged reproduces the stored document (the blank new card is pruned)' );

// ── Escaping: a hostile label and a hostile stamp never reach the markup raw.
$GLOBALS['__options'][ SN_NOW_PAGE_OPTION ] = array( 'raw' => "## \"><script>x</script>\n- <img src=x onerror=1>", 'updated' => '<script>y</script>' );
$kit = snt_leaf_paint( 'content', 'now' );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, '<img' ) && false !== strpos( $kit, 'value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"' ) && false !== strpos( $kit, '<os-code>&lt;script&gt;y&lt;/script&gt;</os-code>' ), 'a hostile label, item and stamp are escaped' );
ok( array() === snt_leaf_classic_markers( $kit ), 'the hostile fixture carries no script tag' );

// ── Empty state: nothing stored.
unset( $GLOBALS['__options'][ SN_NOW_PAGE_OPTION ] );
$classic = snt_leaf_classic_html( 'sn_admin_render_now_section' );
$kit     = snt_leaf_paint( 'content', 'now' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( array( 'now_save' ) === snt_leaf_actions( $kit ), 'empty: the save is still offered' );
ok( false !== strpos( $kit, 'Add sections below and save to publish it' ) && false === strpos( $kit, 'Last saved' ) && false === strpos( $kit, 'editor for the live' ), 'empty: the intro is the unpublished one, with no stamp' );
ok( 1 === now_cards( $kit ) && false !== strpos( $kit, 'name="now[groups][__G__][label]"' ), 'empty: only the new card is painted' );
ok( '' === sn_now_rows_to_text( now_groups_from( snt_os_host_expand( now_posted_from( $kit ) ) ) ), 'empty: saving the untouched form serializes to nothing (the clear path), never a refusal' );

// ── Stored but unparseable (no header line): the classic falls to the empty intro.
$GLOBALS['__options'][ SN_NOW_PAGE_OPTION ] = array( 'raw' => 'just a line, no header', 'updated' => '2026-09-02' );
$classic = snt_leaf_classic_html( 'sn_admin_render_now_section' );
$kit     = snt_leaf_paint( 'content', 'now' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 1 === now_cards( $kit ) && false === strpos( $kit, '2026-09-02' ), 'unparseable: paints as empty, as the classic does' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
