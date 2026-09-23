<?php
/**
 * Tests: snt_kit_table()'s slot cells (17.9.0, #1624).
 *
 * A cell value `[ 'html' => ..., 'text' => ... ]` becomes an OpenStation 1.1.11
 * slot cell: `{ slot, text }` in the data and the markup as a light-DOM child.
 * The component requires every slot name to be unique across the table: two
 * `<slot name="x">` in one shadow root means the first takes every matching
 * child and the second row renders blank.
 *
 * Run: php tests/kit-table-slot-cells.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$cols = array( array( 'key' => 'name', 'label' => 'Name' ), array( 'key' => 'go', 'label' => '' ) );

echo "Group 1: a slot cell\n";
$html = snt_kit_table( $cols, array( array( 'name' => 'plain', 'go' => array( 'html' => '<os-button>Go</os-button>', 'text' => 'go' ) ) ) );
$t    = snt_leaf_tables( $html )[0] ?? null;
ok( null !== $t && 'plain' === $t['data'][0]['name'], 'a plain string cell stays a string' );
ok( isset( $t['data'][0]['go']['slot'] ) && 'go' === $t['data'][0]['go']['text'] && ! isset( $t['data'][0]['go']['html'] ), 'an html cell is { slot, text } in the data, with no markup in the JSON' );
ok( '<os-button>Go</os-button>' === snt_leaf_cell_html( $t, $t['data'][0]['go'] ), 'the markup rides as the light-DOM child of that slot' );

echo "\nGroup 2: slot names are unique across the table\n";
$rows = array();
for ( $i = 0; $i < 3; $i++ ) {
	$rows[] = array( 'name' => 'n' . $i, 'go' => array( 'html' => '<b>' . $i . '</b>', 'text' => '' ) );
}
$t     = snt_leaf_tables( snt_kit_table( $cols, $rows ) )[0];
$slots = array_map( static function ( $r ) { return $r['go']['slot']; }, $t['data'] );
ok( 3 === count( array_unique( $slots ) ), 'three rows, three distinct slot names' );
ok( '<b>2</b>' === snt_leaf_cell_html( $t, $t['data'][2]['go'] ), 'each row\'s cell holds its own markup, not the first row\'s' );

echo "\nGroup 3: a stable key\n";
$keyed = static function ( array $keys ) use ( $cols ) {
	$rows = array();
	foreach ( $keys as $k ) {
		$rows[] = array( '_key' => $k, 'name' => $k, 'go' => array( 'html' => $k, 'text' => '' ) );
	}
	return snt_leaf_tables( snt_kit_table( $cols, $rows ) )[0];
};
$before = $keyed( array( 'a', 'b', 'c' ) );
$after  = $keyed( array( 'b', 'c' ) ); // 'a' dismissed: 'b' moved from index 1 to 0.
ok( $before['data'][1]['go']['slot'] === $after['data'][0]['go']['slot'], 'a keyed row keeps its slot name when a dismissal moves it up' );
ok( ! array_key_exists( '_key', $before['data'][0] ), '_key is consumed, never shipped in the data' );
$dup = $keyed( array( 'same', 'same' ) );
ok( $dup['data'][0]['go']['slot'] !== $dup['data'][1]['go']['slot'] && 'same' === snt_leaf_cell_html( $dup, $dup['data'][1]['go'] ), 'two rows sharing a key still get distinct slots, so the second is not blank' );

echo "\nGroup 4: phone\n";
ok( false !== strpos( snt_kit_table( $cols, array(), array( 'stack_on_phone' => true ) ), 'data-snt-stack-on-phone' ), 'stack_on_phone marks the table' );
ok( false === strpos( snt_kit_table( $cols, array() ), 'data-snt-stack-on-phone' ), 'and nothing else does' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
