<?php
/**
 * Tests: impostor reads are counted but not credited
 * (inc/machine-readers-basis-impostors.php and the figures that read it).
 *
 * An impostor is a hit whose agent Cloudflare verifies when genuine (the
 * taxonomy's cf_verified, stamped on each row by the sensor) that Cloudflare
 * did NOT verify, on a day the sensor recorded verification. Like a control
 * token it stays in `total` and is credited to no vendor, family or purpose.
 * Live 2026-10-18: 1,378 of ClaudeBot's 1,436 reads in 14 days came from
 * "Google LLC" with no verification; the real ClaudeBot sent 58.
 *
 * Run: php tests/machine-readers-impostors.php
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
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function add_action( $tag, $cb, $p = 10, $a = 1 ) {}
function wp_register_ability( $slug, $args ) {}

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
require __DIR__ . '/../inc/machine-readers-readers-model.php';
require __DIR__ . '/../inc/machine-readers-evidence-model.php';
require __DIR__ . '/../inc/analytics-lanes-machine.php';

// The real field normalizer, as tests/machine-readers-basis.php uses it.
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
$row = static function ( $family, $purpose, $agent, $day, $hits, $cf, $vb, $net, $fp = '0' ) {
	return array( 'family' => $family, 'surface' => 'html', 'purpose' => $purpose, 'agent' => $agent, 'day' => $day, 'hits' => $hits, 'taxonomy_version' => '1.5.0', 'first_party' => $fp, 'verified_bot' => $vb, 'network' => $net, 'cf_verified' => $cf );
};
$D   = '2026-10-05'; // after SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
$raw = array(
	'fake_claude'  => $row( 'anthropic', 'train', 'anthropic-claudebot', $D, 1378, '1', '', 'Google LLC' ),
	'real_claude'  => $row( 'anthropic', 'train', 'anthropic-claudebot', $D, 58, '1', 'AI Crawler', 'Anthropic, PBC' ),
	'claude_user'  => $row( 'anthropic', 'user', 'anthropic-claude-user', $D, 72, '1', 'AI Assistant', 'Google LLC' ),
	'pre_since'    => $row( 'anthropic', 'train', 'anthropic-claudebot', '2026-09-20', 100, '1', '', 'Google LLC' ),
	'token'        => $row( 'google-ai', 'train', 'google-extended', $D, 173, '0', '', 'Google LLC' ),
	'cohere'       => $row( 'cohere', 'unknown', 'cohere-ai', $D, 443, '0', '', 'Google LLC' ),
	'fake_google'  => $row( 'search', 'search', 'google-bot', $D, 729, '1', '', 'Google LLC' ),
	'real_google'  => $row( 'search', 'search', 'google-bot', $D, 434, '1', 'Search Engine Crawler', 'Google LLC' ),
	'own_tool'     => $row( 'unclassified-machine', 'ops', 'self-ledger', $D, 500, '1', '', 'Microsoft Corporation', '1' ),
);
$n = array_combine( array_keys( $raw ), snt_mr_normalize_rows( array_values( $raw ) ) );

echo "Group A: the credit class is a pure function of the row\n";
ok( 'impostor' === snt_mr_credit_class( $n['fake_claude'] ), 'ClaudeBot, unverified, from Google LLC: impostor' );
ok( 'credited' === snt_mr_credit_class( $n['real_claude'] ), 'ClaudeBot, verified, from Anthropic: credited' );
ok( 'credited' === snt_mr_credit_class( $n['claude_user'] ), 'Claude-User, verified, from Google LLC: credited (verified is never an impostor, whatever the network)' );
ok( 'unverifiable' === snt_mr_row_verification( $n['pre_since'] ) && 'credited' === snt_mr_credit_class( $n['pre_since'] ), 'a row before the since date is unverifiable and never reclassified' );
ok( 'control_token' === snt_mr_credit_class( $n['token'] ), 'Google-Extended is still a control token' );
ok( 'unverified' === snt_mr_row_verification( $n['cohere'] ) && 'credited' === snt_mr_credit_class( $n['cohere'] ), 'cohere-ai (cf_verified false), unverified: credited, not an impostor' );
$absent = $raw['fake_claude'];
unset( $absent['cf_verified'] );
ok( 'credited' === snt_mr_credit_class( snt_mr_normalize_rows( array( $absent ) )[0] ), 'a row from a sensor that sends no cf_verified reads it as false: credited' );
ok( 'credited' === snt_mr_credit_class( $n['own_tool'] ), 'this site\'s own tools are never impostors' );
ok( true === $n['fake_claude']['cf_verified'] && false === $n['cohere']['cf_verified'], 'the normalizer reads "1"/"0" as a boolean' );

echo "\nGroup B: counted, not credited\n";
$plain = array_map( static function ( $r ) { $r['cf_verified'] = '0'; return $r; }, $raw );
$GLOBALS['__mr'] = array( 'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => snt_mr_normalize_rows( array_values( $plain ) ) ) );
$before = snt_mr_summary_payload( 30 );
$GLOBALS['__mr'] = array( 'aggregate' => array( 'ok' => true, 'truncated' => false, 'rows' => array_values( $n ) ) );
$after = snt_mr_summary_payload( 30 );
ok( $before['total'] === $after['total'] && 3887 === $after['total'], 'total is unchanged with impostors present (3,887)' );
$fam = static fn( $s, $f ) => (int) ( $s['verification']['families']->$f['claimed'] ?? 0 );
ok( $fam( $before, 'anthropic' ) - $fam( $after, 'anthropic' ) === 1378, 'the anthropic family loses exactly the 1,378 impostor hits' );
ok( $fam( $before, 'search' ) - $fam( $after, 'search' ) === 729, 'the search family loses exactly the 729 impostor hits' );
$sum_fam = static fn( $s ) => array_sum( array_map( static fn( $t ) => $t['claimed'], (array) $s['verification']['families'] ) );
ok( $sum_fam( $before ) - $sum_fam( $after ) === 2107, 'per-family credited sum drops by exactly the impostor hits (2,107)' );
$imp = array_column( (array) $after['impostors'], null, 'agent' );
ok( 1378 === ( $imp['anthropic-claudebot']['hits'] ?? null ) && 729 === ( $imp['google-bot']['hits'] ?? null ) && 2 === count( $imp ), 'impostors lists ClaudeBot 1,378 and Googlebot 729, nothing else' );
ok( 'inferred' === $imp['anthropic-claudebot']['basis'] && 'inferred' === $after['basis']['impostors'], 'the impostor bucket carries basis inferred, never claimed' );
ok( array( array( 'network' => 'Google LLC', 'hits' => 1378 ) ) === $imp['anthropic-claudebot']['networks'], 'with its top networks' );
ok( 173 === ( $after['impersonations'][0]['hits'] ?? null ), 'control tokens stay listed apart under impersonations' );
ok( array() === $before['impostors'], 'no cf_verified, no impostors: an empty list' );

echo "\nGroup C: every attributed figure reads credited rows only\n";
$who = array_column( snt_mr_who_reads( array_values( $n ) )['rows'], null, 'family' );
ok( 58 + 72 + 100 === $who['anthropic']['split']['claimed'], 'who reads: anthropic is 230 credited reads (58 + 72 + 100 unverifiable)' );
$cells = array_column( snt_mr_crosstab( array_values( $n ) )['cells'], null, 'agent' );
ok( 1378 === ( $cells['anthropic-claudebot']['impostor_hits'] ?? null ) && 1536 === $cells['anthropic-claudebot']['hits'], 'a crosstab cell keeps every hit and names the impostor part (1,378 of 1,536)' );
ok( 0 === ( $cells['anthropic-claude-user']['impostor_hits'] ?? null ), 'a cell with none says 0' );
ok( array( 'claimed_bot', 'impostor' ) === array( sn_lanes_machine_key( $n['fake_claude'] )['lane'], sn_lanes_machine_key( $n['fake_claude'] )['reason'] ) && '' === sn_lanes_machine_key( $n['fake_claude'] )['family'], 'lanes: an impostor is a claimed bot, reason impostor, credited to no family' );
ok( false !== strpos( snt_mr_ai_finding( array_values( $n ) ), '1,378 claimed a known crawler but failed Cloudflare\'s check' ), 'the AI-training sentence names the impostor reads: ' . snt_mr_ai_finding( array_values( $n ) ) );
$exc = array_column( snt_mr_agent_exceptions( array_values( $n ) )['rows'], null, 'agent' );
ok( 1378 === ( $exc['anthropic-claudebot']['split']['unverified'] ?? null ), 'the "is this crawler real" table still shows the impostor reads (it is the evidence)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
