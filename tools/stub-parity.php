<?php
/**
 * Signal & Noise — stub-parity sweep: guard the wrong-guess class in tests.
 *
 * The standalone suites in tests/ stub WordPress functions. The recurring trap
 * (bitten repeatedly; the stub-drift memory line) is a stub whose SHAPE is a
 * GUESS — an invented function name (the get_the_queried_object_id class of
 * incident, where a plausible-looking name that WordPress does not have was
 * stubbed, so the suite passed while production fatalled), or a signature the
 * test author imagined. This sweep compares test-defined functions against the
 * SAME pinned reference PHPStan already trusts: php-stubs/wordpress-stubs.
 *
 * TWO checks, both chosen because a green result would otherwise be evidence
 * of nothing:
 *
 *   1. PHANTOM API (fail). A test defines a WP-shaped function name that the
 *      pinned WordPress stubs do not contain. Either the name is invented, or
 *      it is private/removed core API no plugin may call. This is the exact
 *      incident class above.
 *   2. BY-REFERENCE DRIFT (fail). A parameter is by-reference in WordPress but
 *      by-value in the stub (or the reverse). Writes through the reference
 *      silently vanish in one world and not the other, so behavior diverges
 *      while the suite stays green.
 *
 * DELIBERATELY NOT CHECKED, with reasons — an earlier draft of this tool
 * reported 381 "failures" that were all instrument artifacts:
 *
 *   - Stub arity narrower than WordPress. `function add_action() {}` is the
 *     house's no-op registration-sink idiom (279 instances). A zero-parameter
 *     PHP function accepts any number of arguments at call time, so the sink
 *     is harmless by construction. Flagging it drowns the real findings.
 *   - Stub arity wider than WordPress. Extra arguments are ignored at runtime,
 *     and the reference's variadics (`current_user_can($cap, ...$args)`,
 *     `add_query_arg(...$args)`) make "wider" unmeasurable anyway.
 *
 * LIMIT, stated so a green run is not over-read: this is NAME and REFERENCE
 * parity only. A stub with the right shape and the wrong behavior still
 * passes — that class is caught only by driving the real producer, which the
 * suites already prefer.
 *
 * Usage:
 *   php tools/stub-parity.php                       # this repo's tests/
 *   php tools/stub-parity.php --tests=DIR [--stubs=FILE] [--json] [--self-test]
 *
 * Exit 0 clean · 1 findings · 2 cannot run (missing/implausible reference —
 * a sweep that cannot read its reference must never report green).
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

$opts      = getopt( '', array( 'tests::', 'stubs::', 'src::', 'json', 'self-test' ) );
$root      = dirname( __DIR__ );
$tests_dir = isset( $opts['tests'] ) ? rtrim( (string) $opts['tests'], '/' ) : $root . '/tests';
$stubs     = isset( $opts['stubs'] ) ? (string) $opts['stubs'] : $root . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
$src_dir   = isset( $opts['src'] ) ? rtrim( (string) $opts['src'], '/' ) : $root . '/inc';

/** Prefixes that read as WordPress API. A name the reference does not know AND
 * that matches one of these is the phantom-API finding. */
const SN_PARITY_WP_SHAPED = array(
	'wp_', 'get_', 'is_', 'has_', 'the_', 'esc_', 'add_', 'do_', 'did_', 'apply_',
	'register_', 'unregister_', 'remove_', 'update_', 'delete_', 'sanitize_',
	'current_', 'admin_', 'rest_', 'shortcode_', 'plugin_', 'network_', 'load_',
	'set_transient', 'get_transient', 'wpautop', 'wptexturize', 'absint',
	'trailingslashit', 'untrailingslashit', 'paginate_', 'human_time_diff',
	'size_format', 'number_format_i18n', 'date_i18n', 'antispambot', 'make_clickable',
);

/** House prefixes — our own code, never WordPress; exempt from the phantom check. */
const SN_PARITY_HOUSE = array( 'sn_', 'snt_', 'pn_' );

/**
 * Extract top-level function signatures from PHP source.
 *
 * Token-based with brace tracking so class/interface/trait METHODS are never
 * mistaken for stubs (the reference file is ~90% classes). Functions declared
 * inside `if ( ! function_exists() )` guards are collected — they are real
 * runtime declarations.
 *
 * @param string $src PHP source.
 * @return array<string,array{by_ref:string,variadic:bool}> lowercased name → shape.
 */
