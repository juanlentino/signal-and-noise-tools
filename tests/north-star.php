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
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
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
ok( array( 'readers' => 0, 'deep' => 0, 'career' => 0, 'intent' => 0, 'resume_downloads' => 0, 'subscribes' => 0, 'shares' => 0, 'verifies' => 0 ) === snt_nsm_tally( array(), $cfg ), 'no visits: all zero' );

// Named goals: each counted once per visitor-day, by where and what fired.
$g = snt_nsm_tally( array(
	array( $ev( 'r', 'ce', '/resume/', array( 'ce' => 'download' ) ), $ev( 'r', 'ce', '/resume/', array( 'ce' => 'download' ) ) ),
	array( $ev( 's', 'ce', '/notes/x/', array( 'ce' => 'download' ) ) ),
	array( $ev( 'f', 'ce', '/notes/', array( 'ce' => 'subscribe' ) ) ),
	array( $ev( 'v', 'ce', '/notes/x/', array( 'ce' => 'verify' ) ) ),
	array( $ev( 'h', 'ce', '/notes/x/', array( 'ce' => 'share_copy' ) ), $ev( 'k', 'ce', '/notes/y/', array( 'ce' => 'share_native' ) ) ),
), $cfg );
ok( 1 === $g['resume_downloads'], 'a download on /resume is a resume download, twice in a day counts once; a PDF elsewhere is not' );
ok( 1 === $g['subscribes'], 'a subscribe click counts' );
ok( 1 === $g['verifies'], 'a signature check from the provenance chip counts' );
ok( 2 === $g['shares'], 'Copy link and the share sheet both count as shares' );
ok( 2 === $g['intent'], 'downloads still count as deliberate actions (both PDFs), subscribes and shares do not inflate it' );

// Reading time arrives in slices (one per tab switch): they add up.
$sl = snt_nsm_tally( array( array( $ev( 's', 'pv', '/notes/x/' ), $ev( 's', 'tm', '/notes/x/', array( 'dwell' => 20000 ) ), $ev( 's', 'tm', '/notes/x/', array( 'dwell' => 15000 ) ) ) ), $cfg );
ok( 1 === $sl['readers'], '20 s + 15 s of slices on one note is a 35 s read, past the 30 s floor (the largest slice alone was 20 s)' );

// Weeks: rolling 7-day buckets by a visit's first event.
$now = 10 * 86400;
$w   = snt_nsm_weeks( array( array( array( 'ts' => $now - 1 ) ), array( array( 'ts' => $now - 8 * 86400 ) ), array( array( 'ts' => $now - 40 * 86400 ) ), array( array( 'ts' => $now + 5 ) ) ), $now );
ok( 1 === count( $w[0] ) && 1 === count( $w[1] ) && 0 === count( $w[2] ) + count( $w[3] ), 'weeks: last 7 days is week 0; older than four weeks and future events drop' );

// Zenodo: lifetime totals become a weekly figure only once a week of history exists.
$z = snt_nsm_zenodo_reading( array(), '2026-09-26' );
ok( null === $z['value'] && '' !== $z['pending'], 'no snapshots: null with a reason, never zero' );
$z = snt_nsm_zenodo_reading( array( '2026-09-25' => array( 'downloads' => 40 ), '2026-09-26' => array( 'downloads' => 42 ) ), '2026-09-26' );
ok( 42 === $z['value'] && 'all time' === $z['window'], 'under a week of history: the lifetime total, labelled all time' );
$z = snt_nsm_zenodo_reading( array( '2026-09-26' => array( 'downloads' => 50 ), '2026-09-19' => array( 'downloads' => 42 ) ), '2026-09-26' );
ok( 8 === $z['value'] && '7d' === $z['window'], 'a week back exists: the difference, over 7d (order-independent)' );
$z = snt_nsm_zenodo_reading( array( '2026-09-19' => array( 'downloads' => 42 ), '2026-09-26' => array( 'downloads' => 30 ) ), '2026-09-26' );
ok( 0 === $z['value'], 'a total that shrank (a record withdrawn) floors at zero, never negative' );

// Feed click-throughs: every utm_medium=feed source counts, nothing else.
$utm = array(
	array( 'source' => 'rss', 'medium' => 'feed', 'visits' => 3 ),
	array( 'source' => 'jsonfeed', 'medium' => 'feed', 'visits' => 2 ),
	array( 'source' => 'newsletter', 'medium' => 'email', 'visits' => 9 ),
);
ok( 5 === snt_nsm_sum_feed_visits( $utm ), 'feed click-throughs sum RSS and JSON Feed (3 + 2), never the newsletter' );
ok( 0 === snt_nsm_sum_feed_visits( array() ), 'no campaign rows: zero' );

// Research links followed: outbound clicks to where the research lives.
ok( snt_nsm_is_research_host( 'papers.ssrn.com' ) && snt_nsm_is_research_host( 'doi.org' ) && snt_nsm_is_research_host( 'ZENODO.ORG' ), 'SSRN (a subdomain), doi.org and Zenodo (any case) count' );
ok( ! snt_nsm_is_research_host( 'notssrn.com' ) && ! snt_nsm_is_research_host( 'github.com' ) && ! snt_nsm_is_research_host( '' ), 'a lookalike, an unrelated host and an empty one do not' );
$now = 10 * 86400;
$rw  = snt_nsm_research_weeks( array(
	array( 'vid' => 'a', 'ts' => $now - 60, 'host' => 'doi.org' ),
	array( 'vid' => 'a', 'ts' => $now - 30, 'host' => 'zenodo.org' ),
	array( 'vid' => 'b', 'ts' => $now - 60, 'host' => 'github.com' ),
	array( 'vid' => 'c', 'ts' => $now - 8 * 86400, 'host' => 'orcid.org' ),
), $now );
ok( 1 === $rw[0] && 1 === $rw[1], 'distinct visitor-days per week: a twice is once, b (github) never, c lands in last week' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
