<?php
/**
 * Tests: purge-all-caches fail-loud Cloudflare verdict (v10.4.1).
 *
 * 2026-07-29 incident: the ability answered {"ok":true,"message":"All caches
 * purged.","count":0} on every run while the Cloudflare PoP kept serving
 * stale CSS/HTML through the whole resume session, until a manual dashboard
 * Purge Everything. Root cause: the ability's filter dispatch never passed
 * `verified => true`, so the theme's CF leg ran the fire-and-forget
 * sn_cf_purge_everything() — which silently returns false when no token/zone
 * is configured and never reads the API response when one is. The response
 * message asserted success it could not know.
 *
 * Corrected contract pinned here:
 *   - the dispatch carries verified => true (routes the theme's CF leg
 *     through the blocking accept-confirmed variant, theme >= 10.23.0)
 *   - the response carries a `cloudflare` verdict: status one of
 *     confirmed | failed | not_configured | unconfirmed, plus http and
 *     (when the theme probed routes) edge_fresh
 *   - ok is FALSE when the CF purge could not run (not_configured) or was
 *     rejected (failed) — and the message says so instead of "All caches
 *     purged."
 *   - the verdict trusts sn_last_purge_report ONLY when it is fresh
 *     (written at/after this dispatch) and mode=verified — a stale or
 *     legacy report degrades to 'unconfirmed', never a fake success.
 *
 * Run: php tests/abilities-purge-cf-fail-loud.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

echo "abilities-purge-cf-fail-loud suite\n";

// ─── WP stubs (before the SUT loads) ─────────────────────────────────────
function add_action( $tag, $cb, $p = 10, $a = 1 ) { return true; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

$GLOBALS['__has_purge_filter'] = true;
function has_filter( $tag ) { return $GLOBALS['__has_purge_filter'] && 'sn_purge_all_caches_result' === $tag; }

// Theme-listener simulator: capture the dispatched args, return the override
// count the real listener would (2 when template_overrides, else 0).
$GLOBALS['__purge_filter_args'] = null;
function apply_filters( $tag, $default, $args = array() ) {
	if ( 'sn_purge_all_caches_result' === $tag ) {
		$GLOBALS['__purge_filter_args'] = $args;
		return ! empty( $args['template_overrides'] ) ? 2 : 0;
	}
	return $default;
}

$GLOBALS['__opts'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['__opts'][ $key ] ?? $default; }

// Plugin-local CF config probe (inc/cloudflare-purge.php in real runtime).
$GLOBALS['__cf_configured'] = false;
function sn_cf_is_configured() { return $GLOBALS['__cf_configured']; }

// v13.70.0: the probe log the DASHBOARD reads. Capture every write so the
// suite can pin which purges produce a verdict and which produce silence.
$GLOBALS['__probe_log'] = array();
function snt_cf_probe_record( array $entry ) { $GLOBALS['__probe_log'][] = $entry; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }

require __DIR__ . '/../inc/abilities-system.php';

/** Fresh verified report as theme inc/purge-verify.php writes it. */
function seed_report( $cf, $resolved = null, $overrides = array() ) {
	$report = array_merge( array(
		'time'  => time(),
		'mode'  => 'verified',
		// v13.88.0: the settle check binds to the purge epoch, so the fixture
		// has to carry one or the scheduling branch is silently unreachable and
		// S9.4b would be vacuous.
		'epoch' => 7,
		'legs'  => array( 'breeze_file' => true, 'varnish' => array( 'via' => 'cloudways', 'ok' => true ), 'cf' => $cf ),
	), $overrides );
	if ( null !== $resolved ) {
		$report['resolved'] = $resolved;
	}
	$GLOBALS['__opts']['sn_last_purge_report'] = $report;
}

