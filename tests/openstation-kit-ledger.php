<?php
/**
 * The ledger (inc/openstation-kit-ledger.php): the no-fold list shape. A
 * finding sentence, the first `$cap` rows as a kit table, and a "+N more"
 * line whenever the cap cut anything: never a silent cap.
 *
 * Run: php tests/openstation-kit-ledger.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$cols = array( array( 'key' => 'path', 'label' => 'Path' ), array( 'key' => 'hits', 'label' => 'Hits' ) );
$rows = array();
for ( $i = 1; $i <= 1203; $i++ ) {
	$rows[] = array( 'path' => '/p-' . $i, 'hits' => (string) $i );
}

$html   = snt_kit_ledger( '1,203 paths were hit.', $cols, $rows, 25 );
$tables = snt_leaf_tables( $html );
ok( 0 === strpos( $html, '<p class="snt-prose">1,203 paths were hit.</p><os-table' ), 'the finding leads, as prose, before the table' );
ok( 1 === count( $tables ) && 25 === count( $tables[0]['data'] ) && '/p-25' === $tables[0]['data'][24]['path'], 'the table holds the first 25 rows, in order' );
ok( false !== strpos( $html, '<p class="snt-hint snt-ledger-more">+1,178 more; the list is capped, not complete.</p>' ), 'the cut is counted out loud, formatted' );
ok( array( 'Path', 'Hits' ) === array_column( $tables[0]['columns'], 'label' ), 'every column keeps its header' );

$html = snt_kit_ledger( '', $cols, array_slice( $rows, 0, 25 ), 25 );
ok( 0 === strpos( $html, '<os-table' ) && false === strpos( $html, 'snt-ledger-more' ), 'at the cap exactly: no finding asked for, no "+N more"' );

$html = snt_kit_ledger( 'x', $cols, array_slice( $rows, 0, 26 ), 25, array( 'more' => '+%s more paths, quietest last.' ) );
ok( false !== strpos( $html, '>+1 more paths, quietest last.</p>' ), 'a caller format replaces the default sentence' );

ok( '' === snt_kit_more( 0 ) && '' === snt_kit_more( -3 ), 'nothing cut, nothing said' );

// 2026-10-12: the table split at the row being edited, so its editor opens under it.
list( $head, $tail ) = snt_kit_table_split( $cols, array_slice( $rows, 0, 4 ), 1 );
$h = snt_leaf_tables( $head )[0] ?? array();
$t = snt_leaf_tables( $tail )[0] ?? array();
ok( array( '/p-1', '/p-2' ) === array_column( $h['data'] ?? array(), 'path' ) && 0 === strpos( $head, '<os-table os-prop-selection="[1]"' ), 'split at row 1 of 4: the head ends with that row and marks it selected' );
ok( array( '/p-3', '/p-4' ) === array_column( $t['data'] ?? array(), 'path' ) && array( 'Path', 'Hits' ) === array_column( $t['columns'] ?? array(), 'label' ), 'the tail resumes with the next row under a repeated header' );
list( $whole, $none ) = snt_kit_table_split( $cols, array_slice( $rows, 0, 4 ), -1 );
list( , $after_last ) = snt_kit_table_split( $cols, array_slice( $rows, 0, 4 ), 3 );
ok( 4 === count( snt_leaf_tables( $whole )[0]['data'] ) && false === strpos( $whole, 'os-prop-selection' ) && '' === $none && '' === $after_last, 'no row open: the whole table, nothing marked; the last row open: no tail' );
ok( '<span id="snt-row-' . substr( md5( '/p-1' ), 0, 12 ) . '" data-snt-focus><b>x</b></span>' === snt_kit_row_action( '/p-1', '<b>x</b>' ), 'a row action carries its row anchor and asks for focus' );
ok( false === strpos( (string) file_get_contents( SNT_PATH . 'inc/openstation-kit-ledger.php' ), "\u{2014}" ), 'no em dash in the ledger file' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
