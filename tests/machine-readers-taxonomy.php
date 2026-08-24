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
require __DIR__ . '/../inc/machine-readers-render-taxonomy.php';

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
$html = snt_mr_render_unknown_agents( array( array( 'user_agent' => 'Mozilla/5.0 <script>alert(1)</script>', 'hits' => 7 ) ) );
ok( false === strpos( $html, '<script>' ), 'the renderer emits no script tag' );
ok( false !== strpos( $html, 'Mozilla/5.0' ), 'while still showing the reviewable part of the string' );
ok( false !== strpos( $html, 'at most' ), 'and the cap is reported so truncation is never silent' );

echo "\nGroup: RULE 2 , the empty case is honest\n";
$empty = snt_mr_render_unknown_agents( array() );
ok( false !== stripos( $empty, 'matched the taxonomy' ), 'nothing to review says so, rather than rendering an empty table' );

echo "\nGroup: first-party monitoring is excluded from readership, and declared\n";
$rows = snt_mr_normalize_rows( array(
	array( 'family' => 'uptime', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 6403, 'vendor' => 'betterstack', 'purpose' => 'ops', 'taxonomy_version' => '1.0.0', 'first_party' => '1' ),
	array( 'family' => 'openai', 'surface' => 'llms', 'day' => '2026-08-01', 'hits' => 40, 'vendor' => 'openai', 'purpose' => 'train', 'taxonomy_version' => '1.0.0' ),
) );
$totals = snt_mr_purpose_totals( $rows );
ok( 6403 === $totals['first_party'], 'the first-party total is counted separately' );
ok( ! isset( $totals['purposes']['ops'] ) && 40 === $totals['purposes']['train'], 'and excluded from the purpose totals' );
$ptable = snt_mr_render_purpose_table( $rows, 30 );
ok( false !== strpos( $ptable, '6,403' ) && false !== stripos( $ptable, 'own uptime monitoring' ), 'the exclusion is disclosed on the page, not hidden' );

echo "\nGroup: never-measured is not measured-zero\n";
$note = snt_mr_render_purpose_table( $legacy, 30 );
ok( false !== stripos( $note, 'predates' ) && false !== stripos( $note, 'not a measured zero' ), 'an older sensor renders a stated absence, not a table of zeroes' );

echo "\nGroup: vendor x purpose keeps one vendor on several rows\n";
$multi = snt_mr_normalize_rows( array(
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 30, 'vendor' => 'openai', 'purpose' => 'train', 'taxonomy_version' => '1.0.0' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 20, 'vendor' => 'openai', 'purpose' => 'search', 'taxonomy_version' => '1.0.0' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 10, 'vendor' => 'openai', 'purpose' => 'user', 'taxonomy_version' => '1.0.0' ),
) );
$vp = snt_mr_render_vendor_purpose_table( $multi );
ok( 3 === substr_count( $vp, '<tr><td class="column-primary"' ), 'one vendor across three purposes is three rows, not one' );
ok( false !== strpos( $vp, 'train' ) && false !== strpos( $vp, 'search' ) && false !== strpos( $vp, 'user' ), 'and each purpose is named' );

