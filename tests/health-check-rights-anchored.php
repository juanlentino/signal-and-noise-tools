<?php
/**
 * Tests: rights-signal anchoring health check (v10.39.0).
 *
 * Born from the 2026-08-04 sweep: verify:rights-signals proves every anchored
 * claim is internally sound, and the sibling rights-signals probe proves the
 * live surface is correct — but nothing asked whether the surface being served
 * RIGHT NOW has been anchored at all. A Worker that silently stopped
 * re-anchoring leaves both of those green.
 *
 * The pure evaluator is the tested surface, and it owns the GRACE WINDOW too:
 * the Worker sweeps hourly, so a surface that changed minutes ago is legitimately
 * unanchored and must not raise a finding. Keeping that decision pure is the
 * point — a timing rule buried in a fetch wrapper is a rule nobody can test.
 *
 * Run: php tests/health-check-rights-anchored.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Mirror the REAL envelope builder (inc/health-checks.php), as the sibling
// rights-signals and ledger-ci fixtures do.
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
	);
}

require_once __DIR__ . '/../inc/health-check-rights-anchored.php';

$NOW  = 1785900000;
$HOUR = 3600;

/** Body → the hash the ledger would carry for it. */
function h( $body ) { return hash( 'sha256', $body ); }

/** One anchor row as index.json publishes it. */
function row( $slug, $body ) {
	return array( 'slug' => $slug, 'url' => 'https://example.test/x', 'version' => 1, 'content_hash' => h( $body ), 'ots_status' => 'confirmed', 'bitcoin_block' => 1 );
}

$bodies  = array( 'robots-txt' => "User-agent: *\n", 'tdmrep-json' => '{"a":1}', 'license-xml' => '<rsl></rsl>', 'tdm-policy' => '<html>tdm</html>' );
$live    = array();
$anchors = array();
foreach ( $bodies as $slug => $body ) {
	$live[ $slug ]  = $body;
	$anchors[]      = row( $slug, $body );
}

// ---- everything anchored -------------------------------------------------
$r = snt_rights_anchor_evaluate( $live, $anchors, array(), $NOW );
ok( array() === $r['findings'], 'all four surfaces anchored -> no findings' );
ok( array() === $r['state'], 'a clean scan stores no drift state' );
ok( 'ok' === $r['status'], 'status is ok when everything matches' );

// ---- a surface changed just now (inside the grace window) -----------------
$driftLive               = $live;
$driftLive['robots-txt'] = "User-agent: *\nDisallow: /new\n";
$r1 = snt_rights_anchor_evaluate( $driftLive, $anchors, array(), $NOW );
ok( array() === $r1['findings'], 'a surface that just changed raises NO finding (Worker sweeps hourly)' );
ok( isset( $r1['state']['robots-txt']['first_seen'] ) && $NOW === $r1['state']['robots-txt']['first_seen'], 'first divergence is remembered with its timestamp' );
ok( h( $driftLive['robots-txt'] ) === $r1['state']['robots-txt']['hash'], 'the diverging live hash is remembered, not the anchored one' );

// ---- still diverging one hour later (one tick — still inside grace) -------
$r2 = snt_rights_anchor_evaluate( $driftLive, $anchors, $r1['state'], $NOW + $HOUR );
ok( array() === $r2['findings'], 'one missed sweep is still within the grace window' );
ok( $NOW === $r2['state']['robots-txt']['first_seen'], 'first_seen is NOT reset while the same divergence persists' );

// ---- still diverging after the window -> finding --------------------------
$r3 = snt_rights_anchor_evaluate( $driftLive, $anchors, $r1['state'], $NOW + ( 2 * $HOUR ) + 60 );
ok( 1 === count( $r3['findings'] ), 'two missed sweeps raises exactly one finding' );
ok( false !== strpos( (string) $r3['findings'][0]['subject'], 'robots-txt' ), 'the finding names the surface' );
ok( false !== strpos( (string) $r3['findings'][0]['note'], 'anchor' ), 'the finding says what is wrong' );

// ---- the surface changes AGAIN mid-drift: the clock restarts --------------
$driftLive2               = $live;
$driftLive2['robots-txt'] = "User-agent: *\nDisallow: /newer\n";
$r4 = snt_rights_anchor_evaluate( $driftLive2, $anchors, $r1['state'], $NOW + ( 2 * $HOUR ) + 60 );
ok( array() === $r4['findings'], 'a NEW divergence restarts the grace window rather than inheriting the old clock' );
ok( ( $NOW + ( 2 * $HOUR ) + 60 ) === $r4['state']['robots-txt']['first_seen'], 'first_seen re-stamps when the live hash changes' );

// ---- recovery clears the state -------------------------------------------
$r5 = snt_rights_anchor_evaluate( $live, $anchors, $r1['state'], $NOW + ( 5 * $HOUR ) );
ok( array() === $r5['findings'], 'once anchored again there is no finding' );
ok( array() === $r5['state'], 'recovery clears the remembered drift' );

// ---- an unreachable ledger is an ADVISORY, never evidence of drift --------
$r6 = snt_rights_anchor_evaluate( $live, null, array(), $NOW );
ok( array() === $r6['findings'], 'a null anchor list yields ZERO findings (outage != drift)' );
ok( 'advisory' === $r6['status'], 'an unreachable ledger is reported as advisory' );
ok( array() === $r6['state'], 'an advisory scan does not overwrite remembered state' );

// ---- a surface the ledger does not know about ----------------------------
// The grace window applies here too, and should: a surface the worker has not
// anchored YET is indistinguishable from one it anchored a minute ago, so a
// first sighting only starts the clock.
$onlyRobots = array( row( 'robots-txt', $bodies['robots-txt'] ) );
$r7a = snt_rights_anchor_evaluate( $live, $onlyRobots, array(), $NOW );
ok( array() === $r7a['findings'], 'a surface missing from the ledger starts the clock rather than accusing on sight' );
ok( 3 === count( $r7a['state'] ), 'all three unanchored surfaces are remembered' );

$r7 = snt_rights_anchor_evaluate( $live, $onlyRobots, $r7a['state'], $NOW + ( 9 * $HOUR ) );
ok( 3 === count( $r7['findings'] ), 'surfaces still absent from the ledger after the window are findings, not silent passes' );
ok( false !== strpos( (string) $r7['findings'][0]['note'], 'no record' ), 'a never-anchored surface says so, rather than reusing the drift wording' );

// ---- a surface we could not fetch is skipped, not accused ----------------
$partial = $live;
unset( $partial['tdm-policy'] );
$r8 = snt_rights_anchor_evaluate( $partial, $anchors, array(), $NOW + ( 9 * $HOUR ) );
ok( array() === $r8['findings'], 'an unfetched surface is skipped rather than reported as unanchored' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
