<?php
/**
 * Signal & Noise Tools: the one /resume body (owner, 2026-11-12).
 *
 * /resume was two résumés behind a switch (music, operations; 2026-11-01). The
 * owner turned that down: readers had to notice the switch to use it, and two
 * résumés were two things to keep straight. The page now shows one résumé,
 * built from the operations view of the stored document (its headline,
 * subhead, location, summary, experience order, sections and "What I've
 * Built"), plus whatever rows the owner tags for both. /music carries the
 * music proof. The stored document is unchanged, so the music view still
 * exists for /cv and the music PDF; nothing on /resume links it. Any `?lane=`
 * is ignored: every address shows this body, canonical /resume/.
 * Spanish (/cv), and a document with no operations settings, keep the single
 * music body (sn_resume_body_html()).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-pdf/template-variant.php';
require_once __DIR__ . '/resume-page-bands.php';

/**
 * One lane's document: the variant's view, with the operations résumé's
 * headline, subhead, location and summary over the music ones, its experience
 * order, earlier career without bullets when it asks, only the sections it
 * lists, and its "What I've Built" as `built`. The page and the JSON Resume
 * read the same lane. PURE.
 *
 * @param array|null $raw  Stored document (sn_resume_doc_get_raw()).
 * @param string     $lane 'music' or 'operations'.
 * @return array|null
 */
function sn_resume_lane_doc( $raw, $lane ) {
	$doc = sn_resume_doc_view( $raw, $lane );
	$cfg = 'operations' === $lane ? sn_resume_variant_config( $raw, 'operations' ) : null;
	if ( ! is_array( $doc ) ) {
		return $doc;
	}
	// A lane document never carries the phone, whatever the music PDF's switch
	// says: not on the page, not in the operations JSON Resume. The music PDF and
	// the music JSON Resume read the stored document, not this.
	$doc['pdf']                 = (array) ( $doc['pdf'] ?? array() );
	$doc['pdf']['phone']        = '';
	$doc['pdf']['phone_public'] = false;
	if ( null === $cfg ) {
		return $doc;
	}
	$doc['pdf'] = sn_resume_pdf_variant_header( $doc['pdf'], $cfg );
	if ( '' !== $cfg['summary'] ) {
		$doc['hero']['summary'] = $cfg['summary'];
	}
	$doc['experience'] = sn_resume_pdf_order_entries( $doc['experience'], $cfg['experience_order'] );
	foreach ( array( 'stats', 'experience', 'publications', 'education', 'affiliations', 'skills' ) as $key ) {
		if ( ! in_array( $key, $cfg['sections'], true ) ) {
			$doc[ $key ] = array();
		}
	}
	if ( ! in_array( 'experience', $cfg['sections'], true ) ) {
		$doc['earlier']['entries'] = array();
	}
	$doc['kicker'] = $cfg['kicker'] ?? '';
	$doc['built'] = in_array( 'built', $cfg['sections'], true ) ? sn_resume_built_for( $raw, 'operations' ) : array( 'intro' => '', 'items' => array() );
	if ( ! empty( $cfg['earlier_compact'] ) ) {
		foreach ( $doc['earlier']['entries'] as &$entry ) {
			foreach ( $entry['roles'] as &$role ) {
				$role['bullets'] = array();
			}
			unset( $role );
		}
		unset( $entry );
	}
	return $doc;
}

/**
 * The résumé /resume shows, as a document: the one résumé when the operations
 * settings exist, else the music view. The JSON Resume, the Markdown and the
 * Spanish CV's staleness check read this.
 *
 * @return array|null
 */
function sn_resume_shown_doc() {
	$raw = sn_resume_doc_get_raw();
	return null !== sn_resume_variant_config( $raw, 'operations' ) ? sn_resume_lane_doc( $raw, 'operations' ) : sn_resume_doc_get();
}

