<?php
/**
 * Signal & Noise Tools: the /workflow page editor (data layer).
 *
 * Same house pattern as /now and /uses: the owner edits a structured form on
 * Content > Workflow, the document is stored in an autoload=no OPTION, and
 * every save regenerates a real top-level WordPress Page (slug `workflow`,
 * template page-workflow) from it. The generator and the upsert live in
 * inc/workflow-page-render.php.
 *
 * THE HIDDEN-ROW WALL. A map row carries a "Show on page" flag, unchecked by
 * default. Some rows name work that must never be public, and every public
 * surface (the Page itself, core REST /wp/v2/pages, the theme's .json twin,
 * the index, llms.txt, provenance signing) reads the generated Page. So the
 * filter sits at ONE point: sn_workflow_public_data(), the only reader the
 * generator has. A missing or malformed flag is hidden (fail closed).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_WORKFLOW_PAGE_OPTION = 'sn_workflow_page';
const SN_WORKFLOW_SLUG        = 'workflow';

/**
 * One single-line field: sanitized, no line breaks.
 *
 * @param mixed $value Unslashed value.
 * @return string
 */
function sn_workflow_text( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	return trim( (string) preg_replace( '/\R+/u', ' ', sanitize_text_field( $value ) ) );
}

/**
 * One prose textarea: sanitized, line breaks kept.
 *
 * @param mixed $value Unslashed value.
 * @return string
 */
function sn_workflow_prose( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	return trim( sanitize_textarea_field( $value ) );
}

/**
 * The sample body, VERBATIM: NUL bytes removed, nothing else. No trim, no
 * tag stripping, no newline normalization. It is escaped on output
 * (sn_workflow_esc), never on the way in.
 *
 * @param mixed $value Unslashed value.
 * @return string
 */
function sn_workflow_verbatim( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	return str_replace( "\0", '', $value );
}

/**
 * Normalize a posted (or stored) workflow array into the stored shape. Blank
 * rows are pruned; row order is kept (it is the display order). A map row's
 * flag is true only when it is exactly '1' or true: anything else is hidden.
 * Idempotent on a clean document, which is why the stored value can ride it
 * again on read (with $slashed false: unslashing twice would eat a backslash
 * the owner typed into the sample body).
 *
 * @param mixed $in      Posted `workflow` array or a stored document.
 * @param bool  $slashed True for raw $_POST input (wp_unslash runs once, here).
 * @return array{title:string,dek:string,sample:array<string,string>,map:array<int,array{title:string,line:string,show:bool}>,rules:array<int,array{rule:string,explanation:string}>}
 */
function sn_workflow_normalize( $in, $slashed = true ) {
	$in     = is_array( $in ) ? ( $slashed ? wp_unslash( $in ) : $in ) : array();
	$sample = is_array( $in['sample'] ?? null ) ? $in['sample'] : array();
	$doc    = array(
		'title'  => sn_workflow_text( $in['title'] ?? '' ),
		'dek'    => sn_workflow_prose( $in['dek'] ?? '' ),
		'sample' => array(
			'label'   => sn_workflow_text( $sample['label'] ?? '' ),
			'title'   => sn_workflow_text( $sample['title'] ?? '' ),
			'intro'   => sn_workflow_prose( $sample['intro'] ?? '' ),
			'body'    => sn_workflow_verbatim( $sample['body'] ?? '' ),
			'outcome' => sn_workflow_prose( $sample['outcome'] ?? '' ),
		),
		'map'    => array(),
		'rules'  => array(),
	);
	foreach ( (array) ( $in['map'] ?? array() ) as $row ) {
		$row   = is_array( $row ) ? $row : array();
		$title = sn_workflow_text( $row['title'] ?? '' );
		$line  = sn_workflow_text( $row['line'] ?? '' );
		if ( '' === $title && '' === $line ) {
			continue;
		}
		$show         = $row['show'] ?? null;
		$doc['map'][] = array(
			'title' => $title,
			'line'  => $line,
			'show'  => true === $show || '1' === $show,
		);
	}
	foreach ( (array) ( $in['rules'] ?? array() ) as $row ) {
		$row  = is_array( $row ) ? $row : array();
		$rule = sn_workflow_text( $row['rule'] ?? '' );
		$expl = sn_workflow_prose( $row['explanation'] ?? '' );
		if ( '' !== $rule || '' !== $expl ) {
			$doc['rules'][] = array( 'rule' => $rule, 'explanation' => $expl );
		}
	}
	return $doc;
}

/**
 * Whether a normalized document holds anything at all.
 *
 * @param array $doc Normalized document.
 * @return bool
 */
function sn_workflow_has_content( array $doc ) {
	return '' !== $doc['title'] . $doc['dek'] . implode( '', $doc['sample'] ) || ! empty( $doc['map'] ) || ! empty( $doc['rules'] );
}

/**
 * The stored document, or null when nothing is saved. Stored values ride the
 * normalizer again on read (values are already clean: the second pass only
 * enforces the shape and the fail-closed flag).
 *
 * @return array|null
 */
function sn_workflow_page_get() {
	$stored = get_option( SN_WORKFLOW_PAGE_OPTION );
	return is_array( $stored ) ? sn_workflow_normalize( $stored, false ) : null;
}

/**
 * THE WALL: the stored document as the public may see it. Map rows whose
 * "Show on page" flag is not exactly true are removed HERE, before any markup
 * exists, and the generator reads nothing else. Nothing about a hidden row
 * survives: not its title, its line, its position or its count.
 *
 * @param array|null $doc Normalized document (defaults to the stored one).
 * @return array|null Null when nothing is stored.
 */
function sn_workflow_public_data( $doc = null ) {
	$doc = null === $doc ? sn_workflow_page_get() : sn_workflow_normalize( $doc, false );
	if ( null === $doc ) {
		return null;
	}
	$map = array();
	foreach ( $doc['map'] as $row ) {
		if ( true === $row['show'] ) {
			$map[] = array( 'title' => $row['title'], 'line' => $row['line'] );
		}
	}
	$doc['map'] = $map;
	return $doc;
}

/**
 * Store (or clear) the document. A document with nothing in it deletes the
 * option. Returns true on a real change.
 *
 * @param array $doc Normalized document.
 * @return bool
 */
function sn_workflow_page_save( array $doc ) {
	if ( ! sn_workflow_has_content( $doc ) ) {
		return delete_option( SN_WORKFLOW_PAGE_OPTION );
	}
	if ( get_option( SN_WORKFLOW_PAGE_OPTION ) === $doc ) {
		return false;
	}
	return update_option( SN_WORKFLOW_PAGE_OPTION, $doc, false );
}
