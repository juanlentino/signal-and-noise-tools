<?php
/**
 * Orphan-class ratchet: a class name is a CLAIM that a component exists.
 *
 * TWICE IN ONE EVENING (2026-09-02) a panel shipped with class names no
 * stylesheet declares. The post-purge probe table used .sn-table / .sn-rail-h /
 * .sn-rail-note and rendered as a browser-default table; the Vocabulary leaf's
 * .sn-drift-grid wrapped four .sn-drift-col divs whose own docblock calls them
 * "a compact table column", and they stacked. Both were invisible to every
 * other guard here: the markup is valid, PHP is clean, the page renders, and
 * only a human looking at it can tell. The sweep that found the second one is
 * this file.
 *
 * WHAT COUNTS AS USED. A class is used if it appears in SHIPPED code: the
 * plugin's stylesheets, its JavaScript (a hook is a use), its JSON, or an
 * inline <style>. Only LITERAL class tokens are scored: an attribute built by
 * concatenation leaves fragments like "sn-pill--'" in the source, and scoring
 * those manufactures failures for classes that are fine.
 *
 * TESTS ARE NOT A REFERENCE SURFACE, and the first draft of this file learned
 * why the hard way. It scanned tests/ too — including itself, which names the
 * eight .sn-drift-* classes in its own regression block. Deleting the drift CSS
 * therefore changed nothing: the guard read its own assertions as evidence that
 * the classes were used, and reported green over the exact defect it was
 * written to catch. A test mentioning a class proves nothing about whether
 * anything renders it.
 *
 * THE THEME IS NOT CONSULTED, DELIBERATELY. Some front-end classes this plugin
 * prints are styled in signal-and-noise, a separate repo that CI does not check
 * out. A guard that skipped when a sibling directory was missing would pass
 * vacuously on every CI run — the exact failure mode this repo keeps finding.
 * So those classes are counted here too, and the number is a CEILING rather
 * than a bug count: it may only ever go down.
 *
 * @since 13.72.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * THE BASELINE IS A LIST, NOT A COUNT.
 *
 * A number tells you something grew and leaves you to find what. This names
 * every class the plugin prints that no shipped stylesheet, script, JSON or
 * inline style references, so a new one is reported BY NAME and a fixed one
 * shows up as "no longer an orphan — take it off the list". Same reasoning as
 * the ledger's retired-subjects.json: an exemption is a declaration, and a
 * declaration you can read is worth more than a threshold you cannot.
 *
 * 34 of these ARE styled — in signal-and-noise, the theme repo, which CI does
 * not check out (see the header). They stay on the list because the alternative
 * is a guard that skips when a sibling directory is missing, and that passes
 * vacuously on every CI run. The list is a ceiling, not a bug count.
 *
 * ONLY EVER SHRINKS. Removing a name is the fix; adding one needs a reason in
 * the commit that adds it.
 */
const SN_ORPHAN_CLASS_BASELINE = array(
	'sn-ai-usage--empty', 'sn-an-botbreak', 'sn-an-breakdown', 'sn-an-collector',
	'sn-an-exclude', 'sn-an-funnels', 'sn-an-gate', 'sn-an-heatmap-panel',
	'sn-an-mirrors', 'sn-an-prior-note', 'sn-an-refcats', 'sn-an-status',
	'sn-an-tuning-radios', 'sn-audit-logins-log', 'sn-availability', 'sn-aw-spend',
	'sn-catalog-number', 'sn-cit-legend', 'sn-colophon', 'sn-colophon-items',
	'sn-colophon-versions', 'sn-cron-dashboard', 'sn-dash-briefing', 'sn-dash-zone-label',
	'sn-geo', 'sn-health-advisory', 'sn-health-contrast-arithmetic', 'sn-health-contrast-conditional',
	'sn-health-contrast-usage', 'sn-health-elsewhere', 'sn-health-motion-uncovered', 'sn-health-skipped',
	'sn-kpi-note', 'sn-machine-maturity-giveback', 'sn-machine-maturity-reads', 'sn-mcp-tools',
	'sn-mcp-usage', 'sn-mcp-usage-zero', 'sn-mr-delta', 'sn-mr-deltas',
	'sn-mr-empty', 'sn-mr-leaf', 'sn-mr-rights-log', 'sn-mr-sensor',
	'sn-mr-truncated', 'sn-mr-unknown-log', 'sn-mr-vendor-purpose', 'sn-now-dek',
	'sn-now-eyebrow', 'sn-now-headline', 'sn-now-hero', 'sn-now-item',
	'sn-now-item-text', 'sn-now-meta', 'sn-posts-hero-h', 'sn-prov-paper-blurb',
	'sn-prov-paper-card', 'sn-prov-paper-longform', 'sn-prov-paper-meta', 'sn-prov-paper-subtitle',
	'sn-prov-paper-title', 'sn-prov-papers', 'sn-prov-series', 'sn-prov-series-footer',
	'sn-prov-series-heading', 'sn-prov-series-intro', 'sn-prov-v', 'sn-prov-verify',
	'sn-prov-worker-ver', 'sn-provenance-byline-divider', 'sn-provenance-byline-reading-time', 'sn-provenance-toc',
	'sn-resume-download', 'sn-resume-fold', 'sn-resume-hero-split', 'sn-resume-pub',
	'sn-resume-pub-title', 'sn-resume-role', 'sn-resume-skills', 'sn-resume-stats',
	'sn-resume-title', 'sn-rsm-list', 'sn-schedule-log', 'sn-schedule-op',
	'sn-schedule-remainder', 'sn-uses-dek', 'sn-uses-eyebrow', 'sn-uses-headline',
	'sn-uses-hero', 'sn-uses-item', 'sn-uses-item-name', 'sn-uses-item-note',
	'sn-uses-meta',
);

