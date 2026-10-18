<?php
/**
 * Tests: reader-anomalies on the evidence basis (inc/ml-reader-anomalies-split.php).
 *
 * Three readings the pipeline could not give before: first-party traffic is
 * not readership and leaves the series (live 2026-10-05: unclassified-machine
 * flagged 13.1 sigma on the site's own ledger verifier); verified and
 * unverified hits get series of their own; and several families moving
 * together on unverified reads from one network are ONE finding, named by the
 * network (live 2026-10-10: seven families above their norm, all claimed
 * agents on Google LLC addresses).
 *
 * Run: php tests/ml-reader-anomalies-basis.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__fetch'] = array( 'ok' => true, 'rows' => array(), 'error' => null );
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) {
	return $GLOBALS['__fetch'];
}

if ( ! defined( 'SN_ANALYTICS_CLASSES' ) ) { define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) ); }
require __DIR__ . '/../inc/analytics-derived.php';
require __DIR__ . '/../inc/analytics-signals.php';
require __DIR__ . '/../inc/mr-series.php';
require __DIR__ . '/../inc/rights-evidence-compose.php';
require __DIR__ . '/../inc/machine-readers-basis.php';
require __DIR__ . '/../inc/ml-reader-anomalies.php';
require __DIR__ . '/../inc/ml-reader-anomalies-split.php';
require __DIR__ . '/../inc/ml-reader-anomalies-health.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

/** $days consecutive days ending yesterday; $spike maps day => hits. */
function mr_rows( $family, $hits, $now, $extra = array(), $spike = array(), $days = 30 ) {
	$out = array();
	for ( $i = 1; $i <= $days; $i++ ) {
		$day   = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
		$out[] = array_merge(
			array( 'family' => $family, 'surface' => 'html', 'day' => $day, 'hits' => $spike[ $day ] ?? $hits, 'verified_bot' => '', 'network' => '', 'first_party' => false, 'agent' => $family . '-bot' ),
			$extra
		);
	}
	return $out;
}
$by_family = static fn( $r ) => array_column( $r['families'], null, 'family' );
$kinds     = static fn( $fam ) => array_map( static fn( $s ) => $s['id'], $fam['signals'] );

$NOW    = strtotime( '2026-10-11 12:00:00 UTC' );
$GOOGLE = array( 'network' => 'Google LLC' );
$spike  = array( '2026-10-10' => 400 );

echo "Group A: first-party traffic is not readership\n";
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'unclassified-machine', 500, $NOW, array( 'first_party' => true, 'agent' => 'self-ledger' ), array( '2026-10-05' => 9000 ) ),
	mr_rows( 'unclassified-machine', 10, $NOW, array( 'agent' => 'meta-facebookexternalhit' ) ),
	mr_rows( 'uptime', 480, $NOW, array( 'first_party' => true, 'agent' => 'ops-betterstack' ) )
);
$r = snt_ml_reader_anomalies( $NOW );
$f = $by_family( $r );
ok( 300 === ( $f['unclassified-machine']['total'] ?? null ), 'unclassified-machine counts only its third-party reads (30 x 10), not the site\'s own ledger verifier' );
ok( ! in_array( 'anomaly:unclassified-machine:2026-10-05', $kinds( $f['unclassified-machine'] ?? array( 'signals' => array() ) ), true ), 'the first-party spike on 2026-10-05 no longer flags the family' );
ok( ! isset( $f['uptime'] ) && ! isset( $r['excluded']['uptime'] ), 'a family made only of first-party reads (uptime: BetterStack) is not in the reader series at all' );
ok( true === ( $r['first_party_excluded'] ?? null ), 'the payload says first-party reads were left out' );

echo "\nGroup A2: a control token is no family's reader\n";
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'google-ai', 7, $NOW, array( 'agent' => 'google-extended', 'network' => 'Google LLC' ), $spike ),
	mr_rows( 'google-ai', 3, $NOW, array( 'agent' => 'google-other' ) ),
	mr_rows( 'anthropic', 10, $NOW, $GOOGLE, $spike ),
	mr_rows( 'openai', 12, $NOW, $GOOGLE, $spike )
);
$r = snt_ml_reader_anomalies( $NOW );
$f = $by_family( $r );
ok( 90 === ( $f['google-ai']['total'] ?? null ), 'google-ai counts its own agents (30 x 3), not the Google-Extended control token' );
ok( array() === ( $r['co_movement'] ?? null ), 'a control token spiking beside two families does not make a third mover: no co-movement' );

