<?php
/**
 * Standalone fixture tests for the v7.3.0 color-drift Health check
 * (sn_health_normalize_hex / sn_health_allowed_palette_hexes /
 * sn_health_check_color_drift in inc/health-checks.php).
 *
 * The check is zero-AI: published posts/pages carrying inline hex colors
 * outside the theme palette. Palette read defensively (flat AND origin-keyed
 * wp_get_global_settings shapes; core 'default' origin excluded because the
 * theme sets defaultPalette:false).
 *
 * Run: php tests/health-color-drift.php
 * @since plugin v7.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return 'https://x.test/?p=' . (int) $id; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://x.test' . $p; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) { function wp_get_attachment_metadata( $id ) { return array(); } }

// Palette stub: shape toggled per scenario (null = unavailable).
$GLOBALS['__palette'] = null;
if ( ! function_exists( 'wp_get_global_settings' ) ) {
	function wp_get_global_settings( $path = array() ) { return $GLOBALS['__palette']; }
}

// wpdb fake: returns the configured rows for the color scan.
$GLOBALS['__scan_rows'] = array();
class SnColorDriftWpdb {
	public $posts = 'wp_posts';
	public function get_results( $sql, $out = null ) { return $GLOBALS['__scan_rows']; }
	public function get_var( $sql ) { return 0; }
	public function get_row( $sql, $out = null ) { return null; }
	public function query( $sql ) { return 0; }
	public function prepare( $sql, ...$args ) { return $sql; }
}
$GLOBALS['wpdb'] = new SnColorDriftWpdb();

require_once __DIR__ . '/../inc/health-checks.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── hex normalization ──
echo "\nTest: sn_health_normalize_hex\n";
ok( '#ffffff' === sn_health_normalize_hex( '#FFF' ), '3-digit expands + lowercases' );
ok( '#e00404' === sn_health_normalize_hex( '#E00404' ), '6-digit lowercases' );
ok( '' === sn_health_normalize_hex( 'red' ), 'named color rejected' );
ok( '' === sn_health_normalize_hex( '#12345' ), '5-digit rejected' );

// ── palette reader: flat shape (what the theme design-tokens ability reads) ──
echo "\nTest: sn_health_allowed_palette_hexes\n";
$GLOBALS['__palette'] = array(
	array( 'slug' => 'void', 'color' => '#ffffff' ),
	array( 'slug' => 'blood', 'color' => '#E00404' ),
);
$allowed = sn_health_allowed_palette_hexes();
ok( isset( $allowed['#ffffff'], $allowed['#e00404'] ), 'flat palette: both hexes allowed, normalized' );

// ── palette reader: origin-keyed shape — theme+custom in, default OUT ──
$GLOBALS['__palette'] = array(
	'default' => array( array( 'slug' => 'vivid-red', 'color' => '#cf2e2e' ) ),
	'theme'   => array( array( 'slug' => 'bone', 'color' => '#000000' ) ),
	'custom'  => array( array( 'slug' => 'mine', 'color' => '#123456' ) ),
);
$allowed = sn_health_allowed_palette_hexes();
ok( isset( $allowed['#000000'], $allowed['#123456'] ), 'origin-keyed: theme + custom origins allowed' );
ok( ! isset( $allowed['#cf2e2e'] ), 'origin-keyed: core default origin EXCLUDED (defaultPalette:false)' );

// ── check: offending post flagged, clean post not ──
echo "\nTest: sn_health_check_color_drift\n";
$GLOBALS['__palette']   = array( array( 'slug' => 'void', 'color' => '#ffffff' ), array( 'slug' => 'bone', 'color' => '#000000' ) );
$GLOBALS['__scan_rows'] = array(
	array( 'ID' => 5, 'post_title' => 'Drifter', 'post_content' => '<p style="color:#ff0000">x</p> <span style="background:#FFF">ok</span>' ),
	array( 'ID' => 6, 'post_title' => 'Clean', 'post_content' => '<p style="color:#000">fine</p>' ),
);
$check = sn_health_check_color_drift();
ok( 1 === $check['count'], 'one offending post flagged (clean + palette-only posts skipped)' );
ok( 5 === ( $check['findings'][0]['subject_id'] ?? 0 ), 'the drifting post is the finding' );
ok( false !== strpos( (string) $check['findings'][0]['note'], '#ff0000' ), 'note names the off-palette color' );
ok( 'Color drift' === $check['label'], 'check self-labels' );

// ── check: empty palette degrades to 0 + note, never flags everything ──
$GLOBALS['__palette'] = null;
$check = sn_health_check_color_drift();
ok( 0 === $check['count'], 'no palette: zero findings' );
ok( false !== stripos( (string) $check['fix_hint'], 'unavailable' ), 'no palette: fix_hint says unavailable' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
