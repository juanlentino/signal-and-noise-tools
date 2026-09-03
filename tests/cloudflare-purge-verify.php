<?php
/**
 * Tests: per-post Cloudflare purge verification (v11.10.0).
 *
 * Born from 2026-08-15: three per-post purges fired for one Note and the edge
 * kept serving a 27-hour-old render for fifty minutes. The purge path is
 * fire-and-forget and could not report that it had failed, so the readout
 * stayed green while the public ledger went red three times.
 *
 * The pure decision is driven through every shape two fetches can take.
 *
 * Run: php tests/cloudflare-purge-verify.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// apply_filters is the only WP call the pure half makes.
function apply_filters( $hook, $value ) { return $value; }

// v11.29.0: the summary accessor reads the probe log option.
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

// v13.87.2: the module now produces the shared human phrase both surfaces
// render, so the harness needs the i18n + age helpers it leans on.
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) { return $t; }
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) { return ( (int) $to - (int) $from ) . 's'; }
}

require __DIR__ . '/../inc/cloudflare-purge-verify.php';

echo "Group: staleness decision\n";

$page = '<html><body><p>The master never moves.</p></body></html>';
ok( false === snt_cf_probe_is_stale( $page, $page ), 'identical renders are not stale' );

$stale_body = '<html><body><p>The master never moves.</p><p>There is no diff to read.</p></body></html>';
ok( true === snt_cf_probe_is_stale( $stale_body, $page ), 'the 2026-08-15 shape: cached copy carries a sentence the origin no longer serves' );

// ── v11.29.1: the false positive that fired eleven zone purges ──────────────
// Measured against the live site 2026-08-19. The two fetches this probe
// compares are NOT comparable as whole documents:
//   1. Breeze injects <script id="breeze-prefetch-js-extra"> on the
//      cache-busted request and not on the cached one — a different code path
//      through the caching plugin, present on every URL.
//   2. The cached copy is MINIFIED (inter-tag whitespace AND HTML comments
//      stripped); the cache-busted copy is not. /about/ measured 122,960 vs
//      132,288 bytes.
// So every probe returned stale, every one escalated to a full zone purge, and
// the log read 11 stale of 11. Not a stale edge — a detector that could not
// return anything else.

$cached_min = '<html><head><link rel="canonical" href="/x/"><script id="a"></script></head><body><main><div class="hero"><p>One truth.</p></div></main><footer>f</footer></body></html>';

$fresh_full = <<<'HTML'
<html>
 <head>
  <link rel="canonical" href="/x/">
  <script id="breeze-prefetch-js-extra">var breeze_prefetch = {"local_url":"https://x.test"};</script>
  <script id="a"></script>
 </head>
 <body>
  <main>
   <!-- HERO SECTION - full-width. -->
   <div class="hero">
    <p>One truth.</p>
   </div>
  </main>
  <footer>f</footer>
 </body>
</html>
HTML;

ok( false === snt_cf_probe_is_stale( $cached_min, $fresh_full ),
	'A MINIFIED CACHED COPY vs AN UNMINIFIED FRESH ONE IS NOT STALE - the eleven-zone-purge bug' );

// Each cause on its own, so a partial fix cannot pass.
ok( false === snt_cf_probe_is_stale( '<main><p>a</p></main>', "<main>\n  <p>a</p>\n</main>" ),
	'inter-tag whitespace alone is not staleness' );
ok( false === snt_cf_probe_is_stale( '<main><p>a</p></main>', '<main><!-- a note --><p>a</p></main>' ),
	'an HTML comment alone is not staleness' );
ok( false === snt_cf_probe_is_stale(
	'<html><head><script id="a"></script></head><body><main><p>a</p></main></body></html>',
	'<html><head><script id="breeze-prefetch-js-extra">var b={};</script><script id="a"></script></head><body><main><p>a</p></main></body></html>' ),
	'a head-injected caching script alone is not staleness' );

// AND THE DETECTOR MUST STILL DETECT. Real drift inside <main> survives every
// one of those normalisations.
ok( true === snt_cf_probe_is_stale(
	'<html><body><main><p>The old sentence.</p></main></body></html>',
	"<html>\n<body>\n<main>\n  <!-- c -->\n  <p>The new sentence.</p>\n</main>\n</body>\n</html>" ),
	'REAL CONTENT DRIFT IS STILL CAUGHT through minification, comments and injection' );

// A page with no <main> falls back to the whole document rather than comparing
// nothing - a theme without that element must not silently always read fresh.
ok( true === snt_cf_probe_is_stale( '<html><body><p>old</p></body></html>', '<html><body><p>new</p></body></html>' ),
	'a document with no <main> still compares' );

echo "\nGroup: unknown is never fresh\n";
// A probe that could not answer must not report "fresh" — the caller escalates
// on true only, so null correctly does nothing, but false would be a LIE that
// gets recorded in the log as a clean bill of health.
ok( null === snt_cf_probe_is_stale( null, $page ), 'an unreadable bare fetch is unknown, not fresh' );
ok( null === snt_cf_probe_is_stale( $page, null ), 'an unreadable origin fetch is unknown, not fresh' );
ok( null === snt_cf_probe_is_stale( null, null ), 'two unreadable fetches are unknown' );
ok( null === snt_cf_probe_is_stale( '', $page ), 'an empty body is unknown, not an empty page' );
ok( null === snt_cf_probe_is_stale( '   ', $page ), 'a whitespace-only body is unknown' );

echo "\nGroup: volatile tokens are not staleness\n";
// These differ on EVERY fetch. Without normalization the probe would report
// every page as stale and escalate a zone purge on every single post save —
// far worse than the bug it fixes.
$with_nonce_a = '<form><input name="_wpnonce" value="abc123" /><p>Body</p></form>';
$with_nonce_b = '<form><input name="_wpnonce" value="zzz999" /><p>Body</p></form>';
ok( false === snt_cf_probe_is_stale( $with_nonce_a, $with_nonce_b ), 'a differing form nonce is not staleness' );

$json_nonce_a = '<script>var s={"nonce":"aaa"};</script><p>Body</p>';
$json_nonce_b = '<script>var s={"nonce":"bbb"};</script><p>Body</p>';
ok( false === snt_cf_probe_is_stale( $json_nonce_a, $json_nonce_b ), 'a differing inline JSON nonce is not staleness' );

$ver_a = '<link href="/style.css?ver=1.2.3"><p>Body</p>';
$ver_b = '<link href="/style.css?ver=1.2.4"><p>Body</p>';
ok( false === snt_cf_probe_is_stale( $ver_a, $ver_b ), 'a differing asset ?ver= is not staleness' );

ok( false === snt_cf_probe_is_stale( "<p>Body</p>\n\n  <p>More</p>", '<p>Body</p> <p>More</p>' ), 'whitespace differences are not staleness' );

// The probe adds its own query arg to the fresh URL; if it echoes into markup
// (canonical tags, self-referencing links) it must not read as a difference.
$probe_echo = '<link rel="canonical" href="https://x/notes/a/?sn-cache-probe=1"><p>Body</p>';
$plain_echo = '<link rel="canonical" href="https://x/notes/a/"><p>Body</p>';
ok( false === snt_cf_probe_is_stale( $probe_echo, $plain_echo ), "the probe's own cache-buster is not staleness" );

echo "\nGroup: real drift still detected through the noise\n";
// The failure mode that would make this whole module useless: normalizing so
// aggressively that a genuine content change stops registering.
$noisy_stale = '<form><input name="_wpnonce" value="abc" /><p>OLD SENTENCE</p></form>';
$noisy_fresh = '<form><input name="_wpnonce" value="zzz" /><p>NEW SENTENCE</p></form>';
ok( true === snt_cf_probe_is_stale( $noisy_stale, $noisy_fresh ), 'content differs under differing nonces: still stale' );

echo "\nGroup: wiring\n";
$purge = file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' );
ok( false !== strpos( $purge, 'SN_CF_PROBE_HOOK' ), 'the per-post purge schedules the probe' );
ok( false !== strpos( $purge, 'wp_unschedule_event' ), 'a re-save replaces the pending probe instead of stacking one per save' );

$probe = file_get_contents( __DIR__ . '/../inc/cloudflare-purge-probe.php' );
ok( false !== strpos( $probe, 'sn_cf_purge_everything' ), 'a stale edge escalates to a zone purge' );
ok( 1 === substr_count( $probe, 'sn_cf_purge_everything(' ), 'escalation is bounded to exactly one zone purge, never a retry loop' );
ok( false !== strpos( $probe, 'sn-cache-probe' ), 'the origin is read through a cache-busting query' );

$loader = file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
$pos_verify = strpos( $loader, 'cloudflare-purge-verify.php' );
$pos_purge  = strpos( $loader, "inc/cloudflare-purge.php" );
ok( false !== $pos_verify && false !== $pos_purge && $pos_verify < $pos_purge, 'the constants load BEFORE the purge that reads them' );

echo "\nGroup: the freshness summary (v11.29.0)\n";

// The probe log has been written since v11.10.0 and read by NOTHING. This
// accessor is its first reader — the desktop needs a verdict, and the data
// already exists.

// NEVER PROBED is not ALL FRESH. snt_cf_verify_post_purge() deliberately records
// nothing when a probe is unreadable ("an outage is a gap in evidence, not a
// verdict"), so an empty log means no purge has been verified — not that every
// purge succeeded. Collapsing the two would report a green edge for a site whose
// verification has never once run.
$GLOBALS['__opts'] = array();
ok( null === snt_cf_freshness_summary(), 'AN EMPTY LOG IS NULL, NOT A CLEAN BILL OF HEALTH' );

$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = 'not-an-array';
ok( null === snt_cf_freshness_summary(), 'corrupt option reads as never-probed rather than fataling' );

// Newest first, as snt_cf_probe_record() array_unshifts them.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	// v11.30.3: fixtures carry `algo` because the summary now counts only
	// verdicts the CURRENT detector produced. These exercise the counting
	// logic, so they must be current-detector rows; the epoch filter itself is
	// exercised further down with deliberately unstamped entries.
	array( 'time' => 1000, 'result' => 'fresh', 'url' => 'https://x.test/a', 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' =>  900, 'result' => 'stale', 'url' => 'https://x.test/b', 'escalated' => true, 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' =>  800, 'result' => 'fresh', 'url' => 'https://x.test/c', 'algo' => SN_CF_PROBE_ALGO ),
);
// v13.87.2: `last` comes from the REPORT, not the newest log row — manual
// purges no longer write here, and the report is what the deferred verify
// corrects in place.
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 1000, 'epoch' => 7, 'resolved' => true );
$sum = snt_cf_freshness_summary();
ok( is_array( $sum ), 'a populated log yields a summary' );
ok( 'fresh' === $sum['last'], 'the LAST verdict comes from the purge report, the authoritative record' );
ok( 1000 === $sum['last_time'], 'and carries its timestamp' );
ok( 3 === $sum['total'], 'the window counts every recorded verdict' );
ok( 1 === $sum['stale'], 'and how many were stale' );
ok( 1 === $sum['escalated'], 'escalations are counted separately — a stale purge that escalated is a worse fact' );

// A stale newest entry must surface as the verdict even with fresh history.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'time' => 2000, 'result' => 'stale', 'url' => 'https://x.test/a', 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' => 1000, 'result' => 'fresh', 'url' => 'https://x.test/b', 'algo' => SN_CF_PROBE_ALGO ),
);
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 2000, 'epoch' => 8, 'resolved' => false );
$sum = snt_cf_freshness_summary();
ok( 'stale' === $sum['last'], 'AN UNRESOLVED REPORT IS THE VERDICT, however good the probe history' );
ok( 0 === $sum['escalated'], 'a stale entry that did not escalate is not counted as one' );

// A report with NO edge reading is unknown, never silently fresh. (v13.87.2:
// `last` is sourced from the report, so this is where "unrecognised" now lives
// — a report that recorded no `resolved` key measured nothing.)
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 5, 'epoch' => 11 ); // no `resolved`
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array( array( 'time' => 5, 'result' => 'weird', 'algo' => SN_CF_PROBE_ALGO ) );
$sum = snt_cf_freshness_summary();
ok( 'unknown' === $sum['last'], 'a report with no edge reading is unknown, never fresh' );

// ── A MEASUREMENT FROM A BROKEN INSTRUMENT IS NOT A MEASUREMENT ─────────────
// v11.30.3. Until v11.29.1 this probe compared a CACHED render against a
// CACHE-BUSTED one, which on this site can never be equal — Breeze injects a
// prefetch script on one path only. Every probe therefore returned stale and
// every one escalated: the log read 11 stale of 11, from a detector that could
// not return anything else.
//
// The verdict half was fixed; the LOG was not. So the desktop widget kept
// showing a red "Edge served a stale render" over a day-old pre-fix entry while
// the Dashboard screen said every zone was fresh — two surfaces disagreeing
// because one was counting known-bad evidence.
//
// Entries are now stamped with the algorithm that produced them, and the
// summary ignores anything older than the current one. Absence of a current
// verdict must read as NOT MEASURED — never as fresh, and never as stale.
echo "\nGroup: the summary ignores pre-fix verdicts\n";

// Null needs BOTH sources empty now: no verdict in the report AND no current
// probe rows. Either alone is still information.
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 1000, 'epoch' => 12 ); // no `resolved`
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'result' => 'stale', 'time' => 1000, 'escalated' => true ),
	array( 'result' => 'stale', 'time' => 900,  'escalated' => true ),
);
ok( null === snt_cf_freshness_summary(),
	'ONLY PRE-FIX ENTRIES AND NO REPORT VERDICT SUMMARISES TO NULL — not measured since the detector was repaired' );

// A current-algorithm entry is real evidence and counts.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'result' => 'fresh', 'time' => 2000, 'algo' => SN_CF_PROBE_ALGO ),
	array( 'result' => 'stale', 'time' => 1000, 'escalated' => true ),
);
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 2000, 'epoch' => 9, 'resolved' => true );
$sum = snt_cf_freshness_summary();
ok( is_array( $sum ), 'a log containing a current entry summarises' );
ok( 'fresh' === $sum['last'], 'and the report supplies the verdict' );
ok( 1 === $sum['total'], 'THE TOTAL COUNTS ONLY CURRENT-ALGORITHM ENTRIES — 11 known-bad rows are not a denominator' );
ok( 0 === $sum['stale'], 'and the pre-fix stale rows do not inflate the stale count' );
ok( 0 === $sum['escalated'], 'nor the escalation count' );

// A genuine stale verdict from the CURRENT detector must still alarm.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'result' => 'stale', 'time' => 3000, 'escalated' => true, 'algo' => SN_CF_PROBE_ALGO ),
);
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 3000, 'epoch' => 10, 'resolved' => false );
$sum2 = snt_cf_freshness_summary();
ok( 'stale' === $sum2['last'] && 1 === $sum2['stale'] && 1 === $sum2['escalated'],
	'A REAL STALE VERDICT STILL ALARMS — the filters drop old evidence and operator actions, never bad news' );

// And the recorder stamps what produced the entry, or the filter is worthless.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array();
snt_cf_probe_record( array( 'result' => 'fresh', 'time' => 4000 ) );
$rec = $GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ][0];
ok( isset( $rec['algo'] ) && SN_CF_PROBE_ALGO === $rec['algo'],
	'THE RECORDER STAMPS THE ALGORITHM — without it every new entry reads as pre-fix and the widget never recovers' );


// ── v13.87.2: the summary reads the AUTHORITATIVE record ──────────────────
// Manual purges no longer write here. "Last purge" comes from
// sn_last_purge_report — which the theme's deferred verify corrects in place —
// so pressing Purge updates the verdict without touching the diagnostic, and
// BOTH surfaces render this one derive layer and therefore cannot disagree.
echo "\nv13.87.2: last-purge from the report, tally from post-save probes\n";
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 1788400000, 'epoch' => 7, 'resolved' => true );
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ]  = array();
$sum = snt_cf_freshness_summary();
ok( is_array( $sum ) && 'fresh' === $sum['last'], 'a resolved report reports fresh with NO probe-log rows at all' );
ok( is_array( $sum ) && 0 === $sum['total'], 'and the post-save tally stays at zero — pressing Purge cannot move it' );
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 1788400000, 'epoch' => 7, 'resolved' => false );
$sum = snt_cf_freshness_summary();
ok( is_array( $sum ) && 'stale' === $sum['last'], 'an unresolved report still reports stale — not a fresh-only filter' );
// verified_at is stamped when the DEFERRED verify runs, and is the honest time
// for a verdict taken after propagation rather than at the moment of purging.
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 1788400000, 'verified_at' => 1788400075, 'epoch' => 7, 'resolved' => true );
$sum = snt_cf_freshness_summary();
ok( 1788400075 === $sum['last_time'], 'last_time prefers verified_at — when the verdict was actually taken' );
// An upgraded site still holds manual rows written before this. Filtering only
// at WRITE time would carry the defect forward until they aged out.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'time' => 1788400000, 'result' => 'stale', 'source' => 'manual_zone_purge', 'algo' => SN_CF_PROBE_ALGO ),
	array( 'time' => 1788399000, 'result' => 'fresh', 'algo' => SN_CF_PROBE_ALGO ),
);
$sum = snt_cf_freshness_summary();
ok( 1 === $sum['total'] && 0 === $sum['stale'], 'LEGACY manual rows are excluded at READ time, not only at write' );
// Absence of evidence is never a pass.
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => 1788400000, 'epoch' => 7 ); // no `resolved`
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ]  = array();
ok( null === snt_cf_freshness_summary(), 'nothing known from either source is NULL, never a fabricated fresh' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
