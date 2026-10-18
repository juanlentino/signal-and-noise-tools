<?php
/**
 * Tests: inc/machine-readers-basis.php and the counts it labels.
 *
 * Every count attributed to an identity carries its evidence basis: signed,
 * verified, claimed, inferred or unmeasured. "verified" keeps the rights
 * evidence meaning (Cloudflare's verified-bot category, the same since date,
 * through the same helper), so this suite loads the real
 * inc/rights-evidence-compose.php rather than a copy of its rule.
 *
 * Run: php tests/machine-readers-basis.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function __( $s, $d = null ) { return $s; }
$GLOBALS['__acts'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $tag ][] = $cb; }
$GLOBALS['__ab'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; }

// The sensor seam: one fixture per view, the real signature.
$GLOBALS['__mr'] = array();
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) {
	return $GLOBALS['__mr'][ $view ] ?? array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' );
}
function snt_mr_sensor_info() { return null; }
function snt_mr_crawler_list_status() { return null; }

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/rights-evidence-compose.php';
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-basis.php';
require __DIR__ . '/../inc/machine-readers-basis-fold.php';
require __DIR__ . '/../inc/machine-readers-summary.php';
require __DIR__ . '/../inc/machine-readers-daily.php';
require __DIR__ . '/../inc/machine-readers-ledger.php';
require __DIR__ . '/../inc/abilities-machine-readers-ledger.php';

// Fixtures run through the REAL field normalizer (the half of
// snt_mr_normalize_rows() that owns verified_bot, network and first_party), so
// an absent column reads as it does live: ''. inc/machine-readers-api.php
// itself cannot load here, it declares the fetch this suite stubs.
function snt_mr_normalize_rows( $data ) {
	$out = array();
	foreach ( $data as $r ) {
		$out[] = array_merge(
			array( 'family' => (string) ( $r['family'] ?? 'other-bot' ), 'surface' => (string) ( $r['surface'] ?? 'html' ), 'day' => (string) ( $r['day'] ?? '' ), 'hits' => (int) ( $r['hits'] ?? 0 ) ),
			snt_mr_normalize_taxonomy_fields( $r )
		);
	}
	return $out;
}
$row = static function ( $family, $purpose, $agent, $day, $hits, $extra = array() ) {
	return array_merge(
		array( 'family' => $family, 'surface' => 'html', 'purpose' => $purpose, 'agent' => $agent, 'day' => $day, 'hits' => $hits, 'taxonomy_version' => '1.3.1', 'first_party' => '0', 'verified_bot' => '', 'network' => '' ),
		$extra
	);
};

echo "Group A: the five basis values and the row classifier\n";
ok( array( 'signed', 'verified', 'vendor_verified', 'claimed', 'inferred', 'unmeasured' ) === SN_MR_BASIS_VALUES, 'six basis values, in order (vendor_verified: taxonomy 1.6.0)' );
ok( array( 'google-extended', 'apple-bot-extended' ) === array_keys( SN_MR_CONTROL_TOKENS ), 'the two control tokens are listed by taxonomy id' );
ok( snt_mr_is_impersonation( array( 'agent' => 'google-extended' ) ) && snt_mr_is_impersonation( array( 'agent' => 'apple-bot-extended' ) ), 'a hit presenting a control token is an impersonation' );
ok( ! snt_mr_is_impersonation( array( 'agent' => 'google-bot' ) ) && ! snt_mr_is_impersonation( array() ), 'any other agent, or none, is not' );

ok( 'unverifiable' === snt_mr_row_verification( array( 'day' => '2026-09-26', 'verified_bot' => 'AI Crawler' ) ), 'a day before verification began is unverifiable, even with a category' );
ok( 'unverifiable' === snt_mr_row_verification( array( 'day' => '2026-09-27', 'verified_bot' => '' ) ), 'the partial day it began on is unverifiable' );
ok( 'verified' === snt_mr_row_verification( array( 'day' => '2026-09-28', 'verified_bot' => 'AI Crawler' ) ), 'a later day with a category is verified' );
ok( 'unverified' === snt_mr_row_verification( array( 'day' => '2026-09-28', 'verified_bot' => '' ) ), 'a later day with none is unverified' );
ok( 'unverifiable' === snt_mr_row_verification( array( 'day' => '', 'verified_bot' => 'AI Crawler' ) ), 'a row with no day cannot be placed: unverifiable' );
ok( sn_rights_evidence_identity_class( '2026-09-28', 'X' ) === snt_mr_row_verification( array( 'day' => '2026-09-28', 'verified_bot' => 'X' ) ), 'the class is the rights evidence helper\'s, not a copy' );

echo "\nGroup B: verified_matched, the stricter flag\n";
$v = static fn( $purpose, $cat, $day = '2026-10-05' ) => snt_mr_row_verified_matched( array( 'purpose' => $purpose, 'verified_bot' => $cat, 'day' => $day ) );
ok( $v( 'train', 'AI Crawler' ), 'train matches AI Crawler' );
ok( ! $v( 'train', 'Search Engine Crawler' ), 'train does not match a search category: verified, but not for its claim' );
ok( $v( 'search', 'Search Engine Crawler' ) && $v( 'search', 'AI Search' ), 'search matches Search Engine Crawler and AI Search' );
ok( $v( 'user', 'AI Assistant' ) && $v( 'ops', 'Monitoring & Analytics' ) && $v( 'social', 'Page Preview' ), 'user, ops and social match their categories' );
ok( $v( 'train', 'ai crawler' ), 'matching ignores case' );
ok( ! $v( 'unknown', 'AI Crawler' ) && ! $v( 'dev', 'Other' ), 'unknown and dev purposes never match' );
ok( ! $v( 'train', '' ), 'no category is never matched' );
ok( ! $v( 'train', 'AI Crawler', '2026-09-20' ), 'an unverifiable day is never matched' );
ok( array() === array_diff( array_keys( SN_MR_PURPOSE_CATEGORIES ), snt_mr_valid_purposes() ), 'every purpose in the table is a real purpose' );
ok( ! isset( SN_MR_PURPOSE_CATEGORIES['unknown'] ) && ! isset( SN_MR_PURPOSE_CATEGORIES['dev'] ), 'unknown and dev have no row in the table' );

echo "\nGroup C: display labels\n";
ok( 'unmapped' === snt_mr_purpose_label( 'unknown' ) && 'unmapped' === snt_mr_purpose_label( '' ), 'purpose unknown displays as unmapped' );
ok( 'train' === snt_mr_purpose_label( 'train' ), 'every other purpose displays as itself' );

echo "\nGroup D: the summary splits each attributed count by verification\n";
$rows = array(
	// claudebot: 25 verified from Anthropic, 798 unverified from Google LLC.
	$row( 'anthropic', 'train', 'anthropic-claudebot', '2026-10-05', 25, array( 'verified_bot' => 'AI Crawler', 'network' => 'Anthropic, PBC' ) ),
	$row( 'anthropic', 'train', 'anthropic-claudebot', '2026-10-05', 798, array( 'network' => 'Google LLC' ) ),
	// A day before verification began: unverifiable.
	$row( 'anthropic', 'train', 'anthropic-claudebot', '2026-09-20', 10, array( 'network' => 'Google LLC' ) ),
	// Verified, but under a category that does not fit train.
	$row( 'openai', 'train', 'openai-gptbot', '2026-10-05', 4, array( 'verified_bot' => 'Search Engine Crawler', 'network' => 'Microsoft Corporation' ) ),
	// The control token: never credited to Google.
	$row( 'google-ai', 'train', 'google-extended', '2026-10-05', 170, array( 'network' => 'Google LLC' ) ),
	$row( 'google-ai', 'unknown', 'google-other', '2026-10-05', 1, array( 'verified_bot' => 'Search Engine Crawler', 'network' => 'Google LLC' ) ),
	// First-party: inside unclassified-machine, apart from the rest of it.
	$row( 'unclassified-machine', 'ops', 'self-ledger', '2026-10-05', 500, array( 'first_party' => '1', 'network' => 'Microsoft Corporation' ) ),
	// A first-party read that claims training: this site's own probe, never a reader's train.
	$row( 'unclassified-machine', 'train', 'self-train-probe', '2026-10-05', 7, array( 'first_party' => '1', 'network' => 'Microsoft Corporation' ) ),
	$row( 'unclassified-machine', 'social', 'meta-facebookexternalhit', '2026-10-05', 20, array( 'verified_bot' => 'Page Preview', 'network' => 'Meta Platforms Ireland Limited' ) ),
);
$GLOBALS['__mr'] = array(
	'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => snt_mr_normalize_rows( $rows ) ),
	'totals'    => array( 'ok' => true, 'rows' => array( array( 'day' => '2026-09-20', 'hits' => 10 ), array( 'day' => '2026-10-05', 'hits' => 1525 ) ) ),
);
$s = snt_mr_summary_payload( 30 );
ok( true === $s['ok'] && 1535 === $s['total'], 'total stays the edge\'s exact count, impersonations included (they were reads)' );
ok( 833 + 4 + 1 === $s['ai_training'], 'ai_training (legacy, family-based) loses the control-token hits: 833 anthropic + 4 openai + 1 google-other, not +170 google-extended' );
ok( 1 === ( $s['verification']['families']->{'google-ai'}['claimed'] ?? null ), 'google-ai keeps only google-other\'s hit' );
$train = array_values( array_filter( (array) $s['purposes'], static fn( $p ) => 'train' === $p['purpose'] ) )[0] ?? array();
ok( 837 === ( $train['hits'] ?? null ) && 837 === $s['ai_training_by_purpose'], 'purposes.train and ai_training_by_purpose lose the 170 too: 837' );
ok( array( array( 'agent' => 'google-extended', 'token' => 'Google-Extended', 'hits' => 170, 'networks' => array( array( 'network' => 'Google LLC', 'hits' => 170 ) ), 'basis' => 'claimed' ) ) === $s['impersonations'], 'impersonations lists the control token, its hits and networks, basis claimed' );
$vt = $s['verification']['purposes']->train ?? null;
ok( is_array( $vt ) && array( 'claimed' => 837, 'verified' => 29, 'verified_matched' => 25, 'vendor_verified' => 0, 'unverified' => 798, 'unverifiable' => 10 ) === $vt, 'train: claimed 837 = verified 29 + unverified 798 + unverifiable 10; 25 verified for training' );
ok( $s['verification']['ai_training_by_purpose'] === $vt, 'ai_training_by_purpose carries the same split' );
$pk = array_column( (array) $s['purposes'], 'hits', 'purpose' );
$vp = (array) $s['verification']['purposes'];
ksort( $pk );
ksort( $vp );
ok( array_keys( $pk ) === array_keys( $vp ) && array() === array_filter( array_keys( $pk ), static fn( $k ) => $vp[ $k ]['claimed'] !== $pk[ $k ] ), 'purposes[] and verification.purposes name the same purposes, and each split\'s claimed equals its purpose\'s hits (first-party reads out of both)' );
ok( 'cloudflare verified bot category; vendor published ip ranges' === $s['verification']['source'] && SN_RIGHTS_EVIDENCE_VERIFIED_SINCE === $s['verification']['since'], 'the source and since are the rights evidence\'s' );
ok( array( 'claimed' => 'claimed', 'verified' => 'verified', 'verified_matched' => 'verified', 'vendor_verified' => 'vendor_verified', 'unverified' => 'claimed', 'unverifiable' => 'unmeasured' ) === $s['verification']['basis'], 'each part of the split names its basis' );
$vu = $s['verification']['purposes']->unknown ?? array();
ok( 1 === ( $vu['verified'] ?? null ) && 0 === ( $vu['verified_matched'] ?? null ), 'an unmapped purpose can be verified but is never matched' );
$fam = array_column( $s['families'], null, 'family' );
ok( 527 === $fam['unclassified-machine']['hits'] && 507 === $fam['unclassified-machine']['first_party_hits'], 'a family row carries first_party_hits: 507 of unclassified-machine\'s 527 are this site\'s own tools' );
ok( 0 === $fam['anthropic']['first_party_hits'], 'a family with none says 0' );
ok( isset( $s['verification']['families']->anthropic ) && 833 === $s['verification']['families']->anthropic['claimed'], 'verification is keyed by family too' );
ok( 'claimed' === $s['basis']['families'] && 'claimed' === $s['basis']['purposes'] && 'signed' === $s['basis']['identity'] && 'verified' === $s['basis']['verification'] && 'claimed' === $s['basis']['impersonations'], 'basis names how each attributed count is known' );
ok( array() === array_diff( array_values( $s['basis'] ), SN_MR_BASIS_VALUES ), 'every basis is one of the five values' );

$sd = snt_mr_summary_payload( 30, 'day' );
$sum_ai = array_sum( array_column( $sd['daily'], 'ai_training' ) );
ok( $sd['ai_training'] === $sum_ai, 'daily ai_training still sums to the window figure with the control token excluded (' . $sum_ai . ')' );
$sum_train = 0;
foreach ( $sd['daily'] as $d ) {
	foreach ( (array) $d['purposes'] as $p ) {
		$sum_train += 'train' === $p['purpose'] ? $p['hits'] : 0;
	}
}
ok( 837 === $sum_train, 'daily purposes.train sums to the window train figure' );

$GLOBALS['__mr']['aggregate']['rows'] = snt_mr_normalize_rows( array( array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-10-05', 'hits' => 3 ) ) );
$s0 = snt_mr_summary_payload( 30 );
ok( null === $s0['impersonations'] && null === $s0['verification']['purposes'] && null === $s0['verification']['ai_training_by_purpose'], 'a window with no taxonomy reports the purpose-axis splits and impersonations as null, never zeros' );

echo "\nGroup E: crosstab cells carry the split\n";
$x     = snt_mr_crosstab( snt_mr_normalize_rows( $rows ) );
$cells = array();
foreach ( $x['cells'] as $c ) {
	$cells[ $c['agent'] ] = $c;
}
$cb = $cells['anthropic-claudebot'];
ok( 833 === $cb['claimed'] && 25 === $cb['verified'] && 25 === $cb['verified_matched'] && 798 === $cb['unverified'] && 10 === $cb['unverifiable'], 'a cell splits its hits: claimed = verified + unverified + unverifiable' );
ok( 'claimed' === $cb['basis'] && false === $cb['impersonation'] && false === $cb['first_party'], 'a cell names its basis and is not an impersonation' );
ok( true === $cells['google-extended']['impersonation'], 'the control-token cell is flagged impersonation: true' );
ok( true === $cells['self-ledger']['first_party'] && 500 === $cells['self-ledger']['first_party_hits'], 'a first-party cell says so, with its exact first-party hits' );
ok( 0 === $cb['first_party_hits'], 'a third-party cell carries first_party_hits 0' );
$mixed = snt_mr_crosstab( snt_mr_normalize_rows( array(
	$row( 'openai', 'train', 'openai-gptbot', '2026-10-05', 30, array( 'network' => 'Google LLC' ) ),
	$row( 'openai', 'train', 'openai-gptbot', '2026-10-05', 4, array( 'first_party' => '1', 'network' => 'Microsoft Corporation' ) ),
) ) )['cells'][0];
ok( 34 === $mixed['hits'] && 4 === $mixed['first_party_hits'] && true === $mixed['first_party'], 'a cell mixing first- and third-party rows counts the first-party part exactly (4 of 34); the flag is first_party_hits > 0' );
ok( 4 === $cells['openai-gptbot']['verified'] && 0 === $cells['openai-gptbot']['verified_matched'], 'a cell verified under the wrong category is verified but not matched' );

echo "\nGroup F: the unnamed list, folded by sample\n";
$GLOBALS['__mr']['aggregate'] = array( 'ok' => true, 'truncated' => false, 'rows' => snt_mr_normalize_rows( $rows ) );
// Worker after the unknown-view PR: rows split by verified_bot and network.
$GLOBALS['__mr']['unknown-network'] = array(
	'ok'      => true,
	'detail'  => 'network',
	'rows'    => snt_mr_normalize_rows( array(
		array( 'user_agent' => 'GrokBot/1.0', 'hits' => 200, 'verified_bot' => '', 'network' => 'Google LLC' ),
		array( 'user_agent' => 'GrokBot/1.0', 'hits' => 110, 'verified_bot' => 'AI Crawler', 'network' => 'xAI' ),
		array( 'user_agent' => 'KimiBot/1.0', 'hits' => 284, 'verified_bot' => '', 'network' => '' ),
	) ),
);
$u = snt_ability_get_machine_readers_crosstab( array( 'days' => 7 ) )['unnamed'];
ok( 2 === count( $u ) && 'GrokBot/1.0' === $u[0]['ua_sample'] && 310 === $u[0]['hits'], 'rows split by verification and network fold back to one entry per sample, top first' );
ok( 110 === $u[0]['verified_hits'] && 0 === $u[0]['not_measured'] && 'claimed' === $u[0]['basis'], 'a measured sample carries verified_hits and basis claimed' );
ok( array( array( 'network' => 'Google LLC', 'hits' => 200 ), array( 'network' => 'xAI', 'hits' => 110 ) ) === $u[0]['networks'], 'networks: top 5 {network, hits}, descending' );
ok( 284 === $u[1]['not_measured'] && 0 === $u[1]['verified_hits'] && array() === $u[1]['networks'], 'hits with no network are not_measured' );
// Worker before 1.30.0: the parameter is ignored, the old top 50 comes back
// with no detail in the envelope and no verification or network columns.
$GLOBALS['__mr']['unknown-network'] = array( 'ok' => true, 'detail' => '', 'rows' => snt_mr_normalize_rows( array( array( 'user_agent' => 'GrokBot/1.0', 'hits' => 310 ) ) ) );
$u = snt_ability_get_machine_readers_crosstab( array( 'days' => 7 ) )['unnamed'];
ok( null === $u[0]['verified_hits'] && 'unmeasured' === $u[0]['basis'], 'without the columns verified_hits is null and basis unmeasured, never 0' );
ok( 310 === $u[0]['not_measured'] && array() === $u[0]['networks'], 'and every hit is not_measured' );
$GLOBALS['__mr']['unknown-network'] = array( 'ok' => true, 'rows' => snt_mr_normalize_rows( array( array( 'user_agent' => 'GrokBot/1.0', 'verified_bot' => '', 'network' => 'Google LLC', 'hits' => 310 ) ) ) );
ok( null === snt_ability_get_machine_readers_crosstab( array( 'days' => 7 ) )['unnamed'][0]['verified_hits'], 'a read cached before the envelope carried detail reads unmeasured, whatever its rows hold' );

echo "\nGroup G: the display rows both Machine Readers leaves paint\n";
$disp = snt_mr_basis_display( snt_mr_normalize_rows( $rows ) );
$dp   = array_column( $disp['purposes'], null, 'label' );
ok( array( 'label' => 'train', 'claimed' => 837, 'verified' => 29, 'verified_matched' => 25, 'vendor_verified' => 0, 'unverified' => 798, 'unverifiable' => 10 ) === $dp['train'], 'a purpose row: claimed, verified, verified for its purpose, unverified, unverifiable' );
ok( isset( $dp['unmapped'] ) && ! isset( $dp['unknown'] ), 'purpose unknown is labeled unmapped' );
ok( 'train' === $disp['purposes'][0]['label'], 'purpose rows run by claimed reads, largest first' );
$df = array_column( $disp['families'], null, 'label' );
ok( 507 === $df['unclassified-machine']['first_party'] && 527 === $df['unclassified-machine']['claimed'], 'a family row carries its first-party reads' );
ok( array( 'AI-training, by purpose', 'AI-training, by family (legacy)' ) === array_column( $disp['ai'], 'label' ) && 837 === $disp['ai'][0]['claimed'] && 838 === $disp['ai'][1]['claimed'], 'the two AI-training figures side by side, the family one marked legacy' );
ok( 'Google-Extended' === ( $disp['impersonations'][0]['token'] ?? '' ), 'the impersonations ride along' );
ok( SN_RIGHTS_EVIDENCE_VERIFIED_SINCE === $disp['since'], 'with the since date the split is measured from' );
$GLOBALS['__mr']['aggregate']['rows'] = snt_mr_normalize_rows( array( array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-10-05', 'hits' => 3 ) ) );
$nt = snt_mr_basis_display( $GLOBALS['__mr']['aggregate']['rows'] );
ok( null === $nt['purposes'] && null === $nt['ai'][0]['claimed'], 'without the taxonomy the purpose rows are null (not measured), never zeros' );

echo "\nGroup H: the unnamed fold, at any cut\n";
$read = array( 'ok' => true, 'detail' => 'network', 'rows' => snt_mr_normalize_rows( array(
	array( 'user_agent' => 'A/1', 'hits' => 5, 'verified_bot' => '', 'network' => 'N1' ),
	array( 'user_agent' => 'B/1', 'hits' => 9, 'verified_bot' => 'AI Crawler', 'network' => 'N2' ),
	array( 'user_agent' => 'A/1', 'hits' => 6, 'verified_bot' => '', 'network' => 'N3' ),
) ) );
$f1 = snt_mr_unnamed_fold( $read, 1 );
ok( 1 === count( $f1 ) && 'A/1' === $f1[0]['ua_sample'] && 11 === $f1[0]['hits'], 'folded before the cut: A/1 (5+6) outranks B/1 (9)' );
ok( 2 === count( snt_mr_unnamed_fold( $read, 50 ) ), 'the admin list cuts at 50, the ability at 20' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
