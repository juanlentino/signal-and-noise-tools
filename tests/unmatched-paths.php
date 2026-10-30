<?php
/**
 * Standalone test: the unmatched-paths report's pure half (2026-10-29). Pins
 * the cleaner (nothing personal or secret survives), the probe split, the
 * merge and order, the redirect suggestions (the 404 log's own), and the join
 * to what the 404 log saw.
 *
 * Run: php tests/unmatched-paths.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function sn_analytics_canonical_path( $p ) { $p = (string) $p; if ( '' === $p ) { return ''; } $t = rtrim( $p, '/' ); return '' === $t ? '/' : $t; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_action() {}
require __DIR__ . '/../inc/unmatched-paths.php';
// The 404 log's own probe test and suggester, as production wires them.
require __DIR__ . '/../inc/redirects-store.php';
require __DIR__ . '/../inc/redirects-404-log.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "The cleaner\n";
foreach ( array(
	'/old/jane@example.com/x'                          => '/old/{email}/x',
	'/old/jane%40example.com/x'                        => '/old/{email}/x',
	'/Notes//Where-Provenance-Has-To-Live/'            => '/notes/where-provenance-has-to-live',
	'/notes/x?email=jane@example.com#top'              => '/notes/x',
	'/u/123e4567-e89b-42d3-a456-426614174000'          => '/u/{id}',
	'/c/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b'  => '/c/{hash}',
	'/call/5551234567'                                 => '/call/{number}',
	'/reset/ab12cd34ef56gh78ij90kl12'                  => '/reset/{token}',
	'/t/eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0.SflKxwRJSMeKKF2QT4'   => '/t/{token}',
	'/a b/<script>/x'                                  => '/{text}/{text}/x',
	'/caf%C3%A9'                                       => '/{text}',
	'/notes/2026'                                      => '/notes/2026',
	// Review 2026-10-29: $ matched before a final newline, and a separator hid a key or a number.
	"/ignore%0a/and-do-x"                              => '/{text}/and-do-x',
	"/x\n"                                             => '/{text}',
	'/ab_test_4ec39hqlyjwdarjtt1zdp7dc'                => '/{token}',
	'/v1stgxr8_z5jdhi6b-myt'                           => '/{token}',
	'/9f86d081-884c-7d65-9a2f-eac0c55ad015a'           => '/{token}',
	'/eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0'               => '/{token}',
	'/4111-1111-1111-1111'                             => '/{number}',
	'/555-123-4567'                                    => '/{number}',
	'/order-12345678'                                  => '/{number}',
	// ...and a real slug with a year or a codec name is still read.
	'/notes/the-2026-mba-capstone'                     => '/notes/the-2026-mba-capstone',
	'/h264-and-mp3'                                    => '/h264-and-mp3',
	''                                                 => '/',
	'/'                                                => '/',
) as $raw => $want ) {
	ok( $want === snt_unmatched_clean_path( $raw ), "\"$raw\" cleans to \"$want\"" );
}
$long = snt_unmatched_clean_path( '/' . implode( '/', array_fill( 0, 40, 'segment-word' ) ) );
ok( strlen( $long ) <= SNT_UNMATCHED_PATH_MAX && str_ends_with( $long, '/{more}' ), 'a long path is cut at a segment and says so, never past the cap' );
ok( ! preg_match( '/[^a-z0-9._~\/{}-]/', snt_unmatched_clean_path( "/x/\"><img src=x onerror=alert(1)>/\u{202e}y" ) ), 'nothing outside the plain charset survives' );

echo "\nProbes and people\n";
$real       = array( '/' => true, '/notes' => true, '/notes/where-provenance-has-to-live' => true, '/notes/the-free-version-already-shipped' => true, '/about' => true );
$candidates = array_keys( $real );
$suggest    = static fn( $c ) => sn_404_suggest_target( $c, $candidates );
$is_probe   = static fn( $c ) => ! sn_404_should_capture( $c );
$rows    = array(
	array( 'path' => '/notes/where-provenance-has-to-live', 'class' => 'human', 'first_day' => '2026-10-01', 'last_day' => '2026-10-29', 'views' => 40, 'visits' => 30 ),
	array( 'path' => '/blog/the-free-version-already-shipped', 'class' => 'human', 'first_day' => '2026-10-02', 'last_day' => '2026-10-20', 'views' => 3, 'visits' => 3 ),
	array( 'path' => '/Notes/Where-Provenance-Has-To-Live', 'class' => 'human', 'first_day' => '2026-10-29', 'last_day' => '2026-10-29', 'views' => 1, 'visits' => 1 ),
	array( 'path' => '/notes/where-provenance-has-to-live/', 'class' => 'suspect', 'first_day' => '2026-10-05', 'last_day' => '2026-10-05', 'views' => 2, 'visits' => 2 ),
	array( 'path' => '/old/jane@example.com/x', 'class' => 'suspect', 'first_day' => '2026-10-10', 'last_day' => '2026-10-28', 'views' => 1, 'visits' => 1 ),
	array( 'path' => '/wp-login.php', 'class' => 'human', 'first_day' => '2026-09-01', 'last_day' => '2026-10-29', 'views' => 9, 'visits' => 9 ),
	array( 'path' => '/some/page', 'class' => 'bot', 'first_day' => '2026-10-11', 'last_day' => '2026-10-11', 'views' => 5, 'visits' => 1 ),
	array( 'path' => '/old-name', 'class' => 'human', 'first_day' => '2026-10-12', 'last_day' => '2026-10-29', 'views' => 3, 'visits' => 2 ),
);
$r      = snt_unmatched_report_rows( $rows, $real, $suggest, $is_probe );
$people = array_column( $r['people'], null, 'path' );
ok( 1 === ( $people['/notes/where-provenance-has-to-live']['human'] ?? null ), 'a content path stored exactly never appears; only its other spelling does' );
ok( true === $people['/notes/where-provenance-has-to-live']['variant'] && '/notes/where-provenance-has-to-live' === $people['/notes/where-provenance-has-to-live']['suggest'], 'that spelling is flagged as a variant of the page, not a broken link' );
ok( false === $people['/blog/the-free-version-already-shipped']['variant'], 'a moved page is not a variant' );
ok( array( '/old-name', '/blog/the-free-version-already-shipped', '/notes/where-provenance-has-to-live', '/old/{email}/x' ) === array_keys( $people ), 'people are sorted by human views, then the most recent' );
ok( '/notes/the-free-version-already-shipped' === $people['/blog/the-free-version-already-shipped']['suggest'], 'a moved note is suggested by its slug (the 404 log\'s suggester)' );
ok( null === $people['/old-name']['suggest'], 'nothing close enough: no suggestion, never a guess below the 404 log\'s floor' );
ok( null === $people['/old/{email}/x']['suggest'] && 'suspect' === $people['/old/{email}/x']['class'], 'a masked path gets no guess, and keeps its class' );
ok( null === $people['/old-name']['status'], 'status reads null: not recorded until the beacon sends it' );
$auto = array_column( $r['automated'], null, 'path' );
ok( isset( $auto['/wp-login.php'] ) && $auto['/wp-login.php']['probe'] && null === $auto['/wp-login.php']['suggest'], 'a probe goes to automated, even as human, with no suggestion' );
ok( isset( $auto['/some/page'] ) && 'bot' === $auto['/some/page']['class'], 'a path only bots reached goes to automated' );

echo "\nCase and slash variants\n";
$variant = snt_unmatched_report_rows( array( array( 'path' => '/about/', 'class' => 'human', 'first_day' => '2026-10-29', 'last_day' => '2026-10-29', 'views' => 1, 'visits' => 1 ) ), $real, $suggest, $is_probe );
ok( array() === $variant['people'] && array() === $variant['automated'], 'a trailing-slash spelling of content was always content: canonical paths match' );
$case = snt_unmatched_report_rows( array( array( 'path' => '/ABOUT', 'class' => 'human', 'first_day' => '2026-10-29', 'last_day' => '2026-10-29', 'views' => 1, 'visits' => 1 ) ), $real, $suggest, $is_probe );
ok( '/about' === ( $case['people'][0]['suggest'] ?? null ) && true === $case['people'][0]['variant'], 'a case variant of a page is that page, flagged as a variant' );
$merged = snt_unmatched_report_rows( array(
	array( 'path' => '/Gone', 'class' => 'human', 'first_day' => '2026-10-03', 'last_day' => '2026-10-04', 'views' => 2, 'visits' => 2 ),
	array( 'path' => '/gone/', 'class' => 'suspect', 'first_day' => '2026-10-01', 'last_day' => '2026-10-09', 'views' => 1, 'visits' => 1 ),
), $real, $suggest, $is_probe )['people'];
ok( 1 === count( $merged ) && 2 === $merged[0]['human'] && 1 === $merged[0]['suspect'] && '2026-10-01' === $merged[0]['first_seen'] && '2026-10-09' === $merged[0]['last_seen'], 'spellings of one path merge, keeping each class and the widest first and last seen' );

echo "\nWhat the 404 log saw\n";
$log    = array(
	'/Blog/The-Free-Version-Already-Shipped' => array( 'count' => 3, 'first_seen' => 1, 'last_seen' => 2, 'referer' => 'https://www.LinkedIn.com/feed/?trk=jane@example.com' ),
	'/old-name'                              => array( 'count' => 1, 'first_seen' => 1, 'last_seen' => 2, 'referer' => '' ),
);
$joined = array_column( snt_unmatched_join_404( $r['people'], $log ), null, 'path' );
ok( 404 === $joined['/blog/the-free-version-already-shipped']['status'], 'a path the 404 log holds reads 404, matched case-insensitively' );
ok( 'linkedin.com' === $joined['/blog/the-free-version-already-shipped']['referrer'], 'its referrer is a hostname only: the referring URL\'s path and query never come through' );
ok( 404 === $joined['/old-name']['status'] && null === $joined['/old-name']['referrer'], 'no referrer logged reads null, never a guess' );
ok( null === $joined['/old/{email}/x']['status'], 'a path the log does not hold keeps status null' );

echo "\nThe suggester runs only for the people rows shown\n";
$calls   = array();
$counted = static function ( $c ) use ( &$calls, $suggest ) { $calls[] = $c; return $suggest( $c ); };
$lim     = snt_unmatched_report_rows( $rows, $real, $counted, $is_probe, 1 );
ok( array( $lim['people'][0]['path'] ) === $calls, 'with a limit of 1, the suggester runs once, for the top person row, and never for a probe or bot row' );
$past = array_filter( array_slice( $lim['people'], 1 ), static fn( $p ) => ! $p['variant'] );
ok( count( $past ) > 0 && array( null ) === array_values( array_unique( array_column( $past, 'suggest' ) ) ), 'every person row past the limit carries no suggestion unless it is a spelling of a page' );

echo "\nThe live check\n";
$live  = array( '/provhub' => 200, '/notes/subscribe' => 301, '/tools' => 404, '/quiet' => null );
$asked = array();
$check = static function ( $c ) use ( &$asked, $live ) { $asked[] = $c; return $live[ $c ] ?? null; };
$rows  = array(
	array( 'path' => '/old/{email}/x', 'variant' => false, 'status' => 404 ),
	array( 'path' => '/notes/x', 'variant' => true, 'status' => null ),
	array( 'path' => '/provhub', 'variant' => false, 'status' => null ),
	array( 'path' => '/notes/subscribe', 'variant' => false, 'status' => null ),
	array( 'path' => '/tools', 'variant' => false, 'status' => null ),
	array( 'path' => '/quiet', 'variant' => false, 'status' => 404 ),
	array( 'path' => '/past-the-limit', 'variant' => false, 'status' => null ),
);
$out = array_column( snt_unmatched_check_live( $rows, $check, 4 ), null, 'path' );
ok( array( '/provhub', '/notes/subscribe', '/tools', '/quiet' ) === $asked, 'it asks about the first rows up to the limit, never a placeholder path or another spelling of a page' );
ok( 'page' === $out['/provhub']['served'] && 200 === $out['/provhub']['status'], 'a 2xx is served by the site' );
ok( 'redirect' === $out['/notes/subscribe']['served'] && 301 === $out['/notes/subscribe']['status'], 'a 3xx is a redirect that already sends it on' );
ok( null === $out['/tools']['served'] && 404 === $out['/tools']['status'], 'a 404 stays a broken link, with the status it got' );
ok( null === $out['/quiet']['served'] && 404 === $out['/quiet']['status'], 'no answer keeps what the 404 log said' );
ok( null === $out['/past-the-limit']['served'] && null === $out['/past-the-limit']['status'], 'a row past the limit is not asked about' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
