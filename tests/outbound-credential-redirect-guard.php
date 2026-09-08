<?php
/**
 * Census guard: EVERY credentialed outbound call in inc/ forbids redirects.
 *
 * The convention is old (v8.7.1, "CMA audit INFO-1") and well commented at the
 * call sites that have it: a credential attached to a fixed API host must never
 * be re-sent to a 3xx target, so `'redirection' => 0`. WordPress defaults to
 * `redirection => 5`, so OMITTING the key is not neutral — it opts in.
 *
 * WHY A CENSUS AND NOT MORE PER-FEATURE TESTS. On 2026-09-08 a sweep found the
 * convention asserted in eleven separate suites (rss, muso, edge-analytics,
 * wp-update, webhook, genesis, ...) — each pinning its OWN call site, none able
 * to see a new one. Four sites had drifted uncovered: inc/ml-embeddings.php
 * (a Workers AI Bearer to the same api.cloudflare.com host that
 * inc/cloudflare-purge.php explicitly guards) and all three in
 * inc/search-console-client.php — including the two SHARED helpers
 * snt_gsc_api_get()/snt_gsc_api_post(), so every Search Console call in the
 * plugin inherited the gap. A per-feature test can only pin what someone
 * remembered to write; this one is derived from the source, so a NEW
 * credentialed call site fails until it carries the guard.
 *
 * SCOPE: every production wp_remote_* call in this plugin lives under inc/ —
 * verified 2026-09-08, 33 files, 50 call sites, nothing in apps/ or blocks/.
 * If that ever stops being true the floor assertion below drops, not the guard.
 *
 * Balanced-region parse, not a line window: the args array spans many lines and
 * a fixed lookahead both truncates long calls and bleeds into the next one.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}

$root = dirname( __DIR__ );

/** Anything that carries a secret to the other end. */
$GLOBALS['CRED'] = $CRED = '/Authorization|Bearer|X-API-Key|X-Auth|api[_-]?token|X-Signature|X-SNT|X-Goog|access_token|assertion/i';

/** Extract the balanced ( ... ) region of each wp_remote_* call. */
function snt_calls( $src ) {
	$out = array();
	if ( ! preg_match_all( '/wp_remote_(?:get|post|request|head)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return $out;
	}
	foreach ( $m[0] as $hit ) {
		$start = $hit[1];
		$i     = $start + strlen( $hit[0] ) - 1;
		$depth = 0;
		$end   = null;
		for ( $j = $i, $n = strlen( $src ); $j < $n; $j++ ) {
			if ( '(' === $src[ $j ] )      { $depth++; }
			elseif ( ')' === $src[ $j ] )  { $depth--; if ( 0 === $depth ) { $end = $j; break; } }
		}
		if ( null === $end ) { continue; } // unbalanced: reported below, never skipped silently
		$out[] = array(
			'line'   => substr_count( substr( $src, 0, $start ), "\n" ) + 1,
			'region' => substr( $src, $start, $end - $start + 1 ),
		);
	}
	return $out;
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc' ) );
$total = 0; $cred_total = 0; $unguarded = array(); $unbalanced = array();

foreach ( $files as $f ) {
	if ( ! $f->isFile() || 'php' !== strtolower( $f->getExtension() ) ) { continue; }
	$path = $f->getPathname();
	$rel  = ltrim( str_replace( $root, '', $path ), '/' );
	$src  = (string) file_get_contents( $path );

	$raw = preg_match_all( '/wp_remote_(?:get|post|request|head)\s*\(/', $src );
	$got = snt_calls( $src );
	if ( $raw !== count( $got ) ) { $unbalanced[] = "$rel ($raw found, " . count( $got ) . ' parsed)'; }

	foreach ( $got as $c ) {
		$total++;
		if ( ! preg_match( $GLOBALS['CRED'] ?? '/(?!)/', $c['region'] ) ) { continue; }
		$cred_total++;
		if ( ! preg_match( "/'redirection'\s*=>\s*0/", $c['region'] ) ) {
			$unguarded[] = "$rel:{$c['line']}";
		}
	}
}

// The scan must prove it ran. A derivation that matches nothing reports a clean
// sweep and a broken scanner identically; the floor separates them.
ok( $total >= 40, "scan reached the outbound call sites (found $total, floor 40)" );
ok( $cred_total >= 10, "scan recognised credentialed call sites (found $cred_total, floor 10)" );
ok( empty( $unbalanced ), 'every wp_remote_* call parsed to a balanced region' . ( $unbalanced ? ': ' . implode( ', ', $unbalanced ) : '' ) );

ok(
	empty( $unguarded ),
	empty( $unguarded )
		? "all $cred_total credentialed outbound calls set redirection => 0"
		: 'credentialed outbound calls MISSING redirection => 0: ' . implode( ', ', $unguarded )
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
