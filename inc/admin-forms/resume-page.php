<?php
/**
 * Signal & Noise — Resume Page admin section (Content tab → Resume Page).
 *
 * The STRUCTURED editor for the /resume page: real fields and repeatable rows
 * (employers → roles → bullets, publications, stats, chips, skills), not a
 * plain-text box. Prefills from sn_resume_doc_get() (the shipped seed before
 * the first save); sn_action=resume_save rebuilds the document from the posted
 * arrays and regenerates the live Page via the sync engine.
 *
 * Every renderer here ECHOES (not returns) with esc_* on each dynamic value at
 * the echo site — the WP-core renderer style, and what keeps BOTH WPCS and
 * Plugin Check's independent EscapeOutput sniffs clean without exclusions
 * (Plugin Check does not read phpcs.xml.dist).
 *
 * Repeatable-row mechanics (assets/resume-admin.js): every list is a
 * [data-rsm-list] container + a <template data-rsm-tpl> + an [data-rsm-add]
 * button sharing one id. Templates for NESTED lists bake placeholder tokens
 * (__E__ employers, __R__/__Y__ roles, __X__ earlier entries, __D__/__A__
 * titled-line entries) into names/ids; the JS swaps every token for a unique
 * key at clone time — nested <template> content included. PHP receives
 * string keys; the data layer iterates and reindexes, so contiguity never
 * matters. Leaf lists (chips, bullets, lines) use plain [] names: order is
 * DOM order, no keys at all.
 *
 * @package SignalNoiseTools
 * @since 10.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Row chrome: move-up / move-down / remove. */
function sn_rsm_controls() {
	echo '<span class="sn-rsm-controls">'
		. '<button type="button" class="button-link sn-rsm-up" aria-label="Move up">&uarr;</button>'
		. '<button type="button" class="button-link sn-rsm-down" aria-label="Move down">&darr;</button>'
		. '<button type="button" class="button-link sn-rsm-del" aria-label="Remove">&times;</button>'
		. '</span>';
}

/** One labelled text input. @param string $name @param string $value @param string $label @param string $ph */
function sn_rsm_input( $name, $value, $label, $ph = '' ) {
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">' . esc_html( $label ) . '</span>'
		. '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $ph ) . '"></label>';
}

/** One bullet row (leaf list — plain [] name). @param string $prefix @param string $value */
/**
 * A plain string list as ONE textarea, one entry per line. (v10.48.0)
 *
 * The rule this release applies across all three content editors: a repeatable
 * of PLAIN STRINGS becomes lines in a textarea; a repeatable of RECORDS keeps
 * its rows. Bullets, detail lines and hero chips are the former — each was
 * costing an input plus three buttons to hold one short string. Publications
 * and stats are the latter and are deliberately untouched.
 *
 * @since 10.48.0
 * @param string   $name  Field name (no [] — the value posts as one string).
 * @param array    $items Current values.
 * @param string   $label Visible label.
 * @param string   $ph    Placeholder.
 * @param int      $rows  Textarea rows.
 */
function sn_rsm_lines( $name, $items, $label, $ph = '', $rows = 4 ) {
	$id = 'rsml-' . substr( md5( $name ), 0, 8 );
	echo '<div class="sn-rsm-lines">';
	echo '<label class="sn-field-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
	echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( (string) (int) $rows ) . '" class="large-text sn-rsm-items" placeholder="' . esc_attr( $ph ) . '">';
	echo esc_textarea( implode( "\n", array_map( 'strval', (array) $items ) ) );
	echo '</textarea>';
	echo '</div>';
}

function sn_rsm_bullet_row( $prefix, $value ) {
	echo '<div class="sn-rsm-row sn-rsm-bullet" data-rsm-row>'
		. '<textarea rows="2" class="large-text" name="' . esc_attr( $prefix . '[bullets][]' ) . '">' . esc_textarea( $value ) . '</textarea>';
	sn_rsm_controls();
	echo '</div>';
}

/**
 * One role row: title + its bullets list. Shared by Experience and the
 * Earlier-career fold.
 *
 * @param string $prefix Input-name prefix for this role (may carry tokens).
 * @param string $bul_id data-rsm id for the bullets list (may carry tokens).
 * @param array  $role   {title,bullets[]} (empty for templates).
 */
