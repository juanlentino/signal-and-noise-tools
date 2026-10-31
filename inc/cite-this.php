<?php
/**
 * Signal & Noise Tools: Cite this note. The reader's citation (APA and
 * BibTeX) and the Google Scholar head meta for a single note.
 *
 * One builder, read by the theme's cite line (signal-noise/note-cite) and by
 * the head meta below. Its inputs are the Article's own (inc/seo-schema-pages-
 * parts.php): the title, the publish time, dateModified through
 * sn_schema_date_modified() (the provenance commit, so the date is the
 * version's), and the production DOI through sn_zenodo_doi_for(). The version
 * is the chain head, sn_zenodo_head_commit(). The bridge's get-citation reads
 * the same Article from the page; its escape and key are ported here byte for
 * byte (tests/cite-this.php pins both against that module's output).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BibTeX-escape a field value. A port of the bridge's snBibEscape: one pass
 * over one character class, braces dropped, the backslash and the specials
 * escaped, tilde and caret as their text forms.
 *
 * @param string $v Value.
 * @return string
 */
function sn_cite_this_bib_escape( $v ) {
	$map = array( '\\' => '\\textbackslash{}', '{' => '', '}' => '', '&' => '\\&', '%' => '\\%', '$' => '\\$', '#' => '\\#', '_' => '\\_', '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}' );
	return (string) preg_replace_callback( '/[\\\\{}&%$#_~^]/', static fn( $m ) => $map[ $m[0] ], (string) $v );
}

/**
 * The BibTeX key, a port of the bridge's snCiteKey: surname, publish year,
 * the first title word that is not a stopword.
 *
 * @param string $year  Publish year.
 * @param string $title Title.
 * @return string
 */
function sn_cite_this_key( $year, $title ) {
	$stop  = array( 'a', 'an', 'the', 'on', 'of', 'in', 'and', 'to', 'is', 'not' );
	$words = preg_split( '/\s+/', (string) preg_replace( '/[^a-z0-9 ]/', '', strtolower( (string) $title ) ) );
	$words = array_values( array_filter( (array) $words, static fn( $w ) => '' !== $w && ! in_array( $w, $stop, true ) ) );
	return 'lentino' . $year . ( $words[0] ?? 'note' );
}

/**
 * The citation of a note as read now: pinned to the chain head's version and
 * that version's date (UTC, as the Article and Zenodo date it). null when the
 * post does not exist.
 *
 * @param int $post_id Post ID.
 * @return array{title:string,url:string,doi:string,doi_url:string,version:int,date:string,published:string,apa:string,bibtex:string}|null
 */
function sn_cite_this_for( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return null;
	}
	$title     = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	$url       = (string) get_permalink( $post );
	$published = (string) get_post_time( 'Y-m-d', true, $post );
	$ts        = (int) strtotime( sn_schema_date_modified( $post ) );
	$head      = sn_zenodo_head_commit( (int) $post->ID );
	$version   = (int) ( $head['version'] ?? 0 );
	$doi       = sn_zenodo_doi_for( (int) $post->ID );
	$doi_url   = '' === $doi ? '' : 'https://doi.org/' . $doi;

	$apa = 'Lentino, J. (' . gmdate( 'Y, F j', $ts ) . '). ' . $title . ( $version > 0 ? ' (Version ' . $version . ')' : '' ) . '. Signal & Noise. ' . ( '' === $doi ? $url : $doi_url );

	$bib = array(
		'@online{' . sn_cite_this_key( substr( $published, 0, 4 ), $title ) . ',',
		'  author = {Lentino, Juan},',
		'  title = {' . sn_cite_this_bib_escape( $title ) . '},',
		'  organization = {Signal \\& Noise},',
		'  date = {' . gmdate( 'Y-m-d', $ts ) . '},',
		'  year = {' . gmdate( 'Y', $ts ) . '},',
		'  month = {' . gmdate( 'm', $ts ) . '},',
	);
	if ( $version > 0 ) {
		$bib[] = '  version = {' . $version . '},';
	}
	if ( '' !== $doi ) {
		$bib[] = '  doi = {' . $doi . '},';
	}
	$bib[] = '  url = {' . $url . '},';
	$bib[] = '  language = {english}';
	$bib[] = '}';

	return array(
		'title'     => $title,
		'url'       => $url,
		'doi'       => $doi,
		'doi_url'   => $doi_url,
		'version'   => $version,
		'date'      => gmdate( 'Y-m-d', $ts ),
		'published' => $published,
		'apa'       => $apa,
		'bibtex'    => implode( "\n", $bib ),
	);
}

/**
 * wp_head: the Highwire Press tags Google Scholar's inclusion guidelines read,
 * on a single note only. The publication date is the publish date (Scholar
 * groups versions); the DOI tag only when the note has a production DOI.
 */
function sn_cite_this_head() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$c = sn_cite_this_for( get_queried_object_id() );
	if ( null === $c ) {
		return;
	}
	$tags = array(
		'citation_title'            => $c['title'],
		'citation_author'           => 'Lentino, Juan',
		'citation_publication_date' => str_replace( '-', '/', $c['published'] ),
		'citation_doi'              => $c['doi'],
	);
	foreach ( $tags as $name => $content ) {
		if ( '' !== $content ) {
			echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '">' . "\n";
		}
	}
}
add_action( 'wp_head', 'sn_cite_this_head', 3 );
