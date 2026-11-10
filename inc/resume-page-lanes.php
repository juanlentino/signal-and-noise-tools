<?php
/**
 * Signal & Noise Tools: the two-lane /resume body (owner brief 2026-11-01 #2).
 *
 * Once the operations résumé is set up, the English /resume carries both
 * résumés: a shared head (eyebrow, title, the lane switch), then the music
 * lane and the operations lane, each a full set of bands built from its own
 * view of the document. Both lanes are in the stored body, so the page's text
 * is the same whichever lane shows: if /resume is ever signed, it stays one
 * record. Each lane has its own address all the same: /resume/?lane=operations
 * is that lane's canonical (inc/resume-lane-address.php). `?lane=` only chooses which lane is hidden
 * at render time (inc/resume-page-lane-render.php); each lane is
 * a page load (no script). Each lane has its own Download, so the download
 * follows the lane without script. Spanish (/cv), and a document with no
 * operations settings, keep the single body (sn_resume_body_html()).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-pdf/template-variant.php';
require_once __DIR__ . '/resume-lane-marks.php';

/** The switch's labels, in order. "Operations & AI Strategy" names the work, the /services/consulting/ door. */
const SN_RESUME_LANE_LABELS  = array( 'music' => 'Music', 'operations' => 'Operations & AI Strategy' );
const SN_RESUME_LANE_CAPTION = 'Two doors, one standard.'; // verbatim from /services/music/.

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
	$ops     = sn_resume_lane_doc( $raw, 'operations' );
	$ops_pdf = function_exists( 'sn_resume_ops_pdf_link' ) ? sn_resume_ops_pdf_link( $cfg['pdf_file'] ) : ''; // with its ?v=; '' before it exists (no Download to a 404).
	$m_keys  = sn_resume_lane_band_keys( $music );
	$o_keys  = sn_resume_lane_band_keys( $ops );
	list( $music, $ops ) = sn_resume_lane_marks( $music, $ops );
	$m_bands = sn_resume_lane_bands( 'music', $music, array_diff( $m_keys, $o_keys ), sn_resume_lane_hero_blocks( $music, array(), $music['hero']['pdf_url'], true ), false );
	$o_bands = sn_resume_lane_bands( 'operations', $ops, array_diff( $o_keys, $m_keys ), sn_resume_lane_hero_blocks( $ops, $cfg['links'], $ops_pdf, false ), true );
	$lanes   = sn_resume_lane_group( 'music', sn_resume_lane_join( 'music', $m_bands, $o_bands ) )
		. sn_resume_lane_group( 'operations', sn_resume_lane_join( 'operations', $o_bands, $m_bands ) );
	return rtrim( sn_resume_lanes_head_blocks( false !== strpos( $lanes, 'sn-resume-only' ) ) . $lanes ) . "\n";
}

/**
 * A lane's bands, keyed: its hero and stats, then each headed band its
 * document fills (SN_RESUME_LANE_BANDS), each h2 with a lane-prefixed anchor.
 * The operations lane numbers its bands as they fall; the music lane keeps the
 * music résumé's numbers. The operations document holds only the sections the
 * operations résumé lists (sn_resume_lane_doc()).
 *
 * @param string   $lane     'music' or 'operations'.
 * @param array    $doc      The lane's document, marked (sn_resume_lane_marks()).
 * @param string[] $only     The band keys only this lane has.
 * @param string   $hero     The lane's serialized hero band.
 * @param bool     $numbered Whether to number the bands as they fall.
 * @return array<string,string>
 */
function sn_resume_lane_bands( $lane, $doc, $only, $hero, $numbered ) {
	$build = array(
		'built'        => static fn( $n, $b ) => sn_resume_built_blocks( $doc['built'], $n, $b ),
		'experience'   => static fn( $n, $b ) => sn_resume_experience_blocks( $doc['experience'], $doc['earlier'], $n, $b ),
		'credentials'  => static fn( $n, $b ) => sn_resume_credentials_blocks( $doc['education'], $doc['affiliations'], $n, $b ),
		'publications' => static fn( $n, $b ) => sn_resume_publications_blocks( $doc['publications'], $n, $b ),
		'skills'       => static fn( $n, $b ) => sn_resume_skills_blocks( $doc['skills'], $n, $b ),
	);
	$out   = array( 'hero' => $hero, 'stats' => sn_resume_stats_blocks( $doc['stats'] ) );
	foreach ( sn_resume_lane_band_keys( $doc ) as $i => $key ) {
		$out[ $key ] = $build[ $key ]( $numbered ? $i + 1 : null, array( 'id' => 'resume-' . $lane . '-' . $key, 'only' => in_array( $key, $only, true ) ) );
	}
	return $out;
}

