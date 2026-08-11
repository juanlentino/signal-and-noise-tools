<?php
/**
 * Tests: the operator map — the named gate on the give-back ratio.
 *
 * The board row states the gate in its own sentence: "landing once an explicit
 * operator map names which crawler families and which referrer hosts are the
 * same company". This file is that map's contract.
 *
 * THE TRAP THIS EXISTS FOR: the crawler taxonomy and the AI-referrer list are
 * two different vocabularies. `GPTBot` is a user-agent family; `chatgpt.com` is
 * a referrer host resolved to the brand label "ChatGPT". They are the same
 * COMPANY and nothing in either list says so. A string match happens to work for
 * `perplexity`/`Perplexity` and fails silently for every other pair — which is
 * the worst possible failure, because the one that works makes the technique
 * look sound.
 *
 * The load-bearing assertions here are the COMPLETENESS ones: every family and
 * every AI source label must be either mapped to an operator or explicitly
 * listed as unmapped WITH A REASON. Adding a value to either enum without
 * deciding where it belongs fails this suite rather than silently dropping out
 * of a ratio.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-api.php';
require __DIR__ . '/../inc/machine-readers-operators.php';

$ops       = snt_mr_operators();
$unmapped  = snt_mr_unmapped_families();
$families  = snt_mr_valid_families();
// The referrer side's closed list, read from the map's own declaration of it so
// the two never drift apart silently.
$ai_labels = snt_mr_ai_source_labels();

echo "Group: the join is EXPLICIT — the pair that a string match would miss\n";
ok( 'openai' === snt_mr_operator_for_family( 'openai' ), 'the openai crawler family resolves to the openai operator' );
ok( 'openai' === snt_mr_operator_for_source( 'ChatGPT' ), 'the ChatGPT referrer label resolves to the SAME operator' );
ok( snt_mr_operator_for_family( 'openai' ) === snt_mr_operator_for_source( 'ChatGPT' ), 'GPTBot and chatgpt.com are one company — the whole point of the map' );
ok( 'google' === snt_mr_operator_for_family( 'google-ai' ) && 'google' === snt_mr_operator_for_source( 'Gemini' ), 'google-ai and Gemini join, and share no substring' );
ok( 'mistral' === snt_mr_operator_for_source( 'Le Chat' ), '"Le Chat" joins to mistral — a label a string match could never reach' );

echo "\nGroup: COMPLETENESS — every family is decided, none silently dropped\n";
$mapped_families = array();
foreach ( $ops as $key => $op ) {
	foreach ( $op['families'] as $f ) { $mapped_families[] = $f; }
}
$accounted = array_merge( $mapped_families, array_keys( $unmapped ) );
sort( $accounted );
$sorted_families = $families; sort( $sorted_families );
ok( $accounted === $sorted_families, 'every crawler family is either mapped to an operator or explicitly unmapped — no silent omissions' . ( $accounted === $sorted_families ? '' : ' — missing: ' . implode( ',', array_diff( $sorted_families, $accounted ) ) ) );
ok( count( $mapped_families ) === count( array_unique( $mapped_families ) ), 'no family belongs to two operators (a double-count in the numerator)' );
foreach ( $unmapped as $fam => $reason ) {
	ok( is_string( $reason ) && strlen( $reason ) > 12, "the unmapped family '$fam' states WHY, not just that it is unmapped" );
}

echo "\nGroup: COMPLETENESS — every AI referrer label is decided too\n";
$mapped_sources = array();
foreach ( $ops as $op ) {
	foreach ( $op['sources'] as $s ) { $mapped_sources[] = $s; }
}
$missing_sources = array_diff( $ai_labels, $mapped_sources );
ok( array() === $missing_sources, 'every AI source label belongs to an operator' . ( $missing_sources ? ' — missing: ' . implode( ',', $missing_sources ) : '' ) );
ok( count( $mapped_sources ) === count( array_unique( $mapped_sources ) ), 'no source label belongs to two operators' );
$stray = array_diff( $mapped_sources, $ai_labels );
ok( array() === $stray, 'the map invents no source label the referrer list does not have' . ( $stray ? ' — invented: ' . implode( ',', $stray ) : '' ) );

echo "\nGroup: the asymmetries are REAL and must survive\n";
// An operator can crawl without ever sending a reader, and can send readers
// without a crawler family this site can distinguish. Both are normal, and a map
// that cannot express them would force a wrong answer rather than an absent one.
ok( array() === $ops['commoncrawl']['sources'], 'Common Crawl crawls and has no assistant — sources is EMPTY, not invented' );
ok( array() === $ops['microsoft']['families'], 'Copilot refers readers but has no AI crawler family here — families is EMPTY' );
ok( ! empty( $ops['commoncrawl']['families'] ), 'and Common Crawl does have a family' );
ok( ! empty( $ops['microsoft']['sources'] ), 'and Microsoft does have a source' );

echo "\nGroup: non-AI crawler families are NOT operators\n";
foreach ( array( 'search', 'seo', 'feed', 'uptime', 'other-bot', 'unclassified-machine' ) as $f ) {
	ok( null === snt_mr_operator_for_family( $f ), "'$f' resolves to no operator — it is not an AI company" );
	ok( isset( $unmapped[ $f ] ), "'$f' is on the unmapped list with a reason" );
}

echo "\nGroup: unknown input is UNKNOWN, never a guess\n";
ok( null === snt_mr_operator_for_family( 'not-a-family' ), 'an unknown family returns null' );
ok( null === snt_mr_operator_for_source( 'Bing' ), 'a non-AI source label returns null (Bing is search, not an assistant)' );
ok( null === snt_mr_operator_for_source( '' ), 'an empty label returns null' );
ok( null === snt_mr_operator_for_family( 'OpenAI' ), 'family lookup is exact — the enum is lowercase, and a near-miss must not resolve' );

echo "\nGroup: every operator is well-formed\n";
foreach ( $ops as $key => $op ) {
	ok( isset( $op['label'] ) && '' !== $op['label'], "operator '$key' has a human label" );
	ok( isset( $op['families'] ) && is_array( $op['families'] ) && isset( $op['sources'] ) && is_array( $op['sources'] ), "operator '$key' has both sides declared, even when empty" );
	ok( ! empty( $op['families'] ) || ! empty( $op['sources'] ), "operator '$key' is attached to at least one vocabulary" );
	foreach ( $op['families'] as $f ) {
		ok( in_array( $f, $families, true ), "operator '$key' references '$f', a family the sensor can actually emit" );
	}
}

echo "\nGroup: the mirrored label list must not drift from the real one\n";
// snt_mr_ai_source_labels() is a DECLARED mirror of the 'ai'-category rules in
// inc/analytics-sources.php, because this data layer must not depend on a
// render-side fold. A mirror is only safe if something notices when it drifts,
// so read the real list out of that file and compare. Parsed from source rather
// than loaded: sn_analytics_source_rules() pulls in WP-dependent neighbours.
$src = (string) file_get_contents( __DIR__ . '/../inc/analytics-sources.php' );
preg_match_all( "/\\\$r\\(\\s*'([^']+)',\\s*'ai'/", $src, $m );
$real = $m[1];
ok( ! empty( $real ), 'the ai-category labels were actually parsed out of analytics-sources.php (a regex that matched nothing would pass everything below)' );
sort( $real );
$mirror = snt_mr_ai_source_labels();
sort( $mirror );
ok( $real === $mirror, 'the mirrored label list matches the real one exactly' . ( $real === $mirror ? '' : ' — real: ' . implode( ',', $real ) . ' | mirror: ' . implode( ',', $mirror ) ) );

echo "\nGroup: measurability is a property callers must be able to ASK\n";
ok( true === snt_mr_operator_is_measurable( 'openai' ), 'openai has a denominator — a ratio is askable' );
ok( false === snt_mr_operator_is_measurable( 'microsoft' ), 'microsoft has NO crawler family — its ratio is unknown, not zero' );
ok( true === snt_mr_operator_is_measurable( 'commoncrawl' ), 'commoncrawl has a denominator; "never referred" will be a real answer' );
ok( false === snt_mr_operator_is_measurable( 'nope' ), 'an unknown operator is not measurable' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
