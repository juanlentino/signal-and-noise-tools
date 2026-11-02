<?php
/**
 * Signal & Noise Tools: the résumé as purpose-built Markdown.
 *
 * Written for AI readers and applicant-tracking parsers, not converted from
 * the page HTML: no site chrome, no icon glyphs, no tables. Everything but the
 * stats strip comes from the JSON Resume document (inc/resume-jsonresume.php),
 * so the two formats cannot disagree on an employer or a date. Dates are
 * ISO 8601 (2022-09), unambiguous to a parser in either language. YAML front
 * matter carries identity, the public contact, every format and the
 * authorship proof. Served by inc/resume-formats-route.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Headings and fixed words in the résumé's language.
 *
 * @param string $lang 'en' or 'es'.
 * @return array<string,string>
 */
function sn_resume_markdown_words( $lang ) {
	$es = 'es' === $lang;
	return array(
		'summary'  => $es ? 'Resumen' : 'Summary',
		'built'    => $es ? 'Proyectos' : "What I've Built",
		'stats'    => $es ? 'Datos destacados' : 'Highlights',
		'work'     => $es ? 'Experiencia' : 'Experience',
		'pubs'     => $es ? 'Investigación y publicaciones' : 'Research & Publications',
		'edu'      => $es ? 'Formación' : 'Education',
		'creds'    => $es ? 'Membresías y credenciales' : 'Memberships & Credentials',
		'skills'   => $es ? 'Habilidades' : 'Skills',
		'langs'    => $es ? 'Idiomas' : 'Languages',
		'verify'   => $es ? 'Verificar:' : 'Verify:',
		'to'       => $es ? 'a' : 'to',
		'present'  => $es ? 'actualidad' : 'present',
		'meaning'  => $es
			? 'Esta página lleva una prueba de autoría anclada en Bitcoin; el enlace verify_url la comprueba en el navegador.'
			: 'This page carries a Bitcoin-anchored authorship proof; verify_url checks it in the browser.',
	);
}