echo "\nGroup: MR3 , the agent/purpose breakdown folds and its remainder learns the house wording\n";
// Unlike the two logs, this table ALREADY sliced at 20 — the cap was real.
// What was wrong was the summary's absence and a remainder line that used
// softer wording than the rest of the surface, and that still said
// "vendor/purpose" though v10.80.0 gave the table an Agent column.
$mr3_rows = array();
for ( $i = 1; $i <= 21; $i++ ) {
	$mr3_rows[] = array(
		'family'           => 'openai',
		'surface'          => 'html',
		'day'              => '2026-08-01',
		'hits'             => 100 - $i, // Descending, so worst-first ordering is observable.
		'vendor'           => 'vendor' . $i,
		'agent'            => 'Agent' . $i,
		'purpose'          => 'train',
		'taxonomy_version' => '1.1.0',
	);
}
$mr3_html = snt_mr_render_vendor_purpose_table( snt_mr_normalize_rows( $mr3_rows ) );
ok( false !== strpos( $mr3_html, '<details class="sn-mr-vendor-purpose sn-disclosure">' ), 'the breakdown sits inside its own disclosure' );
ok( false === strpos( $mr3_html, '<details class="sn-mr-vendor-purpose sn-disclosure" open' ), 'and it is CLOSED by default' );
$mr3_det = strpos( $mr3_html, '<details class="sn-mr-vendor-purpose' );
$mr3_sum = substr( $mr3_html, (int) $mr3_det, 200 );
ok( false !== strpos( $mr3_sum, '21' ), 'the summary names the TRUE pair count (21), not the sliced 20' );
ok( 20 === substr_count( $mr3_html, '<tr><td class="column-primary"' ), 'the existing worst-first cap of 20 still applies' );
ok( false !== stripos( $mr3_html, 'capped, not complete' ), 'the remainder line now uses the house wording' );
ok( false === stripos( $mr3_html, 'are not shown' ), 'and the softer old phrasing is gone' );
ok( false !== stripos( $mr3_html, 'agent/purpose' ), 'the remainder names AGENT/purpose — the table grew an Agent column in v10.80.0 and the vocabulary follows it' );
// Worst-first survives the fold: vendor1 (99 hits) leads, vendor21 (79) is cut.
$mr3_first = strpos( $mr3_html, '<tr><td class="column-primary"' );
ok( false !== strpos( substr( $mr3_html, (int) $mr3_first, 300 ), 'vendor1<' ), 'the busiest pair still renders first' );
ok( false === strpos( $mr3_html, 'vendor21<' ), 'and the quietest is the one the cap drops' );
// Empty and pre-taxonomy both stay '' — never an empty disclosure.
ok( '' === snt_mr_render_vendor_purpose_table( $legacy ), 'a pre-taxonomy sensor still renders NOTHING at all, not an empty fold' );
$mr3_first_party_only = snt_mr_normalize_rows( array(
	array( 'family' => 'uptime', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 500, 'vendor' => 'betterstack', 'purpose' => 'ops', 'taxonomy_version' => '1.1.0', 'first_party' => '1' ),
) );
ok( '' === snt_mr_render_vendor_purpose_table( $mr3_first_party_only ), 'a window with only first-party rows renders nothing, not a fold promising 0 pairs' );

echo "\nGroup: the purpose vocabulary is closed\n";
$purposes = snt_mr_valid_purposes();
ok( 13 === count( $purposes ), 'exactly thirteen purposes' );
ok( in_array( 'ads', $purposes, true ), "the 'ads' purpose exists so ad validators are not stretched into security" );
ok( in_array( 'train', $purposes, true ) && ! in_array( 'training', $purposes, true ), 'the value is train, not a near-miss synonym' );
ok( array( 'train', 'retrieval' ) === snt_mr_ai_purposes(), 'the AI-consumption set is train + retrieval, and does not silently include user or search' );

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
$rhtml = snt_mr_render_rights_detail( $rd_hostile );
ok( false === strpos( $rhtml, '<script>' ), 'the renderer escapes the un-allowlisted UA (its only defence)' );
ok( false !== stripos( snt_mr_render_rights_detail( array() ), 'No reads of the rights surfaces' ), 'an empty window says so rather than rendering an empty table' );

