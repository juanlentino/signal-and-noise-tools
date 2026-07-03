<?php
/**
 * Standalone test: the empty-panel collector + fold line.
 * Run: php tests/analytics-empty-fold.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

require_once __DIR__ . '/../inc/analytics-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// Fresh collector.
unset( $GLOBALS['sn_an_empty_panels'] );

// Nothing noted → flush emits nothing.
ob_start(); snt_an_flush_empty_fold(); $none = ob_get_clean();
ok( '' === trim( $none ), 'flush with nothing collected emits nothing' );

// Note two → flush emits one line listing both.
snt_an_note_empty( 'Edge locations' );
snt_an_note_empty( 'Threats' );
ob_start(); snt_an_flush_empty_fold(); $out = ob_get_clean();
ok( strpos( $out, 'sn-an-empty-fold' ) !== false, 'fold line carries the .sn-an-empty-fold class' );
ok( strpos( $out, 'Edge locations' ) !== false && strpos( $out, 'Threats' ) !== false, 'fold line lists both collected titles' );
ok( substr_count( $out, '<p' ) === 1, 'exactly one fold line' );

// Flush clears the collector: a second flush emits nothing.
ob_start(); snt_an_flush_empty_fold(); $again = ob_get_clean();
ok( '' === trim( $again ), 'flush clears the collector (second flush is empty)' );

// A malicious title is escaped.
snt_an_note_empty( '<script>x</script>' );
ob_start(); snt_an_flush_empty_fold(); $esc = ob_get_clean();
ok( strpos( $esc, '<script>' ) === false, 'collected titles are escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
