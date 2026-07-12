<?php
/**
 * Contract test: the settings-hub developer filter reference
 * (snt_analytics_render_filter_reference, v9.36.0) stays in lockstep with the
 * real filter seams in inc/. Scans every inc/ file (multi-line aware) for
 * apply_filters() tags in the reference's namespaces and asserts both ways:
 *   1. every discovered seam is documented in the reference — or explicitly
 *      allowlisted below as intentionally undocumented, with a reason; and
 *   2. every <code> tag the reference renders exists in the codebase
 *      (no phantom docs).
 * A future filter #13 fails this suite until it is documented or allowlisted —
 * the same staleness-armor pattern as tests/admin-registry.php.
 *
 * Run: php tests/analytics-filter-reference-parity.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// Minimal WP stubs for the render fn (static i18n-wrapped <details> content).
function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }

require __DIR__ . '/../inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── 1. Discover the real seams: apply_filters() tags in the reference's
// namespaces. sn_beacon_ joins the two analytics/AI prefixes because the
// reference documents sn_beacon_token (inc/rss-feed-tracker.php) — without it
// the no-phantom-docs assertion below could never hold. \s* + /s keep the
// match multi-line safe (snt_ai_economy_features is applied across lines).
$prefixes = 'sn_analytics_|snt_ai_|sn_beacon_';
$found    = array();
$files    = array_merge( glob( __DIR__ . '/../inc/*.php' ), glob( __DIR__ . '/../inc/*/*.php' ) );
foreach ( $files as $f ) {
	if ( preg_match_all( "~apply_filters\\(\\s*['\"]((?:{$prefixes})[a-z0-9_]+)['\"]~s", (string) file_get_contents( $f ), $m ) ) {
		foreach ( $m[1] as $tag ) { $found[ $tag ] = true; }
	}
}
$found = array_keys( $found );
sort( $found );
ok( count( $found ) >= 12, 'scan discovers the seam inventory (>= the 12 documented tags; got ' . count( $found ) . ')' );

// ── 2. Intentionally-undocumented seams. The reference is the settings hub's
// knob-exposure surface (design spec §7): the analytics + AI-routing seams an
// owner might pull. These are internal/non-analytics seams it deliberately
// omits — REMOVE an entry here if you promote its tag into the reference.
$allowlist = array(
	'snt_ai_alt_image_max_bytes', // alt-text internal memory guard (byte cap on the attached image) — media/AI plumbing, not an analytics-hub knob.
	'snt_ai_alt_text_model',      // per-feature (alt-text) model re-pin; the hub documents the general snt_ai_model_preference router instead.
	'snt_ai_model_pricing',       // spend-estimate rate-table calibration — estimates only; the provider console is authoritative (see snt_ai_model_pricing()).
);

// ── 3. Render the reference and pull out its <code>-wrapped tags.
ob_start();
snt_analytics_render_filter_reference();
$html = (string) ob_get_clean();
preg_match_all( '~<code>([a-z][a-z0-9_]+)</code>~', $html, $m );
$documented = array_values( array_unique( $m[1] ) );
sort( $documented );
ok( count( $documented ) > 0, 'reference renders a non-empty documented set (got ' . count( $documented ) . ')' );

// ── 4. Forward: every discovered seam is documented or allowlisted.
foreach ( $found as $tag ) {
	ok(
		in_array( $tag, $documented, true ) || in_array( $tag, $allowlist, true ),
		"discovered seam '$tag' is documented in the reference (or explicitly allowlisted above)"
	);
}

// ── 5. Reverse: no phantom docs — every rendered tag is a real seam in inc/.
foreach ( $documented as $tag ) {
	ok( in_array( $tag, $found, true ), "documented tag '$tag' exists as a real apply_filters() seam in inc/" );
}

// ── 6. Allowlist hygiene: an allowlisted tag must still exist (else the entry
// is stale) and must not ALSO be documented (else the allowlist lies).
foreach ( $allowlist as $tag ) {
	ok( in_array( $tag, $found, true ), "allowlisted tag '$tag' still exists in inc/ (drop stale allowlist entries)" );
	ok( ! in_array( $tag, $documented, true ), "allowlisted tag '$tag' is not simultaneously documented" );
}

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
