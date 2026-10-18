<?php
/**
 * Tests: the machine-reader evidence as rows (2026-10-18 redesign). One
 * vocabulary (inc/machine-readers-impostor-copy.php) for every surface, the
 * identity split's new parts (vendor ranges, control tokens) and /stats'
 * nested rows. The desktop card is pinned in tests/desktop-status-resilience.cjs,
 * Reader evidence in tests/analytics-machine-views.php.
 *
 * Run: php tests/machine-readers-evidence-rows.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require __DIR__ . '/lib/machine-readers-impostor-boot.php';
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function snt_os_compat_add_filter( $a, $b, $cb ) {}
require __DIR__ . '/../inc/desktop-mode-payloads.php';
require __DIR__ . '/../inc/public-stats-sections.php';
require __DIR__ . '/../inc/public-stats-machines.php';

echo "Group A: one vocabulary\n";
$L = snt_mr_reader_labels();
$keys = array( 'verified', 'by_cloudflare', 'by_vendor', 'unverified', 'failed_check', 'cf_directory', 'vendor_ranges', 'off_home_network', 'rotator_network', 'control_token', 'not_measured', 'first_party', 'ai_training' );
ok( $keys === array_keys( $L ), 'every row the surfaces print has one entry, in display order' );
$all = implode( ' ', array_merge( array_column( $L, 'label' ), array_column( $L, 'title' ) ) );
ok( array() === array_filter( $L, static fn( $w ) => '' === (string) ( $w['label'] ?? '' ) || '' === (string) ( $w['title'] ?? '' ) ), 'each has a short label and a long description' );
ok( false === strpos( $all, "\u{2014}" ) && false === strpos( $all, '_' ) && 0 === preg_match( '/cf_|vendor_|home_network|rotator/', $all ), 'no em dash and no internal names (the words reach the public page)' );
ok( max( array_map( static fn( $w ) => strlen( $w['label'] ), $L ) ) <= 40, 'labels are short: the sentence is the description' );
ok( array( 'Cloudflare’s check', 'outside the vendor’s network', 'network posing as many crawlers' ) === array( $L['cf_directory']['label'], $L['off_home_network']['label'], $L['rotator_network']['label'] ), 'the reason labels' );
$rr = snt_mr_reason_rows( array( 'rotator_network' => 504, 'vendor_ranges' => 0, 'cf_directory' => 8892, 'off_home_network' => 2087 ) );
ok( array( array( 'reason' => 'cf_directory', 'hits' => 8892 ), array( 'reason' => 'off_home_network', 'hits' => 2087 ), array( 'reason' => 'rotator_network', 'hits' => 504 ) ) === $rr, 'reason rows: ladder order, a zero reason hidden' );
ok( array() === snt_mr_reason_rows( null ) && array() === snt_mr_reason_rows( array( 'bogus' => 9 ) ), 'no reasons, or none known: no rows' );
ok( '83 by Cloudflare, 140 by the vendor’s published ranges' === snt_mr_verified_by( 223, 140, true ) && '83 by Cloudflare' === snt_mr_verified_by( 83, 0, false ), 'verified, by source; the vendor part only once measured' );
ok( '1,719 claimed, credited to their vendor: 83 verified (83 by Cloudflare, 0 by the vendor’s published ranges), 1,500 named themselves, not verified, 136 before verification was recorded.' === snt_mr_ai_training_title( array( 'claimed' => 1719, 'verified' => 83, 'vendor_verified' => 0, 'unverified' => 1500, 'unverifiable' => 136 ), true ), 'the AI-training description carries the claimed figure: ' . snt_mr_ai_training_title( array( 'claimed' => 1719, 'verified' => 83, 'vendor_verified' => 0, 'unverified' => 1500, 'unverifiable' => 136 ), true ) );

echo "\nGroup B: the identity split's parts\n";
$D   = '2026-10-05';
$row = static fn( $hits, array $x = array() ) => $x + array( 'day' => $D, 'hits' => $hits, 'verified_bot' => '', 'network' => 'Google LLC', 'agent' => 'openai-gptbot', 'vendor' => 'openai' );
$id  = snt_desktop_machine_readers_identity( array( $row( 7, array( 'verified_bot' => 'AI Crawler' ) ), $row( 5 ), $row( 2, array( 'agent' => 'google-extended', 'vendor' => 'google' ) ), $row( 1, array( 'agent' => 'google-extended', 'vendor' => 'google', 'verified_bot' => 'search' ) ) ) );
ok( array( 'verified' => 8, 'unverified' => 7, 'not_measured' => 0, 'first_party' => 0, 'vendor_verified' => null, 'control_token' => 2 ) === $id, 'no row carried the vendor-ranges field: not measured (null), never 0; control tokens counted where they sit, among the unverified' );
$id  = snt_desktop_machine_readers_identity( array( $row( 4, array( 'vendor_verified' => '1' ) ), $row( 3, array( 'vendor_verified' => '0' ) ) ) );
ok( 4 === $id['verified'] && 4 === $id['vendor_verified'] && 3 === $id['unverified'], 'vendor ranges measured: their part of verified, by count' );
ok( 0 === snt_desktop_machine_readers_identity( array( $row( 3, array( 'vendor_verified' => '0' ) ) ) )['vendor_verified'], 'measured and none: 0, a real figure' );

// tests/public-stats.php stubs this function; a stub that drifts from the real
// shape would let /stats' tests pass against a fake.
preg_match( "/function snt_desktop_machine_readers_identity[^{]*\\{.*?\\\$out = array\\( (.*?) \\);/s", (string) file_get_contents( __DIR__ . '/public-stats.php' ), $stub );
preg_match_all( "/'(\\w+)' =>/", $stub[1] ?? '', $sk );
ok( array_keys( snt_desktop_machine_readers_identity( array() ) ) === $sk[1], 'the /stats suite\'s identity stub has the real keys: ' . implode( ', ', $sk[1] ) );

echo "\nGroup C: /stats, nested rows\n";
$m = array(
	'total'            => 121968,
	'split'            => array( 'verified' => 10260, 'unverified' => 26359, 'not_measured' => 15848, 'first_party' => 69501, 'vendor_verified' => 0, 'control_token' => 524 ),
	'train'            => array( 'claimed' => 1719, 'verified' => 83, 'verified_matched' => 80, 'vendor_verified' => 0, 'unverified' => 1500, 'unverifiable' => 136 ),
	'tokens'           => array( array( 'token' => 'Google-Extended', 'hits' => 524 ) ),
	'impostors'        => 11483,
	'impostor_reasons' => array( 'cf_directory' => 8892, 'off_home_network' => 2087, 'rotator_network' => 504 ),
);
$h = sn_public_stats_machines_html( array( 'views' => 9800, 'machines' => $m ) );
preg_match_all( '/<li(?: data-depth="(\d)")?><span class="sn-public-stats__bar-label">([^<]*)<\/span>.*?<span class="sn-public-stats__bar-value">([^<]*)<\/span><\/li>/s', substr( $h, (int) strpos( $h, 'Who the machines are' ) ), $mm, PREG_SET_ORDER );
$got = array_map( static fn( $x ) => (int) $x[1] . '|' . html_entity_decode( $x[2], ENT_QUOTES ) . '|' . html_entity_decode( $x[3], ENT_QUOTES ), $mm );
$want = array( '0|Verified|8%', '1|by Cloudflare|8%', '1|by the vendor’s published ranges|0%', '0|Named themselves, not verified|22%', '1|failed a check|9%', '2|Cloudflare’s check|7%', '2|outside the vendor’s network|2%', '2|network posing as many crawlers|<1%', '1|control token (Google-Extended)|<1%', '0|Not measured|13%', '0|This site’s own tools|57%' );
ok( $want === $got, 'who the machines are: parts with their nested rows, in order: ' . implode( ', ', $got ) );
ok( false === strpos( $h, 'cf_directory' ) && false === strpos( $h, 'off_home_network' ), 'no internal names on the public page' );
ok( false !== strpos( $h, 'Failed a check: ' . $L['failed_check']['title'] ), 'the long description, said once under the rows in plain words' );
$m2 = $m;
$m2['split']['vendor_verified'] = null;
$m2['impostor_reasons'] = array( 'cf_directory' => 11483 );
$h2 = sn_public_stats_machines_html( array( 'views' => 9800, 'machines' => $m2 ) );
ok( false === strpos( $h2, 'published ranges' ) && false === strpos( $h2, 'posing as many' ), 'vendor ranges not measured: no row; a zero reason: no row' );
$m3 = $m;
unset( $m3['split']['vendor_verified'], $m3['split']['control_token'] );
$h3 = sn_public_stats_machines_html( array( 'views' => 9800, 'machines' => $m3 ) );
ok( 1 === preg_match( '/Verified<\/span>.*?bar-value">8%</s', $h3 ) && false === strpos( $h3, 'published ranges' ), 'an older snapshot without the new parts: the shares still add up to the four parts' );
$t = sn_public_stats_training_html( array( 'machines' => $m ) );
ok( false === strpos( $t, 'Claimed a known crawler' ) && false === strpos( $t, 'robots.txt token, not a crawler' ), 'the training section no longer carries the two run-on sentences' );
ok( false !== strpos( $t, 'Verified by Cloudflare <span class="sn-public-stats__key-n">83</span>' ) && false !== strpos( $t, 'Verified by the vendor’s published ranges <span class="sn-public-stats__key-n">0</span>' ), 'the key names verified by source once vendor ranges are measured' );

echo "\nGroup D: the desktop card carries no vocabulary of its own\n";
$js = (string) file_get_contents( __DIR__ . '/../assets/desktop-mode-widget-anchors.js' );
ok( 0 === preg_match( "/'[^'\\n]*(Cloudflare\\\\u2019s check|published (IP )?ranges|posing as many|failed a check|Named themselves|Control tokens|Verified by|own tools)[^'\\n]*'/i", $js ), 'the reader-evidence words come from the payload (labels): no string in the script names them' );
$w = snt_mr_reader_words( array( 'impostors' => array( array( 'agent' => 'a', 'hits' => 9, 'reasons' => array( 'rotator_network' => 4, 'cf_directory' => 5 ) ) ), 'verification' => array( 'ai_training_by_purpose' => array( 'claimed' => 10, 'verified' => 2, 'vendor_verified' => 0, 'unverified' => 8, 'unverifiable' => 0 ) ) ), true );
ok( $L === $w['labels'] && array( array( 'reason' => 'cf_directory', 'hits' => 5 ), array( 'reason' => 'rotator_network', 'hits' => 4 ) ) === $w['impostor_reasons'] && 0 === strpos( $w['ai_training_title'], '10 claimed' ), 'the desktop payload ships the labels, the reason rows and the AI-training description' );

echo "\n$pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
