<?php
/**
 * Tests: the vendor/purpose axes (v10.79.0).
 *
 * The load-bearing assertions here are the NEGATIVE ones: that `family` did not
 * move, that a *-User agent never lands in `train`, and that the two
 * attacker-influenced strings on this surface cannot carry markup.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Minimal i18n/escaping stubs modelling the REAL transform, not identity: a
// stub that returns its input unchanged would certify an unescaped renderer.
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
// RETURNS the chosen form, never echoes, and picks on the COUNT — the real
// _n()'s shape. A stub that ignored $n would green a renderer that says
// "1 events" on the live site (the stub-drift trap, bitten repeatedly).
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-api.php';
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-basis.php'; // Unreleased: unmapped, and the control-token rule.

echo "\nGroup: RULE 1 , the frozen family enum did not move\n";
$families = snt_mr_valid_families();
ok( 'openai' === $families[0] && 'other-bot' === $families[17], 'the original enum still starts and ends where it did' );
ok( 'unclassified-machine' === $families[18] && 19 === count( $families ), 'the additive value is appended, never inserted' );
ok( ! in_array( 'unclassified-machine', array_slice( $families, 0, 18 ), true ), 'the additive value never displaces a frozen one' );

echo "\nGroup: an older Worker degrades to the v10.0.0 shape, it does not fabricate\n";
$legacy = snt_mr_normalize_rows( array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-08-01', 'hits' => 5 ) ) );
ok( 5 === $legacy[0]['hits'] && 'openai' === $legacy[0]['family'], 'legacy rows still normalize exactly as before' );
ok( '' === $legacy[0]['vendor'] && 'unknown' === $legacy[0]['purpose'], 'absent taxonomy fields land on empty/unknown' );
ok( true === snt_mr_taxonomy_absent( $legacy ), 'and the absence is DETECTABLE, not silently zero' );
$modern = snt_mr_normalize_rows( array( array( 'family' => 'other-bot', 'surface' => 'llms', 'day' => '2026-08-01', 'hits' => 3, 'vendor' => 'anthropic', 'purpose' => 'search', 'taxonomy_version' => '1.0.0' ) ) );
ok( false === snt_mr_taxonomy_absent( $modern ), 'a taxonomy-bearing response is not reported as absent' );

echo "\nGroup: purpose is never derived from family\n";
ok( 'other-bot' === $modern[0]['family'], 'Claude-SearchBot keeps its frozen other-bot family' );
ok( 'anthropic' === $modern[0]['vendor'] && 'search' === $modern[0]['purpose'], 'while carrying its true vendor and purpose' );

echo "\nGroup: hostile worker values fail INTO the enum\n";
$hostile = snt_mr_normalize_rows( array( array(
	'family'  => '<script>x</script>',
	'surface' => '../../etc/passwd',
	'day'     => 'not-a-day',
	'hits'    => -99,
	'vendor'  => '<img src=x onerror=alert(1)>',
	'purpose' => 'train"; DROP TABLE',
	'taxonomy_version' => '1.0.0<script>',
) ) );
ok( 'other-bot' === $hostile[0]['family'] && 'html' === $hostile[0]['surface'], 'unknown family/surface coerce to the enum' );
ok( 'unknown' === $hostile[0]['purpose'], 'an unknown purpose coerces to unknown, never passes through' );
ok( '' === $hostile[0]['day'] && 0 === $hostile[0]['hits'], 'malformed day and negative hits are neutralised' );
ok( false === strpos( $hostile[0]['vendor'], '<' ) && false === strpos( $hostile[0]['vendor'], '>' ), 'vendor cannot carry markup' );
ok( '1.0.0' === $hostile[0]['taxonomy_version'], 'taxonomy_version is stripped to digits and dots' );

echo "\nGroup: the sampled user agent is sanitized, then escaped again at the sink\n";
$ua = snt_mr_normalize_ua_sample( 'Mozilla/5.0 <script>alert("xss")</script> \'; DROP' );
ok( false === strpos( $ua, '<' ) && false === strpos( $ua, '"' ) && false === strpos( $ua, "'" ), 'markup and quotes never survive normalization' );
ok( 96 >= strlen( snt_mr_normalize_ua_sample( str_repeat( 'A', 500 ) ) ), 'a long UA cannot dominate the column' );
echo "\nGroup: the purpose vocabulary is closed\n";
$purposes = snt_mr_valid_purposes();
ok( 13 === count( $purposes ), 'exactly thirteen purposes' );
ok( in_array( 'ads', $purposes, true ), "the 'ads' purpose exists so ad validators are not stretched into security" );
ok( in_array( 'train', $purposes, true ) && ! in_array( 'training', $purposes, true ), 'the value is train, not a near-miss synonym' );

echo "\nGroup: RULE 3 , the rights stream is normalized on its OWN shape\n";
$rights_raw = array( array(
	'observed_at' => '2026-08-10T09:14:02.511Z',
	'path'        => '/license.xml',
	'user_agent'  => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
	'accept'      => '*/*',
	'vendor'      => 'anthropic',
	'purpose'     => 'train',
	'family'      => 'anthropic',
	'hits'        => 1,
) );
$rd = snt_mr_normalize_rights_rows( $rights_raw );
ok( '/license.xml' === $rd[0]['path'], 'the path survives (the aggregate normalizer would have dropped it)' );
ok( false !== strpos( $rd[0]['user_agent'], 'ClaudeBot/1.0' ), 'the COMPLETE user agent survives, unlike the allowlisted sample' );
ok( '2026-08-10T09:14:02.511Z' === $rd[0]['observed_at'], 'the timestamp survives intact' );
$rd_hostile = snt_mr_normalize_rights_rows( array( array(
	'observed_at' => "2026-08-10T09:14:02Z<script>",
	'path'        => '/license.xml',
	'user_agent'  => 'Mozilla/5.0 <script>alert(1)</script>',
	'purpose'     => 'nonsense',
	'family'      => 'nonsense',
) ) );
ok( false === strpos( $rd_hostile[0]['observed_at'], '<' ), 'the timestamp is shape-restricted' );
ok( 'unknown' === $rd_hostile[0]['purpose'] && 'other-bot' === $rd_hostile[0]['family'], 'enums still fail INTO the allowlist' );
echo "\nGroup: #1227 — UA/path caps are mb_-safe, timestamp cap fits milliseconds\n";
// A multibyte-heavy path/UA must not have its last character split into an
// invalid byte fragment by the taxonomy normalizer's byte-based cap.
$long_multibyte = str_repeat( 'Ω', 520 ); // 520 chars, well past the 512-char UA cap.
$rd_mb = snt_mr_normalize_rights_rows( array( array(
	'observed_at' => '2026-08-10T09:14:02.511Z',
	'path'        => '/license.xml',
	'user_agent'  => $long_multibyte,
	'purpose'     => 'train',
	'family'      => 'anthropic',
) ) );
ok( 512 === mb_strlen( $rd_mb[0]['user_agent'] ), 'a >512-char multibyte UA is clipped to exactly 512 CHARACTERS' );
ok( 1 === preg_match( '//u', $rd_mb[0]['user_agent'] ), 'the clipped UA is valid UTF-8, not a split multibyte tail' );

