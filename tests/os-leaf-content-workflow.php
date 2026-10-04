<?php
/**
 * Native window leaf: Content > Workflow (apps/sn-dashboard/parts/leaves/content-workflow.php).
 *
 * The oracle is the classic form (inc/admin-forms/workflow-page.php): the kit
 * form must post the same field names in the same order, including the spare
 * rows under the classic templates' tokens, and the same action, both empty
 * and with a stored document. The "Show on page" box is checked only for a
 * row stored as shown, and never on the spare row.
 *
 * Run: php tests/os-leaf-content-workflow.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }

require SNT_PATH . 'inc/workflow-page.php';
require SNT_PATH . 'inc/admin-forms/resume-page.php'; // sn_rsm_input(), sn_rsm_controls().
require SNT_PATH . 'inc/admin-forms/workflow-page.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-workflow.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/workflow'] ), 'the painter is registered under content/workflow' );

// ── Empty: nothing stored.
unset( $GLOBALS['__options']['sn_workflow_page'] );
$classic = snt_leaf_classic_html( 'sn_admin_render_workflow_section' );
$kit     = snt_leaf_paint( 'content', 'workflow' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty: field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( array( 'workflow_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is workflow_save, as on the classic form' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup in the kit leaf: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( 0 === preg_match_all( '/ checked[ =>]/', $kit ) && 0 === preg_match_all( '/ checked[ =>]/', $classic ), 'empty: no "Show on page" box is checked, on either form' );

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
$kit     = snt_leaf_paint( 'content', 'workflow' );
$names   = snt_leaf_names( $kit );
ok( snt_leaf_names( $classic ) === $names, 'stored: field names match the classic form: ' . implode( ',', $names ) );
ok( in_array( 'workflow[map][1][show]', $names, true ) && in_array( 'workflow[map][__M__][show]', $names, true ) && in_array( 'workflow[rules][__R__][explanation]', $names, true ), 'each row and each spare carries its fields' );
ok( 1 === preg_match_all( '/ checked[ =>]/', $kit ) && 1 === preg_match_all( '/ checked[ =>]/', $classic ), 'only the shown row is checked, on both forms' );
ok( 1 === preg_match( '/name="workflow\[map\]\[0\]\[show\]"[^>]*checked/', $kit ) && 1 === preg_match( '/name="workflow\[map\]\[0\]\[show\]"[^>]*checked/', $classic ), '...and it is row 0' );
ok( false !== strpos( $kit, 'value="  &lt;b&gt;x&lt;/b&gt;' ) && false !== strpos( $classic, "&lt;b&gt;x&lt;/b&gt;\n\tindent\n</textarea>" ), 'the body is escaped and keeps its whitespace in both editors' );
ok( false !== strpos( $classic, "class=\"large-text code\">\n  &lt;b&gt;" ), 'the classic body textarea is monospace and protects a leading newline' );
preg_match_all( '/<os-repeater [^>]*>/', $kit, $reps );
ok( 3 === count( $reps[0] ) && 3 === count( preg_grep( '/ reorderable /', $reps[0] ) ) && 3 === count( preg_grep( '/ os-prop-keys="/', $reps[0] ) ), 'Map, Rules and Proof are each a reorderable <os-repeater> with its keys, as on Resume' );
ok( 1 === preg_match( '#<os-repeater [^>]*os-key="workflow\[map\]"[^>]*>.*?<template data-rsm-tpl data-rsm-token="__M__">.*?name="workflow\[map\]\[__M__\]\[title\]".*?</template></os-repeater>#s', $kit ) && 1 === preg_match( '#<template data-rsm-tpl data-rsm-token="__R__">.*?name="workflow\[rules\]\[__R__\]\[rule\]"#s', $kit ), 'each repeater carries the classic row template under its token, which resume-admin.js clones on Add' );
ok( false !== strpos( $kit, 'slot="row-0"' ) && false !== strpos( $kit, 'slot="row-1"' ) && false !== strpos( $kit, 'add-label="+ Add map step"' ) && false !== strpos( $kit, 'add-label="+ Add rule"' ), 'stored rows are slotted by index and each list has its Add button' );
$live = preg_replace( '#<template\b.*?</template>#s', '', $kit );
ok( 1 === preg_match( '#<template data-rsm-tpl data-rsm-token="__P__">.*?name="workflow\[proof\]\[__P__\]\[url\]"#s', $kit ) && false !== strpos( $kit, 'add-label="+ Add proof link"' ), 'the Proof repeater carries its template (title, link, line, show) and its Add button' );
ok( false === strpos( $live, '[__M__]' ) && false === strpos( $live, '[__R__]' ) && false === strpos( $live, '[__P__]' ), 'no blank row posts outside its template: an empty form adds nothing' );

ok( false !== strpos( $kit, 'Name where it goes' ), 'the proof title field tells the owner it is the link text and to name where it goes' );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