function sn_parity_extract( $src ) {
	$tokens = token_get_all( $src );
	$out    = array();
	$n      = count( $tokens );
	$class_depths = array();
	$depth  = 0;

	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( '{' === $t ) { $depth++; continue; }
		if ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) { $depth++; continue; }
		if ( '}' === $t ) {
			$depth--;
			while ( $class_depths && end( $class_depths ) > $depth ) { array_pop( $class_depths ); }
			continue;
		}
		if ( ! is_array( $t ) ) { continue; }
		if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) { $class_depths[] = $depth + 1; continue; }
		if ( T_FUNCTION !== $t[0] ) { continue; }
		if ( $class_depths && $depth >= end( $class_depths ) ) { continue; } // method

		$j = $i + 1;
		while ( $j < $n && ( '&' === $tokens[ $j ] || ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) ) ) { $j++; }
		if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) { continue; } // closure
		$name = strtolower( $tokens[ $j ][1] );

		while ( $j < $n && '(' !== $tokens[ $j ] ) { $j++; }
		$paren    = 1;
		$by_ref   = '';
		$variadic = false;
		$in_param = false;
		$is_ref   = false;
		for ( $k = $j + 1; $k < $n && $paren > 0; $k++ ) {
			$p = $tokens[ $k ];
			if ( '(' === $p ) { $paren++; continue; }
			if ( ')' === $p ) {
				$paren--;
				if ( 0 === $paren && $in_param ) { $by_ref .= $is_ref ? 'R' : '-'; }
				continue;
			}
			if ( 1 !== $paren ) { continue; }
			if ( ',' === $p ) { if ( $in_param ) { $by_ref .= $is_ref ? 'R' : '-'; } $in_param = false; $is_ref = false; continue; }
			if ( '&' === $p ) { $is_ref = true; continue; }
			// PHP 8 tokenizes a by-reference parameter's ampersand as a token
			// ARRAY, not the string '&' — reading only '&' left this check
			// inert (caught by the self-test, which is why it exists).
			if ( is_array( $p ) && defined( 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG' ) && T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG === $p[0] ) { $is_ref = true; continue; }
			if ( is_array( $p ) && T_ELLIPSIS === $p[0] ) { $variadic = true; continue; }
			if ( is_array( $p ) && T_VARIABLE === $p[0] ) { $in_param = true; continue; }
		}
		if ( ! isset( $out[ $name ] ) ) {
			$out[ $name ] = array( 'by_ref' => $by_ref, 'variadic' => $variadic );
		}
		$i = $j;
	}
	return $out;
}

/** @param string $name @param string[] $prefixes @return bool */
function sn_parity_has_prefix( $name, $prefixes ) {
	foreach ( $prefixes as $p ) {
		if ( 0 === strpos( $name, $p ) ) { return true; }
	}
	return false;
}

/**
 * How production code reaches a function name.
 *
 * A stub for a name WordPress does not have is only a HAZARD when production
 * actually calls it and nothing defines it: that call fatals live while the
 * suite stays green. Three other cases are not hazards and must not be
 * reported as such — a test-local helper that merely looks WP-shaped, a
 * house function production defines itself, and a `function_exists()`-guarded
 * forward-compat call (correct code by construction).
 *
 * @param string $name    Lowercased function name.
 * @param string $src_dir Production source root.
 * @return string 'unused' | 'defined' | 'guarded' | 'unguarded'
 */
function sn_parity_production_usage( $name, $src_dir ) {
	static $cache = array();
	if ( isset( $cache[ $name ] ) ) { return $cache[ $name ]; }
	$hits = array();
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( 'php' !== strtolower( $f->getExtension() ) ) { continue; }
		$src = (string) file_get_contents( $f->getPathname() );
		if ( false === stripos( $src, $name ) ) { continue; }
		if ( preg_match( '/\bfunction\s+' . preg_quote( $name, '/' ) . '\s*\(/i', $src ) ) {
			return $cache[ $name ] = 'defined';
		}
		if ( preg_match( '/(?<![a-z0-9_])' . preg_quote( $name, '/' ) . '\s*\(/i', $src ) ) {
			$hits[] = $src;
		}
	}
	if ( ! $hits ) { return $cache[ $name ] = 'unused'; }
	foreach ( $hits as $src ) {
		// Unguarded if ANY call site lacks a function_exists() check for it.
		if ( ! preg_match( '/function_exists\(\s*[\'"]' . preg_quote( $name, '/' ) . '[\'"]\s*\)/i', $src ) ) {
			return $cache[ $name ] = 'unguarded';
		}
	}
	return $cache[ $name ] = 'guarded';
}

/**
 * Compare one directory of test files against the reference.
 *
 * @param string $tests_dir Directory of *.php suites.
 * @param array  $reference Reference shapes from sn_parity_extract().
 * @param string $src_dir   Production source root (usage triage), '' to skip.
 * @return array{fails:string[],notes:string[],checked:int,files:int}
 */
