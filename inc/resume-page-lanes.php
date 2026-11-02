<?php
/**
 * Signal & Noise Tools: the two-lane /resume body (owner brief 2026-11-01 #2).
 *
 * Once the operations résumé is set up, the English /resume carries both
 * résumés: a shared head (eyebrow, title, the lane switch), then the music
 * lane and the operations lane, each a full set of bands built from its own
 * view of the document. Both lanes are in the stored body, so the page's text
 * is the same whichever lane shows: a signed page stays one record, and
 * /resume/ is the canonical of both. `?lane=` only chooses which lane is hidden
 * at render time (inc/resume-page-lane-render.php); the theme's script
 * switches without a reload. Each lane has its own Download, so the download
 * follows the lane without script. Spanish (/cv), and a document with no
 * operations settings, keep the single body (sn_resume_body_html()).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-pdf/template-variant.php';

/** The switch's labels, in order. "Operations & AI Strategy" is the /services door's name. */
const SN_RESUME_LANE_LABELS  = array( 'music' => 'Music', 'operations' => 'Operations & AI Strategy' );
const SN_RESUME_LANE_CAPTION = 'Two doors, one standard.'; // verbatim from /services.

/**
 * A lane name from untrusted input: 'operations', else 'music'.
 *
 * @param mixed $v
 * @return string
 */
function sn_resume_lane_param( $v ) {
	return 'operations' === $v ? 'operations' : 'music';
}

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
 * The /resume body for a stored document: two lanes when the operations
 * résumé exists and the page is English, else the single body.
 *
 * @param array|null $raw Stored document.
 * @return string
 */
function sn_resume_page_body( $raw ) {
	$cfg = sn_resume_variant_config( $raw, 'operations' );
	if ( 'en' !== sn_resume_lang() || null === $cfg ) {
		return sn_resume_body_html( sn_resume_doc_view( $raw, 'music' ) );
	}
	$music = sn_resume_lane_doc( $raw, 'music' );
	if ( '' === sn_resume_body_html( $music ) ) {
		return ''; // the same "unusable document" rule as the single body.
	}
	$ops      = sn_resume_lane_doc( $raw, 'operations' );
	$ops_pdf  = function_exists( 'sn_resume_ops_pdf_live' ) && sn_resume_ops_pdf_live() ? sn_resume_ops_pdf_url( $cfg['pdf_file'] ) : ''; // no Download to a 404.
	$music_in = sn_resume_lane_hero_blocks( $music, array(), $music['hero']['pdf_url'], true )
		. sn_resume_stats_blocks( $music['stats'] )
		. sn_resume_experience_blocks( $music['experience'], $music['earlier'] )
		. sn_resume_credentials_blocks( $music['education'], $music['affiliations'] )
		. sn_resume_publications_blocks( $music['publications'] )
		. sn_resume_skills_blocks( $music['skills'] );
	return rtrim(
		sn_resume_lanes_head_blocks()
		. sn_resume_lane_group( 'music', $music_in )
		. sn_resume_lane_group( 'operations', sn_resume_ops_lane_blocks( $ops, $cfg['links'], $ops_pdf ) )
	) . "\n";
}

/**
 * The operations lane's bands, numbered as they fall. Its document holds only
 * the sections the operations résumé lists (sn_resume_lane_doc()).
 *
 * @param array    $doc   The operations lane's document.
 * @param string[] $links The operations résumé's extra contact links.
 * @param string   $pdf   The operations PDF's address.
 * @return string
 */
function sn_resume_ops_lane_blocks( $doc, $links, $pdf ) {
	$n   = 0;
	$out = sn_resume_lane_hero_blocks( $doc, $links, $pdf, false ) . sn_resume_stats_blocks( $doc['stats'] );
	if ( $doc['built']['items'] ) {
		$out .= sn_resume_built_blocks( $doc['built'], ++$n );
	}
	if ( $doc['experience'] || $doc['earlier']['entries'] ) {
		$out .= sn_resume_experience_blocks( $doc['experience'], $doc['earlier'], ++$n );
	}
	if ( $doc['education'] || $doc['affiliations'] ) {
		$out .= sn_resume_credentials_blocks( $doc['education'], $doc['affiliations'], ++$n );
	}
	if ( $doc['publications'] ) {
		$out .= sn_resume_publications_blocks( $doc['publications'], ++$n );
	}
	if ( $doc['skills'] ) {
		$out .= sn_resume_skills_blocks( $doc['skills'], ++$n );
	}
	return $out;
}

