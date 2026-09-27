<?php
/**
 * The notes feed carries the full note: excerpt mode is off for /notes/feed/
 * only, the content is made safe for a feed reader, and each item ends with a
 * "Read on the site" link and a pixel whose URL carries only the note id. The
 * fixture is a real note's rendered entry content, plus hostile additions.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['ft_filters'] = array();
$GLOBALS['ft_req']     = array( 'feed' => true, 'comment' => false, 'admin' => false );
function add_filter( $h, $cb ) { $GLOBALS['ft_filters'][ $h ][] = $cb; }
function add_action() {}
function apply_filters( $h, $v ) { foreach ( $GLOBALS['ft_filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); } return $v; }
function is_admin() { return $GLOBALS['ft_req']['admin']; }
function did_action() { return 1; }
function is_feed() { return $GLOBALS['ft_req']['feed']; }
function is_comment_feed() { return $GLOBALS['ft_req']['comment']; }
function wp_unslash( $v ) { return $v; }
function wp_kses_post( $v ) { return $v; } // the DOM pass is what is under test.
function esc_url( $u ) { return str_replace( '&', '&#038;', $u ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function rest_url( $p ) { return 'https://juanlentino.com/wp-json/' . $p; }
function add_query_arg( $k, $v, $u ) { return $u . '?' . $k . '=' . $v; }
function get_the_ID() { return 2669; }
function get_permalink() { return 'https://juanlentino.com/notes/the-form-is-not-part-of-the-process/'; }
require __DIR__ . '/../inc/feed-utm.php';
require __DIR__ . '/../inc/feed-opens.php';
require __DIR__ . '/../inc/feed-full-text.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Feed full text\n\n";

// Scope: the notes feed only.
ok( snt_feed_is_notes_path( '/notes/feed/' ) && snt_feed_is_notes_path( '/notes/feed/atom/' ) && snt_feed_is_notes_path( '/notes/feed?x=1' ), 'the notes feed paths match' );
ok( ! snt_feed_is_notes_path( '/feed/' ) && ! snt_feed_is_notes_path( '/notes/feedback/' ) && ! snt_feed_is_notes_path( '/notes/x/feed/' ), 'the site feed, a lookalike and a per-note comment feed do not' );
$opt = $GLOBALS['ft_filters']['option_rss_use_excerpt'][0];
$_SERVER['REQUEST_URI'] = '/notes/feed/';
ok( 0 === $opt( '1' ), 'excerpt-only is off in the notes feed, so content:encoded is emitted' );
$_SERVER['REQUEST_URI'] = '/feed/';
ok( '1' === $opt( '1' ), 'the site-wide option is left alone everywhere else' );
$_SERVER['REQUEST_URI'] = '/notes/feed/';
$GLOBALS['ft_req']['comment'] = true;
ok( '1' === $opt( '1' ), 'a comment feed is left alone' );
$GLOBALS['ft_req']['comment'] = false;

// Content.
$note = file_get_contents( __DIR__ . '/fixtures/feed-note-entry.html' );
$in   = $note
	. '<p>Café’s “note”</p><script>alert(1)</script><p onclick="x()">Tail <a href="/notes/other/">rel</a> <img src="/wp-content/uploads/a.webp" srcset="/a-300.webp 300w" alt="a"></p>'
	. '<figure class="wp-block-embed"><iframe src="https://open.spotify.com/embed/track/1"></iframe></figure>'
	. '<div class="sn-prov-panel"><div class="sn-prov-chain"><button>Verify</button><form><input></form></div></div>';
$f   = $GLOBALS['ft_filters']['the_content_feed'][0];
$out = $f( $in );

ok( false !== strpos( $out, 'Credits and rights information sit under workflow' ), 'the full note body is present, not an excerpt' );
ok( false !== strpos( $out, 'Café’s “note”' ), 'UTF-8 text survives the DOM pass unmangled' );
ok( false === stripos( $out, '<script' ) && false === stripos( $out, '<iframe' ) && false === stripos( $out, '<form' ) && false === stripos( $out, '<button' ), 'no script, iframe, form or button survives' );
ok( false !== strpos( $out, '<a href="https://open.spotify.com/embed/track/1">' ), 'an embed degrades to a plain link' );
ok( 1 === substr_count( $out, 'Provenance record on the site' ) && false === strpos( $out, 'sn-prov-chain' ), 'the provenance panel degrades to one link' );
ok( false !== strpos( $out, 'href="https://juanlentino.com/notes/other/"' ) && false !== strpos( $out, 'src="https://juanlentino.com/wp-content/uploads/a.webp"' ) && false === strpos( $out, 'srcset' ), 'root-relative links and images become absolute' );
ok( false === strpos( $out, 'href="#' ) && false === strpos( $out, 'sn-article-toc' ), 'no in-page fragment links or TOC are left' );
ok( 1 === preg_match( '#<p><a href="https://juanlentino\.com/notes/the-form-is-not-part-of-the-process/\?utm_source=rss&\#038;utm_medium=feed">Read on the site</a></p><img src="[^"]+" width="1" height="1" alt="" />$#', $out ), 'the item ends with the plain Read on the site link (feed-tagged) and the pixel' );

// Pixel URL: the note id and nothing else.
$px = snt_feed_open_pixel_url( 2669 );
parse_str( (string) parse_url( $px, PHP_URL_QUERY ), $q );
ok( array( 'p' => '2669' ) === $q && '/wp-json/signal-noise/v1/feed-open' === parse_url( $px, PHP_URL_PATH ), 'the pixel URL carries only p=<note id>' );

// Structure: the item validates as XML with content:encoded in CDATA.
$xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item><title>t</title>'
	. '<description><![CDATA[An excerpt.]]></description><content:encoded><![CDATA[' . $out . ']]></content:encoded></item></channel></rss>';
libxml_use_internal_errors( true );
$sx = simplexml_load_string( $xml );
ok( false !== $sx && 0 === count( libxml_get_errors() ), 'a rendered item is well-formed XML' );
ok( false !== $sx && 'An excerpt.' === (string) $sx->channel->item->description && $out === (string) $sx->channel->item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded, 'description stays the excerpt; content:encoded round-trips the full body' );

// Outside the notes feed the content is untouched.
$_SERVER['REQUEST_URI'] = '/feed/';
ok( $in === $f( $in ), 'the site feed content is untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