/**
 * A résumé page body for a stored document, /resume or /cv (rendered in
 * Spanish by inc/resume-es.php): the one résumé when it carries operations
 * settings, else the single body.
 *
 * @param array|null  $raw Stored document.
 * @param string|null $pdf The Download's address; null is /resume's operations PDF.
 * @return string
 */
function sn_resume_page_body( $raw, $pdf = null ) {
	$cfg = sn_resume_variant_config( $raw, 'operations' );
	if ( null === $cfg ) {
		return sn_resume_body_html( sn_resume_doc_view( $raw, 'music' ) );
	}
	$doc = sn_resume_lane_doc( $raw, 'operations' );
	if ( '' === sn_resume_body_html( $doc ) ) {
		return ''; // the same "unusable document" rule as the single body.
	}
	if ( null === $pdf ) {
		$pdf = function_exists( 'sn_resume_ops_pdf_link' ) ? sn_resume_ops_pdf_link( $cfg['pdf_file'] ) : ''; // with its ?v=; '' before it exists (no Download to a 404).
	}
	$bands = sn_resume_lane_bands( $doc, sn_resume_lane_hero_blocks( $doc, $cfg['links'], $pdf ) );
	$keys  = sn_resume_lane_band_keys( $doc );
	$last  = end( $keys );
	if ( false !== $last ) {
		$close          = '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n"; // how every sn_resume_band() ends.
		$bands[ $last ] = substr( $bands[ $last ], 0, -strlen( $close ) ) . sn_resume_lane_formats() . $close;
	}
	return rtrim( sn_resume_lanes_head_blocks() . implode( '', $bands ) ) . "\n";
}

/**
 * The résumé's bands, keyed: its hero and stats, then each headed band its
 * document fills (SN_RESUME_LANE_BANDS), numbered as they fall, each h2 with
 * an anchor (#resume-experience). The document holds only the sections the
 * operations settings list (sn_resume_lane_doc()).
 *
 * @param array  $doc  The résumé's document.
 * @param string $hero Its serialized hero band.
 * @return array<string,string>
 */
function sn_resume_lane_bands( $doc, $hero ) {
	$build = array(
		'built'        => static fn( $n, $b ) => sn_resume_built_blocks( $doc['built'], $n, $b ),
		'experience'   => static fn( $n, $b ) => sn_resume_experience_blocks( $doc['experience'], $doc['earlier'], $n, $b ),
		'credentials'  => static fn( $n, $b ) => sn_resume_credentials_blocks( $doc['education'], $doc['affiliations'], $n, $b ),
		'publications' => static fn( $n, $b ) => sn_resume_publications_blocks( $doc['publications'], $n, $b ),
		'skills'       => static fn( $n, $b ) => sn_resume_skills_blocks( $doc['skills'], $n, $b ),
	);
	$out   = array( 'hero' => $hero, 'stats' => sn_resume_stats_blocks( $doc['stats'] ) );
	foreach ( sn_resume_lane_band_keys( $doc ) as $i => $key ) {
		$out[ $key ] = $build[ $key ]( $i + 1, array( 'id' => 'resume-' . $key ) );
	}
	return $out;
}

/**
 * The head: the eyebrow and the page title.
 *
 * @return string
 */
function sn_resume_lanes_head_blocks() {
	$title = sn_resume_para( 'sn-catalog-eyebrow', sn_resume_l( 'Dossier · Background', 'Dossier · Trayectoria' ) )
		. '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 7rem)","lineHeight":"0.95"}}} -->' . "\n"
		. '<h1 class="wp-block-heading" style="font-size:clamp(3rem, 8vw, 7rem);line-height:0.95">' . sn_resume_l( 'RESUME', 'CURRICULUM VITAE' ) . '</h1>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n";
	return sn_resume_band( '1320px', '60', '10', $title );
}

