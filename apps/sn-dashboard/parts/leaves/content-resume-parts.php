<?php
/**
 * S&N Dashboard, Content → Resume Page: the row painters.
 *
 * The classic editor's repeatable rows (inc/admin-forms/resume-page.php:
 * sn_rsm_input / sn_rsm_lines / sn_rsm_role_row / sn_rsm_employer_card /
 * sn_rsm_titled_lines_row and the inline stat, publication and skills rows),
 * painted from the kit. Every field name is the classic one, token keys
 * included: each list is an `<os-repeater>` (OpenStation, Stable) whose rows
 * are the kit cards slotted `row-<index>`, and the classic `<template>` for
 * the list ships verbatim inside it under the template's own key (`__S__`,
 * `__E__`, `__R__`, ...). The repeater paints the move handles, the Remove
 * and Add buttons and the Alt+Arrow model; assets/resume-admin.js applies
 * the three events to the DOM (#1598). The handler never needed an
 * add/remove action: it posts the whole document and sn_resume_doc_normalize()
 * prunes blank rows and reindexes string keys.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** One labelled text field, sn_rsm_input(). @param string $name @param string $label @param mixed $value @param string $ph @return string */
function resume_text( $name, $label, $value, $ph = '' ) {
	return \snt_kit_field( 'text', $name, $label, (string) $value, array( 'placeholder' => '' !== $ph ? $ph : null ) );
}

/** A plain string list as one textarea, one entry per line, sn_rsm_lines(). @param string $name (no []) @param mixed $items @param string $label @param string $ph @param int $rows @return string */
function resume_lines( $name, $items, $label, $ph = '', $rows = 4 ) {
	$value = implode( "\n", array_map( 'strval', (array) $items ) );
	return \snt_kit_field( 'textarea', $name, $label, $value, array( 'rows' => (int) $rows, 'placeholder' => '' !== $ph ? $ph : null ) );
}

/** Two fields side by side, the classic `.sn-rsm-pair`. @param string $a @param string $b @return string */
function resume_pair( $a, $b ) {
	return \snt_kit_tag( 'os-grid', array( 'columns' => '2', 'gap' => '12' ), $a . $b );
}

/**
 * One record row, the classic `.sn-rsm-row.sn-rsm-card`, slotted into its
 * list's `<os-repeater>` under the row's own index (`row-0`, `row-1`, or the
 * template token, `row-__S__`, inside the inert template). The repeater
 * owns the arrows and the Remove button, so the card carries no control of
 * its own and no classic mark.
 *
 * @param string $key     Morph identity (the name prefix).
 * @param string $inner   Row fields.
 * @param bool   $compact Nested rows are compact.
 * @return string
 */
function resume_card( $key, $inner, $compact = false ) {
	return \snt_kit_tag( 'os-card', array( 'os-key' => (string) $key, 'slot' => 'row-' . resume_row_key( $key ), 'compact' => (bool) $compact ), $inner );
}

/**
 * The last bracket segment of a name prefix: `resume[stats][1]` is `1`,
 * `resume[experience][0][roles][__R__]` is `__R__`. Unique among the rows
 * of one list, which is all a repeater key has to be.
 *
 * @param string $prefix Name prefix.
 * @return string
 */
function resume_row_key( $prefix ) {
	return preg_match( '/\[([^\]]*)\]$/', (string) $prefix, $m ) ? $m[1] : (string) $prefix;
}

/**
 * A repeatable list as the kit's `<os-repeater>`: every row slotted under
 * its index, `os-prop-keys` naming them in order (the runtime assigns the
 * `keys` property after each render), and the classic `<template>` for the
 * list, verbatim, as the repeater's last child: inert, so its token-keyed
 * fields never post, and cloned by assets/resume-admin.js on
 * `os-repeater-add` with the token rewritten to a unique key. The repeater
 * emits add, remove and move; the script moves the slotted node too, since
 * os-form collects fields in light-DOM order and the handler saves the
 * order posted.
 *
 * @param string $base      Name prefix of the list (e.g. resume[stats]).
 * @param string $rows      The painted rows.
 * @param int    $count     Row count.
 * @param string $token     The classic template token.
 * @param string $add_label The classic "+ Add ..." label.
 * @param string $row_label The singular noun the repeater names its buttons with ("Remove stat").
 * @param string $blank     The blank row the classic template carries.
 * @return string
 */
function resume_repeater( $base, $rows, $count, $token, $add_label, $row_label, $blank ) {
	return \snt_kit_tag(
		'os-repeater',
		array(
			'os-key'       => (string) $base,
			'reorderable'  => true,
			'add-label'    => (string) $add_label,
			'row-label'    => (string) $row_label,
			'empty-text'   => __( 'No rows yet.', 'signal-and-noise-tools' ),
			'os-prop-keys' => $count > 0 ? array_map( 'strval', range( 0, $count - 1 ) ) : array(),
		),
		$rows . \snt_kit_tag( 'template', array( 'data-rsm-tpl' => true, 'data-rsm-token' => (string) $token ), $blank )
	);
}

/** One role: title + bullets, sn_rsm_role_row(). @param string $prefix @param array $role {title,bullets[]} (empty for the blank row). @return string */
function resume_role_row( $prefix, array $role ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[title]', __( 'Role title · dates', 'signal-and-noise-tools' ), $role['title'] ?? '', 'Role · Jan 2020 - Present' )
		. resume_lines( $prefix . '[bullets]', $role['bullets'] ?? array(), __( 'Bullets: one per line', 'signal-and-noise-tools' ), __( 'What you did, one line each', 'signal-and-noise-tools' ), 4 ),
		true
	);
}

