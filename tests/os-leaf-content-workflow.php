<?php
/**
 * Native window leaf: Content > Workflow (apps/sn-dashboard/parts/leaves/content-workflow.php).
 *
 * The oracle is the classic form (inc/admin-forms/workflow-page.php): the kit
 * form must post the same field names, including the spare rows under the
 * classic templates' tokens, and the same action, both empty and with a
 * stored document. The "Show on page" box is checked only for a row stored as
 * shown, and never on the spare row.
 *
 * 2026-10-11 (owner rule: no collapsible sections). Map, Rules and Proof are
 * ONE table with an Edit button per row; `sn_edit=<list>` paints that list's
 * repeater as the one edit region below it. The page fields stay open. The
 * lists not being edited ride the same workflow_save form as hidden inputs,
 * because sn_workflow_normalize() rebuilds the WHOLE document from the post:
 * the round trip below runs every paint's post through the real normalizer.
 *
 * Run: php tests/os-leaf-content-workflow.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }

require SNT_PATH . 'inc/workflow-page.php';
require SNT_PATH . 'inc/admin-forms/resume-page.php'; // sn_rsm_input(), sn_rsm_controls().
require SNT_PATH . 'inc/admin-forms/workflow-page.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-workflow.php';
require_once SNT_PATH . 'inc/openstation-host-pipelines.php'; // snt_os_host_expand(): the host's own expansion of a posted bag.

// The Page at /workflow (2026-10-12 audit: the leaf counted rows "on the page"
// whether or not the page was live). Published by default; the suite flips it.
$GLOBALS['__wf_page'] = (object) array( 'ID' => 12, 'post_status' => 'publish', 'post_modified_gmt' => '2026-10-11 18:30:00' );
function get_page_by_path( $path, $output = 'OBJECT', $post_type = 'page' ) { return 'workflow' === $path ? $GLOBALS['__wf_page'] : null; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/workflow'] ), 'the painter is registered under content/workflow' );

$keys  = array( 'map', 'rules', 'proof' );
$paint = static function ( $key = '' ) {
	return snt_leaf_paint( 'content', 'workflow', '' === $key ? array() : array( 'params' => array( 'sn_edit' => $key ) ) );
};
// The default paint plus one per list being edited, keyed '' / map / rules / proof.
$states = static function () use ( $keys, $paint ) {
	$out = array( '' => $paint() );
	foreach ( $keys as $k ) {
		$out[ $k ] = $paint( $k );
	}
	return $out;
};
// Template content is not in the document: drop every <template>, innermost first.
$strip_tpl = static function ( $html ) {
	do {
		$html = preg_replace( '#<template\b(?:(?!<template\b).)*?</template>#s', '', (string) $html, -1, $n );
	} while ( $n > 0 );
	return $html;
};
// What os-form posts (os-form.ts getValues(): every [name] in the light DOM,
// a checkbox as its boolean), expanded as the host expands it (true '1',
// false ''). Independent of the leaf's own carrier code on purpose.
$posted = static function ( $html ) use ( $strip_tpl ) {
	preg_match_all( '/<(os-[a-z-]+|input)\b[^>]*>/', $strip_tpl( $html ), $tags, PREG_SET_ORDER );
	$values = array();
	foreach ( $tags as $tag ) {
		if ( ! preg_match( '/\sname="([^"]*)"/', $tag[0], $n ) ) {
			continue;
		}
		$name = html_entity_decode( $n[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( in_array( $tag[1], array( 'os-checkbox-label', 'os-checkbox', 'os-switch' ), true ) ) {
			$values[ $name ] = 1 === preg_match( '/\schecked\b/', preg_replace( '/="[^"]*"/', '', $tag[0] ) );
			continue;
		}
		$values[ $name ] = preg_match( '/\svalue="([^"]*)"/', $tag[0], $v ) ? html_entity_decode( $v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}
	return snt_os_host_expand( $values );
};
// Checked boxes: tags whose own attributes (values stripped) say checked; a
// hint that SAYS "checked" is not a box.
$checked = static function ( $html ) {
	preg_match_all( '/<(?:os-checkbox-label|os-checkbox|input)\b[^>]*>/', (string) $html, $tags );
	return count( preg_grep( '/\schecked\b/', preg_replace( '/="[^"]*"/', '', $tags[0] ) ) );
};
// The os-forms of a paint, in order (none nests).
$forms = static function ( $html ) {
	preg_match_all( '#<os-form\b.*?</os-form>#s', (string) $html, $m );
	return $m[0];
};
$folds_in = static function ( array $all ) {
	$found = array();
	foreach ( $all as $state => $html ) {
		if ( array() !== snt_leaf_folds( $html ) ) {
			$found[] = ( '' === $state ? 'default' : $state ) . ':' . implode( ',', snt_leaf_folds( $html ) );
		}
	}
	return $found;
};

// ── Empty: nothing stored.
unset( $GLOBALS['__options']['sn_workflow_page'] );
$classic = snt_leaf_classic_html( 'sn_admin_render_workflow_section' );
$all     = $states();
$kit     = $all[''];
$kit_all = implode( '', $all );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === $folds_in( $all ), 'empty: no fold on the default paint or any editing paint: ' . implode( ' ', $folds_in( $all ) ) );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit_all ), 'empty: field names across the default and the three editing paints match the classic form: ' . implode( ',', snt_leaf_names( $kit_all ) ) );
$bad = array();
foreach ( $all as $state => $html ) {
	if ( snt_leaf_names( $strip_tpl( $html ) ) !== snt_leaf_names( $strip_tpl( $classic ) ) || array( 'workflow_save' ) !== snt_leaf_actions( $html ) ) {
		$bad[] = '' === $state ? 'default' : $state;
	}
}
ok( array() === $bad && array( 'workflow_save' ) === snt_leaf_actions( $classic ), 'empty: every paint posts every live classic name through the one action, workflow_save, as on the classic form: ' . implode( ',', $bad ) );
ok( array() === snt_leaf_classic_markers( $kit_all ), 'no wp-admin markup in the kit leaf: ' . implode( ',', snt_leaf_classic_markers( $kit_all ) ) );
ok( 0 === $checked( $kit_all ) && 0 === $checked( $classic ), 'empty: no "Show on page" box is checked, on either form' );
$t = snt_leaf_tables( $kit )[0] ?? array( 'columns' => array(), 'data' => array(), 'slots' => array() );
ok( array( 'Map steps', 'Rules', 'Proof links' ) === array_column( $t['data'], 'list' ) && array( '0', '0', '0' ) === array_column( $t['data'], 'rows' ), 'empty: the ledger lists the three lists, 0 rows each (a stored count, measured)' );
ok( false !== strpos( $kit, '<p class="snt-prose">No map steps, rules or proof links yet.</p>' ), 'empty: the finding says the lists are empty' );

// ── Stored: two map rows (one shown), one rule, a body with markup and whitespace.
$GLOBALS['__options']['sn_workflow_page'] = array(
	'title'  => 'Workflow',
	'dek'    => 'How it works.',
	'sample' => array( 'label' => 'Sample', 'title' => 'A prompt', 'intro' => 'Intro', 'body' => "  <b>x</b>\n\tindent\n", 'outcome' => '' ),
	'map'    => array(
		array( 'title' => 'Shown', 'line' => 'on the page', 'show' => true ),
		array( 'title' => 'Private', 'line' => 'admin only', 'show' => false ),
	),
	'rules'  => array( array( 'rule' => 'Read first', 'explanation' => 'Then write.' ) ),
);
$classic = snt_leaf_classic_html( 'sn_admin_render_workflow_section' );
$all     = $states();
$kit     = $all[''];
$kit_all = implode( '', $all );
$names   = snt_leaf_names( $kit_all );
ok( array() === $folds_in( $all ), 'stored: no fold on any paint: ' . implode( ' ', $folds_in( $all ) ) );
ok( snt_leaf_names( $classic ) === $names, 'stored: field names across the four paints match the classic form: ' . implode( ',', $names ) );
// The classic form names map row 1's unchecked box, which a browser never
// posts; a carrier mirrors the browser and leaves it out. So every paint
// carries the live classic names exactly, less that one name wherever the
// map is not the list being edited.
$classic_live = snt_leaf_names( $strip_tpl( $classic ) );
$bad          = array();
foreach ( $all as $state => $html ) {
	$want = 'map' === $state ? $classic_live : array_values( array_diff( $classic_live, array( 'workflow[map][1][show]' ) ) );
	if ( snt_leaf_names( $strip_tpl( $html ) ) !== $want ) {
		$bad[] = '' === $state ? 'default' : $state;
	}
}
ok( array() === $bad, 'stored: every paint carries every live classic name, the unchecked box of a list not being edited excepted: ' . implode( ',', $bad ) );
ok( in_array( 'workflow[map][1][show]', $names, true ) && in_array( 'workflow[map][__M__][show]', $names, true ) && in_array( 'workflow[rules][__R__][explanation]', $names, true ), 'each row and each spare carries its fields' );
ok( 1 === $checked( $all['map'] ) && 1 === $checked( $classic ), 'editing the map: only the shown row is checked, on both forms' );
ok( 1 === preg_match( '/name="workflow\[map\]\[0\]\[show\]"[^>]*checked/', $all['map'] ) && 1 === preg_match( '/name="workflow\[map\]\[0\]\[show\]"[^>]*checked/', $classic ), '...and it is row 0' );
ok( false !== strpos( $kit, '<input type="hidden" name="workflow[map][0][show]" value="1">' ) && false === strpos( $kit, 'name="workflow[map][1][show]"' ) && false === strpos( $all['rules'], 'name="workflow[map][1][show]"' ), 'a list not being edited carries a shown row as show=1 and a hidden row as NO show field, the way a browser posts an unchecked box' );
ok( false !== strpos( $kit, 'value="  &lt;b&gt;x&lt;/b&gt;' ) && false !== strpos( $classic, "&lt;b&gt;x&lt;/b&gt;\n\tindent\n</textarea>" ), 'the body is escaped and keeps its whitespace in both editors' );
ok( 1 === preg_match( '#class="large-text code"[^>]*>\n  &lt;b&gt;#', $classic ), 'the classic body textarea is monospace and protects a leading newline' );
ok( false !== strpos( $classic, '>Sample body</span>' ) && false !== strpos( $classic, '>Page title</span>' ) && false !== strpos( $classic, '>Map steps</h3>' ) && false !== strpos( $classic, '>Proof links</h3>' ) && false !== strpos( $classic, '+ Add map step' ) && false !== strpos( $classic, '>Map step</span>' ) && false !== strpos( $classic, 'Each heading shows only over rows that are on the page.' ) && false !== strpos( $classic, 'Shown exactly as typed, whitespace included, in a monospace block.' ) && false === strpos( $classic, 'ticked' ), 'twins: the classic names the fields and lists in the window\'s words, with both of its hints, and says "checked"' );
preg_match_all( '/<os-repeater [^>]*>/', $kit_all, $reps );
ok( 3 === count( $reps[0] ) && 3 === count( preg_grep( '/ reorderable /', $reps[0] ) ) && 3 === count( preg_grep( '/ os-prop-keys="/', $reps[0] ) ), 'Map, Rules and Proof are each a reorderable <os-repeater> with its keys, as on Resume (one per editing paint)' );
ok( false === strpos( $kit, '<os-repeater' ) && 1 === substr_count( $all['map'], '<os-repeater' ) && 1 === substr_count( $all['rules'], '<os-repeater' ) && 1 === substr_count( $all['proof'], '<os-repeater' ), 'the default paint holds no repeater; an editing paint holds exactly its own list' );
ok( 1 === preg_match( '#<os-repeater [^>]*os-key="workflow\[map\]"[^>]*>.*?<template data-rsm-tpl data-rsm-token="__M__">.*?name="workflow\[map\]\[__M__\]\[title\]".*?</template></os-repeater>#s', $all['map'] ) && 1 === preg_match( '#<template data-rsm-tpl data-rsm-token="__R__">.*?name="workflow\[rules\]\[__R__\]\[rule\]"#s', $all['rules'] ), 'each repeater carries the classic row template under its token, which resume-admin.js clones on Add' );
ok( false !== strpos( $all['map'], 'slot="row-0"' ) && false !== strpos( $all['map'], 'slot="row-1"' ) && false !== strpos( $all['map'], 'add-label="+ Add map step"' ) && false !== strpos( $all['rules'], 'add-label="+ Add rule"' ), 'stored rows are slotted by index and each list has its Add button' );
ok( 1 === preg_match( '#<template data-rsm-tpl data-rsm-token="__P__">.*?name="workflow\[proof\]\[__P__\]\[url\]"#s', $all['proof'] ) && false !== strpos( $all['proof'], 'add-label="+ Add proof link"' ), 'the Proof repeater carries its template (title, link, line, show) and its Add button' );
$leaks = array();
foreach ( $all as $state => $html ) {
	$live = $strip_tpl( $html );
	if ( false !== strpos( $live, '[__M__]' ) || false !== strpos( $live, '[__R__]' ) || false !== strpos( $live, '[__P__]' ) ) {
		$leaks[] = '' === $state ? 'default' : $state;
	}
}
ok( array() === $leaks, 'no blank row posts outside its template on any paint: an empty form adds nothing: ' . implode( ',', $leaks ) );

ok( false !== strpos( $all['proof'], 'Name where it goes' ), 'the proof title field tells the owner it is the link text and to name where it goes' );
ok( false !== strpos( $classic, 'aria-describedby="sn-hint-workflow-sample-outcome"' ) && false !== strpos( $classic, 'id="sn-hint-workflow-sample-outcome"' ), 'classic: the Outcome hint is tied to its field' );
ok( false !== strpos( $classic, 'aria-describedby="sn-hint-workflow-proof-__P__-title"' ) && false !== strpos( $classic, 'id="sn-hint-workflow-proof-__P__-title"' ), 'classic: the proof title hint is tied to its field, with the row token in the id so each cloned row keeps its own' );

// ── The ledger: one table, a finding over it, a visible Edit per row.
$t = snt_leaf_tables( $kit )[0] ?? array( 'columns' => array(), 'data' => array(), 'slots' => array() );
ok( 1 === count( snt_leaf_tables( $kit ) ) && array( 'Map steps', 'Rules', 'Proof links' ) === array_column( $t['data'], 'list' ), 'one table, one row per list' );
ok( array( '2', '1', '0' ) === array_column( $t['data'], 'rows' ) && array( '1', '1', '0' ) === array_column( $t['data'], 'shown' ), 'the fold counts (2 / 1 / 0) are the Rows cells, and Set to show reads the public view (1 of 2 map steps; every rule)' );
ok( array( 'Shown', 'Read first', '' ) === array_column( $t['data'], 'first' ), 'each row names the list\'s first entry' );
ok( count( $t['columns'] ) === count( array_filter( array_column( $t['columns'], 'label' ) ) ), 'every column carries a header label' );
$edit_ok = 3 === count( $t['data'] );
foreach ( $t['data'] as $i => $row ) {
	$edit_ok = $edit_ok && 1 === preg_match( '#<os-button[^>]* os-action="go"[^>]* os-arg-sub="workflow" os-arg-sn_edit="' . $keys[ $i ] . '"[^>]*>Edit</os-button>#', snt_leaf_cell_html( $t, $row['edit'] ?? null ) );
}
ok( $edit_ok, 'every row carries a visible Edit button: a go to this leaf with sn_edit=<list>' );
ok( false !== strpos( $kit, '<p class="snt-prose">1 of 2 map steps, 1 of 1 rule and no proof links show on the page.</p>' ), 'the finding states what reaches the page, from the data' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'three lists: never capped, so no "+N more" line' );
ok( 1 === preg_match( '#</os-table>.*<os-section heading="Map steps" description="[^"]*Show on page[^"]*"#s', $all['map'] ) && 1 === substr_count( $all['map'], '<os-section heading="Map steps"' ), 'editing the map paints ONE region below the table, named for the list, its helper line as the description' );
ok( 1 === preg_match( '#<os-section heading="Proof links" description="Links to evidence a reader can check\\. A row shows only when &quot;Show on page&quot; is checked and its link is a path on this site \\(/maturity/\\) or an https URL\\.#', $all['proof'] ) && false !== strpos( $all['rules'], '<os-section heading="Rules" description="Add, remove and reorder rows with their buttons, or Alt+Arrow keys on a row; the page shows them in this order."' ) && false !== strpos( $all['map'], 'Unchecked steps stay here and nowhere else.' ), 'the fold\'s and the intro\'s helper lines are the regions\' descriptions (the Proof link rule, the Show on page rule, the reorder keys)' );
ok( 1 === preg_match( '#<span slot="footer-leading"><os-button variant="ghost" os-action="go" os-arg-sub="workflow" os-arg-anchor="snt-row-' . substr( md5( 'map' ), 0, 12 ) . '">Cancel</os-button></span>#', $all['map'] ) && false === strpos( $kit, '>Cancel</os-button>' ), 'the region has a Cancel beside Save (a go that clears sn_edit); the default paint has none' );
ok( false !== strpos( $kit, 'name="workflow[title]"' ) && false !== strpos( $all['proof'], 'name="workflow[sample][body]" value=' ), 'the page fields stay open on every paint' );
ok( snt_leaf_paint( 'content', 'workflow', array( 'params' => array( 'sn_edit' => 'bogus' ) ) ) === $kit, 'an unknown sn_edit paints the default view' );

// ── Data safety: the post from EVERY paint rebuilds the stored document
// exactly through the real sn_workflow_normalize() (the handler's own call).
$rich = array(
	'title'         => 'Workflow',
	'dek'           => 'How a piece moves from a note to the page.',
	'map_heading'   => 'The rest of the set',
	'rules_heading' => 'Field rules',
	'proof_heading' => 'Check it yourself',
	'sample'        => array( 'label' => 'Sample', 'title' => 'One prompt, start to finish', 'intro' => "Intro line one.\nLine two.", 'body' => "  <b>x</b> & y\n\tindent\n", 'outcome' => 'It shipped.' ),
	'map'           => array(),
	'rules'         => array(),
	'proof'         => array(),
);
foreach ( array( 'Capture', 'Outline', 'Draft', 'Review', 'Publish', 'Measure' ) as $i => $step ) {
	$rich['map'][] = array( 'title' => $step, 'line' => 'What happens at ' . strtolower( $step ) . '.', 'show' => $i < 4 );
}
foreach ( array( 'Read the source first', 'One claim per sentence', 'Numbers carry their unit', 'Name the person', 'No em dashes', 'Say what was measured', 'Link the evidence', 'Cut the adjective' ) as $rule ) {
	$rich['rules'][] = array( 'rule' => $rule, 'explanation' => 'Why: ' . $rule . ".\nSecond line." );
}
$proofs = array( array( 'Maturity index', '/maturity/', true ), array( 'Provenance log', '/provenance/', true ), array( 'Status page', 'https://status.example.org/', true ), array( 'Old draft', '/drafts/old/', false ), array( 'Broken link', 'javascript:alert(1)', true ) );
foreach ( $proofs as $p ) {
	$rich['proof'][] = array( 'title' => $p[0], 'url' => $p[1], 'line' => 'It proves ' . strtolower( $p[0] ) . '.', 'show' => $p[2] );
}
$rich = sn_workflow_normalize( $rich, false );
$GLOBALS['__options']['sn_workflow_page'] = $rich;
$all  = $states();
$lost = array();
foreach ( $all as $state => $html ) {
	$back = sn_workflow_normalize( $posted( $html )['workflow'] ?? array() );
	if ( $back !== $rich ) {
		$lost[] = '' === $state ? 'default' : $state;
	}
}
ok( 6 === count( $rich['map'] ) && 8 === count( $rich['rules'] ) && 5 === count( $rich['proof'] ) && array() === $lost, 'round trip: the post from the default paint and from each editing paint normalizes to the stored document exactly (6 map steps, 8 rules, 5 proof links, every flag): ' . implode( ',', $lost ) );
// The guard can fail: the rules paint WITHOUT its hidden carriers wipes the map and the proof.
$bare_post = $posted( preg_replace( '#<input type="hidden" name="workflow\[(?:map|proof)\][^>]*>#', '', $all['rules'] ) );
$wiped     = sn_workflow_normalize( $bare_post['workflow'] ?? array() );
ok( $wiped !== $rich && array() === $wiped['map'] && array() === $wiped['proof'] && $wiped['rules'] === $rich['rules'], 'negative control: without the carriers, saving the rules would wipe the map and the proof (the round trip above discriminates)' );
$t = snt_leaf_tables( $all[''] )[0] ?? array( 'data' => array() );
ok( array( '6', '8', '5' ) === array_column( $t['data'], 'rows' ) && array( '4', '8', '3' ) === array_column( $t['data'], 'shown' ), 'Set to show counts what sn_workflow_public_data() lets through: 4 of 6 steps, all 8 rules, 3 of 5 proof links (the unchecked row and the javascript: link stay off)' );
ok( false !== strpos( $all[''], '<p class="snt-prose">4 of 6 map steps, 8 of 8 rules and 3 of 5 proof links show on the page.</p>' ), 'the finding counts the public view' );

// ── 2026-10-12 audit: two forms, each Save under its own fields. One form
// held the page fields and the list editor, so Save and Cancel sat at the
// foot of everything and an Edit repainted typed page fields away.
$f_def = $forms( $all[''] );
$f_map = $forms( $all['map'] );
ok( 1 === count( $f_def ) && false !== strpos( $f_def[0], 'name="workflow[title]"' ) && false !== strpos( $f_def[0], 'submit-label="Save workflow page"' ) && false === strpos( $f_def[0], '<os-repeater' ), 'two forms: with no list open, one form, the page fields with Save workflow page, no list editor in it' );
ok( 2 === count( $f_map ) && false === strpos( $f_map[0], '<os-repeater' ) && false !== strpos( $f_map[0], 'submit-label="Save workflow page"' ), 'two forms: editing a list, the page form keeps its own Save and holds no editor' );
ok( 2 === count( $f_map ) && 1 === substr_count( $f_map[1], '<os-repeater' ) && false !== strpos( $f_map[1], 'submit-label="Save map steps"' ) && 1 === preg_match( '#</os-repeater>.*>Cancel</os-button></span>#s', $f_map[1] ), 'two forms: the editor is its own form, Save map steps and Cancel under the list' );
ok( 1 === preg_match( '#<input type="hidden" name="workflow\[title\]" value="Workflow">#', $f_map[1] ) && false === strpos( $f_map[1], '<os-text-field name="workflow[title]"' ), 'two forms: the editor carries the page fields as hidden inputs, never as a second live field' );
ok( 1 === preg_match( '#</os-table>.*?<os-section heading="Map steps"[^>]*id="snt-edit-region".*?<os-form#s', $all['map'] ) && strpos( $all['map'], 'submit-label="Save workflow page"' ) < strpos( $all['map'], '<os-table' ), 'two forms: the page Save sits before the ledger, the editor under its row' );
$lost = array();
foreach ( $all as $state => $html ) {
	foreach ( $forms( $html ) as $i => $form ) {
		if ( sn_workflow_normalize( $posted( $form )['workflow'] ?? array() ) !== $rich ) {
			$lost[] = ( '' === $state ? 'default' : $state ) . '#' . $i;
		}
	}
}
ok( array() === $lost, 'round trip per form: each form alone posts the whole stored document (the page form carries the lists, the editor the page fields): ' . implode( ',', $lost ) );
$bare = $forms( preg_replace( '#<input type="hidden" name="workflow\[(?:title|dek|sample)[^>]*>#', '', $all['rules'] ) )[1] ?? '';
$gone = sn_workflow_normalize( $posted( $bare )['workflow'] ?? array() );
ok( '' === $gone['title'] && '' === $gone['sample']['body'] && $gone['rules'] === $rich['rules'], 'negative control: the rules editor without its page carriers would blank the title and the sample (the per-form round trip discriminates)' );

// ── 2026-10-12 audit: "On the page" only when /workflow is live.
$t = snt_leaf_tables( $all[''] )[0] ?? array( 'columns' => array() );
ok( in_array( 'Set to show', array_column( $t['columns'], 'label' ), true ) && ! in_array( 'On the page', array_column( $t['columns'], 'label' ), true ), 'the count column says Set to show: it counts flags, not what a reader sees' );
ok( false !== strpos( $all[''], '/workflow is live. Last saved October 11, 2026 at 6:30 pm.' ) && 1 === preg_match( '#editor for the <(?:a|os-button) class="snt-link[^"]*"[^>]*>/workflow<#', $all[''] ), 'live: the intro links /workflow and gives the last save in the site format' );
$GLOBALS['__wf_page'] = (object) array( 'ID' => 12, 'post_status' => 'draft', 'post_modified_gmt' => '2026-10-11 18:30:00' );
$draft   = $paint();
$classic = snt_leaf_classic_html( 'sn_admin_render_workflow_section' );
ok( false !== strpos( $draft, '<p class="snt-prose">4 of 6 map steps, 8 of 8 rules and 3 of 5 proof links are set to show; none reach a reader until /workflow is live.</p>' ) && false === strpos( $draft, 'show on the page' ), 'draft: the finding says the rows are set to show, never that they are on the page' );
ok( false !== strpos( $draft, '/workflow is offline (draft): readers cannot see it. Last saved October 11, 2026 at 6:30 pm.' ) && false !== strpos( $classic, '/workflow is offline (draft): readers cannot see it. Last saved October 11, 2026 at 6:30 pm.' ), 'draft: both surfaces say /workflow is offline and when it was saved' );
ok( false === strpos( $draft, 'class="snt-link' ) && false !== strpos( $draft, '<os-code>/workflow</os-code>' ) && false === strpos( $classic, 'href="https://example.test/workflow"' ), 'draft: /workflow is not linked on either surface (it would open a 404)' );
$GLOBALS['__wf_page'] = null;
$none    = $paint();
$classic = snt_leaf_classic_html( 'sn_admin_render_workflow_section' );
ok( false !== strpos( $none, '/workflow does not exist yet: the first save with something to show creates it.' ) && false !== strpos( $classic, '/workflow does not exist yet: the first save with something to show creates it.' ) && false !== strpos( $none, 'none reach a reader until /workflow is live.' ), 'no page: both surfaces say /workflow does not exist yet, and the finding counts flags only' );
$GLOBALS['__wf_page'] = (object) array( 'ID' => 12, 'post_status' => 'publish', 'post_modified_gmt' => '2026-10-11 18:30:00' );
$classic = snt_leaf_classic_html( 'sn_admin_render_workflow_section' );
ok( false !== strpos( $classic, '/workflow is live. Last saved October 11, 2026 at 6:30 pm.' ) && false !== strpos( $classic, 'href="https://example.test/workflow"' ), 'live: the classic says the same sentence and links /workflow' );

// ── Live-shaped preview: ~6 map steps, ~8 rules, ~5 proof links (the fixture above).
ok( array() === $folds_in( $all ) && false === strpos( $all[''], 'snt-ledger-more' ), 'live-shaped: fold-free on every paint, the three-row ledger never capped' );
snt_leaf_preview( 'content-workflow', $all[''] );
snt_leaf_preview( 'content-workflow-editing', $all['map'] );
$focus_problems = snt_leaf_edit_focus_problems( $all[''], $all['map'] );
ok( array() === $focus_problems, 'a11y: Edit lands the keyboard on the one region, Cancel lands it back on the list: ' . implode( '; ', $focus_problems ) );

// ── 2026-10-12 (owner: "it's weird to click in 'edit' and something else
// opens"): the editor opens UNDER its row, not below the whole table.
$ip_def   = $all[''];
$ip_ed    = $all['rules'];
$ip_table = ( snt_leaf_tables( $all[''] )[0] ?? array( 'data' => array(), 'attrs' => '' ) );
$ip_keys  = array_map( static function ( $v ) { return is_array( $v ) ? (string) ( $v['text'] ?? '' ) : (string) $v; }, array_column( $ip_table['data'], 'list' ) );
$ip_at    = array_search( 'Rules', $ip_keys, true );
$ip_split = snt_leaf_split_problems( $ip_ed, 'list', 'Rules', false === $ip_at ? null : ( $ip_keys[ $ip_at + 1 ] ?? null ) );
ok( false !== $ip_at && array() === $ip_split, 'edit in place: the editor opens under its row, the row marked selected, the table resuming with the next row after it: ' . implode( '; ', $ip_split ) );
ok( false !== strpos( $ip_ed, '>Editing</os-badge>' ), 'edit in place: the open row says Editing in words, not only by its accent bar' );
ok( 1 === preg_match( '/os-arg-anchor="(snt-row-[0-9a-f]{12})"[^>]*>Cancel/', $ip_ed, $ip_m ) && false !== strpos( $ip_def, 'id="' . $ip_m[1] . '" data-snt-focus' ), 'edit in place: Cancel lands back on the open row\'s own button' );
ok( false !== strpos( $ip_table['attrs'], 'class="snt-table-full"' ), 'edit in place: the list is not boxed in an inner scroller (the app caps os-table at 440px)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
