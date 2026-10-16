<?php
/**
 * AI › Agent tools, the refusals table: both twins paint door × rule × 7 and
 * 30 days from one read, busiest first, with honest empty states.
 *
 * Run: php tests/os-leaf-door-refusals.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
function sn_door_refusals_read() { return $GLOBALS['__read']; }
require SNT_PATH . 'inc/admin-forms/mcp-usage-block.php';
require SNT_PATH . 'inc/admin-forms/door-refusals-block.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-agent-tools-refusals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function rows_of( $kit ) { preg_match( '/os-prop-data="([^"]+)"/', $kit, $m ); return json_decode( html_entity_decode( $m[1] ?? '[]', ENT_QUOTES, 'UTF-8' ), true ); }
function paint_both() {
	return array( \SignalNoise\OpenStationHost\Dashboard\Leaves\agent_tools_refusals_html(), snt_leaf_classic_html( 'sn_admin_render_door_refusals' ) );
}

// Nothing declined, log readable.
$GLOBALS['__read'] = array( 'log_readable' => true, 'rows' => array(), 'total_7' => 0, 'total_30' => 0 );
list( $kit, $classic ) = paint_both();
ok( false !== strpos( $kit, 'No write door declined a call in the last 30 days.' ) && false !== strpos( $classic, 'No write door declined a call in the last 30 days.' ), 'empty: both twins say no write was declined' );
ok( false === strpos( $kit, 'os-prop-data' ) && false === strpos( $classic, '<table' ), 'empty: no table, never a table of zeros' );
ok( false === strpos( $kit, 'could not be read' ), 'a readable log paints no warning' );
ok( array() === snt_leaf_folds( $kit ), 'no fold' );

// Rows: sorted already by the read; the twins keep the order and the words.
$GLOBALS['__read'] = array(
	'log_readable' => true,
	'rows'         => array(
		array( 'door' => 'rw', 'rule' => 'snt_sn_apply_fingerprint_stale', 'd7' => 2, 'd30' => 1200 ),
		array( 'door' => 'direct', 'rule' => 'sn_rw_kill_switch', 'd7' => 0, 'd30' => 3 ),
	),
	'total_7'      => 2,
	'total_30'     => 1203,
);
list( $kit, $classic ) = paint_both();
$rows = rows_of( $kit );
ok( array( 'snt_sn_apply_fingerprint_stale', 'sn_rw_kill_switch' ) === array_column( (array) $rows, 'rule' ), 'native: one row per door and rule, in the read\'s order' );
ok( 'write door' === ( $rows[0]['door'] ?? '' ) && '1,200' === ( $rows[0]['d30'] ?? '' ) && '0' === ( $rows[1]['d7'] ?? '' ), 'native: the door by its screen name, counts formatted, a quiet week reads 0' );
ok( strpos( $classic, 'snt_sn_apply_fingerprint_stale' ) < strpos( $classic, 'sn_rw_kill_switch' ) && false !== strpos( $classic, '<td>write door</td>' ) && false !== strpos( $classic, '<td>1,200</td>' ), 'classic: the same rows in the same order' );
$finding = '1,203 writes were declined in the last 30 days, 2 in the last 7. The rule that declined most is snt_sn_apply_fingerprint_stale at the write door, 1,200 times.';
ok( false !== strpos( $kit, $finding ) && false !== strpos( $classic, $finding ), 'both twins lead with the same finding' );
ok( false !== strpos( $kit, 'no payload is kept' ) && false !== strpos( $classic, 'no payload is kept' ), 'both say where the counts come from' );
ok( false === strpos( $kit . $classic, '—' ), 'no em dash in the new copy' );

// The log could not be read.
$GLOBALS['__read']['log_readable'] = false;
list( $kit, $classic ) = paint_both();
ok( false !== strpos( $kit, 'The call log could not be read' ) && false !== strpos( $classic, 'The call log could not be read' ), 'an unreadable log is a warning on both twins, not a quiet zero' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
