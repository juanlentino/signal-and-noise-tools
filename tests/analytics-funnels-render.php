<?php
/**
 * Render tests for snt_analytics_render_funnels() — the settings-hub Session
 * funnels card (S2 §3, Task 3): a zero-JS textarea, one funnel per line,
 * prefilled from the CURRENT analytics.funnels setting via
 * sn_analytics_funnels_to_text() (inc/analytics-sessions.php).
 *
 * Model: tests/analytics-tuning-render.php (same stub set + isolation shape).
 *
 * Run: php tests/analytics-funnels-render.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
// esc_attr(__) escape for real (unlike the plain-passthrough stubs elsewhere in
// this file) — the placeholder text itself contains '>' characters, and a
// passthrough stub would leak them unescaped into the attribute, breaking the
// naive tag-boundary regex below (a false failure the real esc_attr__ never
// produces, since it turns '>' into '&gt;' in production).
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { echo '<input type="hidden" name="_wpnonce" value="x">'; return ''; }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }

$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}

require __DIR__ . '/../inc/analytics-sessions.php';
require __DIR__ . '/../inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ─────────────────────────────────────────────────────────────────────────
echo "Group: form scaffold — nonce, submit, textarea\n";
// ─────────────────────────────────────────────────────────────────────────
$GLOBALS['__settings'] = array();
ob_start();
snt_analytics_render_funnels();
$h = ob_get_clean();
ok( strpos( $h, '<form method="post"' ) !== false, 'wraps in a POST form' );
ok( strpos( $h, 'name="_wpnonce"' ) !== false, 'nonce present' );
ok( strpos( $h, 'value="analytics_funnels_save"' ) !== false, 'submit posts analytics_funnels_save' );
ok( 1 === preg_match( '/<textarea[^>]*name="sn_funnels"[^>]*rows="6"/', $h ), 'textarea present with 6 rows' );
ok( strpos( $h, 'placeholder="' ) !== false, 'placeholder present (shows the format example)' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: empty setting -> empty textarea\n";
// ─────────────────────────────────────────────────────────────────────────
ok( 1 === preg_match( '#<textarea[^>]*>\s*</textarea>#', $h ), 'empty analytics.funnels setting -> empty textarea body' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: prefill — a path-only funnel round-trips into the textarea\n";
// ─────────────────────────────────────────────────────────────────────────
$GLOBALS['__settings'] = array(
	'analytics.funnels' => array(
		array(
			'title' => 'Home flow',
			'steps' => array(
				array( 'match' => 'path', 'value' => '/entry', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/goal', 'prefix' => false ),
			),
		),
	),
);
ob_start();
snt_analytics_render_funnels();
$h = ob_get_clean();
ok( strpos( $h, 'Home flow: /entry > /goal' ) !== false, 'path-only funnel prefilled in "Name: /a > /b" form' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: a funnel with a ce/prefix step is OMITTED, not invented into a comment\n";
// ─────────────────────────────────────────────────────────────────────────
$GLOBALS['__settings'] = array(
	'analytics.funnels' => array(
		array(
			'title' => 'Home → post → subscribe',
			'steps' => array(
				array( 'match' => 'path', 'value' => '/', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/notes/', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'subscribe', 'prefix' => false ),
			),
		),
		array(
			'title' => 'Editable',
			'steps' => array(
				array( 'match' => 'path', 'value' => '/a', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/b', 'prefix' => false ),
			),
		),
	),
);
ob_start();
snt_analytics_render_funnels();
$h = ob_get_clean();
ok( strpos( $h, 'Home → post → subscribe' ) === false, 'a funnel with a ce/prefix step is omitted from the textarea entirely' );
ok( strpos( $h, '# (code-defined' ) === false, 'no invented comment syntax stands in for the omitted funnel' );
ok( strpos( $h, 'Editable: /a > /b' ) !== false, 'a path-only sibling funnel still prefills' );
ok( strpos( $h, 'sn_analytics_session_funnels' ) !== false, 'help text names the filter that keeps the omitted funnel alive' );
ok( stripos( $h, 'replaces the built-in defaults' ) !== false, 'help text warns that saving replaces the built-in defaults' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: round-trip pin — parse(to_text(parse(x)['funnels']))['funnels'] === parse(x)['funnels']\n";
// ─────────────────────────────────────────────────────────────────────────
$raw     = "Home flow: /entry > /step > /goal\nContact: /a > /b";
$parsed1 = sn_analytics_parse_funnels( $raw );
$text    = sn_analytics_funnels_to_text( $parsed1['funnels'] );
$parsed2 = sn_analytics_parse_funnels( $text );
ok( $parsed1['funnels'] === $parsed2['funnels'], 'round trip holds for path-only funnels' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
