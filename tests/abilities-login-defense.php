<?php
/**
 * CLI fixture for the Login-defense read ability (v12.11.0).
 *
 * WHY THIS ABILITY EXISTS: the IPv6 criterion gauge shipped in v10.74.0 and
 * could only ever be read by a human opening wp-admin. The criterion was fixed
 * in advance (worker v1.5.2) precisely so the NUMBER triggers the call instead
 * of reopening the argument — but an unreadable number triggers nothing, and
 * the reading sat undone from the day the question was asked.
 *
 * THE FAILURE THIS PINS, from 2026-08-22: asked to read the gauge, I derived
 * the window from the worker's v1.5.0 TAG DATE (2026-07-22) and reported the
 * criterion satisfied. The sensor's first actual row is 2026-07-26 — the
 * window had 27 of 30 days. The gauge measures min(timestamp) over rows the
 * sensor really wrote for exactly this reason. So this ability returns the
 * MEASURED window and a named `decision`, never the raw share alone: a caller
 * that has to re-derive the rule will eventually re-derive it wrong.
 *
 * Drives the real sn_login_defense_ipv6_share() across the file boundary. Only
 * the WP seams and the AE query are stubbed.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { echo "PASS: $m\n"; $pass++; } else { echo "FAIL: $m\n"; $fail++; } }

function esc_html__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function number_format_i18n( $n ) { return (string) $n; }
function esc_html( $s ) { return (string) $s; }
function current_user_can( $c ) { return true; }
const SN_LG_DATASET = 'sn_login_guard';

// Ability registry capture.
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
$GLOBALS['__hooked'] = array();
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__hooked'][ $h ][] = $cb; return true; }

// AE seam. null = query failure, array = measured (the real contract).
$GLOBALS['__family'] = array();
function sn_analytics_query( $sql ) { return $GLOBALS['__family']; }

require __DIR__ . '/../inc/login-defense-gauges.php';   // the REAL producer
require __DIR__ . '/../inc/abilities-login-defense.php';

// v13.1.1 — THE WIRING PIN. The registrar was hooked to 'abilities_api_init'
// (no wp_ prefix, a hook nothing fires) from v12.11.0 to v13.1.0 and this
// suite never noticed, because the old inert add_action stub meant "drive the
// registrar directly" tested the callback while skipping the wiring. The
// ability was doored but unregistered: uncallable over MCP the whole time.
// Now the stub CAPTURES, the hook name is asserted, and registration is
// driven THROUGH the captured wiring rather than around it.
echo "Login-defense read ability (v12.11.0)\n\n";
ok( isset( $GLOBALS['__hooked']['wp_abilities_api_init'] ), 'the registrar hooks wp_abilities_api_init — the hook WordPress actually fires' );
ok( ! isset( $GLOBALS['__hooked']['abilities_api_init'] ), 'and never the unprefixed abilities_api_init, which nothing fires (the v12.11.0–v13.1.0 dead-registration bug)' );
foreach ( $GLOBALS['__hooked']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
ok( isset( $GLOBALS['__abilities']['signal-noise/login-defense-ipv6-criterion'] ), 'firing the REAL hook registers the ability — wiring exercised, not bypassed' );

// v13.1.1 — repo-wide source pin: no ability file may hook the unprefixed
// name again. Source-text guard, same class as the filters.md parity test.
$offenders = array();
foreach ( glob( __DIR__ . '/../inc/*.php' ) as $f ) {
	if ( false !== strpos( (string) file_get_contents( $f ), "add_action( 'abilities_api_init'" ) ) {
		$offenders[] = basename( $f );
	}
}
ok( array() === $offenders, 'no inc/ file hooks the unprefixed abilities_api_init' . ( $offenders ? ' — offenders: ' . implode( ', ', $offenders ) : '' ) );

// ── registration ────────────────────────────────────────────────────────────
$slug = 'signal-noise/login-defense-ipv6-criterion';
$a    = $GLOBALS['__abilities'][ $slug ] ?? null;
ok( is_array( $a ), 'the ability is registered' );
ok( 'snt_ability_perm_manage_options' === ( $a['permission_callback'] ?? '' ), 'gated on manage_options (security data, owner tier)' );
ok( true === ( $a['meta']['annotations']['readonly'] ?? null ), 'annotated readonly' );
ok( true === ( $a['meta']['annotations']['idempotent'] ?? null ), 'annotated idempotent' );

// ── rows helper: first_seen N days back, in the AE timestamp shape ──────────
function ld_rows( $v6, $v4, $days_back, $pre = 0 ) {
	$seen = gmdate( 'Y-m-d H:i:s', time() - ( $days_back * 86400 ) );
	$rows = array(
		array( 'family' => 'v6', 'hits' => $v6, 'first_seen' => $seen ),
		array( 'family' => 'v4', 'hits' => $v4, 'first_seen' => $seen ),
	);
	if ( $pre > 0 ) { $rows[] = array( 'family' => '', 'hits' => $pre, 'first_seen' => '2026-01-01 00:00:00' ); }
	return $rows;
}

// ── THE LIVE CASE, 2026-08-22: 51.7% over a 27-of-30-day window ────────────
$GLOBALS['__family'] = ld_rows( 517, 483, 27 );
$out = sn_ability_login_defense_ipv6_criterion();
ok( 51.7 === $out['share_pct'], 'share_pct carries the measured share (51.7)' );
ok( true === $out['crossed'], 'crossed is true — 51.7 is over the 5% line' );
ok( 27 === $out['measured_days'], 'measured_days is the MEASURED window, not the one asked for' );
ok( false === $out['window_complete'], 'window_complete is false at 27 of 30 days' );
ok( 'withhold_unfinished_window' === $out['decision'], 'DECISION WITHHELD: a crossed line on an unfinished window authorises nothing' );
ok( 30 === $out['criterion_days'] && 5 === $out['threshold_pct'], 'both halves of the criterion travel in the payload' );

// ── the window closes ───────────────────────────────────────────────────────
$GLOBALS['__family'] = ld_rows( 517, 483, 30 );
$out = sn_ability_login_defense_ipv6_criterion();
ok( true === $out['window_complete'], 'window_complete is true at 30 of 30 days' );
ok( 'build_ranges' === $out['decision'], 'DECISION NAMED once both halves hold' );

// ── complete window, share under the line ──────────────────────────────────
$GLOBALS['__family'] = ld_rows( 10, 990, 30 );
$out = sn_ability_login_defense_ipv6_criterion();
ok( false === $out['crossed'] && 'below_threshold' === $out['decision'], 'a covered window under 5% is a real answer, not a withholding' );

// ── zero-vs-null honesty: a failed fetch is NOT a reassuring zero ──────────
$GLOBALS['__family'] = null;
$out = sn_ability_login_defense_ipv6_criterion();
ok( false === $out['measured'], 'a failed AE query reports measured=false' );
ok( null === $out['share_pct'], 'share_pct is NULL on failure, never 0' );
ok( 'unknown' === $out['decision'], 'no decision is named on an unavailable measurement' );

// ── never-measured: every row predates the sensor ──────────────────────────
$GLOBALS['__family'] = array( array( 'family' => '', 'hits' => 900, 'first_seen' => '2026-01-01 00:00:00' ) );
$out = sn_ability_login_defense_ipv6_criterion();
ok( null === $out['share_pct'], 'all-pre-sensor rows are not a measurement' );
ok( 'unknown' === $out['decision'], 'never-measured names no decision' );
ok( 900 === $out['pre_sensor_hits'], 'pre-sensor hits are reported, not silently dropped' );

// ── the doors ───────────────────────────────────────────────────────────────
$caps   = file_get_contents( __DIR__ . '/../inc/mcp/mcp-capabilities.php' );
$remote = file_get_contents( __DIR__ . '/../inc/mcp/mcp-remote-guard.php' );
ok( false !== strpos( $caps, $slug ), 'exposed on the LOCAL MCP read door' );
ok( false === strpos( $remote, $slug ), 'ABSENT from the remote door — login defense is not the analytics-only remote slice' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
