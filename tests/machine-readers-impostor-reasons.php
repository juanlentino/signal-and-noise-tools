<?php
/**
 * Tests: the impostor reasons beyond Cloudflare's directory
 * (inc/machine-readers-basis-impostors.php, -credit-ladder.php, -rotator.php).
 *
 * rights-signals taxonomy 1.6.0 adds two signals for agents Cloudflare's
 * directory does not list: vendor_verified (blob16, the client IP checked
 * against the vendor's published ranges) and home_network (stamped at read
 * time from home_networks). The plugin adds a third, the rotator network: one
 * network presenting many vendors' agents, none of them verified. Live
 * 2026-10-18: PerplexityBot, Perplexity-User, CCBot, Bytespider and cohere-ai
 * sent about 2,400 reads in 14 days, mostly from "Google LLC".
 *
 * Run: php tests/machine-readers-impostor-reasons.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require __DIR__ . '/machine-readers-impostor-boot.php';

$D  = '2026-10-05'; // after SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
$G  = 'Google LLC';
$AZ = 'Amazon Technologies Inc.';
// $row( vendor, purpose, agent, hits, network, extra ) on day $D, unverified unless extra says.
$row = static function ( $vendor, $purpose, $agent, $hits, $net, array $x = array() ) use ( $D ) {
	return $x + array( 'family' => 'other-bot', 'surface' => 'html', 'vendor' => $vendor, 'purpose' => $purpose, 'agent' => $agent, 'day' => $D, 'hits' => $hits, 'taxonomy_version' => '1.6.0', 'first_party' => '0', 'verified_bot' => '', 'network' => $net, 'cf_verified' => '0' );
};
$home = static fn( $v ) => array( 'home_network' => $v );
$vv   = static fn( $v, $h = null ) => array( 'vendor_verified' => $v ) + ( null === $h ? array() : array( 'home_network' => $h ) );
$norm = static fn( array $raw ) => snt_mr_normalize_rows( array_values( $raw ) );
$one  = static function ( array $r, array $window = array() ) use ( $norm ) {
	$rows = $norm( array_merge( array( $r ), $window ) );
	return snt_mr_credit_verdict( $rows[0], snt_mr_credit_context( $rows ) );
};

echo "Group A: vendor ranges and home networks\n";
$v = $one( $row( 'perplexity', 'search', 'perplexity-bot', 10, $AZ, $vv( '1', '1' ) ) );
ok( array( 'credited', '' ) === array( $v['class'], $v['reason'] ) && 'vendor_verified' === $v['basis'], 'PerplexityBot in range: credited, basis vendor_verified' );
$v = $one( $row( 'perplexity', 'search', 'perplexity-bot', 10, $AZ, $vv( '0', '1' ) ) );
ok( 'credited' === $v['class'] && 'claimed' === $v['basis'], 'PerplexityBot out of range on Amazon: credited (claimed); an out-of-range IP alone is not enough' );
$v = $one( $row( 'perplexity', 'search', 'perplexity-bot', 10, $G, $vv( '0', '0' ) ) );
ok( array( 'impostor', 'vendor_ranges' ) === array( $v['class'], $v['reason'] ), 'PerplexityBot out of range on Google LLC: impostor, vendor_ranges' );
$v = $one( $row( 'perplexity', 'search', 'perplexity-bot', 10, $G, $vv( '', '0' ) ) );
ok( array( 'impostor', 'off_home_network' ) === array( $v['class'], $v['reason'] ), 'PerplexityBot not measured on Google LLC: falls to off_home_network' );
$v = $one( $row( 'bytedance', 'train', 'bytedance-bytespider', 10, 'Bytedance Inc.', $home( '1' ) ) );
ok( 'credited' === $v['class'], 'Bytespider on ByteDance\'s network: credited' );
$v = $one( $row( 'bytedance', 'train', 'bytedance-bytespider', 10, $G, $home( '0' ) ) );
ok( array( 'impostor', 'off_home_network' ) === array( $v['class'], $v['reason'] ), 'Bytespider on Google LLC: impostor, off_home_network' );
$v = $one( $row( 'bytedance', 'train', 'bytedance-bytespider', 10, '', $home( '0' ) ) );
ok( 'credited' === $v['class'], 'network not measured: never reclassified, whatever home_network says' );
$v = $one( $row( 'perplexity', 'search', 'perplexity-bot', 10, $G ) );
ok( 'credited' === $v['class'], 'a row from a sensor that sends neither field: credited (absent is not measured)' );
$n = $norm( array( $row( 'perplexity', 'search', 'perplexity-bot', 1, $G, $vv( '0', '' ) ), $row( 'perplexity', 'search', 'perplexity-bot', 1, $G, $vv( '', '' ) ) ) );
ok( '0' === $n[0]['vendor_verified'] && '' === $n[1]['vendor_verified'] && '' === $n[1]['home_network'], 'the normalizer keeps three states: \'1\', \'0\', \'\'' );

echo "\nGroup B: the rotator network\n";
$five = array();
foreach ( array( 'mistral' => 'mistral-user', 'meta' => 'meta-externalfetcher', 'openai' => 'openai-adsbot', 'discord' => 'social-discord', 'perplexity' => 'perplexity-user' ) as $vendor => $agent ) {
	$five[] = $row( $vendor, 'user', $agent, 3, $G );
}
$v = $one( $row( 'cohere', 'unknown', 'cohere-ai', 443, $G ), array_slice( $five, 0, 4 ) );
ok( array( 'impostor', 'rotator_network' ) === array( $v['class'], $v['reason'] ), 'cohere-ai on a network presenting 5 unverified vendor agents (itself + 4): impostor, rotator_network' );
$v = $one( $row( 'cohere', 'unknown', 'cohere-ai', 443, $G ), array_slice( $five, 0, 3 ) );
ok( 'credited' === $v['class'], 'cohere-ai on a network presenting 4: credited' );
$noise = array( $row( '', 'dev', 'dev-curl', 9, $G ), $row( '', 'dev', 'dev-go-http', 9, $G ), $row( 'signal-and-noise', 'ops', 'self-ledger', 9, $G, array( 'first_party' => '1' ) ), $row( 'google', 'train', 'google-extended', 9, $G ) );
$v = $one( $row( 'cohere', 'unknown', 'cohere-ai', 443, $G ), array_merge( array_slice( $five, 0, 3 ), $noise ) );
ok( 'credited' === $v['class'], 'dev agents (no vendor), first-party and control tokens do not count toward the five' );
$v = $one( $row( 'cohere', 'unknown', 'cohere-ai', 443, $G ), array_merge( array_slice( $five, 0, 4 ), array( $row( 'mistral', 'user', 'mistral-user', 1, $G, array( 'verified_bot' => 'AI Assistant' ) ) ) ) );
ok( 'credited' === $v['class'], 'an agent with a verified read from the network does not count toward the five' );
$v = $one( $row( 'cohere', 'unknown', 'cohere-ai', 443, $G, array( 'day' => '2026-09-20' ) ), $five );
ok( 'credited' === $v['class'] && 'unverifiable' === $v['verification'], 'an unverifiable row is never reclassified, by any rule' );
$v = $one( $row( 'anthropic', 'user', 'anthropic-claude-user', 72, $G, array( 'verified_bot' => 'AI Assistant', 'cf_verified' => '1' ) ), $five );
ok( 'credited' === $v['class'], 'Claude-User verified from Google LLC: still credited, rotator or not' );
$v = $one( $row( 'anthropic', 'train', 'anthropic-claudebot', 9, $G, array( 'cf_verified' => '1' ) ), $five );
ok( 'cf_directory' === $v['reason'], 'a cf_verified agent keeps its first reason, cf_directory, on a rotator network' );
ok( 5 === SN_MR_ROTATOR_MIN_AGENTS, 'the threshold is a named constant, 5' );
$pre = $norm( array_map( static fn( $r ) => array( 'day' => '2026-09-20' ) + $r, array_merge( $five, array( $row( 'cohere', 'unknown', 'cohere-ai', 1, $G ) ) ) ) );
ok( array() === snt_mr_credit_context( $pre )['rotators'], 'unverifiable rows do not make a rotator' );

echo "\nGroup C: unverifiable rows are never reclassified, by any rule\n";
foreach ( array( 'vendor_ranges' => $vv( '0', '0' ), 'off_home_network' => $home( '0' ) ) as $why => $x ) {
	$v = $one( $row( 'perplexity', 'search', 'perplexity-bot', 10, $G, array( 'day' => '2026-09-20' ) + $x ) );
	ok( 'credited' === $v['class'], "before the since date: not $why" );
}

echo "\nGroup D: counted, not credited, with a reason\n";
$window = array_merge(
	$five,
	array(
		$row( 'cohere', 'unknown', 'cohere-ai', 443, $G ),
		$row( 'perplexity', 'search', 'perplexity-bot', 432, $G, $vv( '0', '0' ) ),
		$row( 'perplexity', 'search', 'perplexity-bot', 100, $AZ, $vv( '1', '1' ) ),
		$row( 'bytedance', 'train', 'bytedance-bytespider', 461, $G, $home( '0' ) ),
		$row( 'anthropic', 'train', 'anthropic-claudebot', 1378, $G, array( 'cf_verified' => '1' ) ),
		$row( 'anthropic', 'user', 'anthropic-claude-user', 72, $G, array( 'verified_bot' => 'AI Assistant', 'cf_verified' => '1' ) ),
	)
);
$plain = array_map( static fn( $r ) => array( 'cf_verified' => '0', 'vendor_verified' => '', 'home_network' => '', 'network' => '' ) + $r, $window );
$GLOBALS['__mr'] = array( 'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => $norm( $plain ) ) );
$before = snt_mr_summary_payload( 30 );
$GLOBALS['__mr'] = array( 'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => $norm( $window ) ) );
$after = snt_mr_summary_payload( 30 );
ok( $before['total'] === $after['total'], 'total is unchanged' );
$imp  = array_column( (array) $after['impostors'], null, 'agent' );
$hits = array_sum( array_column( (array) $after['impostors'], 'hits' ) );
$sum  = static fn( $s ) => array_sum( array_map( static fn( $t ) => $t['claimed'], (array) $s['verification']['families'] ) );
ok( $sum( $before ) - $sum( $after ) === $hits && 15 + 443 + 432 + 461 + 1378 === $hits, "the per-family credited sum drops by exactly the impostor hits ($hits)" );
ok( array( 'rotator_network' => 443 ) === $imp['cohere-ai']['reasons'] && array( 'vendor_ranges' => 432 ) === $imp['perplexity-bot']['reasons'], 'each impostor carries a count per reason' );
ok( array( 'off_home_network' => 461 ) === $imp['bytedance-bytespider']['reasons'] && array( 'cf_directory' => 1378 ) === $imp['anthropic-claudebot']['reasons'], '... off_home_network and cf_directory too' );
ok( 'inferred' === $imp['cohere-ai']['basis'] && 'inferred' === $imp['perplexity-bot']['basis'], 'basis stays inferred for every reason' );
$fv = (array) $after['verification']['families'];
ok( 100 === $fv['other-bot']['vendor_verified'] && $fv['other-bot']['verified'] >= 172, 'a vendor-verified read counts as verified, and is labeled vendor_verified' );
ok( 'vendor_verified' === ( $after['verification']['basis']['vendor_verified'] ?? null ), 'the split names the vendor_verified basis' );
$reasons = snt_mr_impostor_reasons( $after['impostors'] );
ok( array( 'cf_directory' => 1378, 'vendor_ranges' => 432, 'off_home_network' => 461, 'rotator_network' => 458 ) === $reasons, 'the per-reason breakdown sums across agents, in ladder order' );

echo "\nGroup E: every attribution site takes the context\n";
$rows = $norm( $window );
$ctx  = snt_mr_credit_context( $rows );
$who  = array_sum( array_map( static fn( $f ) => $f['split']['claimed'], snt_mr_who_reads( $rows )['rows'] ) );
ok( $who === array_sum( array_map( static fn( $r ) => snt_mr_is_credited( $r, $ctx ) && empty( $r['first_party'] ) ? $r['hits'] : 0, $rows ) ), 'who reads credits exactly the credited rows' );
$k = sn_lanes_machine_key( $rows[5], $ctx );
ok( array( 'claimed_bot', 'impostor' ) === array( $k['lane'], $k['reason'] ), 'lanes: a rotator impostor is a claimed bot, reason impostor' );
$k = sn_lanes_machine_key( $rows[7], $ctx );
ok( array( 'verified_bot', 'vendor_verified' ) === array( $k['lane'], $k['reason'] ), 'lanes: a vendor-verified read is a verified bot, reason vendor_verified' );
$cells = array_column( snt_mr_crosstab( $rows )['cells'], null, 'agent' );
ok( 443 === $cells['cohere-ai']['impostor_hits'], 'the crosstab names the rotator part of a cell' );
$threw = false;
try {
	snt_mr_credit_class( $rows[0] );
} catch ( ArgumentCountError $e ) {
	$threw = true;
}
ok( $threw, 'the context is required: a call site that forgets it fails loudly' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