/** The shared head: eyebrow, the page title, the lane switch. @return string */
function sn_resume_lanes_head_blocks() {
	$base  = '/' . ( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' ) . '/';
	$items = array();
	foreach ( SN_RESUME_LANE_LABELS as $lane => $label ) {
		$href    = 'music' === $lane ? $base : $base . '?lane=' . $lane;
		$items[] = '<a href="' . esc_url( $href ) . '" data-lane="' . $lane . '" data-sn-goal="resume_lane_' . $lane . '">' . esc_html( $label ) . '</a>';
	}
	$inner = sn_resume_para( 'sn-catalog-eyebrow', sn_resume_l( 'Dossier · Background', 'Dossier · Trayectoria' ) )
		. '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 7rem)","lineHeight":"0.95"}}} -->' . "\n"
		. '<h1 class="wp-block-heading" style="font-size:clamp(3rem, 8vw, 7rem);line-height:0.95">' . sn_resume_l( 'RESUME', 'CURRICULUM VITAE' ) . '</h1>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n"
		. '<!-- wp:group {"tagName":"nav","className":"sn-resume-switch"} -->' . "\n"
		. '<nav class="wp-block-group sn-resume-switch">' . sn_resume_list( 'sn-resume-switch-list', $items )
		. sn_resume_para( 'sn-resume-switch-caption', esc_html( SN_RESUME_LANE_CAPTION ) )
		. '</nav>' . "\n" . '<!-- /wp:group -->' . "\n\n";
	return sn_resume_band( '1320px', '60', '10', $inner );
}

/**
 * One lane: a plain group the render filter can hide. The id is the HTML
 * anchor, so it lives in the markup only.
 *
 * @param string $lane
 * @param string $inner Serialized bands.
 * @return string
 */
function sn_resume_lane_group( $lane, $inner ) {
	$class = 'sn-resume-variant sn-resume-variant--' . $lane;
	return '<!-- wp:group {"className":"' . $class . '"} -->' . "\n"
		. '<div id="resume-' . $lane . '" class="wp-block-group ' . $class . '">' . "\n"
		. $inner
		. '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n";
}

/**
 * A lane's hero: headline, subhead and summary on the left; the credential
 * ledger, the contact rail and that lane's Download on the right.
 *
 * @param array    $doc     The lane's document.
 * @param string[] $links   Extra contact links for the rail.
 * @param string   $pdf     The lane's PDF address.
 * @param bool     $resolve Whether the generated music PDF replaces $pdf.
 * @return string
 */
function sn_resume_lane_hero_blocks( $doc, $links, $pdf, $resolve ) {
	$left = '';
	foreach ( array( 'headline' => 'sn-resume-headline', 'tagline' => 'sn-resume-tagline' ) as $key => $class ) {
		if ( '' !== (string) ( $doc['pdf'][ $key ] ?? '' ) ) {
			$left .= sn_resume_para( $class, esc_html( $doc['pdf'][ $key ] ) );
		}
	}
	$left .= sn_resume_summary_blocks( $doc['hero']['summary'] );
	$right = sn_resume_list( 'sn-resume-chips', array_map( 'esc_html', $doc['hero']['chips'] ) )
		. sn_resume_hero_rail_blocks( $doc['hero'], $links )
		. sn_resume_download_blocks( $pdf, $resolve );
	return sn_resume_band( '1320px', '30', '30', sn_resume_hero_split_blocks( $left, $right ) );
}

/**
 * "What I've Built" on the page: the intro, then one card per project with
 * its code as the receipt.
 *
 * @param array $built {intro,items[]{title,url,text}}.
 * @param int   $n     Section number.
 * @return string
 */
function sn_resume_built_blocks( $built, $n ) {
	$inner = sn_resume_section_head( sprintf( '%02d · Projects', $n ), "WHAT I'VE BUILT" );
	if ( '' !== (string) $built['intro'] ) {
		$inner .= sn_resume_para( 'sn-resume-built-intro', esc_html( $built['intro'] ) );
	}
	foreach ( $built['items'] as $item ) {
		$inner .= '<!-- wp:group {"className":"sn-resume-pub sn-resume-built"} -->' . "\n"
			. '<div class="wp-block-group sn-resume-pub sn-resume-built">' . "\n"
			. '<!-- wp:heading {"level":3,"className":"sn-resume-pub-title"} -->' . "\n"
			. '<h3 class="wp-block-heading sn-resume-pub-title">' . esc_html( $item['title'] ) . sn_resume_receipt( $item['url'], 'code', $item['title'] ) . '</h3>' . "\n"
			. '<!-- /wp:heading -->' . "\n"
			. ( '' !== (string) $item['text'] ? sn_resume_para( '', esc_html( $item['text'] ) ) : '' )
			. '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n";
	}
	return sn_resume_band( '1320px', '40', '40', $inner );
}