echo "\nGroup: markdown_requested and signed_agent normalize additively\n";
// v12.16.0: markdown_requested — Worker v1.18.0's blob10, normalized additively.
$md_on     = snt_mr_normalize_taxonomy_fields( array( 'markdown_requested' => '1' ) );
$md_off    = snt_mr_normalize_taxonomy_fields( array( 'markdown_requested' => '0' ) );
$md_absent = snt_mr_normalize_taxonomy_fields( array() );
ok( true === ( $md_on['markdown_requested'] ?? null ), "markdown_requested '1' normalizes to true" );
ok( false === ( $md_off['markdown_requested'] ?? null ), "markdown_requested '0' normalizes to false" );
// The additive contract: an OLDER Worker sends no such column at all. It must
// land on false — "nobody asked" — never null and never a warning.
ok( false === ( $md_absent['markdown_requested'] ?? null ), 'an older Worker sending no column degrades to false, not null' );
ok( false === ( snt_mr_normalize_taxonomy_fields( array( 'markdown_requested' => 'yes' ) )['markdown_requested'] ?? null ), 'any non-"1" value is false (fails toward not-requested)' );

// v12.24.0: signed_agent — Worker v1.19.0's blob11, exposed by the read query
// in Worker v1.20.0. FOUR states plus two honesty cases, which is the whole
// reason this is not a boolean.
foreach ( array( 'valid', 'unsigned', 'invalid', 'unknown-key' ) as $state ) {
	ok(
		$state === ( snt_mr_normalize_taxonomy_fields( array( 'signed_agent' => $state ) )['signed_agent'] ?? null ),
		"signed_agent '$state' passes through intact"
	);
}

