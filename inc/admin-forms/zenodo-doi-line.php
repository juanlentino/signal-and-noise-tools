<?php
/**
 * Signal & Noise Tools -- Connections › Zenodo: the words and the preview for
 * the DOI downloads line on /provenance (inc/provenance-doi-line.php), shared
 * by the classic leaf and the kit leaf so the two say the same sentences.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The form's words, one source for both surfaces.
 *
 * @return array<string,string>
 */
function sn_zenodo_doi_line_copy() {
	return array(
		'title'      => __( 'Downloads on /provenance', 'signal-and-noise-tools' ),
		'intro'      => __( 'A quiet line at the end of /provenance: how many site documents carry a DOI and how often Zenodo has served them (unique downloads, Zenodo\'s own deduplicated count). Only records whose DOI belongs to a site document count. It reads the daily snapshot, never Zenodo itself, and hides while the snapshot is missing or more than two days old.', 'signal-and-noise-tools' ),
		'enabled'    => __( 'Show the downloads line on /provenance', 'signal-and-noise-tools' ),
		'label'      => __( 'Lead label (optional)', 'signal-and-noise-tools' ),
		'label_hint' => __( 'Plain text before the figures, for example "Open record". Empty shows the figures alone.', 'signal-and-noise-tools' ),
		'floor'      => __( 'Floor for the 30-day figure', 'signal-and-noise-tools' ),
		'floor_hint' => __( 'Below this many downloads in the last 30 days, the line shows the all-time figure only. 0 always shows it once 30 days of snapshots exist.', 'signal-and-noise-tools' ),
		'submit'     => __( 'Save the downloads line', 'signal-and-noise-tools' ),
	);
}

/**
 * What the line reads right now, or why it is hidden. Stored values only.
 *
 * @return string
 */
function sn_zenodo_doi_line_preview() {
	$line = function_exists( 'snt_prov_doi_line' ) ? snt_prov_doi_line() : '';
	if ( '' !== $line ) {
		/* translators: %s: the line as /provenance shows it. */
		return sprintf( __( 'Now reads: %s', 'signal-and-noise-tools' ), html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES, 'UTF-8' ) );
	}
	return function_exists( 'snt_prov_doi_settings' ) && ! snt_prov_doi_settings()['enabled']
		? __( 'Now hidden: switched off.', 'signal-and-noise-tools' )
		: __( 'Now hidden: no recent snapshot with the site split yet. The daily Zenodo snapshot fills it.', 'signal-and-noise-tools' );
}
