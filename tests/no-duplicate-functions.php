<?php
/**
 * Guard: no two plugin files declare the same global function. Each suite
 * loads only the files it tests, so a second `function x(` in another file
 * passes every suite and fatals ("Cannot redeclare") on the live site.
 * 2026-10-10: snt_mr_daily_series was declared twice (#1982); only PHPStan
 * in CI noticed, by arity.
 *
 * Run: php tests/no-duplicate-functions.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
$root  = dirname( __DIR__ );
$files = array_merge( array( "$root/signal-and-noise-tools.php" ), array_map( 'strval', iterator_to_array( new RegexIterator( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/inc", FilesystemIterator::SKIP_DOTS ) ), '/\.php$/' ), false ) ) );
foreach ( array( 'apps' ) as $extra ) {
	if ( is_dir( "$root/$extra" ) ) {
		$files = array_merge( $files, array_map( 'strval', iterator_to_array( new RegexIterator( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$extra", FilesystemIterator::SKIP_DOTS ) ), '/\.php$/' ), false ) ) );
	}
}
$seen = array();
foreach ( $files as $f ) {
	$ns    = '';
	$depth = 0;
	$cls   = null;  // the depth a class-like body opened at; methods inside are not functions.
	$want  = false;
	$toks  = token_get_all( (string) file_get_contents( $f ) );
	foreach ( $toks as $i => $t ) {
		if ( is_array( $t ) && T_NAMESPACE === $t[0] ) {
			$ns = '';
			for ( $j = $i + 1; isset( $toks[ $j ] ) && ';' !== $toks[ $j ] && '{' !== $toks[ $j ]; $j++ ) {
				$ns .= is_array( $toks[ $j ] ) && T_WHITESPACE !== $toks[ $j ][0] ? $toks[ $j ][1] : '';
			}
		}
		if ( is_array( $t ) && in_array( $t[0], array( T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM ), true ) && null === $cls ) { $want = true; }
		if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			$depth++;
			if ( $want ) { $cls = $depth; $want = false; }
		}
		if ( '}' === $t ) {
			if ( $cls === $depth ) { $cls = null; }
			$depth--;
		}
		// A top-level (or namespace-block) function: not a method, not a closure.
		if ( is_array( $t ) && T_FUNCTION === $t[0] && null === $cls && $depth <= 1 ) {
			for ( $j = $i + 1; isset( $toks[ $j ] ) && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0]; $j++ ) {}
			if ( isset( $toks[ $j ] ) && is_array( $toks[ $j ] ) && T_STRING === $toks[ $j ][0] ) {
				$seen[ strtolower( ltrim( $ns . '\\' . $toks[ $j ][1], '\\' ) ) ][] = substr( $f, strlen( $root ) + 1 );
			}
		}
	}
}
$dups = array_filter( $seen, static fn( $where ) => count( array_unique( $where ) ) > 1 );
foreach ( $dups as $name => $where ) {
	echo "FAIL: $name declared in " . implode( ', ', array_unique( $where ) ) . "\n";
}
echo 'Result: ' . ( $dups ? 0 : 1 ) . ' passed, ' . count( $dups ) . ' failed. (' . count( $seen ) . " functions)\n";
exit( $dups ? 1 : 0 );