// THE DISTINCTION THAT MATTERS. An older Worker, or a row written before
// v1.19.0, carries no value at all. That is NOT-MEASURED, and it must never
// collapse into 'unsigned', which is a measurement that the agent did not sign.
// Collapsing them would silently inflate the unsigned population with every
// historical row and make adoption look worse than it is.
$sa_absent = snt_mr_normalize_taxonomy_fields( array() );
$sa_empty  = snt_mr_normalize_taxonomy_fields( array( 'signed_agent' => '' ) );
ok( 'unmeasured' === ( $sa_absent['signed_agent'] ?? null ), 'an absent column is unmeasured, NOT unsigned' );
ok( 'unmeasured' === ( $sa_empty['signed_agent'] ?? null ), 'an empty value is unmeasured, NOT unsigned' );

// Forward-compat: a state this plugin has never heard of must not be dressed up
// as one it has. 'other' says "the Worker knows something I do not".
ok(
	'other' === ( snt_mr_normalize_taxonomy_fields( array( 'signed_agent' => 'sideways' ) )['signed_agent'] ?? null ),
	'an unrecognized state reads as other, never as a known state'
);

// v12.24.1: the normalizer must be IDEMPOTENT.
//
// snt_mr_fetch() already normalizes before returning, so any caller that
// normalizes a fetched row again runs the value through twice. Before this
// fix '' became 'unmeasured' on the first pass and 'other' on the second --
// a silent corruption that produced a plausible-looking answer rather than an
// error, which is the worst way for a data bug to behave.
// The property is FIXED POINT, not mere stability: every value this function
// can OUTPUT must map to itself. Asserting f(x) === f(f(x)) is weaker and
// passes on the very bug being fixed ('unmeasured' -> 'other' -> 'other' is
// stable, and wrong).
foreach ( array( 'valid', 'invalid', 'unknown-key', 'unsigned', 'unmeasured', 'other' ) as $state ) {
	ok(
		$state === snt_mr_normalize_signed_agent( $state ),
		"signed_agent '$state' is a fixed point — normalizing it again returns itself"
	);
}

// The specific regression: a row that has already been through the normalizer
// (which is every row snt_mr_fetch returns) must not drift on a second pass.
$fetched = snt_mr_normalize_taxonomy_fields( array( 'signed_agent' => '' ) );
$again   = snt_mr_normalize_taxonomy_fields( $fetched );
ok( 'unmeasured' === ( $again['signed_agent'] ?? null ), 'an already-normalized unmeasured row stays unmeasured' );

// ── v13.43.0: snt_mr_identity_breakdown() ───────────────────────────────────
//
// WHY THIS EXISTS. `signed_agent` rides the aggregate row beside `agent`,
// `surface`, `family` and `day`, so the data to answer "WHICH agents proved
// identity" has existed since Worker v1.20.0. Nothing folded it: the admin KPI
// collapses it to a scalar and the summary ability did not carry it at all, so
// concentration was unanswerable from every read surface at once (measured
// 2026-08-30, when the full-week reading was 311/13,238 and could not be split).
echo "\nGroup: identity breakdown (v13.43.0)\n";

$id_rows = array(
	array( 'agent' => 'anthropic-claudebot', 'surface' => 'html',            'hits' => 40, 'signed_agent' => 'valid' ),
	array( 'agent' => 'anthropic-claudebot', 'surface' => 'agent-discovery', 'hits' => 25, 'signed_agent' => 'valid' ),
	array( 'agent' => 'openai-gptbot',       'surface' => 'html',            'hits' => 10, 'signed_agent' => 'valid' ),
	// Non-valid states must never reach the by-agent leaderboard: an agent that
	// FAILED verification appearing beside one that passed would read as proof.
	array( 'agent' => 'spoofer',             'surface' => 'html',            'hits' => 99, 'signed_agent' => 'invalid' ),
	array( 'agent' => 'plain-crawler',       'surface' => 'html',            'hits' => 30, 'signed_agent' => 'unsigned' ),
	array( 'agent' => 'rotated-key',         'surface' => 'html',            'hits' => 5,  'signed_agent' => 'unknown-key' ),
	// Counted in nothing: silence is not a measurement.
	array( 'agent' => 'historical',          'surface' => 'html',            'hits' => 500, 'signed_agent' => 'unmeasured' ),
);
$b = snt_mr_identity_breakdown( $id_rows );

ok( is_array( $b ), 'a window with real signature states returns a breakdown' );
ok( 209 === ( $b['measured'] ?? null ), 'measured sums every row carrying a real state (75+99+30+5), excluding unmeasured' );
ok( 75 === ( $b['valid'] ?? null ), 'valid sums only the verified rows' );
ok( 99 === ( $b['invalid'] ?? null ), 'invalid is its own bucket' );
ok( 30 === ( $b['unsigned'] ?? null ), 'unsigned is its own bucket' );
ok( 5 === ( $b['unknown_key'] ?? null ), 'unknown-key is its own bucket, keyed without the hyphen' );