echo "\nGroup A3: impostors stay in the series (the detector that found them)\n";
// cf_verified agents Cloudflare did not verify: credited to no family in the
// summary, but the co-movement signal must still see them move.
$FAKE = array( 'network' => 'Google LLC', 'cf_verified' => true );
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 10, $NOW, $FAKE + array( 'agent' => 'anthropic-claudebot' ), $spike ),
	mr_rows( 'openai', 12, $NOW, $FAKE + array( 'agent' => 'openai-gptbot' ), $spike ),
	mr_rows( 'amazon-ai', 9, $NOW, $FAKE + array( 'agent' => 'amazon-bot' ), $spike )
);
ok( function_exists( 'snt_mr_is_impostor' ) && snt_mr_is_impostor( $GLOBALS['__fetch']['rows'][0], snt_mr_credit_context( $GLOBALS['__fetch']['rows'] ) ), 'the fixture rows are impostors by the summary\'s own rule' );
$r = snt_ml_reader_anomalies( $NOW );
$f = $by_family( $r );
ok( 29 * 10 + 400 === ( $f['anthropic']['total'] ?? null ), 'anthropic\'s series keeps every impostor read (29 x 10 + 400)' );
ok( 1 === count( $r['co_movement'] ?? array() ) && 'Google LLC' === ( $r['co_movement'][0]['network'] ?? null ), 'three impostor families spiking together from Google LLC still make one co-movement' );

echo "\nGroup A4: rotator impostors stay in the series too\n";
// Five vendors' agents from one network, none verified, none in Cloudflare's
// directory: impostors by rotator_network, and still in every family series.
$ROT = array( 'network' => 'Google LLC', 'cf_verified' => false );
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 10, $NOW, $ROT + array( 'agent' => 'cohere-ai', 'vendor' => 'cohere' ), $spike ),
	mr_rows( 'openai', 12, $NOW, $ROT + array( 'agent' => 'mistral-user', 'vendor' => 'mistral' ), $spike ),
	mr_rows( 'amazon-ai', 9, $NOW, $ROT + array( 'agent' => 'perplexity-user', 'vendor' => 'perplexity' ), $spike ),
	mr_rows( 'bytedance', 9, $NOW, $ROT + array( 'agent' => 'openai-adsbot', 'vendor' => 'openai' ), array() ),
	mr_rows( 'meta-ai', 9, $NOW, $ROT + array( 'agent' => 'meta-externalfetcher', 'vendor' => 'meta' ), array() )
);
$ctx = snt_mr_credit_context( $GLOBALS['__fetch']['rows'] );
ok( 'rotator_network' === snt_mr_credit_verdict( $GLOBALS['__fetch']['rows'][0], $ctx )['reason'], 'the fixture rows are rotator impostors by the summary\'s own rule' );
$r = snt_ml_reader_anomalies( $NOW );
$f = $by_family( $r );
ok( 29 * 10 + 400 === ( $f['anthropic']['total'] ?? null ), 'the series keeps every rotator impostor read (29 x 10 + 400)' );
ok( 1 === count( $r['co_movement'] ?? array() ), 'and they still make the co-movement' );

echo "\nGroup B: co-movement, one finding per network\n";
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 10, $NOW, $GOOGLE, $spike ),
	mr_rows( 'openai', 12, $NOW, $GOOGLE, $spike ),
	mr_rows( 'amazon-ai', 9, $NOW, $GOOGLE, $spike ),
	mr_rows( 'bytedance', 11, $NOW, $GOOGLE, $spike ),
	// Verified search traffic spiking the same day is its own finding.
	mr_rows( 'search', 150, $NOW, array( 'network' => 'Google LLC', 'verified_bot' => 'Search Engine Crawler' ), array( '2026-10-10' => 3000 ) )
);
$r  = snt_ml_reader_anomalies( $NOW );
$f  = $by_family( $r );
$co = $r['co_movement'] ?? array();
ok( 1 === count( $co ), 'four families above their norm on unverified Google LLC reads the same day are ONE signal' );
ok( 'reader_co_movement' === ( $co[0]['kind'] ?? '' ) && 'Google LLC' === ( $co[0]['network'] ?? '' ) && '2026-10-10' === ( $co[0]['day'] ?? '' ), 'it names the network and the day' );
ok( array( 'amazon-ai', 'anthropic', 'bytedance', 'openai' ) === ( $co[0]['families'] ?? null ), 'and the families, sorted' );
ok( 4 === count( $co[0]['members'] ?? array() ) && 'anomaly:anthropic:2026-10-10' === ( $co[0]['members'][1]['id'] ?? '' ), 'the per-family signals ride inside it as members, nothing lost' );
ok( ! in_array( 'anomaly:anthropic:2026-10-10', $kinds( $f['anthropic'] ), true ), 'and leave the family rows, so the finding is counted once' );
ok( in_array( 'anomaly:search:2026-10-10', $kinds( $f['search'] ), true ), 'verified search traffic keeps its own anomaly: not part of the co-movement' );
ok( 1 === $r['counts']['co_movements'] && 1 === $r['counts']['anomalies'], 'counts: one co-movement, one remaining anomaly (search)' );
ok( 'claimed' === ( $co[0]['basis'] ?? '' ) && false === strpos( (string) ( $co[0]['plain_label'] ?? '' ), "\xE2\x80\x94" ), 'basis claimed, and a plain label without an em dash' );
$h = snt_ml_reader_anomalies_health( $r );
ok( false !== strpos( $h['summary'], '1 co-movement' ), 'Site Health names the co-movement: ' . $h['summary'] );

