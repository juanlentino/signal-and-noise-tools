<?php
/**
 * Guard: /resume's edition marks and hunks (inc/resume-lane-marks.php) and the
 * coded link's lane links (inc/resume-page-lane-render.php).
 *
 * What only one lane holds is marked by string equality with the other lane's
 * document; where the other lane has a band this one lacks, one hunk links to
 * its anchor; the body survives core's parse and re-save; on /r/CODE every lane
 * link keeps the code; nothing else (PDF, JSON Resume, Markdown, /cv) changes.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/resume-lane-marks.php
 * (core's block parser is read from the same wp-includes, as CI fetches it).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0; $skip = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function add_action() {}
function add_filter() {}
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); }
function is_preview() { return ! empty( $GLOBALS['__preview'] ); }
function current_user_can( $cap, $id = 0 ) { return ! empty( $GLOBALS['__editor'] ); }
function get_the_ID() { return 7; }
function esc_url_raw( $s ) { return (string) $s; }
function wp_make_link_relative( $u ) { return (string) preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', (string) $u ); }
// The query helpers, as core shapes them for a path with no query of its own.
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function remove_query_arg( $k, $url = null ) {
	$url = null === $url ? (string) $_SERVER['REQUEST_URI'] : (string) $url;
	$p   = explode( '?', $url, 2 );
	parse_str( $p[1] ?? '', $q );
	unset( $q[ $k ] );
	return $p[0] . ( $q ? '?' . http_build_query( $q ) : '' );
}
function sn_jsonresume_page( $slug ) { return (object) array( 'ID' => 7 ); }
if ( ! function_exists( 'wp_has_noncharacters' ) ) { function wp_has_noncharacters( string $text ): bool { return false; } }
if ( ! function_exists( 'wp_kses_uri_attributes' ) ) { function wp_kses_uri_attributes() { return array( 'href', 'src', 'action' ); } }

$api = rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' );
$api = is_file( $api . '/class-wp-html-tag-processor.php' ) ? $api : '';
if ( '' !== $api ) {
	if ( is_file( dirname( $api ) . '/class-wp-token-map.php' ) ) { require_once dirname( $api ) . '/class-wp-token-map.php'; }
	if ( is_file( $api . '/html5-named-character-references.php' ) ) { require_once $api . '/html5-named-character-references.php'; } // the decoder reads &name; references in a preview's query.
	foreach ( array( 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-attribute-token.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $f ) {
		require_once "$api/$f";
	}
}
$parser = '' !== $api && is_file( dirname( $api ) . '/class-wp-block-parser.php' );
if ( $parser ) {
	foreach ( array( 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php' ) as $f ) {
		require_once dirname( $api ) . "/$f";
	}
}

$root = dirname( __DIR__ );
require_once "$root/inc/resume-page.php";
require_once "$root/inc/resume-operations-setup.php";
require_once "$root/inc/resume-pdf/operations.php";
require_once "$root/inc/resume-sync-engine.php";
require_once "$root/inc/resume-page-lane-render.php";
require_once "$root/inc/resume-jsonresume.php";
require_once "$root/inc/resume-markdown.php";
require_once "$root/inc/link-codes.php";
require_once "$root/inc/provenance-core-normalize-parts.php";

$plain = sn_resume_doc_normalize( sn_resume_seed_doc() );
$raw   = sn_resume_doc_normalize( sn_resume_operations_setup( $plain )['doc'] );
$GLOBALS['__opt']['sn_resume_pdf_operations'] = array( 'url' => 'https://juanlentino.com/resume/JuanLentino_Resume_Operations.pdf', 'sha256' => str_repeat( 'a', 64 ) );
$music = sn_resume_lane_doc( $raw, 'music' );
$ops   = sn_resume_lane_doc( $raw, 'operations' );
$body  = sn_resume_page_body( $raw );
list( $mm, $om ) = sn_resume_lane_marks( $music, $ops );

echo "Group: membership, by string equality\n";
// Every markable row as [kind, its key, marked?], read from a marked lane document.
$rows = static function ( $d ) {
	$out = array();
	foreach ( array( 'education', 'affiliations' ) as $k ) {
		foreach ( $d[ $k ] as $r ) { $out[] = array( $k, $r['title'] . "\n" . implode( "\n", $r['lines'] ), ! empty( $r['_only'] ) ); }
	}
	foreach ( $d['stats'] as $r ) { $out[] = array( 'stat', $r['n'] . "\n" . $r['label'], ! empty( $r['_only'] ) ); }
	foreach ( $d['skills'] as $r ) { $out[] = array( 'skill', $r['category'] . "\n" . $r['items'], ! empty( $r['_only'] ) ); }
	foreach ( $d['earlier']['entries'] as $e ) { $out[] = array( 'earlier', $e['org'], ! empty( $e['_only'] ) ); }
	foreach ( array_merge( $d['experience'], $d['earlier']['entries'] ) as $e ) {
		foreach ( $e['roles'] as $r ) { $out[] = array( 'role', $e['org'] . "\n" . $r['title'], ! empty( $r['_only'] ) ); }
	}
	return $out;
};
// The same keys from an unmarked document: what the other lane holds.
$held = static function ( $d ) use ( $rows ) {
	$keys = array();
	foreach ( $rows( $d ) as $r ) { $keys[ $r[0] . "\n" . $r[1] ] = true; }
	return $keys;
};
foreach ( array( 'music' => array( $mm, $ops ), 'operations' => array( $om, $music ) ) as $lane => list( $mine, $other ) ) {
	$theirs = $held( $other );
	$bad    = 0;
	foreach ( $rows( $mine ) as $r ) {
		$in_other = isset( $theirs[ $r[0] . "\n" . $r[1] ] ) || ( 'role' === $r[0] && in_array( array( 'earlier', strtok( $r[1], "\n" ), true ), $rows( $mine ), true ) ); // a role of a marked entry is not marked again.
		$bad     += ( $r[2] === $in_other ) ? 1 : 0;
	}
	ok( 0 === $bad, "$lane: every marked row is absent from the other lane, every unmarked row present ($bad wrong)" );
}
$marked = static function ( $d ) use ( $rows ) {
	return array_values( array_map( static fn( $r ) => $r[0] . ': ' . strtok( 'role' === $r[0] ? substr( $r[1], strpos( $r[1], "\n" ) + 1 ) : $r[1], "\n" ), array_filter( $rows( $d ), static fn( $r ) => $r[2] ) ) );
};
ok( array( 'affiliations: Voting Member, The Recording Academy', 'affiliations: Voting Member, The Latin Recording Academy', 'affiliations: Spatial Audio with Dolby Atmos in Logic Pro X', 'stat: 15+', 'stat: 110', 'stat: 90%', 'stat: 50+', 'skill: Business Development', 'skill: Artist & Label Relations', 'skill: Markets & Operations', 'skill: Music Tech & Research', 'skill: Production', 'skill: Languages', 'role: Production Assistant · Nov 1997 - Dec 2000' ) === $marked( $mm ), 'music marks: the two Academy rows, Dolby, the stats, the skills table, the Production Assistant role (' . implode( ' | ', $marked( $mm ) ) . ')' );
ok( array( 'affiliations: Voting Member, The Recording Academy and The Latin Recording Academy', 'affiliations: Team Captain, Recording Academy Music Advocacy Day', 'skill: Operations', 'skill: Partnerships', 'skill: AI and tools', 'skill: Languages' ) === $marked( $om ), 'operations marks: the merged Academy row, the Advocacy Day captain, the skills table; nothing in Experience' );
$extra                         = $music;
$extra['earlier']['entries'][] = array( 'org' => 'FREELANCE · BUENOS AIRES, ARGENTINA', 'roles' => array( array( 'title' => 'Independent Producer and Engineer', 'bullets' => array( 'A bullet.' ) ) ) );
$fm                            = sn_resume_lane_marks( $extra, $ops )[0];
$free                          = end( $fm['earlier']['entries'] );
ok( ! empty( $free['_only'] ) && empty( $free['roles'][0]['_only'] ), 'an earlier-career entry only one lane has is marked whole, and its role is not marked again' );
$gpm  = array_search( 'Google Project Management Certificate', array_column( $music['affiliations'], 'title' ), true );
$twin = $music;
$twin['affiliations'][ $gpm ]['lines'] = array( 'June 2025' ); // the operations lane has this title, with May 2025.
$tw   = sn_resume_lane_marks( $twin, $ops )[0];
ok( false !== $gpm && empty( sn_resume_lane_marks( $music, $ops )[0]['affiliations'][ $gpm ]['_only'] ) && ! empty( $tw['affiliations'][ $gpm ]['_only'] ), 'a row whose title the other lane shares but whose lines differ is lane-only: the key is title and lines' );
$ex = sn_resume_lane_marks( $music, $ops );
ok( array() === array_filter( array_merge( $ex[0]['experience'], $ex[1]['experience'] ), static fn( $e ) => ! empty( $e['_only'] ) || array_filter( $e['roles'], static fn( $r ) => ! empty( $r['_only'] ) ) ), 'a role whose bullets differ is the same role: Experience carries no mark in either lane' );

echo "\nGroup: the markup of a mark\n";
list( , $rest ) = explode( 'id="resume-music"', $body, 2 ) + array( '', '' );
list( $music_html, $ops_html ) = explode( 'id="resume-operations"', $rest, 2 ) + array( '', '' );
$sr = '<!-- wp:paragraph {"className":"screen-reader-text sn-resume-only-mark"} -->' . "\n" . '<p class="screen-reader-text sn-resume-only-mark">Only in this version.</p>';
ok( 3 === substr_count( $music_html, '<div class="wp-block-group sn-resume-only">' . "\n" . $sr ) && 3 === substr_count( $music_html, '<p class="sn-resume-only"><span class="screen-reader-text sn-resume-only-mark">Only in this version.</span><strong>' ), 'music: three groups (the stats, the role, the skills table) open on the screen-reader words; three credential rows carry them inside' );
ok( false !== strpos( $music_html, '<div class="wp-block-group sn-resume-only">' . "\n" . $sr . "\n" . '<!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:table {"hasFixedLayout":false,"className":"sn-resume-skills"} -->' ) && false !== strpos( $music_html, $sr . "\n" . '<!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:columns {"className":"sn-resume-stats"} -->' ), 'the skills table and the stats sit inside their mark group (the table\'s figure would clip a bar drawn outside it)' );
ok( 2 === substr_count( $ops_html, 'class="sn-resume-only"' ) && 1 === substr_count( $ops_html, 'class="wp-block-group sn-resume-only"' ), 'operations: two credential rows and the skills table' );
ok( 0 === preg_match( '#<li>[^<]*<span class="screen-reader-text sn-resume-only-mark"#', $body ) && 0 === preg_match( '#<h2[^>]*sn-resume-only#', $body ), 'never a bullet, never a band heading' );
ok( 1 === substr_count( $music_html, '>03 · Research · Only in this version</p>' ) && 1 === substr_count( $ops_html, '>01 · Projects · Only in this version</p>' ) && 2 === substr_count( $body, 'Only in this version</p>' ), 'a band only one lane has says so in its eyebrow, and only those two do' );
ok( false !== strpos( $body, '</nav>' . "\n" . '<!-- /wp:group -->' . "\n\n" . '<!-- wp:paragraph {"className":"sn-resume-legend"} -->' . "\n" . '<p class="sn-resume-legend">A bar beside an entry: only in this version.</p>' ) && 1 === substr_count( $body, '<p class="sn-resume-legend">' ), 'the legend is stored once, the block right after the switch nav' );
ok( false === strpos( sn_resume_lanes_head_blocks(), 'sn-resume-legend' ), 'with no mark there is no legend' );
$fmt = static fn( $q ) => '<p class="sn-resume-formats">Also as <a href="/resume.md' . $q . '">Markdown</a> · <a href="/resume/json-resume.json' . $q . '">JSON Resume</a></p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n" . '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n" . '</div>';
ok( false !== strpos( $music_html, $fmt( '' ) ) && false !== strpos( $ops_html, $fmt( '?lane=operations' ) ) && 2 === substr_count( $body, '<p class="sn-resume-formats">' ), 'each lane ends its last band with its own formats line' );

echo "\nGroup: hunks\n";
preg_match_all( '#<p class="sn-resume-hunk">(.*?)</p>#', $body, $hunks );
ok( array( 'In Operations &amp; AI Strategy: <a href="/resume/?lane=operations#resume-operations-built" data-lane="operations" data-sn-goal="resume_hunk_operations">WHAT I&#039;VE BUILT</a>', 'In Music: <a href="/resume/#resume-music-publications" data-lane="music" data-sn-goal="resume_hunk_music">PUBLICATIONS</a>' ) === $hunks[1], 'exactly two hunks, named for section bands, never a lane headline' );
ok( false === strpos( implode( '', $hunks[1] ), 'Founder' ) && 2 === substr_count( $body, '"className":"sn-resume-hunk-band"' ), 'the hero headline (an h2 too) never becomes a hunk' );
$at_music = strpos( $music_html, 'sn-resume-hunk-band' );
ok( $at_music > strpos( $music_html, 'sn-resume-stats' ) && $at_music < strpos( $music_html, 'id="resume-music-experience"' ) && strpos( $ops_html, 'sn-resume-hunk-band' ) < strpos( $ops_html, 'id="resume-operations-skills"' ) && strpos( $ops_html, 'sn-resume-hunk-band' ) > strpos( $ops_html, 'id="resume-operations-credentials"' ), 'music\'s hunk sits after the stats and before Experience; operations\' after Credentials and before Skills' );
$resolves = true;
foreach ( $hunks[1] as $h ) {
	preg_match( '~href="[^"#]*#([^"]+)" data-lane="([a-z]+)"~', $h, $f );
	$resolves = $resolves && isset( $f[2] ) && 1 === substr_count( 'music' === $f[2] ? $music_html : $ops_html, 'id="' . $f[1] . '"' ) && 0 === substr_count( 'music' === $f[2] ? $ops_html : $music_html, 'id="' . $f[1] . '"' );
}
ok( $resolves, 'every hunk fragment is an id in the other lane\'s markup, and only there' );
preg_match_all( '/\sid="([^"]*)"/', $body, $ids );
ok( count( $ids[1] ) === count( array_unique( $ids[1] ) ), 'ids are unique in the stored body (' . count( $ids[1] ) . ')' );
ok( array() === sn_resume_lane_hunks( array( 'experience', 'skills' ), array( 'experience', 'skills' ) ) && array( '' => array( 'skills' ) ) === sn_resume_lane_hunks( array( 'experience' ), array( 'experience', 'skills' ) ), 'no hunk when both lanes share every band; one at the end when nothing shared follows' );

echo "\nGroup: core's parse and re-save\n";
if ( ! $parser ) {
	++$skip;
	echo "SKIP: no WP_Block_Parser beside the html-api (bash tools/fetch-wp-test-core.sh fetches it)\n";
} else {
	// Core's serialize_block_attributes(), serialize_block() and get_comment_delimited_block_content() (wp-includes/blocks.php, 7.1).
	$attrs = static fn( $a ) => strtr( wp_json_encode( $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), array( '\\\\' => '\\u005c', '--' => '\\u002d\\u002d', '<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022' ) );
	$ser   = static function ( $b ) use ( &$ser, $attrs ) {
		$c = '';
		$i = 0;
		foreach ( $b['innerContent'] as $chunk ) { $c .= is_string( $chunk ) ? $chunk : $ser( $b['innerBlocks'][ $i++ ] ); }
		if ( null === $b['blockName'] ) { return $c; }
		$name = 0 === strpos( $b['blockName'], 'core/' ) ? substr( $b['blockName'], 5 ) : $b['blockName'];
		$a    = empty( $b['attrs'] ) ? '' : $attrs( $b['attrs'] ) . ' ';
		return '' === $c ? sprintf( '<!-- wp:%s %s/-->', $name, $a ) : sprintf( '<!-- wp:%s %s-->%s<!-- /wp:%s -->', $name, $a, $c, $name );
	};
	$rt = static fn( $s ) => implode( '', array_map( $ser, ( new WP_Block_Parser() )->parse( $s ) ) );
	ok( $rt( $body ) === $body, 'serialize_blocks( parse_blocks( $body ) ) gives the two-lane body back byte for byte' );
	ok( $rt( sn_resume_page_body( $plain ) ) === sn_resume_page_body( $plain ), 'and the single body' );
	$parsed = ( new WP_Block_Parser() )->parse( $body );
	$find   = static function ( $blocks, $class ) use ( &$find ) {
		foreach ( $blocks as $b ) {
			if ( false !== strpos( (string) ( $b['attrs']['className'] ?? '' ), $class ) ) { return $b; }
			$hit = $find( $b['innerBlocks'], $class );
			if ( $hit ) { return $hit; }
		}
		return null;
	};
	ok( 'sn-resume-variant sn-resume-variant--music' === ( $find( $parsed, 'sn-resume-variant--music' )['attrs']['className'] ?? '' ), 'the lane\'s className parses back as the render filter reads it' );
}

echo "\nGroup: /r/CODE keeps its code in every lane link\n";
if ( '' === $api ) {
	++$skip;
	echo "SKIP: no WP_HTML_Tag_Processor (SNT_WP_HTML_API=<wp>/wp-includes/html-api)\n";
} else {
	$strip = static fn( $s ) => (string) preg_replace( '/<!--.*?-->\n?/s', '', $s );
	$html  = $strip( $body );
	$nav   = substr( $html, (int) strpos( $html, '<nav' ), strpos( $html, '</nav>' ) + 6 - strpos( $html, '<nav' ) );
	$m_div = substr( $html, (int) strpos( $html, '<div id="resume-music"' ), strpos( $html, '<div id="resume-operations"' ) - strpos( $html, '<div id="resume-music"' ) );
	$o_div = substr( $html, (int) strpos( $html, '<div id="resume-operations"' ) );
	$render = static function ( $uri, $lane ) use ( $nav, $m_div, $o_div ) {
		$_SERVER['REQUEST_URI'] = $uri;
		$_GET                   = null === $lane ? array() : array( 'lane' => $lane );
		$g = static fn( $h, $l ) => sn_resume_lane_render( $h, array( 'attrs' => array( 'className' => 'sn-resume-variant sn-resume-variant--' . $l ) ) );
		return sn_resume_lane_render( $nav, array( 'attrs' => array( 'className' => 'sn-resume-switch' ) ) ) . $g( $m_div, 'music' ) . $g( $o_div, 'operations' );
	};
	$hrefs = static function ( $h ) {
		$p   = new WP_HTML_Tag_Processor( $h );
		$out = array();
		while ( $p->next_tag( 'a' ) ) {
			if ( null !== $p->get_attribute( 'data-lane' ) ) { $out[] = (string) $p->get_attribute( 'href' ); }
		}
		return $out;
	};
	ok( array( 'page_id' => 7 ) === sn_link_code_request( array(), '/r/hera/?lane=operations' ) && ( static function () { $_GET = array( 'lane' => 'operations' ); return sn_resume_active_lane(); } )() === 'operations', '?lane works on /r/CODE: the code still serves the page, and the lane is read from the query' );
	$coded = $hrefs( $render( '/r/hera', 'operations' ) );
	ok( array( '/r/hera', '/r/hera?lane=operations', '/r/hera?lane=operations#resume-operations-built', '/r/hera#resume-music-publications' ) === $coded, 'on /r/hera every a[data-lane] keeps the code: the switch, then each lane\'s hunk, fragment kept (' . implode( ' ', $coded ) . ')' );
	ok( 4 === count( $coded ) && array() === array_filter( $coded, static fn( $h ) => 0 !== strpos( $h, '/r/hera' ) ), 'every lane link in the response starts with /r/hera' );
	$plain_view = $render( '/resume/', 'operations' );
	ok( $hrefs( $plain_view ) === $hrefs( $nav . $m_div . $o_div ) && array( '/resume/', '/resume/?lane=operations', '/resume/?lane=operations#resume-operations-built', '/resume/#resume-music-publications' ) === $hrefs( $plain_view ), 'on /resume/ every lane link is the stored one' );
	ok( $render( '/resume/', 'operations' ) === $render( '/resume/?utm_source=x', 'operations' ) && false === strpos( $render( '/resume/?x="><b>', 'music' ), '<b>' ), 'and no request path or query ever reaches the markup' );
	$x = $render( '/r/hera', '"><script>' );
	ok( $hrefs( $x ) === array( '/r/hera', '/r/hera?lane=operations', '/r/hera?lane=operations#resume-operations-built', '/r/hera#resume-music-publications' ) && false === strpos( $x, '<script' ), 'an unknown lane is the music lane on a coded link too' );

	$GLOBALS['__preview'] = true;
	ok( $hrefs( $render( '/resume/?preview=true&lane=operations', 'operations' ) ) === $hrefs( $nav . $m_div . $o_div ), 'an anonymous ?preview=true rewrites nothing' );
	$GLOBALS['__editor'] = true;
	ok( array( '/resume/?preview=true&preview_id=7', '/resume/?preview=true&preview_id=7&lane=operations', '/resume/?preview=true&preview_id=7&lane=operations#resume-operations-built', '/resume/?preview=true&preview_id=7#resume-music-publications' ) === $hrefs( $render( '/resume/?preview=true&preview_id=7&lane=operations', 'operations' ) ), "an editor's preview keeps its own query, without its lane" );
	ok( 0 === strpos( $hrefs( $render( '//evil.example/x?preview=true', 'music' ) )[0], '/x' ) , 'a leading // never becomes a protocol-relative link' );
	$GLOBALS['__preview'] = false; $GLOBALS['__editor'] = false;

	echo "\nGroup: the inactive lane is hidden, never removed\n";
	$view   = $render( '/r/hera', 'operations' );
	$open   = static fn( $id ) => (int) strrpos( substr( $view, 0, (int) strpos( $view, 'id="' . $id . '"' ) ), '<div' );
	$m_part = substr( $view, $open( 'resume-music' ), $open( 'resume-operations' ) - $open( 'resume-music' ) );
	$o_tag  = substr( $view, $open( 'resume-operations' ), strpos( $view, '>', $open( 'resume-operations' ) ) - $open( 'resume-operations' ) );
	ok( 1 === preg_match( '/^<div[^>]*\shidden[\s>=]/', $m_part ) && false === strpos( $o_tag, 'hidden' ), '?lane=operations: the music lane carries hidden, operations does not' );
	ok( sn_prov_normalize_v1( $view ) === sn_prov_normalize_v1( $nav . $m_div . $o_div ) && false !== strpos( wp_strip_all_tags( $m_part ), 'Production Assistant' ) && false !== strpos( wp_strip_all_tags( $m_part ), 'Only in this version.' ), 'its text, marks and all, is still in the response: the page reads the same to the provenance checker' );
}
$_SERVER['REQUEST_URI'] = ''; $_GET = array();

echo "\nGroup: nothing else changes\n";
ok( false === strpos( (string) json_encode( array( $music, $ops, sn_resume_doc_view( $raw, 'music' ) ) ), '_only' ), 'the lane documents the PDFs, the JSON Resume and the Markdown read carry no mark' );
$jo = sn_jsonresume_build( $ops, array( 'name' => 'Juan Lentino', 'projects' => $ops['built']['items'], 'lane' => 'operations' ) );
$jm = sn_jsonresume_build( $music, array( 'name' => 'Juan Lentino', 'lane' => 'music' ) );
ok( '364ef4dfa8c88d98f9faac59782a392e816ddc82' === sha1( (string) json_encode( $jo ) ) && '89f9b0759401c1c8e9ca4906b632f428e29cda74' === sha1( (string) json_encode( $jm ) ), 'both JSON Resumes are byte for byte what they were (engine rev 5)' );
ok( '6e8137bd8c8983acd69d912bb00ce7682efaed42' === sha1( sn_resume_markdown( $ops, $jo, array(), 'en' ) ) && '941da0e68ab1921565ca28ed45f80f537ef65445' === sha1( sn_resume_markdown( $music, $jm, array(), 'en' ) ), 'both Markdowns are byte for byte what they were' );
ok( '488a844f9c316d00c9925e0f5a451c3fab0fe794' === sha1( sn_resume_page_body( $plain ) ) && false === strpos( sn_resume_page_body( $plain ), 'sn-resume-only' ), 'the single body is unchanged and unmarked' );
sn_resume_lang( 'es' );
ok( 'ab72ff0aee4e342f9e51e57b50822002ba6d49e1' === sha1( sn_resume_page_body( $raw ) ), '/cv (Spanish) is unchanged' );
sn_resume_lang( 'en' );

$ci = (string) getenv( 'CI' );
if ( $skip > 0 && '' !== $ci && '0' !== $ci && 'false' !== strtolower( $ci ) ) {
	echo "FAILED: $skip group(s) SKIPPED under CI because WordPress's html-api or block parser is missing.\n";
	$fail += $skip;
}
echo "\nResult: $pass passed, $fail failed, $skip skipped.\n";
exit( $fail > 0 ? 1 : 0 );
