<?php
/**
 * Hidden-field answers: the strongest spam signal a conditional form has.
 *
 * A person only sees the fields the form's logic shows for what they picked,
 * so a browser can only submit answers there. Forms validates against the
 * visible fields but stores everything posted, so a script that fills every
 * input leaves answers in fields that were hidden. One stray branch can be a
 * person who switched their choice after typing; answers in two or more
 * hidden branches cannot. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does one rule hold against the submitted values? Mirrors Forms'
 * alltfo_logic_compare() for the operators a visibility rule uses; any other
 * operator is treated as unknown (the field counts as visible, never guessed
 * hidden).
 *
 * @param array $rule   {field, operator, value}.
 * @param array $values Values keyed by field id.
 * @return bool|null Null when the operator is not one we mirror.
 */
function snt_fs_rule_holds( array $rule, array $values ) {
	$actual   = $values[ (string) ( $rule['field'] ?? '' ) ] ?? '';
	$actual   = is_scalar( $actual ) ? (string) $actual : '';
	$expected = (string) ( $rule['value'] ?? '' );
	switch ( (string) ( $rule['operator'] ?? '' ) ) {
		case 'is':
			return $actual === $expected;
		case 'is_not':
			return $actual !== $expected;
		case 'empty':
			return '' === trim( $actual );
		case 'not_empty':
			return '' !== trim( $actual );
	}
	return null;
}

/**
 * Was a field visible for this submission? True when it has no logic, or
 * when its logic cannot be read with certainty.
 *
 * @param array $field  Schema field.
 * @param array $values Values keyed by field id.
 * @return bool
 */
function snt_fs_field_visible( array $field, array $values ) {
	$logic = (array) ( $field['logic'] ?? array() );
	$rules = (array) ( $logic['rules'] ?? array() );
	if ( empty( $logic['enabled'] ) || array() === $rules ) {
		return true;
	}
	$results = array();
	foreach ( $rules as $rule ) {
		$held = snt_fs_rule_holds( (array) $rule, $values );
		if ( null === $held ) {
			return true;
		}
		$results[] = $held;
	}
	$matched = 'any' === ( $logic['match'] ?? 'all' ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	return 'hide' === ( $logic['action'] ?? 'show' ) ? ! $matched : $matched;
}

/**
 * How many distinct hidden branches carry answers. A branch is a field's rule
 * set, so Outlet, Angle and Deadline (all "intent is Press") are one branch.
 *
 * @param array $values Values keyed by field id.
 * @param array $schema Form schema.
 * @return int
 */
function snt_fs_hidden_branches( array $values, array $schema ) {
	$branches = array();
	foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
		$id = (string) ( $field['id'] ?? '' );
		$v  = $values[ $id ] ?? null;
		$filled = is_array( $v ) ? '' !== trim( implode( '', array_filter( $v, 'is_scalar' ) ) ) : ( is_scalar( $v ) && '' !== trim( (string) $v ) );
		if ( $filled && ! snt_fs_field_visible( (array) $field, $values ) ) {
			$branches[ md5( (string) json_encode( $field['logic']['rules'] ?? array() ) ) ] = true;
		}
	}
	return count( $branches );
}
