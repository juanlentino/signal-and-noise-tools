<?php
// CLI-only fixture for tests/js/leaf-tables.mjs (17.9.0, #1624): snt_kit_table()
// output carrying the same controls the four ported leaves put in their cells.
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require dirname( __DIR__ ) . '/lib/os-leaf-harness.php';

$suggest = static function ( $id ) {
	return snt_kit_tag( 'os-button', array( 'variant' => 'secondary', 'data-snt-suggest' => '1', 'data-check' => 'missing_alt', 'data-attachment-id' => (string) $id ), 'Suggest' );
};
// Health: Suggest straight in the slot cell (no os-cluster), as the port paints it.
$health = snt_kit_table(
	array(
		array( 'key' => 'subject', 'label' => 'Subject', 'sortable' => true, 'stack' => 'title' ),
		array( 'key' => 'note', 'label' => 'Note' ),
		array( 'key' => 'ai', 'label' => 'AI fix', 'stack' => 'actions' ),
	),
	array(
		array( 'subject' => array( 'html' => '<os-code>b.png</os-code>', 'text' => 'b.png' ), 'note' => 'no alt', 'ai' => array( 'html' => $suggest( 77 ), 'text' => '' ) ),
		array( 'subject' => array( 'html' => '<os-code>a.png</os-code>', 'text' => 'a.png' ), 'note' => 'no alt', 'ai' => array( 'html' => $suggest( 78 ), 'text' => '' ) ),
	),
	array( 'id' => 'health', 'stack_on_phone' => true )
);
// Block Migrations: keyed rows, the Suggest + Dismiss pair in an os-cluster.
$pair = static function ( $fp ) {
	$data = array( 'data-post-id' => '5', 'data-fingerprint' => $fp, 'data-migration-type' => 'heading-hierarchy-skip' );
	return snt_kit_tag( 'os-cluster', array( 'gap' => '6' ),
		snt_kit_tag( 'os-button', array( 'variant' => 'ghost', 'data-snt-block-migrations-dismiss' => '1' ) + $data, 'Dismiss' ) );
};
$migrations = snt_kit_table(
	array( array( 'key' => 'post', 'label' => 'Post', 'stack' => 'title' ), array( 'key' => 'action', 'label' => 'Action', 'stack' => 'actions' ) ),
	array(
		array( '_key' => 'h:fp1', 'post' => 'One', 'action' => array( 'html' => $pair( 'fp1' ), 'text' => '' ) ),
		array( '_key' => 'h:fp2', 'post' => 'Two', 'action' => array( 'html' => $pair( 'fp2' ), 'text' => '' ) ),
	),
	array( 'id' => 'migrations', 'stack_on_phone' => true )
);
// Tags: radios and checkboxes as slot cells inside a native form.
$tags = '<form id="tagsform">' . snt_kit_table(
	array( array( 'key' => 'into', 'label' => 'Canonical' ), array( 'key' => 'tag', 'label' => 'Tag' ) ),
	array(
		array( '_key' => 't1', 'into' => array( 'html' => '<input type="radio" name="sn_tag_into" value="1" checked>', 'text' => '' ), 'tag' => 'alpha' ),
		array( '_key' => 't2', 'into' => array( 'html' => '<input type="radio" name="sn_tag_into" value="2">', 'text' => '' ), 'tag' => 'beta' ),
	),
	array( 'id' => 'tags', 'stack_on_phone' => true )
) . '</form>';
echo '<div class="snt-app snt-leaf">' . $health . $migrations . $tags . '</div>';