// ─── S1: CF not configured → fail-loud, never "All caches purged." ──────
$GLOBALS['__cf_configured'] = false;
$out = snt_ability_purge_all_caches( null );
ok( false === ( $out['ok'] ?? null ), 'S1.1 not configured: ok=false' );
ok( 'not_configured' === ( $out['cloudflare']['status'] ?? null ), 'S1.2 not configured: cloudflare.status=not_configured' );
ok( 'All caches purged.' !== ( $out['message'] ?? '' ), 'S1.3 not configured: message is NOT the old blanket success' );
ok( false !== stripos( (string) ( $out['message'] ?? '' ), 'cloudflare' )
	&& false !== stripos( (string) ( $out['message'] ?? '' ), 'could not run' ),
	'S1.4 not configured: message names the Cloudflare leg and says it could not run' );
ok( 0 === ( $out['count'] ?? null ), 'S1.5 not configured: override count still 0 on bare purge' );

// ─── S2: dispatch args carry verified=true (blocking CF confirmation) ───
ok( true === ( $GLOBALS['__purge_filter_args']['verified'] ?? null ), 'S2.1 dispatch carries verified=true' );
ok( false === ( $GLOBALS['__purge_filter_args']['template_overrides'] ?? null ), 'S2.2 bare purge keeps template_overrides=false' );

// ─── S3: configured + confirmed report → honest success ─────────────────
$GLOBALS['__cf_configured'] = true;
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), true );
$out = snt_ability_purge_all_caches( null );
ok( true === ( $out['ok'] ?? null ), 'S3.1 confirmed: ok=true' );
ok( 'confirmed' === ( $out['cloudflare']['status'] ?? null ), 'S3.2 confirmed: cloudflare.status=confirmed' );
ok( 200 === ( $out['cloudflare']['http'] ?? null ), 'S3.3 confirmed: http=200 surfaced' );
ok( true === ( $out['cloudflare']['edge_fresh'] ?? null ), 'S3.4 confirmed: edge_fresh=true from the probed report' );
ok( false !== stripos( (string) ( $out['message'] ?? '' ), 'confirmed' ), 'S3.5 confirmed: message says the CF purge was confirmed' );

// ─── S4: confirmed purge but edge probe still stale → loud warning ──────
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), false );
$out = snt_ability_purge_all_caches( null );
ok( true === ( $out['ok'] ?? null ), 'S4.1 edge-stale: purge itself confirmed, ok stays true' );
ok( false === ( $out['cloudflare']['edge_fresh'] ?? null ), 'S4.2 edge-stale: edge_fresh=false surfaced' );
// v13.88.0 — WAS matching the word "stale". The message no longer uses it,
// because at that instant the edge was not stale: the purge had not finished
// propagating. What must survive is that the caller is NOT handed a bare
// success line, so the assertion now pins the PROPERTY rather than the word.
$plain_ok = 'All caches purged; Cloudflare zone purge confirmed.';
ok( (string) ( $out['message'] ?? '' ) !== $plain_ok
	&& strlen( (string) ( $out['message'] ?? '' ) ) > strlen( $plain_ok ),
	'S4.3 edge-not-fresh: the message says MORE than the plain success line — the caller is told the edge was not confirmed' );

// ─── S5: configured + CF rejected the purge → ok=false with HTTP code ───
seed_report( array( 'accepted' => false, 'http' => 403, 'cf_success' => false ), false );
$out = snt_ability_purge_all_caches( null );
ok( false === ( $out['ok'] ?? null ), 'S5.1 failed: ok=false' );
ok( 'failed' === ( $out['cloudflare']['status'] ?? null ), 'S5.2 failed: cloudflare.status=failed' );
ok( 403 === ( $out['cloudflare']['http'] ?? null ), 'S5.3 failed: http code surfaced' );
ok( false !== strpos( (string) ( $out['message'] ?? '' ), '403' ), 'S5.4 failed: message carries the HTTP code' );

// ─── S6: configured but no usable report → unconfirmed, never fake ──────
unset( $GLOBALS['__opts']['sn_last_purge_report'] );
$out = snt_ability_purge_all_caches( null );
ok( true === ( $out['ok'] ?? null ) && 'unconfirmed' === ( $out['cloudflare']['status'] ?? null ),
	'S6.1 no report (older theme): status=unconfirmed, ok=true' );
