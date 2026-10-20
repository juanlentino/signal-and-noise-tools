<?php
/**
 * Standalone test: the daily CSP inline-hash check (2026-10-18).
 *
 * The theme's planned strict script-src (theme docs/CSP.md) drops
 * 'unsafe-inline' and pins sha256 hashes of three inline scripts: theme-init,
 * core's import map and core's emoji module. The import map changes with every
 * core update, and a stale hash silently stops every script module. This check
 * reads the public pages as a stranger once a day, hashes each executable
 * inline script exactly as the doc does, and raises the csp_hashes watch with
 * the replacement script-src line when the strict policy lacks one.
 *
 * The extraction group runs WordPress's own WP_HTML_Tag_Processor and needs it
 * on disk (SNT_WP_HTML_API, as CI fetches); under CI a skip counts as a fail.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/csp-hash-check.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_CSP_HASH_TEST', true );
const DAY_IN_SECONDS = 86400;
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_posts( $a ) { return array( 42 ); }
function get_permalink( $id ) { return 'https://site.test/notes/latest/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
// The real fetcher lives in inc/login-door-leak-check.php; this one records what the CSP check asks of it.
function sn_door_leak_fetch( $url, $headers = array() ) { $GLOBALS['__fetch_headers'] = $headers; return array( 'ok' => false, 'code' => 0, 'body' => '', 'headers' => array() ); }

$pass = 0; $fail = 0; $skip = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$api = '';
foreach ( array( rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' ), dirname( __DIR__, 4 ) . '/wp-includes/html-api' ) as $dir ) {
	if ( '' !== $dir && is_file( $dir . '/class-wp-html-tag-processor.php' ) ) { $api = $dir; break; }
}
if ( '' !== $api ) {
	if ( is_file( dirname( $api ) . '/class-wp-token-map.php' ) ) { require_once dirname( $api ) . '/class-wp-token-map.php'; }
	foreach ( array( 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-attribute-token.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $f ) {
		require_once "$api/$f";
	}
}
$root = dirname( __DIR__ );
require "$root/inc/csp-hash-parts.php";
require "$root/inc/csp-hash-check.php";
require "$root/inc/csp-hash-health.php";

echo "Group 1: the hash is over exactly the body bytes\n";
ok( 'sha256-6ylx2nk61t31O3rHPzQZfJJkryyz5Ldd+rHtiDdohm0=' === sn_csp_hash( "\nconsole.log(1);\n" ), 'hash of a known body, leading and trailing newline included, matches the precomputed value' );
ok( sn_csp_hash( "\nconsole.log(1);\n" ) !== sn_csp_hash( 'console.log(1);' ), 'the surrounding newlines change the hash (nothing is trimmed)' );

$theme_init = '<script id="sn-theme-init">' . "\nconsole.log(1);\n" . '</script>';
$html = '<html><head>' . $theme_init
	. '<script type="application/ld+json">{"@type":"Person"}</script>'
	. '<script id="wp-importmap" type="importmap">' . "\n" . '{"imports":{"@wordpress/interactivity":"/x.js?ver=1"}}' . "\n" . '</script>'
	. '<script type="application/json" id="sn-cmdk-data">{"a":1}</script>'
	. '<script type="speculationrules">{"prefetch":[]}</script>'
	. '<script src="/wp-includes/js/a.js"></script>'
	. '<script src="/b.js" type="module"></script>'
	. '</head><body><script type="module">' . "\nimport('/e.js');\n" . '</script>'
	. '<script type="text/javascript">var other = 1;</script>'
	. '<script type="text/x-template"><b>not code</b></script></body></html>';

echo "\nGroup 2: which inline scripts count (WordPress's HTML API)\n";
if ( '' === $api ) {
	++$skip;
	echo "SKIP: no WP_HTML_Tag_Processor on this machine (SNT_WP_HTML_API=<wp>/wp-includes/html-api)\n";
} else {
	$found = sn_csp_inline_scripts( $html );
	$labels = array_column( $found, 'label' );
	ok( array( 'theme-init', 'importmap', 'speculation', 'module', 'other' ) === $labels, 'theme-init, the import map, speculation rules, the module and a plain script are found, in page order: ' . implode( ',', $labels ) );
	ok( 'sha256-6ylx2nk61t31O3rHPzQZfJJkryyz5Ldd+rHtiDdohm0=' === $found[0]['hash'], 'the theme-init hash is over the bytes between > and </script>' );
	ok( sn_csp_hash( "\nimport('/e.js');\n" ) === $found[3]['hash'], 'the module hash is over its raw body' );
	$all = implode( ' ', array_column( $found, 'hash' ) );
	ok( false === strpos( $all, sn_csp_hash( '{"@type":"Person"}' ) ) && false === strpos( $all, sn_csp_hash( '{"a":1}' ) ), 'JSON islands (application/json, application/ld+json) are ignored' );
	ok( false !== strpos( $all, sn_csp_hash( '{"prefetch":[]}' ) ), 'speculation rules are hashed: Chromium 152 still demanded their hash with inline-speculation-rules in the policy (live, 2026-10-18)' );
	ok( 5 === count( $found ), 'scripts with src, and a non-script type, are ignored' );
}

echo "\nGroup 3: reading the strict policy from the headers\n";
$live = "default-src 'self'; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'";
$strict = "default-src 'self'; script-src 'self' 'sha256-THEME=' 'sha256-OLDMAP=' 'inline-speculation-rules' https://challenges.cloudflare.com; img-src 'self'";
ok( array( "'self'", "'sha256-THEME='", "'sha256-OLDMAP='", "'inline-speculation-rules'", 'https://challenges.cloudflare.com' ) === sn_csp_script_src( $strict ), 'script-src sources are parsed in order' );
ok( null === sn_csp_script_src( "default-src 'self'; script-src-elem 'self'" ), 'a policy with no script-src directive has none (script-src-elem is not it)' );
$s = sn_csp_strict( array( 'content-security-policy' => $live ) );
ok( 'none' === $s['mode'] && true === $s['csp_seen'], 'only the live policy with unsafe-inline: none, but a CSP header was seen' );
$s = sn_csp_strict( array( 'content-security-policy' => $live, 'content-security-policy-report-only' => array( $strict ) ) );
ok( 'report-only' === $s['mode'] && in_array( "'sha256-THEME='", $s['sources'], true ), 'the strict policy in Report-Only is found (header as an array too)' );
$s = sn_csp_strict( array( 'content-security-policy' => $strict ) );
ok( 'enforced' === $s['mode'], 'the strict policy enforced is found' );
$s = sn_csp_strict( array( 'content-security-policy' => $strict, 'content-security-policy-report-only' => "script-src 'self' 'sha256-OTHER='" ) );
ok( 'enforced' === $s['mode'] && in_array( "'sha256-THEME='", $s['sources'], true ), 'enforced wins when both are strict' );
$s = sn_csp_strict( array( 'x-other' => 'y' ) );
ok( 'none' === $s['mode'] && false === $s['csp_seen'], 'no CSP header at all is told apart from a lax one' );

echo "\nGroup 4: missing hashes and the exact replacement line\n";
$sources = sn_csp_script_src( $strict );
$seen    = array( 'sha256-THEME=', 'sha256-NEWMAP=', 'sha256-MOD=' );
ok( array( 'sha256-NEWMAP=', 'sha256-MOD=' ) === sn_csp_missing( $seen, $sources ), 'missing = seen but absent from the strict policy' );
ok( array() === sn_csp_missing( array( 'sha256-THEME=' ), $sources ), 'nothing missing when every seen hash is pinned' );
ok( "script-src 'self' 'sha256-THEME=' 'sha256-NEWMAP=' 'sha256-MOD=' 'inline-speculation-rules' https://challenges.cloudflare.com" === sn_csp_replacement_line( $sources, $seen ), 'the line swaps the stale hash for the fresh ones and keeps every other source in place' );
ok( "script-src 'self' 'sha256-MOD=' https://x.test" === sn_csp_replacement_line( array( "'self'", 'https://x.test' ), array( 'sha256-MOD=' ) ), 'with no hash yet, the hashes go after self' );

echo "\nGroup 5: the run and the watch\n";
$GLOBALS['__opt'] = array();
$pages = sn_csp_hash_pages();
foreach ( array( '/', '/notes/', '/notes/latest/', '/provenance/', '/index/', '/resume/', '/verify/', '/stats/' ) as $p ) {
	ok( in_array( 'https://site.test' . $p, $pages, true ), "it reads $p" );
}
ok( ! in_array( 'https://site.test/contact/', $pages, true ), '/contact/ is skipped (exempt by design)' );
$body  = '<script id="sn-theme-init">' . "\nconsole.log(1);\n" . '</script>';
$pinned = "script-src 'self' 'sha256-6ylx2nk61t31O3rHPzQZfJJkryyz5Ldd+rHtiDdohm0='";
$fetch  = static function ( $url ) use ( $body, $pinned ) {
	if ( false !== strpos( $url, '/index/' ) ) {
		return array( 'ok' => true, 'code' => 301, 'body' => '<script>challenge()</script>', 'headers' => array() );
	}
	return array( 'ok' => true, 'code' => 200, 'body' => $body, 'headers' => array( 'Content-Security-Policy-Report-Only' => $pinned ) );
};
if ( '' !== $api ) {
	$r = sn_csp_hash_run( $fetch, 1000 );
	ok( 1 === $r['errors'] && 8 === $r['checked'], 'a non-200 answer is unreadable, not read' );
	ok( 'report-only' === $r['mode'] && array() === $r['missing'], 'the run records the strict header and nothing missing' );
	ok( false === strpos( (string) json_encode( get_option( SN_CSP_HASH_OPT ) ), 'console.log' ), 'the stored result holds no text of a pinned script' );
	// 2026-10-20: /stats/ printed wp_localize_script's config as an executing
	// script; its values change, so no hash can pin it. The run keeps the first
	// 60 characters of an UNLISTED script only, so the note says what it is.
	$dyn   = '<script id="sn-live-now-js-extra">' . "
" . 'var snLiveNow = {"url":"https:\/\/site.test\/wp-json\/signal-noise\/v1\/live","interval":"60"};' . "
" . '</script>';
	$fetch2 = static function ( $url ) use ( $body, $dyn, $pinned ) {
		return array( 'ok' => true, 'code' => 200, 'body' => $body . ( false !== strpos( $url, '/stats/' ) ? $dyn : '' ), 'headers' => array( 'Content-Security-Policy' => $pinned ) );
	};
	$r = sn_csp_hash_run( $fetch2, 1000 );
	$h = $r['missing'][0] ?? '';
	ok( 1 === count( $r['missing'] ) && array( '/stats/' ) === $r['seen'][ $h ]['pages'], 'the unlisted script is found on /stats/ only' );
	ok( 'var snLiveNow = {"url":"https:\/\/site.test\/wp-json\/signal' === ( $r['seen'][ $h ]['head'] ?? '' ), 'it carries its first 60 characters: ' . ( $r['seen'][ $h ]['head'] ?? '(none)' ) );
	$v = snt_csp_hash_verdict( $r, 2000 );
	ok( $v['ripe'] && 'critical' === $v['status'] && false !== strpos( $v['note'], 'other on /stats/ ("var snLiveNow = {' ), 'the watch names the page and how the script begins: ' . substr( $v['note'], 0, 160 ) );
	ok( false === strpos( (string) json_encode( $r['seen'] ), 'console.log' ), 'a pinned script still stores no text' );
	// 2026-10-20: Cloudflare leaves a response on the loose policy when it
	// carries X-SN-CSP: lax. A stranger's page in the set never should.
	$fetch3 = static function ( $url ) use ( $body ) {
		$h = array( 'Content-Security-Policy' => "script-src 'self' 'unsafe-inline'" );
		if ( false !== strpos( $url, '/verify/' ) ) {
			$h['X-Sn-Csp'] = array( ' LAX ' );
		}
		return array( 'ok' => true, 'code' => 200, 'body' => $body, 'headers' => $h );
	};
	$r = sn_csp_hash_run( $fetch3, 1000 );
	ok( array( '/verify/' ) === ( $r['lax'] ?? null ), 'a page answering a cookieless read with X-SN-CSP: lax is recorded (any case, as an array)' );
	$v = snt_csp_hash_verdict( $r, 2000 );
	ok( $v['ripe'] && false !== strpos( $v['note'], '/verify/' ) && false !== strpos( $v['note'], 'X-SN-CSP: lax' ), 'it ripens the watch even though the policy then reads as not deployed: ' . substr( $v['note'], 0, 140 ) );
	$r = sn_csp_hash_run( $fetch, 1000 );
	ok( array() === $r['lax'], 'no marker, nothing recorded' );
}
$GLOBALS['__fetch_headers'] = null;
sn_csp_hash_fetch( 'https://site.test/' );
ok( array( 'Sec-GPC' => '1' ) === $GLOBALS['__fetch_headers'], 'the daily read sends Sec-GPC: 1, the same signal every probe of the site sends' );
$base = array( 'at' => 1000, 'checked' => 5, 'errors' => 0, 'csp_seen' => true, 'mode' => 'none', 'sources' => array(), 'seen' => array(), 'missing' => array() );
$v = snt_csp_hash_verdict( $base, 2000 );
ok( false === $v['ripe'] && false !== strpos( $v['note'], 'strict policy not deployed' ) && 'critical' !== $v['status'], 'not deployed is not ripe, and never critical' );
$miss = array_merge( $base, array( 'mode' => 'enforced', 'sources' => $sources, 'seen' => array( 'sha256-THEME=' => array( 'label' => 'theme-init', 'pages' => array( '/' ) ), 'sha256-NEWMAP=' => array( 'label' => 'importmap', 'pages' => array( '/' ) ) ), 'missing' => array( 'sha256-NEWMAP=' ) ) );
$v = snt_csp_hash_verdict( $miss, 2000 );
ok( true === $v['ripe'] && false !== strpos( $v['note'], "script-src 'self' 'sha256-THEME=' 'sha256-NEWMAP=' 'inline-speculation-rules' https://challenges.cloudflare.com" ), 'enforced with a missing hash is ripe and gives the exact line to paste' );
ok( 'critical' === $v['status'], 'enforced and missing is critical in Site Health (modules are blocked now)' );
ok( 'recommended' === snt_csp_hash_verdict( array_merge( $miss, array( 'mode' => 'report-only' ) ), 2000 )['status'], 'report-only and missing is only recommended' );
ok( false === snt_csp_hash_verdict( array_merge( $miss, array( 'missing' => array() ) ), 2000 )['ripe'], 'deployed with nothing missing stays quiet' );
ok( true === snt_csp_hash_verdict( array_merge( $base, array( 'errors' => 3 ) ), 2000 )['ripe'], 'the blind run (most pages unreadable) is ripe' );
ok( false === snt_csp_hash_verdict( array_merge( $base, array( 'errors' => 1 ) ), 2000 )['ripe'], 'one unreadable page of five stays quiet' );
ok( true === snt_csp_hash_verdict( $base, 1000 + 3 * DAY_IN_SECONDS )['ripe'], '2-day silence is ripe' );
ok( true === snt_csp_hash_verdict( array_merge( $base, array( 'csp_seen' => false ) ), 2000 )['ripe'], 'no CSP header on any page is ripe (the check is not reading through Cloudflare)' );
ok( false === snt_csp_hash_verdict( array(), 2000 )['ripe'], 'not ripe before the first run' );
ok( false === snt_csp_hash_verdict( $base, 2000 )['ripe'], 'a run stored before the lax key existed reads as no marker' );
$GLOBALS['__opt'][ SN_CSP_HASH_OPT ] = array_merge( $miss, array( 'at' => time() ) );
ok( true === snt_watch_ripe_csp_hashes( array(), time() )['ripe'], 'the watch reads the stored run' );
ok( 'critical' === snt_csp_hash_site_health_result()['status'], 'Site Health reads the stored run' );

echo "\nGroup 6: registration\n";
ok( false !== strpos( (string) file_get_contents( "$root/inc/watches.php" ), "'csp_hashes'" ), 'the watch is registered' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/cron-dashboard.php" ), 'SN_CSP_HASH_HOOK' ), 'the daily job is in the cron dashboard registry' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/cron-lifecycle.php" ), 'SN_CSP_HASH_HOOK' ), 'and cleared on deactivation' );
$main = (string) file_get_contents( "$root/signal-and-noise-tools.php" );
ok( false !== strpos( $main, 'inc/csp-hash-check.php' ) && false !== strpos( $main, 'inc/csp-hash-parts.php' ) && false !== strpos( $main, 'inc/csp-hash-health.php' ), 'the modules are loaded' );

$ci = (string) getenv( 'CI' );
if ( $skip > 0 && '' !== $ci && '0' !== $ci && 'false' !== strtolower( $ci ) ) {
	echo "FAILED: $skip group(s) SKIPPED under CI because WordPress's html-api is missing; the extraction is the check's core.\n";
	$fail += $skip;
}
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
