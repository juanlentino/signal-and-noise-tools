<?php
/**
 * S&N Dashboard — Content → Resume Page: the row painters.
 *
 * The classic editor's repeatable rows (inc/admin-forms/resume-page.php:
 * sn_rsm_input / sn_rsm_lines / sn_rsm_role_row / sn_rsm_employer_card /
 * sn_rsm_titled_lines_row and the inline stat, publication and skills rows),
 * painted from the kit. Every field name is the classic one, token keys
 * included: the classic `<template>` for each list becomes a closed fold
 * holding ONE blank row under the template's own key (`__S__`, `__E__`,
 * `__R__`, …). The handler never needed an add/remove action — it posts the
 * whole document and sn_resume_doc_normalize() prunes blank rows and
 * reindexes string keys — so a filled fold is a new row and a blanked row is
 * a removed one.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** One labelled text field — sn_rsm_input(). @param string $name @param string $label @param mixed $value @param string $ph @return string */
function resume_text( $name, $label, $value, $ph = '' ) {
	return \snt_kit_field( 'text', $name, $label, (string) $value, array( 'placeholder' => '' !== $ph ? $ph : null ) );
}

/** A plain string list as one textarea, one entry per line — sn_rsm_lines(). @param string $name (no []) @param mixed $items @param string $label @param string $ph @param int $rows @return string */
function resume_lines( $name, $items, $label, $ph = '', $rows = 4 ) {
	$value = implode( "\n", array_map( 'strval', (array) $items ) );
	return \snt_kit_field( 'textarea', $name, $label, $value, array( 'rows' => (int) $rows, 'placeholder' => '' !== $ph ? $ph : null ) );
}

/** Two fields side by side — the classic `.sn-rsm-pair`. @param string $a @param string $b @return string */
function resume_pair( $a, $b ) {
	return \snt_kit_tag( 'os-grid', array( 'columns' => '2', 'gap' => '12' ), $a . $b );
}

/** One record row — the classic `.sn-rsm-row.sn-rsm-card`. @param string $key Morph identity (the name prefix). @param string $inner @param bool $compact Nested rows are compact. @return string */
function resume_card( $key, $inner, $compact = false ) {
	return \snt_kit_tag( 'os-card', array( 'os-key' => (string) $key, 'compact' => (bool) $compact ), $inner );
}

/**
 * The classic "+ Add …" button and its `<template>`: a closed fold holding
 * one blank row under the template's token key. Unlike the classic
 * `<template>`, this fold's fields are live and DO post on save; a blank row
 * posts blank and sn_resume_doc_normalize() prunes it, so the hint tells the
 * one thing the classic page had a whole button for (Remove) that this port
 * does not: blank a row and save to drop it.
 *
 * @param string $label The classic button label.
 * @param string $inner The blank row.
 * @return string
 */
function resume_add_fold( $label, $inner ) {
	return \snt_kit_tag( 'os-disclosure', array( 'heading' => (string) $label, 'hint' => __( 'Blank a row and save to remove it; rows keep the order shown', 'signal-and-noise-tools' ) ), $inner );
}

/** One role: title + bullets — sn_rsm_role_row(). @param string $prefix @param array $role {title,bullets[]} (empty for the blank row). @return string */
function resume_role_row( $prefix, array $role ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[title]', __( 'Role title · dates', 'signal-and-noise-tools' ), $role['title'] ?? '', 'Role · Jan 2020 - Present' )
		. resume_lines( $prefix . '[bullets]', $role['bullets'] ?? array(), __( 'Bullets: one per line', 'signal-and-noise-tools' ), __( 'What you did, one line each', 'signal-and-noise-tools' ), 4 ),
		true
	);
}

/**
 * The roles under an employer: every role, then the "+ Add role" fold.
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
	return $out . resume_add_fold( __( '+ Add role', 'signal-and-noise-tools' ), resume_role_row( $prefix . '[roles][' . $token . ']', array() ) );
}

/**
 * One employer: org (+ dates/location) + roles — sn_rsm_employer_card().
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

/** One titled-lines row (Education / Affiliations) — sn_rsm_titled_lines_row(). @param string $prefix @param array $entry {title,lines[]} @return string */
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
 * A repeatable list: every row through its painter, then the "+ Add" fold
 * holding the painter's blank row under the template token.
 *
 * @param array    $items     Rows.
 * @param callable $painter   fn( string $prefix, array $row ): string.
 * @param string   $base      Name prefix of the list (e.g. resume[stats]).
 * @param string   $token     The classic template token.
 * @param string   $add_label The classic "+ Add …" label.
 * @param array    $blank     The blank row the classic template carries.
 * @return string
 */
function resume_list( array $items, callable $painter, $base, $token, $add_label, array $blank = array() ) {
	$out = '';
	$i   = 0;
	foreach ( $items as $row ) {
		$out .= call_user_func( $painter, $base . '[' . $i . ']', (array) $row );
		$i++;
	}
	return $out . resume_add_fold( $add_label, call_user_func( $painter, $base . '[' . $token . ']', $blank ) );
}
