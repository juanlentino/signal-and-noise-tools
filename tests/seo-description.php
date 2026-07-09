<?php
/**
 * Tests for inc/seo-description.php — the shared singular meta-description
 * resolver (override -> excerpt -> theme filter). Extracted from inc/seo.php in
 * v9.3.0 so the head emitter and the Intelligence "descriptionless route"
 * recommendation share ONE precedence (no drift). Run: php tests/seo-description.php
 * @since plugin v9.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__override']   = '';
$GLOBALS['__theme_desc'] = '';
function sn_post_settings_get_description( $id ) { return $GLOBALS['__override']; }
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); }
function apply_filters( $hook, $value, ...$args ) {
	return ( 'sn_seo_singular_description' === $hook ) ? $GLOBALS['__theme_desc'] : $value;
}

require __DIR__ . '/../inc/seo-description.php';

$p = 0; $f = 0;
function d_eq( $e, $a, $m ) { global $p, $f; if ( $e === $a ) { $p++; echo "  PASS: $m\n"; } else { $f++; echo "  FAIL: $m ($e vs $a)\n"; } }

$post = (object) array( 'ID' => 1, 'post_excerpt' => 'EXCERPT' );

echo "\nTest: singular description precedence\n";
$GLOBALS['__override'] = 'OVERRIDE';
d_eq( 'OVERRIDE', sn_seo_resolve_singular_description( $post ), 'override wins over excerpt' );
$GLOBALS['__override'] = '';
d_eq( 'EXCERPT', sn_seo_resolve_singular_description( $post ), 'excerpt used when no override' );
$post2 = (object) array( 'ID' => 2, 'post_excerpt' => '' );
$GLOBALS['__theme_desc'] = 'THEME';
d_eq( 'THEME', sn_seo_resolve_singular_description( $post2 ), 'theme filter fills when no excerpt' );
$GLOBALS['__theme_desc'] = '';
d_eq( '', sn_seo_resolve_singular_description( $post2 ), 'empty when nothing resolves (descriptionless)' );
d_eq( '', sn_seo_resolve_singular_description( null ), 'non-object → empty' );

echo "\nResult: $p passed, $f failed.\n";
exit( $f > 0 ? 1 : 0 );
