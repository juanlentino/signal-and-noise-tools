<?php
/**
 * Rule values that differ from a dropdown choice only in case: found, fixed
 * to the exact spelling, and nothing else touched.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../inc/forms-rule-case.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Forms rule case\n\n";

$rule   = function ( $v, $op = 'is' ) { return array( 'enabled' => true, 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'intent', 'operator' => $op, 'value' => $v ) ) ); };
$schema = array(
	'fields'        => array(
		array( 'id' => 'intent', 'type' => 'select', 'choices' => array( array( 'value' => 'Research' ), array( 'value' => 'Press' ), array( 'value' => 'Other' ) ) ),
		array( 'id' => 'research_org', 'label' => 'Publication', 'logic' => $rule( 'Research' ) ),
	),
	// As exported from the contact form, 2026-09-26.
	'notifications' => array(
		array( 'id' => 'n_research', 'name' => 'Research', 'logic' => $rule( 'research' ) ),
		array( 'id' => 'n_press', 'name' => 'Press', 'logic' => $rule( 'press' ) ),
		array( 'id' => 'n_typo', 'name' => 'Typo', 'logic' => $rule( 'reserch' ) ),
		array( 'id' => 'n_contains', 'name' => 'Contains', 'logic' => $rule( 'press', 'contains' ) ),
	),
	'confirmations' => array( array( 'id' => 'c_other', 'name' => 'Other', 'logic' => $rule( 'Other' ) ) ),
);

$r = snt_fs_fix_rule_case( $schema );
ok( 2 === count( $r['changes'] ), 'two case-only mismatches found (research, press)' );
ok( 'Research' === $r['schema']['notifications'][0]['logic']['rules'][0]['value'] && 'Press' === $r['schema']['notifications'][1]['logic']['rules'][0]['value'], 'fixed to the choice\'s exact spelling' );
ok( 'reserch' === $r['schema']['notifications'][2]['logic']['rules'][0]['value'], 'a real typo is not a case difference: left alone' );
ok( 'press' === $r['schema']['notifications'][3]['logic']['rules'][0]['value'], 'a case-insensitive operator (contains) is left alone' );
ok( $schema['fields'] === $r['schema']['fields'] && $schema['confirmations'] === $r['schema']['confirmations'], 'rules already exact are untouched' );
ok( array() === snt_fs_fix_rule_case( $r['schema'] )['changes'], 'running it again finds nothing: idempotent' );
ok( 'notification: Research' === $r['changes'][0]['where'] && 'research' === $r['changes'][0]['from'], 'each change names where it was and what it was' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