echo "\nGroup: MR1 , the rights log FOLDS (its cap was a caption, not a cap)\n";
// Before MR1 the $limit argument was PRINTED in the footer and never applied:
// every row the sensor handed over rendered, fully open, in arrival order. The
// fold is presentation-only — the sensor envelope, the view, and the row
// normalizer are untouched.
$mr1_rows = array();
for ( $i = 1; $i <= 51; $i++ ) {
	// Ascending timestamps so the NEWEST row is the last one in input order —
	// a renderer that merely slices arrival order would drop it, which is the
	// thing worth catching.
	$mr1_rows[] = array(
		'observed_at' => sprintf( '2026-08-%02dT09:00:00Z', ( $i % 28 ) + 1 ),
		'path'        => '/license.xml',
		'user_agent'  => 'Mozilla/5.0 (compatible; TestBot/' . $i . ')',
		'vendor'      => 'anthropic',
		'purpose'     => 'train',
		'family'      => 'anthropic',
		'hits'        => 1,
	);
}
$mr1_html = snt_mr_render_rights_detail( snt_mr_normalize_rights_rows( $mr1_rows ) );
ok( false !== strpos( $mr1_html, '<details class="sn-mr-rights-log sn-disclosure">' ), 'the rights table sits inside its own disclosure' );
ok( false === strpos( $mr1_html, '<details class="sn-mr-rights-log sn-disclosure" open' ), 'and it is CLOSED by default' );
$mr1_det = strpos( $mr1_html, '<details class="sn-mr-rights-log' );
$mr1_sum = substr( $mr1_html, (int) $mr1_det, 220 );
ok( false !== strpos( $mr1_sum, '51' ), 'the summary names the TRUE event count (51), not the sliced 50 — a fold may hide the evidence, never THAT there is something inside' );
ok( 50 === substr_count( $mr1_html, '<tr><td class="column-primary"' ), 'the display cap renders exactly 50 rows' );
ok( false !== stripos( $mr1_html, 'capped, not complete' ), 'the remainder line uses the house wording' );
ok( false !== strpos( $mr1_html, '+1 more' ), 'and names how many were cut' );
ok( false !== stripos( $mr1_html, 'at most 500' ), 'the SENSOR envelope sentence survives — the display cap must not claim the sensor stores less than it does' );
// Newest-first: the highest timestamp in the fixture is day 28 (i=27), which
// arrives mid-list. Slicing arrival order would lose it entirely.
ok( false !== strpos( $mr1_html, 'TestBot/27' ), 'the newest event is shown even though it arrived mid-list (the sort is real, not arrival order)' );
$mr1_first_row = strpos( $mr1_html, '<tr><td class="column-primary"' );
ok( false !== strpos( substr( $mr1_html, (int) $mr1_first_row, 400 ), '2026-08-28' ), 'the newest event sorts FIRST' );
// A row whose timestamp is missing must not be invented a date, and must not
// float to the top of a newest-first sort by accident.
$mr1_undated = snt_mr_normalize_rights_rows( array(
	array( 'observed_at' => '', 'path' => '/license.xml', 'user_agent' => 'UndatedBot', 'vendor' => 'anthropic', 'purpose' => 'train', 'family' => 'anthropic' ),
	array( 'observed_at' => '2026-08-02T09:00:00Z', 'path' => '/license.xml', 'user_agent' => 'DatedBot', 'vendor' => 'anthropic', 'purpose' => 'train', 'family' => 'anthropic' ),
) );
$mr1_u_html = snt_mr_render_rights_detail( $mr1_undated );
ok( strpos( $mr1_u_html, 'DatedBot' ) < strpos( $mr1_u_html, 'UndatedBot' ), 'a row with no timestamp sorts LAST, never invented a date to lead' );
ok( false === strpos( $mr1_u_html, '1970' ), 'and no epoch fallback leaks into the page' );
// The empty window keeps its sentence with NO fold: a closed disclosure
// reading "0 events" would rhyme with a measured zero.
$mr1_empty = snt_mr_render_rights_detail( array() );
ok( false === strpos( $mr1_empty, '<details' ), 'an empty window renders NO disclosure at all' );
// Report, not findings.
ok( false === strpos( $mr1_html, 'sn-pill--warn' ), 'rights rows carry no warn pill — they are the evidence a published claim rests on, not defects' );
// Under the cap: no remainder line, still folded.
$mr1_small = snt_mr_render_rights_detail( snt_mr_normalize_rights_rows( array_slice( $mr1_rows, 0, 3 ) ) );
ok( false !== strpos( $mr1_small, '<details class="sn-mr-rights-log' ), 'a short log still folds (consistency beats a size heuristic)' );
ok( false === stripos( $mr1_small, 'capped, not complete' ), 'but prints no remainder line when nothing was cut' );

