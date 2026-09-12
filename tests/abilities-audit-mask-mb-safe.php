<?php
/**
 * Regression test (#1227): snt_audit_log_pii_mask_username() must keep the
 * first CHARACTER, not the first byte — a multibyte first character (e.g.
 * 'É...') was truncated to its lead byte alone, an invalid UTF-8 fragment.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function add_action( $hook, $cb = null, $p = 10, $a = 1 ) {}

require __DIR__ . '/../inc/abilities-audit.php';

echo "Group: #1227 — username mask is mb_-safe\n";
ok( 'A****' === snt_audit_log_pii_mask_username( 'Alice' ), 'plain ASCII username masks as before' );
ok( '*' === snt_audit_log_pii_mask_username( 'A' ), 'a 1-char username is fully starred' );
ok( '' === snt_audit_log_pii_mask_username( '' ), 'empty username stays empty' );

// The multibyte case: 'É' is a 2-byte UTF-8 character. A byte-based
// substr( $username, 0, 1 ) keeps only its first byte — an invalid
// fragment — and strlen() over-counts the star run to match.
ok( 'É****' === snt_audit_log_pii_mask_username( 'Émile' ), 'a multibyte first character is kept WHOLE, not split into an invalid byte' );
ok( 1 === preg_match( '//u', snt_audit_log_pii_mask_username( 'Émile' ) ), 'the masked result is valid UTF-8' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
