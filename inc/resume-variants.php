<?php
/**
 * Signal & Noise Tools: résumé variants, two résumés from one document.
 *
 * Owner brief 2026-11-01: the music résumé (the /resume page and its PDF, the
 * public one) and an operations résumé (its own PDF, and since brief #2 the page's second lane) are built
 * from the same entries, so neither can drift from the editor.
 *
 * In the stored document (sn_resume_doc), every experience and earlier entry,
 * role, education and affiliation row, publication and skills row may carry
 * `variant`: 'music' or 'operations'. Absent means both, which is what every
 * entry had before variants existed. A role's bullets stay plain strings; a
 * bullet shown in one variant only is listed in the role's `bullet_variants`,
 * keyed by sn_resume_bullet_key() of its text, so reordering bullets can never
 * move a flag onto the wrong one. Two optional top-level blocks:
 *   built{intro,items[]{title,url,text,variant?}}  "What I've Built"
 *   variants{music{show_built},operations{headline,tagline,location,links[],
 *            summary,sections[],experience_order[],earlier_compact,pdf_file}}
 *
 * sn_resume_doc_view() turns the stored document into one variant in the shape
 * every reader already knows (no variant keys, no built, no variants), and
 * sn_resume_doc_get() returns the music view, which the music PDF, the Spanish
 * CV and vCard read. The page reads the stored document and paints one lane
 * per variant (inc/resume-page-lanes.php), and JSON Resume and Markdown follow
 * its ?lane=operations.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_VARIANTS = array( 'music', 'operations' );

/**
 * The PDF sections a variant can order. The music résumé keeps the order its
 * PDF has always had (SN_RESUME_PDF_MUSIC_SECTIONS), so its output is unchanged.
 */
const SN_RESUME_PDF_SECTION_KEYS    = array( 'summary', 'stats', 'competencies', 'built', 'experience', 'publications', 'education', 'affiliations', 'skills', 'toolkit' );
const SN_RESUME_PDF_MUSIC_SECTIONS  = array( 'summary', 'stats', 'competencies', 'experience', 'publications', 'education', 'affiliations', 'toolkit' );
const SN_RESUME_OPERATIONS_PDF_FILE = 'JuanLentino_Resume_Operations.pdf';

/**
 * A variant flag: 'music', 'operations', or '' for both.
 *
 * @param mixed $v
 * @return string
 */
function sn_resume_variant_flag( $v ) {
	return in_array( $v, SN_RESUME_VARIANTS, true ) ? $v : '';
}

/**
 * The key a bullet's flag is stored under: its text, hashed.
 *
 * @param string $bullet Normalized bullet HTML.
 * @return string
 */
function sn_resume_bullet_key( $bullet ) {
	return substr( hash( 'sha256', (string) $bullet ), 0, 12 );
}

/**
 * The row's variant as a key to append, or nothing for both.
 *
 * @param array $in Raw row.
 * @return array
 */
function sn_resume_variant_keep( $in ) {
	$flag = sn_resume_variant_flag( $in['variant'] ?? '' );
	return '' === $flag ? array() : array( 'variant' => $flag );
}

/**
 * A role's per-bullet flags, kept only for bullets the role still has.
 *
 * @param mixed                $in      Raw {key: flag} map.
 * @param array<int,string>    $bullets The role's normalized bullets.
 * @return array<string,string>
 */
function sn_resume_bullet_variants( $in, $bullets ) {
	$keys = array_flip( array_map( 'sn_resume_bullet_key', $bullets ) );
	$out  = array();
	foreach ( (array) $in as $key => $flag ) {
		$flag = sn_resume_variant_flag( $flag );
		if ( '' !== $flag && isset( $keys[ (string) $key ] ) ) {
			$out[ (string) $key ] = $flag;
		}
	}
	return $out;
}

/**
 * "What I've Built", or null when there is nothing in it.
 *
 * @param mixed $in
 * @return array{intro:string,items:array}|null
 */
function sn_resume_normalize_built( $in ) {
	$in    = is_array( $in ) ? $in : array();
	$items = array();
	foreach ( (array) ( $in['items'] ?? array() ) as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$title = sn_resume_text( $item['title'] ?? '' );
		if ( '' === $title ) {
			continue;
		}
		$items[] = array(
			'title' => $title,
			'url'   => sn_resume_url( $item['url'] ?? '' ),
			'text'  => sn_resume_text( $item['text'] ?? '' ),
		) + sn_resume_variant_keep( $item );
	}
	$intro = sn_resume_text( $in['intro'] ?? '' );
	return ( $items || '' !== $intro ) ? array( 'intro' => $intro, 'items' => $items ) : null;
}

/**
 * The variants block, or null when it holds nothing.
 *
 * @param mixed $in
 * @return array|null
 */
function sn_resume_normalize_variants( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = array();
	if ( ! empty( $in['music']['show_built'] ) ) {
		$out['music'] = array( 'show_built' => true );
	}
	$ops = is_array( $in['operations'] ?? null ) ? $in['operations'] : array();
	$o   = array(
		'headline'         => sn_resume_text( $ops['headline'] ?? '' ),
		'tagline'          => sn_resume_text( $ops['tagline'] ?? '' ),
		'location'         => sn_resume_text( $ops['location'] ?? '' ),
		'links'            => array_values( array_filter( array_map( 'sn_resume_url', sn_resume_string_list( $ops['links'] ?? array() ) ), 'strlen' ) ),
		'summary'          => sn_resume_text( $ops['summary'] ?? '' ),
		'sections'         => array_values( array_intersect( array_unique( array_map( static fn( $s ) => strtolower( trim( (string) $s ) ), sn_resume_string_list( $ops['sections'] ?? array() ) ) ), SN_RESUME_PDF_SECTION_KEYS ) ),
		'experience_order' => array_values( array_filter( array_map( 'sn_resume_text', sn_resume_string_list( $ops['experience_order'] ?? array() ) ), 'strlen' ) ),
		'earlier_compact'  => ! empty( $ops['earlier_compact'] ),
		'pdf_file'         => preg_match( '/^[A-Za-z0-9_-]{1,80}\.pdf\z/', (string) ( $ops['pdf_file'] ?? '' ) ) ? (string) $ops['pdf_file'] : '',
	);
	if ( array_filter( $o ) ) { // any field set keeps the block: the form posts every field, blank or not.
		$out['operations'] = $o;
	}
	return $out ? $out : null;
}