/**
 * A lane's address: /resume/ for music, /resume/?lane=operations.
 *
 * @param string $lane
 * @return string
 */
function sn_resume_lane_url( $lane ) {
	$base = '/' . ( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' ) . '/';
	return 'music' === $lane ? $base : $base . '?lane=' . $lane;
}

/**
 * The shared head: the eyebrow and the page title on the left, the lane switch
 * beside them on the right, both sitting on the band's baseline, and under the
 * switch, when an entry is marked, the legend for the mark.
 *
 * @param bool $legend Whether any entry carries the lane-only mark.
 * @return string
 */
function sn_resume_lanes_head_blocks( $legend = false ) {
	$items = array();
	foreach ( SN_RESUME_LANE_LABELS as $lane => $label ) {
		$items[] = '<a href="' . esc_url( sn_resume_lane_url( $lane ) ) . '" data-lane="' . $lane . '" data-sn-goal="resume_lane_' . $lane . '">' . esc_html( $label ) . '</a>';
	}
	$title  = sn_resume_para( 'sn-catalog-eyebrow', sn_resume_l( 'Dossier · Background', 'Dossier · Trayectoria' ) )
		. '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 7rem)","lineHeight":"0.95"}}} -->' . "\n"
		. '<h1 class="wp-block-heading" style="font-size:clamp(3rem, 8vw, 7rem);line-height:0.95">' . sn_resume_l( 'RESUME', 'CURRICULUM VITAE' ) . '</h1>' . "\n"
		. '<!-- /wp:heading -->' . "\n\n";
	$switch = '<!-- wp:group {"tagName":"nav","className":"sn-resume-switch"} -->' . "\n"
		. '<nav class="wp-block-group sn-resume-switch">' . sn_resume_list( 'sn-resume-switch-list', $items )
		. sn_resume_para( 'sn-resume-switch-caption', esc_html( SN_RESUME_LANE_CAPTION ) )
		. '</nav>' . "\n" . '<!-- /wp:group -->' . "\n\n"
		. ( $legend ? sn_resume_para( 'sn-resume-legend', 'A bar beside an entry: only in this version.' ) : '' );
	// Its own class: sn-resume-hero-split is the hero's, which the page contract keys on.
	return sn_resume_band( '1320px', '60', '10', sn_resume_hero_split_blocks( $title, $switch, 'bottom', 'sn-resume-head-split' ) );
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
	// Core's serializer spells the "--" in the comment's JSON as a JSON escape (never rendered), so a parse and re-save gives the body back byte for byte.
	return sn_resume_block( 'group', array( 'className' => $class ), '<div id="resume-' . $lane . '" class="wp-block-group ' . $class . '">' . "\n" . $inner . '</div>' ) . "\n\n";
}

/**
 * A lane's hero: headline (an h2, under the page's h1), subhead and summary on
 * the left; the credential ledger, the contact rail and that lane's Download on
 * the right.
 *
 * @param array    $doc     The lane's document.
 * @param string[] $links   Extra contact links for the rail.
 * @param string   $pdf     The lane's PDF address.
 * @param bool     $resolve Whether the generated music PDF replaces $pdf.
 * @return string
 */
function sn_resume_lane_hero_blocks( $doc, $links, $pdf, $resolve ) {
	$left = '';
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
		. sn_resume_hero_rail_blocks( $doc['hero'], $links )
		. sn_resume_download_blocks( $pdf, $resolve );
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
	$inner = sn_resume_section_head( sprintf( '%02d · Projects', $n ), "WHAT I'VE BUILT", $band );
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
