<?php
/**
 * The cache verdict's machine reader.
 *
 * `snt_cf_freshness_summary()` had two renderers and no agent reader since
 * v11.29.0. Six releases went into making that readout correct (v13.86.0 →
 * v13.91.1) and every one was verified by asking the owner to look at a widget
 * and describe it back.
 *
 * Third instrument-with-no-agent-reader here in a week. Each got one when
 * somebody asked a question it could not answer, never at review.
 *
 * @since 13.92.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__sum'] = null;
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function snt_cf_freshness_summary() { return $GLOBALS['__sum']; }
function snt_cf_freshness_headline( $last ) { return 'HEADLINE:' . $last; }

require __DIR__ . '/../inc/abilities-cache-freshness.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "cache-freshness ability (v13.92.0)\n\n";

// --- NULL is never a clean edge -------------------------------------------
// The 2026-08-15 failure was a green readout over a 27-hour-old render. A
// summary that has never recorded anything must not read as fine.
$GLOBALS['__sum'] = null;
$r = snt_ability_cache_freshness( null );
ok( 'never_probed' === $r['state'], 'no summary at all reports never_probed' );
ok( 'fresh' !== $r['last'] && 'unknown' === $r['last'], 'and never as fresh' );
ok( null === $r['last_time'] && null === $r['last_iso'], 'with no invented timestamp' );

// --- a real verdict rides through -----------------------------------------
$GLOBALS['__sum'] = array(
	'last' => 'fresh', 'last_time' => 1788400000,
	'headline' => 'Edge fresh', 'phrase' => 'verified 4 mins ago',
	'total' => 12, 'stale' => 1, 'escalated' => 0,
);
$r = snt_ability_cache_freshness( null );
ok( 'recorded' === $r['state'] && 'fresh' === $r['last'], 'a recorded verdict rides through' );
ok( 1788400000 === $r['last_time'] && gmdate( 'c', 1788400000 ) === $r['last_iso'],
	'with epoch AND ISO — correlating a verdict against a deploy should not need mental arithmetic' );
ok( 'Edge fresh' === $r['headline'] && 'verified 4 mins ago' === $r['phrase'],
	'carrying the SAME words both widgets render, so a third surface cannot phrase it differently' );
ok( 12 === $r['post_save']['probes'] && 1 === $r['post_save']['stale'],
	'and the post-save figures, which manual purges cannot move' );

// --- pending is a KNOWN state, not a failure ------------------------------
// An auto purge — what a plugin update fires — writes no verdict until its
// deferred verify lands. v13.87.2 read that as unknown and blanked the readout
// on every update.
$GLOBALS['__sum'] = array( 'last' => 'pending', 'last_time' => 1788400000, 'headline' => 'Purge dispatched, verifying', 'phrase' => 'purged 4 mins ago, verifying', 'total' => 0, 'stale' => 0, 'escalated' => 0 );
$r = snt_ability_cache_freshness( null );
ok( 'pending' === $r['last'], 'pending survives as its own state' );
ok( 'recorded' === $r['state'] && null !== $r['last_time'],
	'and is RECORDED with a time — a purge happened and we know when, which is not "unknown"' );

// --- stale is not softened ------------------------------------------------
// A reader that could only say fresh or pending would be useless.
$GLOBALS['__sum'] = array( 'last' => 'stale', 'last_time' => 1788400000, 'headline' => 'Edge served a stale render', 'phrase' => 'last verdict 4 mins ago', 'total' => 5, 'stale' => 3, 'escalated' => 2 );
$r = snt_ability_cache_freshness( null );
ok( 'stale' === $r['last'] && 3 === $r['post_save']['stale'] && 2 === $r['post_save']['escalated'],
	'a STALE verdict and its escalations report plainly — this is not a fresh-only filter' );

// --- a zero time is null, not epoch zero ----------------------------------
$GLOBALS['__sum'] = array( 'last' => 'unknown', 'last_time' => 0, 'headline' => 'x', 'phrase' => '', 'total' => 0, 'stale' => 0, 'escalated' => 0 );
$r = snt_ability_cache_freshness( null );
ok( null === $r['last_time'] && null === $r['last_iso'], 'a zero timestamp reports null, never 1970' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
