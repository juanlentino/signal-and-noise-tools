<?php
/**
 * Tests: the serve-form branch restores core's documented login globals.
 *
 * WHAT THIS GUARDS. `require` runs the required file in the CALLER'S scope.
 * sn_login_handle_request() requires wp-login.php from inside a function, so
 * without an explicit `global` declaration every file-scope assignment in
 * wp-login.php (`$action`, `$error`, `$interim_login`) lands in the function's
 * locals and core's own login_header() — which declares exactly those three
 * global — sees nothing.
 *
 * That was not theoretical: before v13.28.1 the live login page rendered
 * `<body class="login no-js login-action- …">` with an EMPTY action suffix
 * (stock WordPress renders `login-action-login`), the `login_body_class`
 * filter received a null action, and `$interim_login` — the flag that drives
 * the expired-session re-auth modal — was invisible to core.
 *
 * WHY THIS IS A SOURCE PIN. Same limitation tests/login-noindex-header.php
 * documents: the branch ends in `require_once ABSPATH . 'wp-login.php'; die`,
 * so it cannot run headlessly. The properties below are read out of the
 * shipped source. ORDER is asserted, not just presence, because a `global`
 * declaration placed AFTER the require would be inert while still matching a
 * naive presence grep.
 *
 * Run: php tests/login-serve-form-globals.php
 *
 * @since 13.28.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

$src = (string) file_get_contents( __DIR__ . '/../inc/login-hide.php' );

// TOKENIZE, never grep. The first draft of this suite grepped the raw source
// for the declaration text — and passed against BOTH mutations (declaration
// deleted; declaration moved after the require), because the explanatory
// comment beside the fix QUOTES the statement, so the comment satisfied the
// grep. A guard that cannot fail is decoration; tokenizing drops comments, so
// only real code can satisfy the assertions below.
$tokens = token_get_all( $src );
$global_decls = array(); // line => [var names]
$require_lines = array();
$i = 0;
$n = count( $tokens );
while ( $i < $n ) {
	$t = $tokens[ $i ];
	if ( is_array( $t ) && T_GLOBAL === $t[0] ) {
		$line = $t[2];
		$vars = array();
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$nt = $tokens[ $j ];
			if ( is_string( $nt ) && ';' === $nt ) { break; }
			if ( is_array( $nt ) && T_VARIABLE === $nt[0] ) { $vars[] = ltrim( $nt[1], '$' ); }
		}
		$global_decls[ $line ] = $vars;
	}
	if ( is_array( $t ) && T_REQUIRE_ONCE === $t[0] ) { $require_lines[] = $t[2]; }
	++$i;
}

echo "login serve-form: core's documented globals are restored\n\n";

echo "Group: the declaration exists in CODE, and precedes the require\n";
$trio = array( 'error', 'interim_login', 'action' );
$decl_line = null;
foreach ( $global_decls as $line => $vars ) {
	sort( $vars );
	$want = $trio; sort( $want );
	if ( $vars === $want ) { $decl_line = $line; break; }
}
ok( null !== $decl_line, 'a real `global` statement declares exactly core\'s three login globals' );
ok( array() !== $require_lines, 'the serve-form branch still requires wp-login.php' );
$require_line = $require_lines ? min( $require_lines ) : PHP_INT_MAX;
ok( null !== $decl_line && $decl_line < $require_line,
	'the declaration comes BEFORE the require (after it would be inert)' );

echo "\nGroup: the set is exactly core's @global contract — no wider\n";
// wp-login.php documents precisely these three for the login screen, and
// login_header() declares the same trio. Widening the list would silence real
// signal by lying about scope: $user_login and $errors ARE file-scope in core.
$declared = null !== $decl_line ? $global_decls[ $decl_line ] : array();
foreach ( $trio as $name ) {
	ok( in_array( $name, $declared, true ), "declares \$$name (core's documented login global)" );
}
ok( ! in_array( 'user_login', $declared, true ),
	'does NOT promote $user_login — core assigns it at file scope, and its notice is core\'s own wart' );
ok( ! in_array( 'errors', $declared, true ),
	'does NOT promote $errors — genuinely file-scope in core' );

echo "\nGroup: the reasoning survives with the code\n";
ok( false !== strpos( $src, 'login-action-' ),
	'the measured symptom (empty login-action- body class) is recorded beside the fix' );
ok( false !== strpos( $src, 'display_errors' ),
	'the notice-visibility remedy is named as a SERVER setting, not claimed as fixed here' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
