<?php
/**
 * Rule values that differ from a choice only in case.
 *
 * Forms compares a rule's `is` / `is_not` value exactly, and a dropdown
 * stores the choice's VALUE ("Research"), so a rule written "research"
 * never matches: on the contact form every routed notification was silent.
 * This finds such rules (on notifications, confirmations and field logic)
 * and, when asked, rewrites the value to the choice's exact spelling. Only
 * a case-only difference is touched; any other mismatch is left alone. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix (or just find) case-only rule mismatches in a form schema.
 *
 * @param array $schema Form schema.
 * @return array{schema:array,changes:array<int,array{where:string,field:string,from:string,to:string}>}
 */
function snt_fs_fix_rule_case( array $schema ) {
	$choices = array();
	foreach ( (array) ( $schema['fields'] ?? array() ) as $f ) {
		foreach ( (array) ( $f['choices'] ?? array() ) as $c ) {
			if ( isset( $c['value'] ) && '' !== (string) $c['value'] ) {
				$choices[ (string) ( $f['id'] ?? '' ) ][] = (string) $c['value'];
			}
		}
	}
	$changes = array();
	$fix     = static function ( array $logic, $where ) use ( $choices, &$changes ) {
		foreach ( (array) ( $logic['rules'] ?? array() ) as $i => $rule ) {
			$field = (string) ( $rule['field'] ?? '' );
			$value = (string) ( $rule['value'] ?? '' );
			if ( ! in_array( $rule['operator'] ?? '', array( 'is', 'is_not' ), true ) || ! isset( $choices[ $field ] ) || in_array( $value, $choices[ $field ], true ) ) {
				continue;
			}
			foreach ( $choices[ $field ] as $exact ) {
				if ( 0 === strcasecmp( $exact, $value ) ) {
					$logic['rules'][ $i ]['value'] = $exact;
					$changes[] = array( 'where' => $where, 'field' => $field, 'from' => $value, 'to' => $exact );
					break;
				}
			}
		}
		return $logic;
	};
	foreach ( array( 'notifications', 'confirmations' ) as $group ) {
		foreach ( (array) ( $schema[ $group ] ?? array() ) as $k => $item ) {
			if ( isset( $item['logic'] ) && is_array( $item['logic'] ) ) {
				$schema[ $group ][ $k ]['logic'] = $fix( $item['logic'], rtrim( $group, 's' ) . ': ' . (string) ( $item['name'] ?? $item['id'] ?? $k ) );
			}
		}
	}
	foreach ( (array) ( $schema['fields'] ?? array() ) as $k => $f ) {
		if ( isset( $f['logic'] ) && is_array( $f['logic'] ) ) {
			$schema['fields'][ $k ]['logic'] = $fix( $f['logic'], 'field: ' . (string) ( $f['label'] ?? $f['id'] ?? $k ) );
		}
	}
	return array( 'schema' => $schema, 'changes' => $changes );
}
