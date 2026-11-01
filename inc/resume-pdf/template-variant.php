<?php
/**
 * Signal & Noise Tools: the résumé PDF parts a variant adds (inc/resume-variants.php).
 *
 * The music PDF uses none of them unless its own settings ask, so its output
 * is what it was. Everything here reuses the template's classes: a new rule in
 * the stylesheet would change the music PDF too.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The PDF block with a variant's headline, subhead, location and summary over
 * the music ones. Blank variant fields keep the music value.
 *
 * @param array      $pdf    The document's pdf block.
 * @param array|null $config A variant's layout, or null for music.
 * @return array
 */
function sn_resume_pdf_variant_header( $pdf, $config ) {
	foreach ( array( 'headline', 'tagline', 'location', 'summary' ) as $key ) {
		if ( '' !== (string) ( $config[ $key ] ?? '' ) ) {
			$pdf[ $key ] = (string) $config[ $key ];
		}
	}
	return $pdf;
}

/**
 * Experience entries in a variant's order: those named in $order (by org)
 * first, in that order, then the rest as the document lists them. PURE.
 *
 * @param array             $entries Experience entries.
 * @param array<int,string> $order   Org names.
 * @return array
 */
function sn_resume_pdf_order_entries( $entries, $order ) {
	if ( ! $order ) {
		return $entries;
	}
	$rank = array_flip( array_map( 'strtolower', $order ) );
	$keys = array_keys( $entries );
	usort( $keys, static function ( $a, $b ) use ( $entries, $rank ) {
		$ra = $rank[ strtolower( (string) ( $entries[ $a ]['org'] ?? '' ) ) ] ?? PHP_INT_MAX;
		$rb = $rank[ strtolower( (string) ( $entries[ $b ]['org'] ?? '' ) ) ] ?? PHP_INT_MAX;
		return $ra <=> $rb ?: $a <=> $b;
	} );
	return array_map( static fn( $k ) => $entries[ $k ], $keys );
}

/**
 * Earlier career one line per role, without bullets: "Title, Org, City" with
 * the role's dates on the right, as a role row sits.
 *
 * @param array    $earlier Earlier-career entries.
 * @param callable $e       Escaper.
 * @return string
 */
function sn_resume_pdf_earlier_compact_html( $earlier, $e ) {
	$rows = '';
	foreach ( $earlier as $entry ) {
		list( $org, $loc ) = array_pad( array_map( 'trim', explode( ' · ', (string) ( $entry['org'] ?? '' ), 2 ) ), 2, '' );
		foreach ( (array) ( $entry['roles'] ?? array() ) as $role ) {
			list( $title, $dates ) = sn_resume_pdf_split_role( $role['title'] ?? '' );
			$where = implode( ', ', array_filter( array( $title, sn_resume_pdf_soft_case( $org ), sn_resume_pdf_soft_case( $loc ) ), 'strlen' ) );
			$rows .= '<table class="row"><tr><td>' . $e( $where ) . '</td><td class="dates">' . $e( $dates ) . '</td></tr></table>';
		}
	}
	return '' === $rows ? '' : '<div class="entry"><table class="row"><tr><td class="org">' . sn_resume_l( 'Earlier experience', 'Experiencia anterior' ) . '</td><td class="loc"></td></tr></table>' . $rows . '</div>';
}

/**
 * An all-caps name in mid-sentence case ("OBRAS METÁLICAS S.A." reads "Obras
 * Metálicas S.A."): each word title-cased, a word with a dot left as written.
 * Anything not all caps is returned as it is.
 *
 * @param string $s
 * @return string
 */
function sn_resume_pdf_soft_case( $s ) {
	$s = (string) $s;
	if ( $s !== mb_strtoupper( $s, 'UTF-8' ) ) {
		return $s;
	}
	return implode( ' ', array_map( static fn( $w ) => false !== strpos( $w, '.' ) ? $w : mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ), explode( ' ', $s ) ) );
}

/**
 * "What I've Built": an intro line, then each project's title, link and text.
 *
 * @param array    $built {intro,items[]{title,url,text}} (sn_resume_built_for()).
 * @param callable $e     Escaper.
 * @return string
 */
function sn_resume_pdf_built_html( $built, $e ) {
	$items = (array) ( $built['items'] ?? array() );
	if ( ! $items ) {
		return '';
	}
	$out = '<h2>' . sn_resume_l( "WHAT I'VE BUILT", 'PROYECTOS' ) . '</h2>';
	if ( '' !== (string) ( $built['intro'] ?? '' ) ) {
		$out .= '<p><em>' . $e( $built['intro'] ) . '</em></p>';
	}
	foreach ( $items as $item ) {
		$url  = (string) ( $item['url'] ?? '' );
		$link = '' !== $url ? ' <a href="' . $e( $url ) . '">(' . $e( preg_replace( '~^https?://(www\.)?~i', '', rtrim( $url, '/' ) ) ) . ')</a>' : '';
		$out .= '<p class="lines"><span class="t">' . $e( $item['title'] ?? '' ) . '</span>' . $link . '. ' . $e( $item['text'] ?? '' ) . '</p>';
	}
	return $out;
}

/**
 * The skills table as lines: "Category: items".
 *
 * @param array    $skills Skills rows {category,items}.
 * @param callable $e      Escaper.
 * @return string
 */
function sn_resume_pdf_skills_html( $skills, $e ) {
	if ( ! $skills ) {
		return '';
	}
	$out = '<h2>' . sn_resume_l( 'SKILLS', 'HABILIDADES' ) . '</h2>';
	foreach ( $skills as $row ) {
		$out .= '<p class="lines"><span class="t">' . $e( $row['category'] ?? '' ) . ':</span> ' . $e( $row['items'] ?? '' ) . '</p>';
	}
	return $out;
}
