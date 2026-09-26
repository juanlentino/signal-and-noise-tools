<?php
/**
 * The north star's pure half: config clamping, core-path matching, the
 * visitor-day tally (readers, deep, intent, career) and the week split.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['snt_test_settings'] = array();
function __( $s ) { return $s; }
function sn_setting( $k, $d = null ) { return $GLOBALS['snt_test_settings'][ $k ] ?? $d; }
function add_action() {}
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "North star\n\n";

// Config: defaults, an empty or unknown selection, and clamping.
$c = snt_nsm_config();
ok( 4 === count( $c['sections'] ) && 50 === $c['scroll'] && 30000 === $c['dwell_ms'], 'defaults: every section, 50% scroll, 30 s dwell' );
$GLOBALS['snt_test_settings']['north_star'] = array( 'sections' => array( 'bogus' ), 'scroll' => 900, 'dwell_s' => 0 );
$c = snt_nsm_config();
ok( 4 === count( $c['sections'] ), 'an empty or unknown selection falls back to every section, never a star that reads zero' );
ok( 100 === $c['scroll'] && 1000 === $c['dwell_ms'], 'scroll clamps to 100, dwell to at least 1 s' );
$GLOBALS['snt_test_settings']['north_star'] = array( 'sections' => array( 'notes' ), 'scroll' => 50, 'dwell_s' => 30 );
$c = snt_nsm_config();
ok( array( '/notes/' ) === $c['prefixes'], 'one section selected: only its prefixes count' );

// Core paths: listings never count.
$all = array( '/notes/', '/provenance', '/resume' );
ok( snt_nsm_is_core( '/notes/two-kinds-of-provenance/', $all ), 'a note is core' );
foreach ( array( '/notes/', '/notes', '/notes/page/2/', '/notes/tags/', '/notes/feed/', '/', '/contact/' ) as $p ) {
	ok( ! snt_nsm_is_core( $p, $all ), "not core: $p" );
}
ok( snt_nsm_is_core( '/provenance/', $all ) && snt_nsm_is_core( '/resume/', $all ), 'hub and resume are core when selected' );

// Tally.
$cfg = array( 'prefixes' => array( '/notes/', '/resume' ), 'scroll' => 50, 'dwell_ms' => 30000 );
$ev  = function ( $vid, $ev, $path, $extra = array() ) { return array_merge( array( 'vid' => $vid, 'ev' => $ev, 'path' => $path, 'ts' => 100 ), $extra ); };
$visits = array(
	// a: read one note by scroll only.
	array( $ev( 'a', 'pv', '/notes/x/' ), $ev( 'a', 'sc', '/notes/x/', array( 'scroll' => 60 ) ) ),
	// a again, a second visit the same day: still one reader-day, now deep.
	array( $ev( 'a', 'pv', '/notes/y/' ), $ev( 'a', 'tm', '/notes/y/', array( 'dwell' => 45000 ) ) ),
	// b: glanced at a note (below both floors), visited /contact, downloaded.
	array( $ev( 'b', 'pv', '/notes/x/' ), $ev( 'b', 'sc', '/notes/x/', array( 'scroll' => 20 ) ), $ev( 'b', 'pv', '/contact/' ), $ev( 'b', 'ce', '/contact/', array( 'ce' => 'download' ) ) ),
	// c: scrolled a note with no pageview recorded: not a read.
	array( $ev( 'c', 'sc', '/notes/x/', array( 'scroll' => 90 ) ) ),
	// d: read the notes index deeply: a listing, not a read. Fired an unknown event.
	array( $ev( 'd', 'pv', '/notes/' ), $ev( 'd', 'sc', '/notes/', array( 'scroll' => 100 ) ), $ev( 'd', 'ce', '/notes/', array( 'ce' => 'RSS Feed Request' ) ) ),
);
$t = snt_nsm_tally( $visits, $cfg );
ok( 1 === $t['readers'], 'readers counts visitor-days with a core read by scroll OR dwell (a only; b below floors, c no pageview, d a listing)' );
ok( 1 === $t['deep'], 'deep: a read two notes across two visits of one day' );
ok( 1 === $t['career'], 'career: b reached /contact' );
ok( 1 === $t['intent'], 'intent: only download/outbound count, once per visitor-day' );
ok( array( 'readers' => 0, 'deep' => 0, 'career' => 0, 'intent' => 0 ) === snt_nsm_tally( array(), $cfg ), 'no visits: all zero' );

// Weeks: rolling 7-day buckets by a visit's first event.
$now = 10 * 86400;
$w   = snt_nsm_weeks( array( array( array( 'ts' => $now - 1 ) ), array( array( 'ts' => $now - 8 * 86400 ) ), array( array( 'ts' => $now - 40 * 86400 ) ), array( array( 'ts' => $now + 5 ) ) ), $now );
ok( 1 === count( $w[0] ) && 1 === count( $w[1] ) && 0 === count( $w[2] ) + count( $w[3] ), 'weeks: last 7 days is week 0; older than four weeks and future events drop' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