function sn_parity_sweep( $tests_dir, array $reference, $src_dir = '' ) {
	$files = glob( rtrim( $tests_dir, '/' ) . '/*.php' );
	sort( $files );
	$fails   = array();
	$notes   = array();
	$checked = 0;
	foreach ( $files as $file ) {
		$base = basename( $file );
		foreach ( sn_parity_extract( (string) file_get_contents( $file ) ) as $name => $shape ) {
			if ( sn_parity_has_prefix( $name, SN_PARITY_HOUSE ) ) { continue; }
			if ( ! isset( $reference[ $name ] ) ) {
				if ( ! sn_parity_has_prefix( $name, SN_PARITY_WP_SHAPED ) ) { continue; }
				$usage = '' !== $src_dir ? sn_parity_production_usage( $name, $src_dir ) : 'unguarded';
				if ( 'unguarded' === $usage ) {
					$fails[] = "$base: $name() is stubbed and called UNGUARDED in production, but the pinned WordPress stubs have no such function — an invented name, or private/removed core API. This fatals live while this suite stays green.";
				} elseif ( 'guarded' === $usage ) {
					$notes[] = "$base: $name() is not in the pinned stubs and is called only behind function_exists() — forward-compat, so it is INERT today. Verify the spelling by hand: a misspelling here never runs and never errors.";
				}
				continue;
			}
			$checked++;
			$wp  = $reference[ $name ];
			$len = min( strlen( $shape['by_ref'] ), strlen( $wp['by_ref'] ) );
			for ( $p = 0; $p < $len; $p++ ) {
				if ( $shape['by_ref'][ $p ] !== $wp['by_ref'][ $p ] ) {
					$fails[] = sprintf(
						'%s: %s() parameter %d is %s in the stub but %s in WordPress — writes through the reference diverge.',
						$base, $name, $p + 1,
						'R' === $shape['by_ref'][ $p ] ? 'by-reference' : 'by-value',
						'R' === $wp['by_ref'][ $p ] ? 'by-reference' : 'by-value'
					);
					break;
				}
			}
		}
	}
	return array( 'fails' => $fails, 'notes' => $notes, 'checked' => $checked, 'files' => count( $files ) );
}

// ── Reference, with the refusal-to-guess guard ──
if ( ! is_file( $stubs ) ) {
	fwrite( STDERR, "stub-parity: reference not found at $stubs (run composer install) — refusing to report green.\n" );
	exit( 2 );
}
$reference = sn_parity_extract( (string) file_get_contents( $stubs ) );
if ( count( $reference ) < 1000 ) {
	fwrite( STDERR, 'stub-parity: reference parsed only ' . count( $reference ) . " functions — that is not WordPress; refusing to report green.\n" );
	exit( 2 );
}

// ── Self-test (negative control): the instrument must fail on seeded defects.
// Run in CI before the real sweep, so a green sweep is never a broken sweep. ──
if ( isset( $opts['self-test'] ) ) {
	$tmp = sys_get_temp_dir() . '/sn-parity-selftest-' . getmypid();
	@mkdir( $tmp );
	file_put_contents( $tmp . '/phantom.php', "<?php\nfunction get_the_queried_object_id() { return 1; }\n" );
	file_put_contents( $tmp . '/byref.php', "<?php\nfunction wp_parse_str( \$string, \$array ) {}\n" );
	file_put_contents( $tmp . '/clean.php', "<?php\nfunction esc_html( \$s ) { return \$s; }\nfunction sn_helper() {}\nfunction add_action() {}\n" );
	$res  = sn_parity_sweep( $tmp, $reference, '' );
	array_map( 'unlink', (array) glob( $tmp . '/*.php' ) );
	@rmdir( $tmp );
	$got_phantom = (bool) preg_grep( '/phantom\.php.*get_the_queried_object_id/', $res['fails'] );
	$got_byref   = (bool) preg_grep( '/byref\.php.*wp_parse_str/', $res['fails'] );
	$no_noise    = ! preg_grep( '/clean\.php/', $res['fails'] );
	echo $got_phantom ? "PASS: phantom API detected\n" : "FAIL: phantom API NOT detected\n";
	echo $got_byref ? "PASS: by-reference drift detected\n" : "FAIL: by-reference drift NOT detected\n";
	echo $no_noise ? "PASS: clean fixtures stay silent (no-op sink + house helper + honest stub)\n" : "FAIL: clean fixtures produced noise\n";
	exit( ( $got_phantom && $got_byref && $no_noise ) ? 0 : 1 );
}

if ( ! is_dir( $tests_dir ) ) {
	fwrite( STDERR, "stub-parity: tests dir not found at $tests_dir.\n" );
	exit( 2 );
}

$res = sn_parity_sweep( $tests_dir, $reference, is_dir( $src_dir ) ? $src_dir : '' );

if ( isset( $opts['json'] ) ) {
	echo json_encode(
		array( 'checked' => $res['checked'], 'files' => $res['files'], 'reference' => count( $reference ), 'fails' => $res['fails'], 'notes' => $res['notes'] ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
} else {
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI
	// output. This script is guarded to PHP_SAPI 'cli' at the top, the
	// destination is a terminal or an Actions log, and esc_html() does not
	// exist here: nothing loads WordPress. Escaping for HTML would corrupt the
	// findings without protecting anything. Scoped to the reporting block only.
	// Mirrors tools/version-tag-parity.php, which states the same rationale.
	foreach ( $res['fails'] as $f ) { echo "FAIL: $f\n"; }
	foreach ( $res['notes'] as $nte ) { echo "note: $nte\n"; }
	printf(
		"\nstub-parity: %d known-WordPress stubs verified across %d files, against %d reference functions — %d failing, %d forward-compat notes.\n",
		$res['checked'], $res['files'], count( $reference ), count( $res['fails'] ), count( $res['notes'] )
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}
exit( $res['fails'] ? 1 : 0 );