echo "\nGroup: MR2 , the unclassified-UA review list folds (source-capped, so no second cap)\n";
// RULE 2 says the bucket must stay inspectable. Folding the ROWS is fine;
// folding away THAT the bucket exists is not — so the summary carries the
// count and an empty window keeps its sentence with no disclosure at all.
$mr2_rows = array(
	array( 'user_agent' => 'SomeBot/1.0 (+http://example.test)', 'hits' => 40 ),
	array( 'user_agent' => 'OtherBot/2.0', 'hits' => 12 ),
	// A UA that NORMALIZES TO EMPTY: the renderer skips it, so a summary
	// counting raw rows would promise three and deliver two.
	array( 'user_agent' => '<<<>>>', 'hits' => 7 ),
);
$mr2_html = snt_mr_render_unknown_agents( $mr2_rows );
ok( false !== strpos( $mr2_html, '<details class="sn-mr-unknown-log sn-disclosure">' ), 'the review list sits inside its own disclosure' );
ok( false === strpos( $mr2_html, '<details class="sn-mr-unknown-log sn-disclosure" open' ), 'and it is CLOSED by default' );
$mr2_det = strpos( $mr2_html, '<details class="sn-mr-unknown-log' );
$mr2_sum = substr( $mr2_html, (int) $mr2_det, 200 );
ok( false !== strpos( $mr2_sum, '2' ) && false === strpos( $mr2_sum, '3' ), 'the summary counts the rows that SURVIVE normalization (2), not the raw rows (3) — a summary may not promise more than the fold contains' );
ok( 2 === substr_count( $mr2_html, '<tr><td class="column-primary"' ), 'and exactly those two rows render' );
ok( false !== stripos( $mr2_html, 'at most 50' ), 'the sensor envelope sentence survives — this tier adds NO second display cap' );
ok( false !== stripos( $mr2_html, 'not a verbatim log' ), 'and the sampling caveat stays with it' );
ok( false === strpos( $mr2_html, 'sn-pill--warn' ), 'an unmatched agent is a review item, not a Health finding' );
// Empty: the measured-clean bucket keeps its sentence, and no empty fold.
$mr2_empty = snt_mr_render_unknown_agents( array() );
ok( false !== stripos( $mr2_empty, 'matched the taxonomy' ), 'an empty window keeps its measured-clean sentence' );
ok( false === strpos( $mr2_empty, '<details' ), 'and renders NO disclosure' );
// All rows normalizing away is NOT the same as an empty window: the bucket
// held something the sanitizer could not render, and saying "nothing to
// review" there would be a measured-clean claim the data does not support.
$mr2_allblank = snt_mr_render_unknown_agents( array( array( 'user_agent' => '<<<>>>', 'hits' => 3 ) ) );
ok( false === strpos( $mr2_allblank, '<tr><td class="column-primary"' ), 'a window whose agents all normalize away renders no rows' );
ok( false === stripos( $mr2_allblank, 'matched the taxonomy' ), 'and does NOT claim the taxonomy matched everything — unrenderable is not clean' );
ok( false === strpos( $mr2_allblank, '<details' ), 'and does NOT fold: a summary reading "0 unclassified user agents" would rhyme with a measured zero, which is the whole reason the fold is gated on survivors' );

echo "\nGroup: the over-count is SHOWN, not reconciled away\n";
$recon_rows = snt_mr_normalize_rows( array(
	// google-ai is inside the frozen AI-training family set, but GoogleOther's
	// declared purpose is generic. Exactly the live over-count.
	array( 'family' => 'google-ai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 100, 'vendor' => 'google', 'purpose' => 'unknown', 'taxonomy_version' => '1.1.0' ),
	array( 'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-01', 'hits' => 40, 'vendor' => 'openai', 'purpose' => 'train', 'taxonomy_version' => '1.1.0' ),
) );
$recon = snt_mr_render_ai_reconciliation( $recon_rows );
ok( false !== strpos( $recon, '140' ), 'the frozen family count (140) is reported' );
ok( false !== strpos( $recon, '>40<' ), 'the purpose count (40) is reported beside it' );
ok( false !== strpos( $recon, '100' ) && false !== stripos( $recon, 'GoogleOther' ), 'the gap is named and attributed, not silently dropped' );
ok( false !== stripos( $recon, 'Cite the purpose count' ), 'and the reader is told which number to use' );
ok( '' === snt_mr_render_ai_reconciliation( $legacy ), 'a pre-taxonomy sensor renders no comparison at all (never a false 0 vs 0)' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
