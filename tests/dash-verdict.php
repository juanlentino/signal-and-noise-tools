<?php
/**
 * Tests: the shared verdict — one answer to "is anything wrong?", used by BOTH
 * the index.php widget and the full Dashboard screen.
 *
 * WHY SHARED. Two surfaces deriving the same verdict independently is how you
 * get a green widget above a red screen. It is also the exact shape of the
 * v11.16.2 health-count bug: a numerator counted one way and a denominator
 * another, so the tally read 21/21 while a check was failing. The headline
 * count here is therefore DERIVED FROM the exception list it introduces —
 * never tallied separately — and that property is pinned below.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-verdict.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "the shared verdict\n\n";

function card( $label, $kind, $value = '', $attention = null ) {
	$c = array( 'label' => $label, 'value' => $value, 'pill' => array( 'kind' => $kind, 'text' => $kind ) );
	if ( null !== $attention ) { $c['attention'] = $attention; }
	return $c;
}

// ── the healthy day, which is nearly every day ──────────────────────────────
$healthy = sn_dash_verdict( array( card( 'Health', 'ok', '0 findings' ), card( 'Cron', 'ok', '61 events' ) ) );
ok( 'ok' === $healthy['state'], 'a clean site reads ok' );
ok( array() === $healthy['exceptions'], 'WITH AN EMPTY EXCEPTION LIST — the empty state is the design target, not an afterthought' );
ok( false !== strpos( $healthy['headline'], 'holding' ), 'and a headline that states it plainly' );

// ── trouble ─────────────────────────────────────────────────────────────────
$bad = sn_dash_verdict( array(
	card( 'Health', 'ok', '0 findings' ),
	card( 'Caches', 'warn', '1/3 stale' ),
	card( 'Cron', 'err', 'no events' ),
) );
ok( 'err' === $bad['state'], 'ANY err WINS THE STATE — a warn beside an err must not soften the page' );
ok( 2 === count( $bad['exceptions'] ), 'both the warn and the err are listed' );
ok( 'Caches' === $bad['exceptions'][0]['label'] || 'Cron' === $bad['exceptions'][0]['label'], 'exceptions carry their label' );
ok( false === strpos( $bad['headline'], 'holding' ), 'the headline no longer says everything is holding' );

$warn_only = sn_dash_verdict( array( card( 'Caches', 'warn', '1/3 stale' ) ) );
ok( 'warn' === $warn_only['state'], 'a lone warn is amber, not red' );

// ── COUNT PARITY. The v11.16.2 trap: a headline tallied separately from the
// list it introduces will disagree with it the day they diverge. ────────────
ok( false !== strpos( $bad['headline'], '2' ), 'THE HEADLINE COUNTS WHAT IT LISTS — two exceptions, the word "2"' );
ok( 1 === substr_count( $warn_only['headline'], '1' ), 'and one exception says one' );
$many = sn_dash_verdict( array( card( 'A', 'warn' ), card( 'B', 'warn' ), card( 'C', 'err' ) ) );
ok( count( $many['exceptions'] ) === 3 && false !== strpos( $many['headline'], '3' ),
	'THE COUNT IS DERIVED FROM THE LIST — it cannot drift, because there is only one array' );

// ── COLD IS NOT BROKEN (v11.16.0). A probe that has not reported yet is not
// a fault, and the rail already honours this predicate. ─────────────────────
$cold = sn_dash_verdict( array( card( 'Remote MCP', 'warn', 'warming', false ) ) );
ok( 'ok' === $cold['state'], 'A WARMING PROBE DOES NOT RAISE THE VERDICT — cold is not broken' );
ok( array() === $cold['exceptions'], 'and it does not appear in the exception list' );

// ── an empty input is NOT a healthy site ───────────────────────────────────
$nothing = sn_dash_verdict( array() );
ok( 'unknown' === $nothing['state'],
	'NO CARDS AT ALL IS "UNKNOWN", NOT "OK" — nothing to check and everything checked out are different facts, and only one of them is reassuring' );
ok( false === strpos( $nothing['headline'], 'holding' ), 'so it must not claim everything is holding' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
