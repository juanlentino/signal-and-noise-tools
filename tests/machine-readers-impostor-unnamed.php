<?php
/**
 * Tests: unnamed crawlers from a rotator network are impostors
 * (inc/machine-readers-basis-ladder.php).
 *
 * Live 2026-10-18: the operator on "Google LLC" that presents about twenty
 * vendors' agents, none verified, also sends names no taxonomy lists, built
 * from one template ("compatible GrokBot/1.0 +https //x.ai/", DeepSeekBot,
 * Qwenbot, KimiBot, ChatGLM-Spider ...): about 4,340 reads in 14 days, left in
 * agent '' and credited to other-bot. The aggregate row carries no user agent,
 * but its family does the work: the frozen classifier files a read under
 * other-bot only when the user agent carries a crawler token (bot/, spider,
 * crawler, scraper) and every dev-client token it also matches (curl/, wget/,
 * java/ ...) has a taxonomy entry, so agent '' in other-bot is a crawler name
 * no taxonomy entry knows.
 *
 * Run: php tests/machine-readers-impostor-unnamed.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require __DIR__ . '/lib/machine-readers-impostor-boot.php';
function snt_os_compat_add_filter( $a, $b, $cb ) {}
require __DIR__ . '/../inc/desktop-mode-payloads.php'; // the identity split behind every "who" surface.

$D   = '2026-10-05'; // after SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
$G   = 'Google LLC';
$row = static function ( $vendor, $agent, $hits, $net, array $x = array() ) use ( $D ) {
	return $x + array( 'family' => 'other-bot', 'surface' => 'html', 'vendor' => $vendor, 'purpose' => '' === $agent ? 'unknown' : 'user', 'agent' => $agent, 'day' => $D, 'hits' => $hits, 'taxonomy_version' => '1.6.0', 'first_party' => '0', 'verified_bot' => '', 'network' => $net, 'cf_verified' => '0' );
};
$norm = static fn( array $raw ) => snt_mr_normalize_rows( array_values( $raw ) );
// Five unverified vendor agents on Google LLC: the rotator rule already flags it.
$five = array();
foreach ( array( 'mistral' => 'mistral-user', 'meta' => 'meta-externalfetcher', 'openai' => 'openai-adsbot', 'cohere' => 'cohere-ai', 'perplexity' => 'perplexity-user' ) as $vendor => $agent ) {
	$five[] = $row( $vendor, $agent, 3, $G );
}
$one = static function ( array $r, array $window ) use ( $norm ) {
	$rows = $norm( array_merge( array( $r ), $window ) );
	return snt_mr_credit_verdict( $rows[0], snt_mr_credit_context( $rows ) );
};

echo "Group A: the verdict on an unnamed read\n";
$grok = $row( '', '', 477, $G ); // "compatible GrokBot/1.0 +https //x.ai/"
$v    = $one( $grok, $five );
ok( array( 'impostor', 'rotator_network', 'inferred' ) === array( $v['class'], $v['reason'], $v['basis'] ), 'GrokBot/1.0 (unnamed, other-bot) from a rotator network: impostor, rotator_network' );
$v = $one( $row( '', '', 244, $G, array( 'verified_bot' => 'Search Engine Crawler' ) ), $five );
ok( 'credited' === $v['class'] && 'verified' === $v['verification'], 'ShapBot verified on Google LLC: untouched' );
$v = $one( $row( '', '', 265, 'HOS-382394' ), $five );
ok( 'credited' === $v['class'], 'serpstatbot from its own network: untouched' );
$v = $one( $row( '', '', 50, $G, array( 'family' => 'search' ) ), $five );
ok( 'credited' === $v['class'], 'an unnamed read with no crawler token (not other-bot) from a rotator network: untouched' );
$v = $one( $row( '', '', 477, $G, array( 'day' => '2026-09-20' ) ), $five );
ok( 'credited' === $v['class'] && 'unverifiable' === $v['verification'], 'an unverifiable unnamed read: untouched' );
$v = $one( $row( '', '', 9, $G, array( 'first_party' => '1' ) ), $five );
ok( 'credited' === $v['class'], 'a first-party unnamed read: untouched' );
$v = $one( $grok, array_slice( $five, 0, 4 ) );
ok( 'credited' === $v['class'], 'the same read from a network below the threshold: untouched' );

echo "\nGroup B: unnamed reads are caught by a rotator, never make one\n";
$many = array();
for ( $i = 0; $i < 8; $i++ ) {
	$many[] = $row( '', '', 10 + $i, 'Example Net' );
}
ok( array() === snt_mr_credit_context( $norm( $many ) )['rotators'], 'eight unnamed crawler reads on one network do not make it a rotator' );

echo "\nGroup C: counted, not credited; totals and nesting hold\n";
$names  = array( 497, 477, 471, 461, 457, 432, 417, 412, 403, 168, 147 ); // the eleven names, 14 days.
$window = $five;
foreach ( $names as $h ) {
	$window[] = $row( '', '', $h, $G );
}
$window[] = $row( '', '', 244, $G, array( 'verified_bot' => 'Search Engine Crawler' ) ); // ShapBot.
$window[] = $row( '', '', 265, 'HOS-382394' );                                       // serpstatbot.
$plain    = array_map( static fn( $r ) => array( 'network' => '' ) + $r, $window );
$GLOBALS['__mr'] = array( 'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => $norm( $plain ) ) );
$before          = snt_mr_summary_payload( 14 );
$rows            = $norm( $window );
$GLOBALS['__mr'] = array( 'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => $rows ) );
$after           = snt_mr_summary_payload( 14 );
ok( $before['total'] === $after['total'], 'total is unchanged' );
$imp = array_column( (array) $after['impostors'], null, 'agent' );
ok( array_sum( $names ) === ( $imp['']['reasons']['rotator_network'] ?? null ), 'the unnamed impostor hits (' . array_sum( $names ) . ') are all rotator_network' );
$reasons = snt_mr_impostor_reasons( $after['impostors'] );
ok( array_sum( $names ) + 15 === $reasons['rotator_network'], 'the "network posing as many crawlers" row counts them with the vendor agents' );
$split = snt_desktop_machine_readers_identity( $rows );
$total = (int) snt_mr_impostor_total( $after['impostors'] );
ok( $total <= $split['unverified'] && 244 === $split['verified'], 'the "failed a check" subtotal stays inside "Named themselves, not verified"' );
ok( array_sum( $names ) + 15 + 265 === $split['unverified'], 'the parent counts unnamed unverified reads' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
