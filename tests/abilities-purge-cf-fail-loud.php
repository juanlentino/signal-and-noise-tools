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
		'time' => time(),
		'mode' => 'verified',
		'legs' => array( 'breeze_file' => true, 'varnish' => array( 'via' => 'cloudways', 'ok' => true ), 'cf' => $cf ),
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
ok( false !== stripos( (string) ( $out['message'] ?? '' ), 'stale' ), 'S4.3 edge-stale: message warns the edge still served a stale render' );

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

// ─── S9: the manual purge RECORDS ITS VERDICT where the widget reads it ──
// Owner-reported 2026-09-02: "I purged them, but that didn't change." The
// dashboard's "Last purge" cell reads SN_CF_PROBE_LOG_OPT, and only the
// post-save probe ever wrote to it — so a manual zone purge computed a perfectly
// good edge reading here, discarded it, and left the cell showing a verdict from
// whenever a Note was last published.
$GLOBALS['__cf_configured'] = true;
$GLOBALS['__probe_log'] = array();
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), true );
$out = snt_ability_purge_all_caches( null );
ok( 1 === count( $GLOBALS['__probe_log'] ), 'S9.1 a confirmed purge writes exactly one probe-log entry' );
ok( 'fresh' === ( $GLOBALS['__probe_log'][0]['result'] ?? '' ), 'S9.2 a resolved edge records fresh' );
ok( 'manual_zone_purge' === ( $GLOBALS['__probe_log'][0]['source'] ?? '' ) && 0 === ( $GLOBALS['__probe_log'][0]['post_id'] ?? -1 ),
	'S9.3 the entry says WHICH purge produced it, and carries no post id — a zone purge is not about one post' );

$GLOBALS['__probe_log'] = array();
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ), false );
$out = snt_ability_purge_all_caches( null );
ok( 1 === count( $GLOBALS['__probe_log'] ) && 'stale' === ( $GLOBALS['__probe_log'][0]['result'] ?? '' ),
	'S9.4 a confirmed purge whose edge is STILL stale records stale — the bad news reaches the surface too' );

// AN UNMEASURED PURGE RECORDS NOTHING. Same rule the post-save probe keeps:
// an outage is a gap in evidence, never a verdict.
$GLOBALS['__probe_log'] = array();
seed_report( array( 'accepted' => true, 'http' => 200, 'cf_success' => true ) ); // no `resolved` key at all
$out = snt_ability_purge_all_caches( null );
ok( array() === $GLOBALS['__probe_log'], 'S9.5 a confirmed purge with NO edge reading records nothing — never a fabricated fresh' );

$GLOBALS['__probe_log'] = array();
seed_report( array( 'accepted' => false, 'http' => 403, 'cf_success' => false ), true );
$out = snt_ability_purge_all_caches( null );
ok( array() === $GLOBALS['__probe_log'], 'S9.6 a REJECTED purge records nothing — the edge verdict of a purge that never ran is not evidence' );

$GLOBALS['__probe_log'] = array();
$GLOBALS['__opts'] = array(); // no report at all -> unconfirmed
$out = snt_ability_purge_all_caches( null );
ok( array() === $GLOBALS['__probe_log'], 'S9.7 an unconfirmed purge records nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