function sn_rsm_role_row( $prefix, $bul_id, $role ) {
	echo '<div class="sn-rsm-row sn-rsm-role" data-rsm-row>';
	echo '<div class="sn-rsm-role-head">';
	sn_rsm_input( $prefix . '[title]', (string) ( $role['title'] ?? '' ), 'Role title · dates', 'Role · Jan 2020 - Present' );
	sn_rsm_controls();
	echo '</div>';
	// v10.48.0: bullets are plain strings, so they become lines in one textarea.
	// $bul_id is retained in the signature — the callers pass token-bearing ids
	// and the roles list above still clones — but no longer names a nested list.
	unset( $bul_id );
	sn_rsm_lines( $prefix . '[bullets]', (array) ( $role['bullets'] ?? array() ), 'Bullets — one per line', 'What you did, one line each', 4 );
	echo '</div>';
}

/**
 * One employer card: org/dates/location + roles list. Also used (without the
 * dates/location pair) for earlier-career entries.
 *
 * @param string $prefix     Name prefix (e.g. resume[experience][0]).
 * @param string $rsm_id     data-rsm id base for the roles list.
 * @param string $role_token Token the role template bakes for its own key.
 * @param array  $entry      {org,dates?,location?,roles[]}.
 * @param bool   $with_meta  Render the dates/location inputs.
 */