$root = dirname( __DIR__ );
$read = static function ( $p ) { $s = @file_get_contents( $p ); return is_string( $s ) ? $s : ''; };
$glob = static function ( $pat ) use ( $root ) {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = $f->getPathname();
		if ( false !== strpos( $p, '/node_modules/' ) || false !== strpos( $p, '/vendor/' ) || false !== strpos( $p, '/.git/' ) ) {
			continue;
		}
		if ( 1 === preg_match( $pat, $p ) ) { $out[] = $p; }
	}
	return $out;
};

// ── what the plugin's PHP CLAIMS ───────────────────────────────────────────
$php     = $glob( '#/(inc|admin-forms)/.*\.php$|/signal-and-noise-tools\.php$#' );
$claimed = array();
foreach ( $php as $f ) {
	if ( preg_match_all( '/class="([^"]*)"/', $read( $f ), $m ) ) {
		foreach ( $m[1] as $attr ) {
			foreach ( preg_split( '/\s+/', $attr ) as $tok ) {
				if ( 1 === preg_match( '/^sn-[a-z0-9-]+$/', $tok ) ) { $claimed[ $tok ] = true; }
			}
		}
	}
}
$claimed = array_keys( $claimed );
sort( $claimed );
ok( count( $claimed ) > 400, 'VACUITY: the claim scan found the corpus (' . count( $claimed ) . ' literal classes) — a rotted regex must fail here, never report a clean sweep over nothing' );

// ── every plugin-side surface that could REFERENCE one ─────────────────────
$surfaces = array(
	'stylesheets' => $glob( '#/assets/.*\.css$#' ),
	'javascript'  => $glob( '#/assets/.*\.js$#' ),
	'json'        => $glob( '#\.json$#' ),
	'inline-style'=> array_values( array_filter( $php, static function ( $f ) use ( $read ) { return false !== strpos( $read( $f ), '<style' ); } ) ),
);
// COMMENTS ARE NOT DECLARATIONS. Stripping them is not tidiness: the drift
// block's own comment names .sn-drift-grid to explain what it fixes, so a
// scanner reading raw text counts the explanation as the styling. Deleting the
// rule then changed nothing — the guard read a comment as evidence and stayed
// green over the exact defect it exists to catch. (Third time this repo has
// been bitten by the same shape: the retired --signal token, the "still stale"
// wording scan, this.)
$decomment = static function ( $src ) {
	return (string) preg_replace( array( '#/\*.*?\*/#s', '#(^|\s)//[^\n]*#' ), ' ', $src );
};
$referenced = array();
foreach ( $surfaces as $label => $paths ) {
	$found = array();
	foreach ( $paths as $p ) {
		if ( preg_match_all( '/sn-[a-z0-9-]+/', $decomment( $read( $p ) ), $m ) ) {
			foreach ( $m[0] as $t ) { $found[ $t ] = true; $referenced[ $t ] = true; }
		}
	}
	ok( array() !== $found, "VACUITY: the $label surface yielded class tokens — an empty surface would silently turn every class it styles into an orphan" );
}

// ── the ratchet ────────────────────────────────────────────────────────────
$orphans  = array_values( array_filter( $claimed, static function ( $c ) use ( $referenced ) { return ! isset( $referenced[ $c ] ); } ) );
$baseline = SN_ORPHAN_CLASS_BASELINE;
sort( $baseline );
$appeared = array_values( array_diff( $orphans, $baseline ) );
$fixed    = array_values( array_diff( $baseline, $orphans ) );

ok( count( $baseline ) > 40, 'VACUITY: the baseline itself is populated (' . count( $baseline ) . ') — an empty declaration would accept anything' );
ok( array() === $appeared, 'no NEW orphan class' . ( $appeared ? ' — FOUND: ' . implode( ', ', $appeared ) . '. Either style it, or add it to SN_ORPHAN_CLASS_BASELINE with a reason.' : '' ) );
ok( array() === $fixed, 'the baseline names nothing that is now styled' . ( $fixed ? ' — REMOVE from SN_ORPHAN_CLASS_BASELINE: ' . implode( ', ', $fixed ) : '' ) );

// ── the two panels that taught this lesson stay fixed ──────────────────────
foreach ( array( 'sn-drift-grid', 'sn-drift-col', 'sn-drift-share', 'sn-drift-none', 'sn-drift-thin', 'sn-drift-years', 'sn-drift-pair', 'sn-drift-table' ) as $c ) {
	ok( isset( $referenced[ $c ] ), "REGRESSION: .$c is declared — the Vocabulary leaf renders four columns, not four stacked tables" );
}
foreach ( array( 'sn-table', 'sn-rail-h', 'sn-rail-note' ) as $c ) {
	ok( ! in_array( $c, $claimed, true ), "REGRESSION: .$c is not claimed anywhere — it was invented for a surface that already had a component" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
