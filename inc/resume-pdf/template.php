<?php
/**
 * Signal & Noise Tools — the resume PDF template (docs/RESUME-PDF.md, Phase 2).
 *
 * ONE partial renders the resume document as a printable page. The generator
 * (inc/resume-pdf/generate.php) feeds it to Dompdf; nothing else builds the PDF,
 * so the file behind the Download link cannot drift from the data behind
 * /resume. The design is the owner's navy and gold rebrand (2026-09-22). Its
 * colors live HERE only: the web page and the browser print keep the theme
 * palette.
 *
 * Dompdf-safe on purpose: block flow and tables only, no flex or grid, one
 * column of reading order (ATS parsers read the text layer top to bottom).
 * Every string is escaped at this sink; bullets arrive kses-clean from
 * sn_resume_doc_normalize() and keep their <strong>/<em>/<a>.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Brand ink for the PDF only. */
const SN_RESUME_PDF_NAVY = '#1f3864';
const SN_RESUME_PDF_GOLD = '#c9a227';

/**
 * Split a role line "Title · Mon YYYY - Present" into [title, dates]. The web
 * form stores both in one field; the PDF sets them apart like the design.
 *
 * @param string $line Role title line.
 * @return array{0:string,1:string}
 */
function sn_resume_pdf_split_role( $line ) {
	$line = (string) $line;
	$pos  = strrpos( $line, ' · ' );
	if ( false === $pos ) {
		return array( $line, '' );
	}
	return array( trim( substr( $line, 0, $pos ) ), trim( substr( $line, $pos + strlen( ' · ' ) ) ) );
}

/**
 * The stylesheet: Lato (embedded from lib/pdf/fonts), Letter, navy and gold.
 *
 * @param string $font_dir Absolute path to the Lato files.
 * @return string
 */