function sn_rsm_employer_card( $prefix, $rsm_id, $role_token, $entry, $with_meta ) {
	echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row>';
	echo '<div class="sn-rsm-card-head">';
	sn_rsm_input( $prefix . '[org]', (string) ( $entry['org'] ?? '' ), 'Organization', 'ORGANIZATION NAME' );
	sn_rsm_controls();
	echo '</div>';
	if ( $with_meta ) {
		echo '<div class="sn-rsm-pair">';
		sn_rsm_input( $prefix . '[dates]', (string) ( $entry['dates'] ?? '' ), 'Dates', 'Jan 2020 - Present' );
		sn_rsm_input( $prefix . '[location]', (string) ( $entry['location'] ?? '' ), 'Location', 'City, Country' );
		echo '</div>';
	}
	echo '<div class="sn-rsm-list" data-rsm-list="' . esc_attr( $rsm_id ) . '">';
	$i = 0;
	foreach ( (array) ( $entry['roles'] ?? array() ) as $role ) {
		sn_rsm_role_row( $prefix . '[roles][' . $i . ']', $rsm_id . '-b' . $i, $role );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="' . esc_attr( $rsm_id ) . '" data-rsm-token="' . esc_attr( $role_token ) . '">';
	sn_rsm_role_row( $prefix . '[roles][' . $role_token . ']', $rsm_id . '-b' . $role_token, array() );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="' . esc_attr( $rsm_id ) . '">+ Add role</button>';
	echo '</div>';
}

/** One titled-lines row (Education / Affiliations): title + lines[]. @param string $prefix @param string $lines_id @param array $entry */
function sn_rsm_titled_lines_row( $prefix, $lines_id, $entry ) {
	echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row>';
	echo '<div class="sn-rsm-card-head">';
	sn_rsm_input( $prefix . '[title]', (string) ( $entry['title'] ?? '' ), 'Title', 'Degree, membership, or certificate' );
	sn_rsm_controls();
	echo '</div>';
	unset( $lines_id );
	sn_rsm_lines( $prefix . '[lines]', (array) ( $entry['lines'] ?? array() ), 'Detail lines — one per line', 'Institution · Place · Date', 3 );
	echo '</div>';
}

/**
 * Open a collapsible section card. COLLAPSED by default (v10.33.1: eight
 * open sections made the screen an endless scroll — closed, it reads as a
 * compact table of contents; collapsed inputs still submit with the form).
 * The summary carries a row-count badge so a closed section still says how
 * much it holds.
 *
 * @param string $title Section title.
 * @param string $hint  Helper line shown when open.
 * @param int    $count Row count for the summary badge (-1 = no badge).
 */
function sn_rsm_section_open( $title, $hint = '', $count = -1 ) {
	echo '<details class="sn-rsm-section"><summary><span class="sn-rsm-summary-title">' . esc_html( $title ) . '</span>';
	if ( $count >= 0 ) {
		echo '<span class="sn-rsm-summary-count">' . esc_html( (string) $count ) . '</span>';
	}
	echo '</summary>';
	if ( '' !== $hint ) {
		echo '<p class="sn-field-helper">' . esc_html( $hint ) . '</p>';
	}
}

/**
 * Render the Resume Page section body. sn_admin_render_section() callback for
 * the Content tab's 'resume' sub-tab.
 *
 * @since 10.33.0
 */
function sn_admin_render_resume_section() {
	$doc = function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : null;
	if ( ! is_array( $doc ) ) {
		echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Resume page</h2><p class="sn-fieldset-intro">The resume editor is unavailable: no stored document and no readable seed.</p></div>';
		return;
	}
	$saved = '' !== (string) ( $doc['updated'] ?? '' );

	echo '<form method="post" class="sn-rsm-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Resume page</h2>';
	if ( $saved ) {
		echo '<p class="sn-fieldset-intro">This form is the editor for the live <a href="' . esc_url( home_url( '/resume' ) ) . '" target="_blank" rel="noopener">/resume</a> page. Saving regenerates it. Last saved: <code>' . esc_html( (string) $doc['updated'] ) . '</code>.</p>';
	} else {
		echo '<p class="sn-fieldset-intro">This form is the editor for the live <a href="' . esc_url( home_url( '/resume' ) ) . '" target="_blank" rel="noopener">/resume</a> page, prefilled from the current published content. The first save takes over the page body — from then on this form is the canonical editor.</p>';
	}

	// ── Hero ──
	sn_rsm_section_open( 'Hero', 'The opening band: summary, credential chips, contact line, and the PDF download.' );
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">Summary</span><textarea rows="3" class="large-text" name="resume[hero][summary]">' . esc_textarea( $doc['hero']['summary'] ) . '</textarea></label>';
	sn_rsm_lines( 'resume[hero][chips]', (array) ( $doc['hero']['chips'] ?? array() ), 'Credential chips — one per line', 'Credential or membership', 4 );
	// The contact/LinkedIn pair opens its own two-up row; the chips list used to
	// sit above it and this open tag travelled with the block that was replaced.
	echo '<div class="sn-rsm-pair">';
	sn_rsm_input( 'resume[hero][contact_line]', $doc['hero']['contact_line'], 'Contact line', 'City, State' );
	sn_rsm_input( 'resume[hero][linkedin]', $doc['hero']['linkedin'], 'LinkedIn URL', 'https://www.linkedin.com/in/…' );
	echo '</div><div class="sn-rsm-pair">';
	sn_rsm_input( 'resume[hero][pdf_url]', $doc['hero']['pdf_url'], 'PDF URL', 'https://…/Resume.pdf' );
	sn_rsm_input( 'resume[hero][pdf_label]', $doc['hero']['pdf_label'], 'PDF link label', 'Name · Resume (PDF)' );
	echo '</div></details>';

	// ── Stats ──
	sn_rsm_section_open( 'Stats', 'The numbers strip under the hero.', count( $doc['stats'] ) );
	echo '<div class="sn-rsm-list" data-rsm-list="stats">';
	$i = 0;
	foreach ( $doc['stats'] as $stat ) {
		echo '<div class="sn-rsm-row" data-rsm-row><div class="sn-rsm-pair">';
		sn_rsm_input( 'resume[stats][' . $i . '][n]', $stat['n'], 'Number', '20+' );
		sn_rsm_input( 'resume[stats][' . $i . '][label]', $stat['label'], 'Label', 'Years in the industry' );
		echo '</div>';
		sn_rsm_controls();
		echo '</div>';
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="stats" data-rsm-token="__S__"><div class="sn-rsm-row" data-rsm-row><div class="sn-rsm-pair">';
	sn_rsm_input( 'resume[stats][__S__][n]', '', 'Number', '20+' );
	sn_rsm_input( 'resume[stats][__S__][label]', '', 'Label', 'Years in the industry' );
	echo '</div>';
	sn_rsm_controls();
	echo '</div></template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="stats">+ Add stat</button>';
	echo '</details>';

	// ── Experience ──
	sn_rsm_section_open( 'Experience', 'One card per employer; each holds one or more roles with their bullets. Bullets may use <strong>, <em>, and links.', count( $doc['experience'] ) );
	echo '<div class="sn-rsm-list" data-rsm-list="exp">';
	$i = 0;
	foreach ( $doc['experience'] as $entry ) {
		sn_rsm_employer_card( 'resume[experience][' . $i . ']', 'rol-' . $i, '__R__', $entry, true );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="exp" data-rsm-token="__E__">';
	sn_rsm_employer_card( 'resume[experience][__E__]', 'rol-__E__', '__R__', array( 'roles' => array( array() ) ), true );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="exp">+ Add employer</button>';
	echo '</details>';

	// ── Earlier career ──
	sn_rsm_section_open( 'Earlier career (collapsed fold)', 'Rendered inside a collapsed "details" fold at the end of Experience.', count( $doc['earlier']['entries'] ) );
	sn_rsm_input( 'resume[earlier][label]', $doc['earlier']['label'], 'Fold label', 'Earlier career · 1997 - 2015' );
	echo '<div class="sn-rsm-list" data-rsm-list="earlier">';
	$i = 0;
	foreach ( $doc['earlier']['entries'] as $entry ) {
		sn_rsm_employer_card( 'resume[earlier][entries][' . $i . ']', 'erol-' . $i, '__Y__', $entry, false );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="earlier" data-rsm-token="__X__">';
	sn_rsm_employer_card( 'resume[earlier][entries][__X__]', 'erol-__X__', '__Y__', array( 'roles' => array( array() ) ), false );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="earlier">+ Add earlier employer</button>';
	echo '</details>';

	// ── Education / Affiliations ──
	sn_rsm_section_open( 'Education', '', count( $doc['education'] ) );
	echo '<div class="sn-rsm-list" data-rsm-list="edu">';
	$i = 0;
	foreach ( $doc['education'] as $entry ) {
		sn_rsm_titled_lines_row( 'resume[education][' . $i . ']', 'edl-' . $i, $entry );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="edu" data-rsm-token="__D__">';
	sn_rsm_titled_lines_row( 'resume[education][__D__]', 'edl-__D__', array() );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="edu">+ Add education</button>';
	echo '</details>';

	sn_rsm_section_open( 'Affiliations & Certifications', '', count( $doc['affiliations'] ) );
	echo '<div class="sn-rsm-list" data-rsm-list="aff">';
	$i = 0;
	foreach ( $doc['affiliations'] as $entry ) {
		sn_rsm_titled_lines_row( 'resume[affiliations][' . $i . ']', 'afl-' . $i, $entry );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="aff" data-rsm-token="__A__">';
	sn_rsm_titled_lines_row( 'resume[affiliations][__A__]', 'afl-__A__', array() );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="aff">+ Add affiliation</button>';
	echo '</details>';

	// ── Publications ──
	sn_rsm_section_open( 'Publications', 'A new paper is one row: venue line, title, and link.', count( $doc['publications'] ) );
	echo '<div class="sn-rsm-list" data-rsm-list="pubs">';
	$i = 0;
	foreach ( $doc['publications'] as $pub ) {
		echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
		sn_rsm_input( 'resume[publications][' . $i . '][title]', $pub['title'], 'Title', 'Paper title' );
		sn_rsm_controls();
		echo '</div><div class="sn-rsm-pair">';
		sn_rsm_input( 'resume[publications][' . $i . '][meta]', $pub['meta'], 'Venue · date', 'SSRN Working Paper · April 2026' );
		sn_rsm_input( 'resume[publications][' . $i . '][url]', $pub['url'], 'URL', 'https://ssrn.com/abstract=…' );
		echo '</div></div>';
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="pubs" data-rsm-token="__P__"><div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
	sn_rsm_input( 'resume[publications][__P__][title]', '', 'Title', 'Paper title' );
	sn_rsm_controls();
	echo '</div><div class="sn-rsm-pair">';
	sn_rsm_input( 'resume[publications][__P__][meta]', '', 'Venue · date', 'SSRN Working Paper · April 2026' );
	sn_rsm_input( 'resume[publications][__P__][url]', '', 'URL', 'https://ssrn.com/abstract=…' );
	echo '</div></div></template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="pubs">+ Add publication</button>';
	echo '</details>';

	// ── Skills ──
	sn_rsm_section_open( 'Skills', 'One table row per category; items is the comma-separated cell.', count( $doc['skills'] ) );
	echo '<div class="sn-rsm-list" data-rsm-list="skills">';
	$i = 0;
	foreach ( $doc['skills'] as $row ) {
		echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
		sn_rsm_input( 'resume[skills][' . $i . '][category]', $row['category'], 'Category', 'Production' );
		sn_rsm_controls();
		echo '</div>';
		echo '<label class="sn-rsm-field"><span class="sn-rsm-label">Items</span><textarea rows="2" class="large-text" name="' . esc_attr( 'resume[skills][' . $i . '][items]' ) . '">' . esc_textarea( $row['items'] ) . '</textarea></label>';
		echo '</div>';
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="skills" data-rsm-token="__K__"><div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
	sn_rsm_input( 'resume[skills][__K__][category]', '', 'Category', 'Production' );
	sn_rsm_controls();
	echo '</div>';
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">Items</span><textarea rows="2" class="large-text" name="resume[skills][__K__][items]"></textarea></label>';
	echo '</div></template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="skills">+ Add skills row</button>';
	echo '</details>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="resume_save" class="button button-primary">Save resume</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