// Two families sharing a network is not a co-movement.
$GLOBALS['__fetch']['rows'] = array_merge( mr_rows( 'anthropic', 10, $NOW, $GOOGLE, $spike ), mr_rows( 'openai', 12, $NOW, $GOOGLE, $spike ) );
$r = snt_ml_reader_anomalies( $NOW );
ok( array() === $r['co_movement'] && 2 === $r['counts']['anomalies'], 'two families on one network stay two per-family findings' );

// Three families on DIFFERENT networks are not one either.
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 10, $NOW, array( 'network' => 'Hetzner Online GmbH' ), $spike ),
	mr_rows( 'openai', 12, $NOW, $GOOGLE, $spike ),
	mr_rows( 'amazon-ai', 9, $NOW, array( 'network' => 'OVH SAS' ), $spike )
);
ok( array() === snt_ml_reader_anomalies( $NOW )['co_movement'], 'three families on three networks share nothing' );

// Verified reads from one network are the vendor's own crawlers, not a co-movement.
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 10, $NOW, $GOOGLE + array( 'verified_bot' => 'AI Crawler' ), $spike ),
	mr_rows( 'openai', 12, $NOW, $GOOGLE + array( 'verified_bot' => 'AI Crawler' ), $spike ),
	mr_rows( 'amazon-ai', 9, $NOW, $GOOGLE + array( 'verified_bot' => 'AI Crawler' ), $spike )
);
ok( array() === snt_ml_reader_anomalies( $NOW )['co_movement'], 'three families on verified reads from one network do not co-move' );

// A silence joins too: the family's unverified network over the verifiable window.
$quiet = array( '2026-10-10' => 0 );
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 10, $NOW, $GOOGLE, $quiet ),
	mr_rows( 'openai', 12, $NOW, $GOOGLE, $quiet ),
	mr_rows( 'mistral', 11, $NOW, $GOOGLE, $quiet )
);
$r = snt_ml_reader_anomalies( $NOW );
ok( 1 === count( $r['co_movement'] ) && 'down' === $r['co_movement'][0]['direction'] && 0 === $r['counts']['silences'], 'three families silent the same day, on Google LLC the rest of the window, are one co-movement (down)' );

echo "\nGroup C: verified and unverified series of their own\n";
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 2, $NOW, array( 'network' => 'Anthropic, PBC', 'verified_bot' => 'AI Crawler' ) ),
	mr_rows( 'anthropic', 10, $NOW, $GOOGLE, $spike )
);
$a  = $by_family( snt_ml_reader_anomalies( $NOW ) )['anthropic'];
$bv = $a['by_verification'] ?? array();
ok( '2026-09-28' === ( $bv['from'] ?? '' ), 'the split starts on the first whole day the sensor recorded verification (2026-09-27 is partial)' );
ok( 13 * 2 === ( $bv['verified']['total'] ?? null ) && 12 * 10 + 400 === ( $bv['unverified']['total'] ?? null ), 'verified and unverified totals cover only verifiable days' );
ok( 13 === ( $bv['unverified']['days_present'] ?? null ) && array() === ( $bv['unverified']['signals'] ?? null ) && false !== strpos( (string) ( $bv['unverified']['withheld'] ?? '' ), '13 of 13' ), 'under 20 present days a split series withholds its signals and says why' );
ok( isset( $bv['verified']['baseline']['median'] ) && 2.0 === (float) $bv['verified']['baseline']['median'], 'each split carries its own baseline' );

$LATER = strtotime( '2026-10-25 12:00:00 UTC' );
$GLOBALS['__fetch']['rows'] = array_merge(
	mr_rows( 'anthropic', 2, $LATER, array( 'network' => 'Anthropic, PBC', 'verified_bot' => 'AI Crawler' ) ),
	mr_rows( 'anthropic', 10, $LATER, $GOOGLE, array( '2026-10-24' => 400 ) )
);
$bv = $by_family( snt_ml_reader_anomalies( $LATER ) )['anthropic']['by_verification'];
ok( in_array( 'anomaly:anthropic/unverified:2026-10-24', array_column( $bv['unverified']['signals'], 'id' ), true ), 'with 20 verifiable days the unverified series fires on its own spike' );
ok( ! in_array( 'anomaly:anthropic/verified:2026-10-24', array_column( $bv['verified']['signals'], 'id' ), true ), 'and the verified series, flat, does not' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
