<?php
/**
 * Feed item links carry utm_source=rss&utm_medium=feed so a click-through
 * from a feed reader lands as a campaign visit; the GUID and the comments
 * feed are left alone, and nothing is tagged twice.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['fu_filters'] = array();
$GLOBALS['fu_comment_feed'] = false;
function add_filter( $h, $cb ) { $GLOBALS['fu_filters'][ $h ] = $cb; }
function is_comment_feed() { return $GLOBALS['fu_comment_feed']; }
require __DIR__ . '/../inc/feed-utm.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Feed UTM\n\n";

ok( 'https://juanlentino.com/notes/x/?utm_source=rss&utm_medium=feed' === snt_feed_utm_url( 'https://juanlentino.com/notes/x/' ), 'a plain permalink gets both params' );
ok( 'https://juanlentino.com/?p=5&utm_source=rss&utm_medium=feed' === snt_feed_utm_url( 'https://juanlentino.com/?p=5' ), 'an existing query is kept and extended' );
ok( 'https://juanlentino.com/notes/x/?utm_source=rss&utm_medium=feed#part' === snt_feed_utm_url( 'https://juanlentino.com/notes/x/#part' ), 'a fragment stays last' );
ok( 'https://juanlentino.com/notes/x/?utm_source=news' === snt_feed_utm_url( 'https://juanlentino.com/notes/x/?utm_source=news' ), 'an already-tagged link is never tagged twice' );
ok( '' === snt_feed_utm_url( '' ), 'an empty link stays empty' );

$f = $GLOBALS['fu_filters']['the_permalink_rss'] ?? null;
ok( is_callable( $f ), 'hooked on the_permalink_rss, the item LINK (not the_guid, so readers keep their read state)' );
ok( false !== strpos( $f( 'https://juanlentino.com/notes/x/' ), 'utm_source=rss' ), 'a post feed item link is tagged' );
$GLOBALS['fu_comment_feed'] = true;
ok( 'https://juanlentino.com/notes/x/' === $f( 'https://juanlentino.com/notes/x/' ), 'the comments feed is left alone' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
