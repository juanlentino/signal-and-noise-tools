<?php
/**
 * Standalone fixture tests for the robots.txt AI-crawler policy (v6.53.0).
 *
 * inc/robots-txt.php augments WP's virtual robots.txt (via the `robots_txt`
 * filter) with an explicit, filterable per-AI-crawler policy and an idempotent
 * Sitemap pointer. It deliberately does NOT emit the masked login slug — robots.txt
 * is public, so leaking it would defeat inc/login-hide.php.
 *
 * @since plugin v6.53.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS: $msg\n"; } else { $fail++; echo "FAIL: $msg\n"; } }

// --- Stub WP primitives ---
$GLOBALS['__filters'] = array();
function add_filter() { return true; }
function apply_filters( $tag, $value ) {
	if ( isset( $GLOBALS['__filters'][ $tag ] ) ) {
		return call_user_func( $GLOBALS['__filters'][ $tag ], $value );
	}
	return $value;
}
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function esc_url_raw( $u ) { return $u; }

require __DIR__ . '/../inc/robots-txt.php';

$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

// --- Non-public sites pass through untouched (respect "Discourage search engines") ---
ok( snt_robots_txt( $core, false ) === $core, 'non-public site: output is returned unchanged' );

// --- Default policy (all agents allowed) ---
$out = snt_robots_txt( $core, true );
ok( strpos( $out, '# AI crawler policy' ) !== false, 'emits the AI crawler policy header comment' );
ok( strpos( $out, 'GPTBot' ) !== false && strpos( $out, 'ClaudeBot' ) !== false && strpos( $out, 'PerplexityBot' ) !== false, 'lists the major answer-engine agents as allowed' );
ok( strpos( $out, 'Disallow: /' . "\n" ) === false, 'default policy blocks no agent (no bare Disallow: /)' );
ok( strpos( $out, $core ) === 0, 'preserves the existing (core) robots output as a prefix' );

// --- Idempotent Sitemap pointer ---
ok( substr_count( $out, 'Sitemap:' ) === 1, 'adds exactly one Sitemap: line when none present' );
ok( strpos( $out, 'Sitemap: https://juanlentino.com/wp-sitemap.xml' ) !== false, 'Sitemap points at /wp-sitemap.xml' );
$with_sitemap = $core . "\nSitemap: https://juanlentino.com/wp-sitemap.xml\n";
ok( substr_count( snt_robots_txt( $with_sitemap, true ), 'Sitemap:' ) === 1, 'does NOT duplicate an already-present Sitemap line' );

// --- Filter can flip an agent to disallow ---
$GLOBALS['__filters']['snt_robots_ai_agents'] = function ( $agents ) { $agents['CCBot'] = 'disallow'; return $agents; };
$blocked = snt_robots_txt( $core, true );
ok( strpos( $blocked, "User-agent: CCBot\nDisallow: /" ) !== false, 'a disallowed agent gets a User-agent block with Disallow: /' );
ok( strpos( $blocked, '# Allowed' ) !== false && strpos( $blocked, 'CCBot,' ) === false, 'a disallowed agent is dropped from the Allowed list' );
$GLOBALS['__filters'] = array();

// --- Security: never leak the masked login slug ---
ok( stripos( $out, 'wp-login' ) === false && stripos( $out, 'login' ) === false, 'does NOT leak any login path/slug into public robots.txt' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
