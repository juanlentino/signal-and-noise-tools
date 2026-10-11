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
ok( 0 === preg_match_all( '/ checked[ =>]/', $kit_all ) && 0 === preg_match_all( '/ checked[ =>]/', $classic ), 'empty: no "Show on page" box is checked, on either form' );
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
ok( 1 === preg_match_all( '/ checked[ =>]/', $all['map'] ) && 1 === preg_match_all( '/ checked[ =>]/', $classic ), 'editing the map: only the shown row is checked, on both forms' );
ok( 1 === preg_match( '/name="workflow\[map\]\[0\]\[show\]"[^>]*checked/', $all['map'] ) && 1 === preg_match( '/name="workflow\[map\]\[0\]\[show\]"[^>]*checked/', $classic ), '...and it is row 0' );
ok( false !== strpos( $kit, '<input type="hidden" name="workflow[map][0][show]" value="1">' ) && false === strpos( $kit, 'name="workflow[map][1][show]"' ) && false === strpos( $all['rules'], 'name="workflow[map][1][show]"' ), 'a list not being edited carries a shown row as show=1 and a hidden row as NO show field, the way a browser posts an unchecked box' );
ok( false !== strpos( $kit, 'value="  &lt;b&gt;x&lt;/b&gt;' ) && false !== strpos( $classic, "&lt;b&gt;x&lt;/b&gt;\n\tindent\n</textarea>" ), 'the body is escaped and keeps its whitespace in both editors' );
ok( false !== strpos( $classic, "class=\"large-text code\">\n  &lt;b&gt;" ), 'the classic body textarea is monospace and protects a leading newline' );
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
ok( array( '2', '1', '0' ) === array_column( $t['data'], 'rows' ) && array( '1', '1', '0' ) === array_column( $t['data'], 'shown' ), 'the fold counts (2 / 1 / 0) are the Rows cells, and On the page reads the public view (1 of 2 map steps; every rule)' );
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
ok( 1 === preg_match( '#<os-section heading="Proof links" description="Links to evidence a reader can check\\. A row shows only when &quot;Show on page&quot; is ticked and its link is a path on this site \\(/maturity/\\) or an https URL\\.#', $all['proof'] ) && false !== strpos( $all['rules'], '<os-section heading="Rules" description="Add, remove and reorder rows with their buttons, or Alt+Arrow keys on a row; the page shows them in this order."' ) && false !== strpos( $all['map'], 'Unchecked steps stay here and nowhere else.' ), 'the fold\'s and the intro\'s helper lines are the regions\' descriptions (the Proof link rule, the Show on page rule, the reorder keys)' );
ok( 1 === preg_match( '#<span slot="footer-leading"><os-button variant="ghost" os-action="go" os-arg-sub="workflow" os-arg-anchor="snt-edit-list">Cancel</os-button></span>#', $all['map'] ) && false === strpos( $kit, '>Cancel</os-button>' ), 'the region has a Cancel beside Save (a go that clears sn_edit); the default paint has none' );
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
ok( array( '6', '8', '5' ) === array_column( $t['data'], 'rows' ) && array( '4', '8', '3' ) === array_column( $t['data'], 'shown' ), 'On the page counts what sn_workflow_public_data() lets through: 4 of 6 steps, all 8 rules, 3 of 5 proof links (the unchecked row and the javascript: link stay off)' );
ok( false !== strpos( $all[''], '<p class="snt-prose">4 of 6 map steps, 8 of 8 rules and 3 of 5 proof links show on the page.</p>' ), 'the finding counts the public view' );

// ── Live-shaped preview: ~6 map steps, ~8 rules, ~5 proof links (the fixture above).
ok( array() === $folds_in( $all ) && false === strpos( $all[''], 'snt-ledger-more' ), 'live-shaped: fold-free on every paint, the three-row ledger never capped' );
snt_leaf_preview( 'content-workflow', $all[''] );
snt_leaf_preview( 'content-workflow-editing', $all['map'] );
$focus_problems = snt_leaf_edit_focus_problems( $all[''], $all['map'] );
ok( array() === $focus_problems, 'a11y: Edit lands the keyboard on the one region, Cancel lands it back on the list: ' . implode( '; ', $focus_problems ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