/**
 * The roles under an employer: a nested repeater with `row-label="role"`.
 *
 * @param string $prefix Employer name prefix.
 * @param array  $roles  Roles.
 * @param string $token  The role template's token (`__R__` / `__Y__`).
 * @return string
 */
function resume_roles_list( $prefix, array $roles, $token ) {
	$out = '';
	$i   = 0;
	foreach ( $roles as $role ) {
		$out .= resume_role_row( $prefix . '[roles][' . $i . ']', (array) $role );
		$i++;
	}
	return resume_repeater( $prefix . '[roles]', $out, $i, $token, __( '+ Add role', 'signal-and-noise-tools' ), __( 'role', 'signal-and-noise-tools' ), resume_role_row( $prefix . '[roles][' . $token . ']', array() ) );
}

/**
 * One employer: org (+ dates/location) + roles, sn_rsm_employer_card().
 *
 * @param string $prefix     Name prefix (e.g. resume[experience][0]).
 * @param string $role_token The role template's token.
 * @param array  $entry      {org,dates?,location?,roles[]}.
 * @param bool   $with_meta  Paint the dates/location pair.
 * @return string
 */
function resume_employer_card( $prefix, $role_token, array $entry, $with_meta ) {
	$inner = resume_text( $prefix . '[org]', __( 'Organization', 'signal-and-noise-tools' ), $entry['org'] ?? '', 'ORGANIZATION NAME' );
	if ( $with_meta ) {
		$inner .= resume_pair(
			resume_text( $prefix . '[dates]', __( 'Dates', 'signal-and-noise-tools' ), $entry['dates'] ?? '', 'Jan 2020 - Present' ),
			resume_text( $prefix . '[location]', __( 'Location', 'signal-and-noise-tools' ), $entry['location'] ?? '', 'City, Country' )
		);
	}
	return resume_card( $prefix, $inner . resume_roles_list( $prefix, (array) ( $entry['roles'] ?? array() ), $role_token ) );
}

/** One titled-lines row (Education / Affiliations), sn_rsm_titled_lines_row(). @param string $prefix @param array $entry {title,lines[]} @return string */
function resume_titled_lines_row( $prefix, array $entry ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[title]', __( 'Title', 'signal-and-noise-tools' ), $entry['title'] ?? '', __( 'Degree, membership, or certificate', 'signal-and-noise-tools' ) )
		. resume_lines( $prefix . '[lines]', $entry['lines'] ?? array(), __( 'Detail lines: one per line', 'signal-and-noise-tools' ), 'Institution · Place · Date', 3 ),
		true
	);
}

/** One stat: number + label. @param string $prefix @param array $stat {n,label} @return string */
function resume_stat_row( $prefix, array $stat ) {
	return resume_card(
		$prefix,
		resume_pair(
			resume_text( $prefix . '[n]', __( 'Number', 'signal-and-noise-tools' ), $stat['n'] ?? '', '20+' ),
			resume_text( $prefix . '[label]', __( 'Label', 'signal-and-noise-tools' ), $stat['label'] ?? '', __( 'Years in the industry', 'signal-and-noise-tools' ) )
		),
		true
	);
}

/** One publication: title, then venue + URL. @param string $prefix @param array $pub {meta,title,url} @return string */
function resume_publication_row( $prefix, array $pub ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[title]', __( 'Title', 'signal-and-noise-tools' ), $pub['title'] ?? '', __( 'Paper title', 'signal-and-noise-tools' ) )
		. resume_pair(
			resume_text( $prefix . '[meta]', __( 'Venue · date', 'signal-and-noise-tools' ), $pub['meta'] ?? '', 'SSRN Working Paper · April 2026' ),
			resume_text( $prefix . '[url]', __( 'URL', 'signal-and-noise-tools' ), $pub['url'] ?? '', 'https://ssrn.com/abstract=…' )
		),
		true
	);
}

/** One skills row: category + the comma-separated items cell. @param string $prefix @param array $row {category,items} @return string */
function resume_skills_row( $prefix, array $row ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[category]', __( 'Category', 'signal-and-noise-tools' ), $row['category'] ?? '', __( 'Production', 'signal-and-noise-tools' ) )
		. \snt_kit_field( 'textarea', $prefix . '[items]', __( 'Items', 'signal-and-noise-tools' ), (string) ( $row['items'] ?? '' ), array( 'rows' => 2 ) ),
		true
	);
}

/**
 * A repeatable list: every row through its painter, then the painter's blank
 * row under the template token as the repeater's `<template>`.
 *
 * @param array    $items     Rows.
 * @param callable $painter   fn( string $prefix, array $row ): string.
 * @param string   $base      Name prefix of the list (e.g. resume[stats]).
 * @param string   $token     The classic template token.
 * @param string   $add_label The classic "+ Add ..." label.
 * @param string   $row_label The singular noun for the row buttons.
 * @param array    $blank     The blank row the classic template carries.
 * @return string
 */
function resume_list( array $items, callable $painter, $base, $token, $add_label, $row_label, array $blank = array() ) {
	$out = '';
	$i   = 0;
	foreach ( $items as $row ) {
		$out .= call_user_func( $painter, $base . '[' . $i . ']', (array) $row );
		$i++;
	}
	return resume_repeater( $base, $out, $i, $token, $add_label, $row_label, call_user_func( $painter, $base . '[' . $token . ']', $blank ) );
}