/**
 * One variant of the stored document, in the shape every reader knows: rows
 * flagged for the other variant are gone, and no variant keys, built or
 * variants block remain. An entry that had roles and has none left is dropped.
 * PURE.
 *
 * @param array|null $doc     Normalized stored document.
 * @param string     $variant 'music' or 'operations'.
 * @return array|null
 */
function sn_resume_doc_view( $doc, $variant = 'music' ) {
	if ( ! is_array( $doc ) ) {
		return $doc;
	}
	$keep  = static fn( $row ) => ! isset( $row['variant'] ) || $row['variant'] === $variant;
	$roles = static function ( $roles ) use ( $keep, $variant ) {
		$out = array();
		foreach ( (array) $roles as $role ) {
			if ( ! is_array( $role ) || ! $keep( $role ) ) {
				continue;
			}
			$flags   = (array) ( $role['bullet_variants'] ?? array() );
			$bullets = array_values( array_filter( (array) ( $role['bullets'] ?? array() ), static fn( $b ) => in_array( $flags[ sn_resume_bullet_key( $b ) ] ?? '', array( '', $variant ), true ) ) );
			unset( $role['variant'], $role['bullet_variants'] );
			$role['bullets'] = $bullets;
			$out[]           = $role;
		}
		return $out;
	};
	$rows = static function ( $list ) use ( $keep, $roles ) {
		$out = array();
		foreach ( (array) $list as $row ) {
			if ( ! is_array( $row ) || ! $keep( $row ) ) {
				continue;
			}
			unset( $row['variant'] );
			if ( isset( $row['roles'] ) ) {
				$had          = (bool) $row['roles'];
				$row['roles'] = $roles( $row['roles'] );
				if ( $had && ! $row['roles'] ) {
					continue;
				}
			}
			$out[] = $row;
		}
		return $out;
	};
	foreach ( array( 'experience', 'education', 'affiliations', 'publications', 'skills' ) as $key ) {
		if ( isset( $doc[ $key ] ) ) {
			$doc[ $key ] = $rows( $doc[ $key ] );
		}
	}
	if ( isset( $doc['earlier']['entries'] ) ) {
		$doc['earlier']['entries'] = $rows( $doc['earlier']['entries'] );
	}
	unset( $doc['built'], $doc['variants'] );
	return $doc;
}

/**
 * "What I've Built" items for a variant (title, url, text), and its intro.
 *
 * @param array|null $doc Stored document.
 * @param string     $variant
 * @return array{intro:string,items:array}
 */
function sn_resume_built_for( $doc, $variant ) {
	$built = is_array( $doc['built'] ?? null ) ? $doc['built'] : array();
	$items = array();
	foreach ( (array) ( $built['items'] ?? array() ) as $item ) {
		if ( is_array( $item ) && in_array( $item['variant'] ?? '', array( '', $variant ), true ) ) {
			unset( $item['variant'] );
			$items[] = $item;
		}
	}
	return array( 'intro' => (string) ( $built['intro'] ?? '' ), 'items' => $items );
}

/**
 * How a variant's PDF is laid out. Music is what the PDF has always been, with
 * "What I've Built" after the competencies only when switched on. Operations
 * is null until its settings exist.
 *
 * @param array|null $doc Stored document.
 * @param string     $variant
 * @return array|null {sections,headline,tagline,location,links,summary,experience_order,earlier_compact,pdf_file,phone}
 */
function sn_resume_variant_config( $doc, $variant ) {
	$v = is_array( $doc['variants'] ?? null ) ? $doc['variants'] : array();
	if ( 'operations' === $variant ) {
		$o = $v['operations'] ?? null;
		if ( ! is_array( $o ) ) {
			return null;
		}
		$o['phone'] = false; // the operations PDF never prints the phone, whatever the switch says (the template enforces it).
		if ( '' === $o['pdf_file'] ) {
			$o['pdf_file'] = SN_RESUME_OPERATIONS_PDF_FILE;
		}
		if ( ! $o['sections'] ) {
			$o['sections'] = SN_RESUME_PDF_MUSIC_SECTIONS; // blank falls back to the music PDF's, as every other field does.
		}
		return $o;
	}
	$sections = SN_RESUME_PDF_MUSIC_SECTIONS;
	if ( ! empty( $v['music']['show_built'] ) ) {
		array_splice( $sections, 3, 0, array( 'built' ) );
	}
	return array( 'sections' => $sections );
}

/**
 * The published operations résumé's layout, or null until it has settings.
 *
 * @return array|null
 */
function sn_resume_ops_config() {
	return sn_resume_variant_config( function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null, 'operations' );
}

/**
 * The music PDF's layout with its "What I've Built", for every music render:
 * Generate, the draft preview and the private copy.
 *
 * @param array|null $doc Stored document (or draft).
 * @return array
 */
function sn_resume_pdf_music_config( $doc ) {
	return sn_resume_variant_config( $doc, 'music' ) + array( 'built' => sn_resume_built_for( $doc, 'music' ) );
}
