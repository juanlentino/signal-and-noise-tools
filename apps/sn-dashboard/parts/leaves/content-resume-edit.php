<?php
/**
 * S&N Dashboard: the one-region editor shared by Content > Resume Page and
 * Content > Workflow.
 *
 * 2026-10-11, owner rule: no collapsible sections. A leaf lists its sections
 * in ONE kit table, each row with a visible Edit button (a `go` that sets
 * `sn_edit=<section>`), and paints exactly one edit region, for the section
 * the param names. Both save handlers rebuild the WHOLE document from the
 * post (sn_resume_doc_normalize(), sn_workflow_normalize()), so the form also
 * carries every other section as hidden inputs: the names and values that
 * section's own fields would post. resume_carry() reads them off the
 * section's real paint, so a carrier cannot drift from its field.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The section `sn_edit` names, or '' when none (or one the leaf lacks).
 *
 * @param array<string,mixed> $ctx  Leaf context (tab, sub, state, os).
 * @param string[]            $keys The leaf's section keys.
 * @return string
 */
function resume_edit_key( array $ctx, array $keys ) {
	$state  = $ctx['state'] ?? null;
	$params = ( is_object( $state ) && method_exists( $state, 'get' ) ) ? (array) $state->get( 'params' ) : array();
	$key    = isset( $params['sn_edit'] ) && is_scalar( $params['sn_edit'] ) ? (string) $params['sn_edit'] : '';
	return in_array( $key, $keys, true ) ? $key : '';
}

/**
 * A row's Edit button: a `go` back to this leaf with `sn_edit` set.
 *
 * @param string $sub The leaf.
 * @param string $key The section.
 * @return string
 */
function resume_edit_button( $sub, $key ) {
	return \snt_kit_button( __( 'Edit', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => (string) $sub, 'sn_edit' => (string) $key, 'anchor' => 'snt-edit-region' ) ) );
}

/**
 * The region's Cancel: a `go` back to the leaf without `sn_edit`, slotted
 * into the form's own action row beside its submit (os-form `footer-leading`,
 * the Identity & SEO leaf's slot). It must be a direct child of the form.
 *
 * @param string $sub The leaf.
 * @return string
 */
function resume_edit_cancel( $sub ) {
	return \snt_kit_tag( 'span', array( 'slot' => 'footer-leading' ), \snt_kit_button( __( 'Cancel', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => (string) $sub, 'anchor' => 'snt-edit-list' ) ) ) );
}

/**
 * What a painted section would post, as name => value for hidden inputs.
 *
 * os-form collects every named field in its light DOM (a `<template>`'s
 * content is not in the document) and a checkbox as its boolean. So a field
 * named with a template token (`[__E__]`) is skipped. A checked box carries
 * '1', what the kit's boolean `true` expands to (snt_os_host_expand()), not
 * the box's `value` attribute; an unchecked one carries nothing, as a browser
 * posts it, and both readers take absent as off (`! empty()` for the phone,
 * `'1' ===` for Show on page). Values are the painted attribute, decoded:
 * htmlspecialchars() round-trips exactly, newlines included.
 *
 * @param string $html One section's painted fields.
 * @return array<string,string>
 */
function resume_carry( $html ) {
	preg_match_all( '/<(os-[a-z-]+|input)\b[^>]*\sname="([^"]*)"[^>]*>/', (string) $html, $tags, PREG_SET_ORDER );
	$out = array();
	foreach ( $tags as $tag ) {
		$name = html_entity_decode( $tag[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/\[__[A-Z]+__\]/', $name ) ) {
			continue;
		}
		if ( in_array( $tag[1], array( 'os-checkbox-label', 'os-checkbox', 'os-switch' ), true ) ) {
			// Quoted values out first, so a label that says "checked" is not the flag.
			if ( preg_match( '/\schecked\b/', (string) preg_replace( '/="[^"]*"/', '', $tag[0] ) ) ) {
				$out[ $name ] = '1';
			}
			continue;
		}
		$out[ $name ] = preg_match( '/\svalue="([^"]*)"/', $tag[0], $v ) ? html_entity_decode( $v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}
	return $out;
}

/**
 * The carriers for every section but the one being edited.
 *
 * @param array<string,array{paint:callable}> $sections Key => section (its `paint` paints its fields).
 * @param string                              $editing  The section painted as fields.
 * @return array<string,string>
 */
function resume_carry_others( array $sections, $editing ) {
	$out = array();
	foreach ( $sections as $key => $section ) {
		if ( (string) $key !== (string) $editing ) {
			$out += resume_carry( (string) call_user_func( $section['paint'] ) );
		}
	}
	return $out;
}

/**
 * A table cell's short text: whitespace collapsed, cut at `$max` characters.
 *
 * @param mixed $text Text.
 * @param int   $max  Characters.
 * @return string
 */
function resume_clip( $text, $max = 72 ) {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', is_scalar( $text ) ? (string) $text : '' ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
	return $text;
}
