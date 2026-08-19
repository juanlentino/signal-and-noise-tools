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
	array( 'time' => 1000, 'result' => 'fresh', 'url' => 'https://x.test/a' ),
	array( 'time' =>  900, 'result' => 'stale', 'url' => 'https://x.test/b', 'escalated' => true ),
	array( 'time' =>  800, 'result' => 'fresh', 'url' => 'https://x.test/c' ),
);
$sum = snt_cf_freshness_summary();
ok( is_array( $sum ), 'a populated log yields a summary' );
ok( 'fresh' === $sum['last'], 'the LAST verdict is the newest entry, not the first written' );
ok( 1000 === $sum['last_time'], 'and carries its timestamp' );
ok( 3 === $sum['total'], 'the window counts every recorded verdict' );
ok( 1 === $sum['stale'], 'and how many were stale' );
ok( 1 === $sum['escalated'], 'escalations are counted separately — a stale purge that escalated is a worse fact' );

// A stale newest entry must surface as the verdict even with fresh history.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array(
	array( 'time' => 2000, 'result' => 'stale', 'url' => 'https://x.test/a' ),
	array( 'time' => 1000, 'result' => 'fresh', 'url' => 'https://x.test/b' ),
);
$sum = snt_cf_freshness_summary();
ok( 'stale' === $sum['last'], 'A STALE NEWEST ENTRY IS THE VERDICT, however good the history' );
ok( 0 === $sum['escalated'], 'a stale entry that did not escalate is not counted as one' );

// An entry with an unrecognised result is not silently counted as fresh.
$GLOBALS['__opts'][ SN_CF_PROBE_LOG_OPT ] = array( array( 'time' => 5, 'result' => 'weird' ) );
$sum = snt_cf_freshness_summary();
ok( 'unknown' === $sum['last'], 'an unrecognised result reads as unknown, never as fresh' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