/** A YAML double-quoted scalar (JSON strings are valid YAML). @param string $s @return string */
function sn_resume_markdown_yaml( $s ) {
	return (string) json_encode( (string) $s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * The YAML front matter.
 *
 * @param array               $resume  JSON Resume document.
 * @param array<string,string> $formats Format name to URL.
 * @param array<string,string> $w       Words.
 * @return array<int,string> Lines.
 */
function sn_resume_markdown_front( array $resume, array $formats, array $w ) {
	$b    = $resume['basics'] ?? array();
	$meta = $resume['meta'] ?? array();
	$y    = 'sn_resume_markdown_yaml';
	$out  = array( '---', 'name: ' . $y( $b['name'] ?? '' ) );
	if ( isset( $b['label'] ) ) {
		$out[] = 'label: ' . $y( $b['label'] );
	}
	if ( isset( $b['location'] ) ) {
		$out[] = 'location: ' . $y( implode( ', ', $b['location'] ) );
	}
	$out[] = 'url: ' . $y( $meta['canonical'] ?? '' );
	$out[] = 'updated: ' . $y( $meta['lastModified'] ?? '' );
	foreach ( array( 'email', 'phone' ) as $k ) {
		if ( isset( $b[ $k ] ) ) {
			$out[] = "$k: " . $y( $b[ $k ] );
		}
	}
	$out[] = 'profiles:';
	foreach ( $b['profiles'] ?? array() as $p ) {
		$out[] = '  - ' . $y( $p['url'] );
	}
	$out[] = 'formats:';
	foreach ( $formats as $k => $u ) {
		$out[] = "  $k: " . $y( $u );
	}
	if ( isset( $meta['x-provenance'] ) ) {
		$out[] = 'provenance:';
		$out[] = '  verify_url: ' . $y( $meta['x-provenance']['verify_url'] );
		$out[] = '  note_uid: ' . $y( $meta['x-provenance']['note_uid'] );
		$out[] = '  meaning: ' . $y( $w['meaning'] );
	}
	$out[] = '---';
	return $out;
}

/** "2016-01 to 2021-04" / "2022-09 to present". @param array $item @param array $w @return string */
function sn_resume_markdown_span( array $item, array $w ) {
	return ( $item['startDate'] ?? '' ) . ' ' . $w['to'] . ' ' . ( $item['endDate'] ?? $w['present'] );
}

/**
 * The résumé as Markdown.
 *
 * @param array                $doc     Normalized résumé document (stats only).
 * @param array                $resume  sn_jsonresume_build() output for it.
 * @param array<string,string> $formats Format name to URL.
 * @param string               $lang    'en' or 'es'.
 * @return string
 */
function sn_resume_markdown( array $doc, array $resume, array $formats, $lang = 'en' ) {
	$w   = sn_resume_markdown_words( $lang );
	// The issuer's verification page, indented under its list item.
	$verify = static fn( $u ) => '' !== (string) $u ? array( '  ' . $w['verify'] . ' ' . $u ) : array();
	$b   = $resume['basics'] ?? array();
	$out = sn_resume_markdown_front( $resume, $formats, $w );
	$out = array_merge( $out, array( '', '# ' . ( $b['name'] ?? '' ) ), isset( $b['label'] ) ? array( '', $b['label'] ) : array() );
	$out = array_merge( $out, array( '', '## ' . $w['summary'], '', (string) ( $b['summary'] ?? '' ) ) );
	// A section with nothing in it is left out: the operations résumé (/resume.md?lane=operations)
	// has no highlights or publications, and its projects lead.
	if ( ! empty( $doc['stats'] ) ) {
		$out = array_merge( $out, array( '', '## ' . $w['stats'], '' ) );
		foreach ( $doc['stats'] as $s ) {
			$out[] = '- **' . $s['n'] . '** ' . $s['label'];
		}
	}
	if ( ! empty( $resume['projects'] ) ) {
		$out = array_merge( $out, array( '', '## ' . $w['built'], '' ) );
		foreach ( $resume['projects'] as $p ) {
			$name  = isset( $p['url'] ) ? '[' . $p['name'] . '](' . $p['url'] . ')' : $p['name'];
			$out[] = '- **' . $name . '**' . ( isset( $p['description'] ) ? ': ' . $p['description'] : '' );
		}
	}
	$out  = array_merge( $out, array( '', '## ' . $w['work'] ) );
	$last = null;
	foreach ( $resume['work'] ?? array() as $job ) {
		if ( $job['name'] !== $last ) {
			$out[] = '';
			$out[] = '### ' . $job['name'];
			$last  = $job['name'];
		}
		$out[] = '';
		$out[] = '**' . $job['position'] . '** · ' . sn_resume_markdown_span( $job, $w ) . ( isset( $job['location'] ) ? ' · ' . $job['location'] : '' );
		$out[] = '';
		foreach ( $job['highlights'] ?? array() as $h ) {
			$out[] = '- ' . $h;
		}
	}
	$out = array_merge( $out, empty( $resume['publications'] ) ? array() : array( '', '## ' . $w['pubs'], '' ) );
	foreach ( $resume['publications'] ?? array() as $p ) {
		$title = isset( $p['url'] ) ? '[' . $p['name'] . '](' . $p['url'] . ')' : $p['name'];
		$out[] = '- ' . $title . '. ' . implode( ' · ', array_filter( array( $p['publisher'] ?? '', $p['releaseDate'] ?? '', $p['summary'] ?? '' ) ) );
	}
	$out = array_merge( $out, array( '', '## ' . $w['edu'], '' ) );
	foreach ( $resume['education'] ?? array() as $e ) {
		$out[] = '- **' . implode( ', ', array_filter( array( $e['studyType'] ?? '', $e['area'] ?? '' ) ) ) . '** · ' . implode( ' · ', array_filter( array( $e['institution'] ?? '', $e['endDate'] ?? '' ) ) );
		$out   = array_merge( $out, $verify( $e['x-credentialUrl'] ?? '' ) );
	}
	$out = array_merge( $out, array( '', '## ' . $w['creds'], '' ) );
	foreach ( $resume['awards'] ?? array() as $a ) {
		// A membership's summary is its "start - end" line (sn_jsonresume_affiliations()).
		list( $start, $end ) = sn_jsonresume_range( (string) ( $a['summary'] ?? '' ) );
		$when  = '' !== $start ? sn_resume_markdown_span( array_filter( array( 'startDate' => $start, 'endDate' => $end ) ), $w ) : ( $a['date'] ?? '' );
		$by    = isset( $a['awarder'] ) && false === strpos( $a['title'], $a['awarder'] ) ? $a['awarder'] : '';
		$out[] = '- ' . implode( ' · ', array_filter( array( $a['title'], $by, $when ) ) );
		$out   = array_merge( $out, $verify( $a['x-credentialUrl'] ?? '' ) );
	}
	foreach ( $resume['certificates'] ?? array() as $c ) {
		$out[] = '- ' . $c['name'];
		$out   = array_merge( $out, $verify( $c['url'] ?? '' ) );
	}
	$out = array_merge( $out, array( '', '## ' . $w['skills'], '' ) );
	foreach ( $resume['skills'] ?? array() as $s ) {
		$out[] = '- **' . $s['name'] . ':** ' . implode( ', ', $s['keywords'] );
	}
	$out = array_merge( $out, array( '', '## ' . $w['langs'], '' ) );
	foreach ( $resume['languages'] ?? array() as $l ) {
		$out[] = '- ' . $l['language'] . ( isset( $l['fluency'] ) ? ' (' . $l['fluency'] . ')' : '' );
	}
	return implode( "\n", $out ) . "\n";
}