// THE CONCENTRATION ANSWER. One agent or many is the question that decides
// whether a licence handshake is an ecosystem surface or a bilateral deal.
ok(
	array(
		array( 'agent' => 'anthropic-claudebot', 'hits' => 65 ),
		array( 'agent' => 'openai-gptbot',       'hits' => 10 ),
	) === ( $b['by_agent'] ?? null ),
	'by_agent aggregates across surfaces, sorts desc, and lists VERIFIED hits only'
);
ok(
	! in_array( 'spoofer', array_column( (array) ( $b['by_agent'] ?? array() ), 'agent' ), true ),
	'an agent whose signature FAILED never appears in the verified leaderboard'
);

// THE BRIDGE CONFOUND. /webmcp/bridge.js began being served on every HTML page
// in Worker v1.22.0 (2026-08-28), mid-window. Verified hits landing on the
// agent-discovery surface are the same agents fetching a NEW endpoint, which is
// not the same finding as more agents adopting signatures.
ok(
	array(
		array( 'surface' => 'html',            'hits' => 50 ),
		array( 'surface' => 'agent-discovery', 'hits' => 25 ),
	) === ( $b['by_surface'] ?? null ),
	'by_surface splits verified hits so a new endpoint cannot masquerade as adoption'
);

// THE LOAD-BEARING CASE, same rule the renderer already enforces: an
// all-unmeasured window must not return a block of zeros. Zeros assert a
// measurement that was never taken, and a consumer cannot tell them from
// measured-zero. null is the only honest answer.
$b_un = snt_mr_identity_breakdown( array(
	array( 'agent' => 'openai-gptbot', 'surface' => 'html', 'hits' => 900, 'signed_agent' => 'unmeasured' ),
) );
ok( null === $b_un, 'an all-unmeasured window returns null, never a zeroed block' );
ok( null === snt_mr_identity_breakdown( array() ), 'and so does an empty window' );

// Measured-zero is a DIFFERENT answer and must survive as one: states were
// observed, none verified. The block exists; the leaderboards are empty arrays.
$b_zero = snt_mr_identity_breakdown( array(
	array( 'agent' => 'plain-crawler', 'surface' => 'html', 'hits' => 30, 'signed_agent' => 'unsigned' ),
) );
ok( is_array( $b_zero ) && 0 === $b_zero['valid'], 'measured-with-none-verified reports valid 0, not null' );
ok( array() === ( $b_zero['by_agent'] ?? null ), 'and an empty leaderboard, which is a measured zero' );

// 19.4.0: verified_bot, Worker v1.27.0's blob12. Passes the label through,
// absent reads as '', and markup cannot ride it onto the page.
ok( 'Search Engine Crawler' === ( snt_mr_normalize_taxonomy_fields( array( 'verified_bot' => ' Search Engine Crawler ' ) )['verified_bot'] ?? null ), 'verified_bot label passes through trimmed' );
ok( '' === ( snt_mr_normalize_taxonomy_fields( array() )['verified_bot'] ?? null ), 'an absent verified_bot column reads as empty' );
ok( false === strpos( snt_mr_normalize_taxonomy_fields( array( 'verified_bot' => '<script>x</script>' ) )['verified_bot'] ?? '<', '<' ), 'verified_bot cannot carry markup' );
// Worker v1.28.0's blob13, the network owner. Untrusted (whoever owns the IP block names it).
$nf = static fn( $v ) => snt_mr_normalize_taxonomy_fields( array( 'network' => $v ) )['network'] ?? null;
ok( 'Amazon.com, Inc.' === $nf( '  Amazon.com, Inc. ' ), 'network passes through trimmed with plain punctuation' );
ok( '' === ( snt_mr_normalize_taxonomy_fields( array() )['network'] ?? null ), 'an absent network column reads as empty (not measured)' );
foreach ( array( '<script>alert(1)</script>', '"><img src=x onerror=y>', "a'b`c", "x\x00\ny" ) as $evil ) {
	ok( 0 === preg_match( '/[<>"\'`\x00-\x1F]/', (string) $nf( $evil ) ), 'network strips markup, quotes and control characters: ' . addslashes( $evil ) );
}
ok( 128 === mb_strlen( $nf( str_repeat( 'é', 300 ) ) ), 'network caps at 128 characters without splitting a multibyte one' );
ok( '' === $nf( "\xC3\x28" ), 'invalid UTF-8 reads as empty, not as an error' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
