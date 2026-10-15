<?php
/**
 * Signal & Noise Tools: the stale-CV alert. /cv/ is a translation of the
 * English résumé (inc/resume-es.php) and does not follow it on its own; this
 * says when it has fallen behind, and which English lines changed.
 *
 * The baseline is the set of English lines the Spanish file was translated
 * from. It is captured from the live English résumé whenever the Spanish file
 * itself changes (a new translation is, by definition, of the current
 * English), so nobody records it by hand. The alert lists every English line
 * added or changed since, and every line removed. It shows on the Résumé
 * screen (classic and dashboard) and as a Health check, and clears on its own
 * once the Spanish file is updated and released. Owner, 2026-10-15.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_ES_SOURCE_OPT = 'sn_resume_es_source';

/**
 * Every translatable line of a résumé document, tags stripped. Pure.
 * Contact details are left out: /cv/ takes them from English as they are.
 *
 * @param array|null $doc A normalized résumé document.
 * @return string[]
 */
function sn_resume_lines( $doc ) {
	if ( ! is_array( $doc ) ) {
		return array();
	}
	$lines = array();
	$add   = static function ( $s ) use ( &$lines ) {
		$s = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) );
		if ( '' !== $s ) {
			$lines[] = $s;
		}
	};
	$hero = (array) ( $doc['hero'] ?? array() );
	$add( $hero['summary'] ?? '' );
	$add( $hero['contact_line'] ?? '' );
	array_map( $add, (array) ( $hero['chips'] ?? array() ) );
	foreach ( (array) ( $doc['stats'] ?? array() ) as $stat ) {
		$add( $stat['label'] ?? '' );
	}
	$employers = array_merge( (array) ( $doc['experience'] ?? array() ), (array) ( $doc['earlier']['entries'] ?? array() ) );
	$add( $doc['earlier']['label'] ?? '' );
	foreach ( $employers as $entry ) {
		$add( $entry['org'] ?? '' );
		$add( $entry['dates'] ?? '' );
		$add( $entry['location'] ?? '' );
		foreach ( (array) ( $entry['roles'] ?? array() ) as $role ) {
			$add( $role['title'] ?? '' );
			array_map( $add, (array) ( $role['bullets'] ?? array() ) );
		}
	}
	foreach ( array_merge( (array) ( $doc['education'] ?? array() ), (array) ( $doc['affiliations'] ?? array() ) ) as $item ) {
		$add( $item['title'] ?? '' );
		array_map( $add, (array) ( $item['lines'] ?? array() ) );
	}
	foreach ( (array) ( $doc['publications'] ?? array() ) as $pub ) {
		$add( $pub['meta'] ?? '' );
		$add( $pub['title'] ?? '' );
	}
	foreach ( (array) ( $doc['skills'] ?? array() ) as $skill ) {
		$add( $skill['category'] ?? '' );
		$add( $skill['items'] ?? '' );
	}
	$pdf = (array) ( $doc['pdf'] ?? array() );
	foreach ( array( 'headline', 'tagline', 'summary', 'location' ) as $k ) {
		$add( $pdf[ $k ] ?? '' );
	}
	array_map( $add, (array) ( $pdf['competencies'] ?? array() ) );
	array_map( $add, (array) ( $pdf['toolkit'] ?? array() ) );
	return array_values( array_unique( $lines ) );
}

/**
 * What changed between the baseline and the live English lines. Pure.
 *
 * @param string[] $baseline Lines the Spanish was translated from.
 * @param string[] $current  Lines of the live English résumé.
 * @return array{changed:string[],removed:string[]}
 */
function sn_resume_es_diff( $baseline, $current ) {
	return array(
		'changed' => array_values( array_diff( $current, $baseline ) ),
		'removed' => array_values( array_diff( $baseline, $current ) ),
	);
}

/**
 * The live diff. Captures the baseline the first time, and again whenever the
 * Spanish file has changed since it was captured.
 *
 * @return array{changed:string[],removed:string[]}|null Null when there is no Spanish CV.
 */
function sn_resume_es_stale() {
	$file = __DIR__ . '/seed-content/resume-data-es.json';
	if ( ! is_readable( $file ) || ! function_exists( 'sn_resume_doc_get' ) ) {
		return null;
	}
	$hash    = md5( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bundled file.
	$current = sn_resume_lines( sn_resume_doc_get() );
	$saved   = get_option( SN_RESUME_ES_SOURCE_OPT );
	if ( ! is_array( $saved ) || ( $saved['es_hash'] ?? '' ) !== $hash ) {
		$saved = array( 'es_hash' => $hash, 'lines' => $current );
		update_option( SN_RESUME_ES_SOURCE_OPT, $saved, false );
	}
	return sn_resume_es_diff( (array) $saved['lines'], $current );
}

/**
 * The notice body, or '' when /cv/ is current. Escaped.
 *
 * @param array|null $stale sn_resume_es_stale().
 * @return string
 */
function sn_resume_es_stale_html( $stale ) {
	if ( ! $stale || ( ! $stale['changed'] && ! $stale['removed'] ) ) {
		return '';
	}
	$list = static function ( $lines ) {
		return '<ul style="margin:4px 0 8px 18px;list-style:disc">' . implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $lines ) ) . '</ul>';
	};
	$out = '<strong>' . esc_html__( 'The Spanish CV (/cv/) is out of date.', 'signal-and-noise-tools' ) . '</strong> '
		. esc_html__( 'These English lines changed since it was translated; send this list to have the Spanish updated.', 'signal-and-noise-tools' );
	if ( $stale['changed'] ) {
		$out .= '<br>' . esc_html__( 'New or changed:', 'signal-and-noise-tools' ) . $list( $stale['changed'] );
	}
	if ( $stale['removed'] ) {
		$out .= esc_html__( 'Removed:', 'signal-and-noise-tools' ) . $list( $stale['removed'] );
	}
	return $out;
}

/**
 * Health check: the Spanish CV behind the English résumé.
 *
 * @return array { count, findings, label, fix_hint, skipped }
 */
function sn_health_check_resume_es_stale() {
	$stale = sn_resume_es_stale();
	if ( null === $stale ) {
		return sn_health_pack_check( 'Spanish CV up to date', array(), '', 'No Spanish CV file in this install.' );
	}
	$findings = array();
	foreach ( array( 'changed' => 'Changed or new in the English résumé', 'removed' => 'Removed from the English résumé' ) as $kind => $note ) {
		foreach ( $stale[ $kind ] as $line ) {
			$findings[] = array(
				'subject_type'  => 'resume_line',
				'subject_id'    => 0,
				'subject_url'   => home_url( '/cv/' ),
				'subject_label' => mb_substr( $line, 0, 140 ),
				'edit_url'      => '',
				'note'          => $note . ' since /cv/ was translated.',
			);
		}
	}
	return sn_health_pack_check( 'Spanish CV up to date', $findings, 'Update inc/seed-content/resume-data-es.json for these lines and release; the check clears on its own.' );
}