/**
 * The hero: the kicker (a small line over the headline), the headline (an h2,
 * under the page's h1), subhead and summary on the left; the credential
 * ledger, the contact rail, the verified-credits line (/music) and the
 * Download on the right.
 *
 * @param array    $doc   The résumé's document.
 * @param string[] $links Extra contact links for the rail.
 * @param string   $pdf   The PDF address ('' for no Download).
 * @return string
 */
function sn_resume_lane_hero_blocks( $doc, $links, $pdf ) {
	$left = '' !== (string) ( $doc['kicker'] ?? '' ) ? sn_resume_para( 'sn-catalog-eyebrow sn-resume-kicker', esc_html( $doc['kicker'] ) ) : '';
	if ( '' !== (string) ( $doc['pdf']['headline'] ?? '' ) ) {
		$left .= '<!-- wp:heading {"className":"sn-resume-headline"} -->' . "\n"
			. '<h2 class="wp-block-heading sn-resume-headline">' . esc_html( $doc['pdf']['headline'] ) . '</h2>' . "\n"
			. '<!-- /wp:heading -->' . "\n\n";
	}
	if ( '' !== (string) ( $doc['pdf']['tagline'] ?? '' ) ) {
		$left .= sn_resume_para( 'sn-resume-tagline', esc_html( $doc['pdf']['tagline'] ) );
	}
	$left .= sn_resume_summary_blocks( $doc['hero']['summary'] );
	$right = sn_resume_list( 'sn-resume-chips', array_map( 'esc_html', $doc['hero']['chips'] ) )
		. sn_resume_hero_rail_blocks( $doc['hero'], $links );
	$credits = sn_resume_credits_html( sn_resume_credits_url() );
	$right  .= ( '' !== $credits ? sn_resume_para( 'sn-resume-rail sn-resume-credits', $credits ) : '' )
		. sn_resume_download_blocks( $pdf, false );
	return sn_resume_band( '1320px', '30', '30', sn_resume_hero_split_blocks( $left, $right ) );
}

/**
 * "What I've Built" on the page: the intro, then a grid of one card per
 * project, its code as the receipt after its text (never inside the heading,
 * whose name is the title alone).
 *
 * @param array $built {intro,items[]{title,url,text}}.
 * @param int   $n     Section number.
 * @param array $band  {id?, only?} for its heading (sn_resume_section_head()).
 * @return string
 */
function sn_resume_built_blocks( $built, $n, $band = array() ) {
	$inner = sn_resume_section_head( sprintf( '%02d · ' . sn_resume_l( 'Projects', 'Proyectos' ), $n ), sn_resume_l( "WHAT I'VE BUILT", 'LO QUE CONSTRUÍ' ), $band );
	if ( '' !== (string) $built['intro'] ) {
		$inner .= sn_resume_para( 'sn-resume-built-intro', esc_html( $built['intro'] ) );
	}
	$cards = '';
	foreach ( $built['items'] as $item ) {
		$after  = ltrim( esc_html( (string) $item['text'] ) . sn_resume_receipt( $item['url'], 'code', $item['title'] ) );
		$cards .= '<!-- wp:group {"className":"sn-resume-pub sn-resume-built"} -->' . "\n"
			. '<div class="wp-block-group sn-resume-pub sn-resume-built">' . "\n"
			. '<!-- wp:heading {"level":3,"className":"sn-resume-pub-title"} -->' . "\n"
			. '<h3 class="wp-block-heading sn-resume-pub-title">' . esc_html( $item['title'] ) . '</h3>' . "\n"
			. '<!-- /wp:heading -->' . "\n"
			. ( '' !== $after ? sn_resume_para( '', $after ) : '' )
			. '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n";
	}
	$inner .= '<!-- wp:group {"className":"sn-resume-built-grid"} -->' . "\n"
		. '<div class="wp-block-group sn-resume-built-grid">' . "\n"
		. $cards
		. '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n";
	return sn_resume_band( '1320px', '40', '40', $inner );
}