ok( false !== stripos( (string) ( $out['message'] ?? '' ), 'not confirmed' ), 'S6.2 no report: message says the CF purge is not confirmed' );

// A STALE verified report (from an earlier purge) must not confirm this one.
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), true, array( 'time' => time() - 3600 ) );
$out = snt_ability_purge_all_caches( null );
ok( 'unconfirmed' === ( $out['cloudflare']['status'] ?? null ), 'S6.3 stale report: status=unconfirmed' );

// An auto-mode report (fast auto-purge raced in) is not a confirmation either.
seed_report( array( 'dispatched' => true, 'confirmed' => null ), null, array( 'mode' => 'auto' ) );
$out = snt_ability_purge_all_caches( null );
ok( 'unconfirmed' === ( $out['cloudflare']['status'] ?? null ), 'S6.4 auto-mode report: status=unconfirmed' );

// ─── S7: include_template_overrides still rides through unchanged ───────
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), true );
$out = snt_ability_purge_all_caches( array( 'include_template_overrides' => true ) );
ok( 2 === ( $out['count'] ?? null ), 'S7.1 include_template_overrides: count from the theme filter' );
ok( true === ( $GLOBALS['__purge_filter_args']['template_overrides'] ?? null ), 'S7.2 template_overrides=true reaches the filter' );
ok( false !== strpos( (string) ( $out['message'] ?? '' ), '2 template overrides' ), 'S7.3 message keeps the overrides clause' );

// ─── S8: theme filter missing → WP_Error (unchanged regression pin) ─────
$GLOBALS['__has_purge_filter'] = false;
$out = snt_ability_purge_all_caches( null );
ok( is_wp_error( $out ) && 'snt_helper_unavailable' === $out->get_error_code(), 'S8.1 no theme listener: WP_Error snt_helper_unavailable' );
$GLOBALS['__has_purge_filter'] = true;

// ─── S9: a manual purge NEVER writes to the probe log ────────────────────
//
// The whole premise of this section inverted in v13.87.2, so it is rewritten
// rather than adjusted.
//
// v13.70.0 answered a real complaint — "I purged them, but that didn't change"
// — by APPENDING A ROW HERE so the "Last purge" cell would update. The need was
// real; the mechanism wrote an operator action into a measurement store, and
// every defect since followed from it:
//
//   the count CLIMBED when you purged   (racing inline probes booked as stale)
//   the count FELL when you purged      (each new row evicting an older one)
//   manual rows displaced the diagnostic  (10/10 -> 13/7 -> 15/5 on 2026-09-03)
//
// Both directions, the number answered "how often did you press Purge?" rather
// than "do our purges clear the edge?". THE INVARIANT: a diagnostic must not
// move because you operated the thing it measures.
//
// sn_last_purge_report already carries time, epoch and resolved, and the
// theme's deferred verify corrects it in place. snt_cf_freshness_summary()
// reads it directly, so the cell still updates on every purge — with no copy to
// keep in step, and nothing an operator can do to shift the tally.
$GLOBALS['__cf_configured'] = true;

$GLOBALS['__probe_log'] = array();
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), true );
$out = snt_ability_purge_all_caches( null );
ok( array() === $GLOBALS['__probe_log'], 'S9.1 a RESOLVED manual purge writes nothing to the probe log' );

$GLOBALS['__probe_log'] = array();
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), false );
$out = snt_ability_purge_all_caches( null );
ok( array() === $GLOBALS['__probe_log'], 'S9.2 an UNRESOLVED manual purge writes nothing either — no direction moves the tally' );

// The v13.70.0 need still has to be met, or this is a regression rather than a
// fix: the surfaces must still learn the verdict of the purge just pressed.
// They do — snt_cf_freshness_summary() reads sn_last_purge_report directly,
// which is also why the two surfaces agree by construction. That behaviour is
// pinned in tests/cloudflare-purge-verify.php, which loads the real summary;
// this suite stubs snt_cf_probe_record() and so cannot load that file.

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