function sn_resume_pdf_css( $font_dir ) {
	$n    = SN_RESUME_PDF_NAVY;
	$g    = SN_RESUME_PDF_GOLD;
	$face = '';
	foreach ( array( array( 'Regular', 'normal', 'normal' ), array( 'Bold', 'bold', 'normal' ), array( 'Italic', 'normal', 'italic' ), array( 'BoldItalic', 'bold', 'italic' ) ) as $f ) {
		$face .= '@font-face{font-family:"Lato";font-weight:' . $f[1] . ';font-style:' . $f[2] . ';src:url("' . $font_dir . '/Lato-' . $f[0] . '.ttf") format("truetype");}';
	}
	return $face . '
@page{size:letter;margin:0.45in 0.55in;}
body{font-family:"Lato",sans-serif;font-size:9pt;line-height:1.25;color:#222;}
h1{font-size:22pt;color:' . $n . ';text-align:center;margin:0;letter-spacing:0.5pt;}
.headline{font-size:10pt;font-weight:bold;color:' . $g . ';text-align:center;margin:2pt 0 0;}
.tagline{font-size:9.5pt;color:' . $n . ';text-align:center;margin:2pt 0 0;}
.contact{font-size:9pt;text-align:center;margin:2pt 0 6pt;}
.contact a{color:' . $n . ';}
h2{font-size:10.5pt;color:' . $n . ';border-bottom:1.5pt solid ' . $g . ';padding-bottom:1.5pt;margin:7pt 0 3pt;page-break-after:avoid;}
p{margin:0 0 3pt;text-align:justify;}
table{width:100%;border-collapse:collapse;}
.stats td{background:' . $n . ';color:#fff;text-align:center;padding:4pt 2pt;border:1.5pt solid #fff;}
.stats .n{font-size:13pt;font-weight:bold;color:' . $g . ';display:block;}
.stats .l{font-size:7.5pt;}
.comp td{width:33%;font-size:8.5pt;padding:1pt 0;vertical-align:top;}
.comp .dot{color:' . $g . ';font-family:"DejaVu Sans",sans-serif;} /* Lato has no U+25C6 */
.toolkit{text-align:left;}
.row td{padding:0;vertical-align:bottom;}
.org{font-weight:bold;color:' . $n . ';text-transform:uppercase;font-size:9.5pt;}
.loc{font-style:italic;text-align:right;font-size:9pt;}
.role{font-weight:bold;color:' . $g . ';font-size:9.5pt;}
.dates{font-weight:bold;color:' . $n . ';text-align:right;font-size:9pt;}
.entry{margin:4pt 0 0;}
.row{page-break-after:avoid;}
ul{margin:1pt 0 2pt;padding-left:12pt;list-style-type:none;}
li{margin:0 0 1pt;position:relative;page-break-inside:avoid;}
li:before{content:"\25AA";color:' . $g . ';position:absolute;left:-10pt;top:0;}
li strong,.lines strong{color:' . $n . ';}
.pub{margin:0 0 2pt;}
.pub a{color:' . $n . ';}
.pub .meta{font-style:italic;}
.lines{margin:0 0 1.5pt;}
.lines .t{font-weight:bold;color:' . $n . ';}
.sep{color:#666;}
';
}

/**
 * Render the resume document as one HTML page for Dompdf.
 *
 * @param array  $doc      Canonical document (sn_resume_doc_normalize()).
 * @param string $name     The person's name, for the header.
 * @param string $font_dir      Absolute path to the Lato files.
 * @param bool   $include_phone The private copy always; the public file only
 *                              when pdf.phone_public is on (owner, 2026-09-22:
 *                              off by default, controlled from the resume editor).
 * @return string
 */
function sn_resume_pdf_html( $doc, $name, $font_dir, $include_phone = false ) {
	$hero = (array) ( $doc['hero'] ?? array() );
	$pdf  = (array) ( $doc['pdf'] ?? array() );
	$e    = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	$sep  = ' <span class="sep">|</span> ';
	$out  = '<h1>' . $e( strtoupper( (string) $name ) ) . '</h1>';

	if ( '' !== (string) ( $pdf['headline'] ?? '' ) ) {
		$out .= '<p class="headline">' . $e( strtoupper( $pdf['headline'] ) ) . '</p>';
	}
	if ( '' !== (string) ( $pdf['tagline'] ?? '' ) ) {
		$out .= '<p class="tagline">' . $e( $pdf['tagline'] ) . '</p>';
	}
	$contact = array();
	foreach ( $include_phone ? array( 'location', 'phone' ) : array( 'location' ) as $k ) {
		if ( '' !== (string) ( $pdf[ $k ] ?? '' ) ) {
			$contact[] = $e( $pdf[ $k ] );
		}
	}
	if ( '' !== (string) ( $pdf['email'] ?? '' ) ) {
		$contact[] = '<a href="mailto:' . $e( $pdf['email'] ) . '">' . $e( $pdf['email'] ) . '</a>';
	}
	if ( '' !== (string) ( $hero['linkedin'] ?? '' ) ) {
		$contact[] = '<a href="' . $e( $hero['linkedin'] ) . '">' . $e( preg_replace( '~^https?://(www\.)?~i', '', (string) $hero['linkedin'] ) ) . '</a>';
	}
	if ( $contact ) {
		$out .= '<p class="contact">' . implode( ' &#8226; ', $contact ) . '</p>';
	}

	if ( '' !== (string) ( $hero['summary'] ?? '' ) ) {
		$out .= '<h2>PROFESSIONAL SUMMARY</h2><p>' . $e( $hero['summary'] ) . '</p>';
	}

	$stats = (array) ( $doc['stats'] ?? array() );
	if ( $stats ) {
		$out .= '<table class="stats"><tr>';
		foreach ( $stats as $s ) {
			$out .= '<td><span class="n">' . $e( $s['n'] ?? '' ) . '</span><span class="l">' . $e( $s['label'] ?? '' ) . '</span></td>';
		}
		$out .= '</tr></table>';
	}

	$comp = (array) ( $pdf['competencies'] ?? array() );
	if ( $comp ) {
		$out .= '<h2>CORE COMPETENCIES</h2><table class="comp">';
		foreach ( array_chunk( $comp, 3 ) as $row ) {
			$out .= '<tr>';
			foreach ( array_pad( $row, 3, '' ) as $c ) {
				$out .= '<td>' . ( '' !== $c ? '<span class="dot">&#9670;</span> ' . $e( $c ) : '' ) . '</td>';
			}
			$out .= '</tr>';
		}
		$out .= '</table>';
	}

	$entries = array_merge( (array) ( $doc['experience'] ?? array() ), (array) ( $doc['earlier']['entries'] ?? array() ) );
	if ( $entries ) {
		$out .= '<h2>PROFESSIONAL EXPERIENCE</h2>';
		foreach ( $entries as $entry ) {
			$org = (string) ( $entry['org'] ?? '' );
			$loc = (string) ( $entry['location'] ?? '' );
			// Earlier-career entries have no location field; the form's
			// convention is "ORG · City, Country" in the org line.
			if ( '' === $loc && false !== strpos( $org, ' · ' ) ) {
				list( $org, $loc ) = array_map( 'trim', explode( ' · ', $org, 2 ) );
			}
			$out .= '<div class="entry"><table class="row"><tr><td class="org">' . $e( $org ) . '</td><td class="loc">' . $e( $loc === strtoupper( $loc ) ? ucwords( strtolower( $loc ) ) : $loc ) . '</td></tr></table>';
			foreach ( (array) ( $entry['roles'] ?? array() ) as $role ) {
				list( $title, $dates ) = sn_resume_pdf_split_role( $role['title'] ?? '' );
				$out .= '<table class="row"><tr><td class="role">' . $e( $title ) . '</td><td class="dates">' . $e( $dates ) . '</td></tr></table>';
				$bullets = (array) ( $role['bullets'] ?? array() );
				if ( $bullets ) {
					$out .= '<ul><li>' . implode( '</li><li>', $bullets ) . '</li></ul>'; // kses-clean at normalize.
				}
			}
			$out .= '</div>';
		}
	}

	$pubs = (array) ( $doc['publications'] ?? array() );
	if ( $pubs ) {
		$out .= '<h2>RESEARCH &amp; PUBLICATIONS</h2><ul>';
		foreach ( $pubs as $p ) {
			$t     = '' !== (string) ( $p['url'] ?? '' ) ? '<a href="' . $e( $p['url'] ) . '">' . $e( $p['title'] ?? '' ) . '</a>' : $e( $p['title'] ?? '' );
			$out  .= '<li class="pub">' . $t . ( '' !== (string) ( $p['meta'] ?? '' ) ? ' &#8212; <span class="meta">' . $e( $p['meta'] ) . '</span>' : '' ) . '</li>';
		}
		$out .= '</ul>';
	}

	foreach ( array( 'education' => 'EDUCATION', 'affiliations' => 'AFFILIATIONS &amp; CERTIFICATIONS' ) as $key => $head ) {
		$rows = (array) ( $doc[ $key ] ?? array() );
		if ( ! $rows ) {
			continue;
		}
		$out .= '<h2>' . $head . '</h2>';
		foreach ( $rows as $r ) {
			$bits = array( '<span class="t">' . $e( $r['title'] ?? '' ) . '</span>' );
			foreach ( (array) ( $r['lines'] ?? array() ) as $l ) {
				$bits[] = $e( $l );
			}
			$out .= '<p class="lines">' . implode( $sep, $bits ) . '</p>';
		}
	}

	$toolkit = (array) ( $pdf['toolkit'] ?? array() );
	if ( $toolkit ) {
		$out .= '<h2>TECHNICAL TOOLKIT</h2><p class="toolkit">' . implode( ' &#8226; ', array_map( $e, $toolkit ) ) . '</p>';
	}

	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $e( $name ) . ' — Resume</title><style>'
		. sn_resume_pdf_css( $font_dir ) . '</style></head><body>' . $out . '</body></html>';
}
