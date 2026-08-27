<?php
/**
 * Signal & Noise — editor API smoke against a WordPress build.
 *
 * The block-editor surfaces here (the pre-publish gate, the ability-run client)
 * are plain scripts against core's runtime globals: they declare `wp-*` script
 * handles as dependencies and call `wp.plugins.registerPlugin`,
 * `wp.editor.PluginPrePublishPanel`, and friends. Nothing is bundled, so
 * nothing fails at build time. If core renames a package, drops a handle, or
 * moves an export, the failure appears in the editor, in the middle of a
 * writing session, on the day WordPress ships.
 *
 * This tool answers one question against ANY WordPress tree — nightly in CI,
 * so the answer arrives weeks early: does core still publish every handle we
 * depend on, and every symbol we call, in the package we call it from?
 *
 * NOTHING IS HAND-MAINTAINED. Both requirement sets are DERIVED from this
 * repo's own source at run time — the handles from the `wp-*` dependency
 * arrays in the enqueue calls, the symbols from the `wp.<package>.<Symbol>`
 * usages in the enqueued scripts — so a new dependency is covered the moment
 * it is written, and a list cannot rot into a green that means nothing.
 *
 * THE LIMIT, stated because this exact instrument class has already lied here
 * once: a symbol-existence check answers "does this upstream NAME still
 * exist", and a name can survive while the BEHAVIOR dies. That is precisely
 * what happened with the command palette on the OpenStation upgrades — every
 * name-based probe passed clean while the palette was completely dead,
 * because upstream deferred a runtime rather than renaming anything. A green
 * run here means core still ships what we reference. It does NOT mean the
 * editor works. Only a runtime probe can say that.
 *
 * Usage:
 *   php tools/editor-api-smoke.php --wp=/path/to/wordpress
 *   php tools/editor-api-smoke.php --self-test --wp=/path/to/wordpress
 *   php tools/editor-api-smoke.php --wp=DIR --json
 *
 * Exit 0 clean · 1 findings · 2 cannot run (no WordPress tree, or a tree that
 * does not look like WordPress — a smoke test that cannot read its subject
 * must never report green).
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

$opts = getopt( '', array( 'wp::', 'json', 'self-test' ) );
$root = dirname( __DIR__ );
$wp   = isset( $opts['wp'] ) ? rtrim( (string) $opts['wp'], '/' ) : '';

/**
 * Map a runtime package global to its core dist basename.
 * `wp.blockEditor` → `block-editor`, `wp.data` → `data`.
 *
 * @param string $pkg camelCase package global.
 * @return string kebab-case basename.
 */
function sn_editor_pkg_file( $pkg ) {
	return strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $pkg ) );
}

/**
 * Derive the editor requirements from this repo's own source.
 *
 * @param string $root Repo root.
 * @return array{handles:array<string,string[]>,symbols:array<string,array<string,string[]>>}
 *         handles: 'wp-editor' → [files declaring it]
 *         symbols: package → symbol → [files using it]
 */
function sn_editor_requirements( $root ) {
	$handles = array();
	$symbols = array();

	// PHP side: the DEPENDENCY ARRAYS of scripts enqueued for the block editor.
	//
	// The discriminator is the DEPENDENCY ARRAY, not the hook name. Two earlier
	// gates were both wrong, in opposite directions:
	//
	//   - A bare search for 'wp-…' anywhere in a file read REST paths
	//     ('wp-json'), file paths ('wp-config', 'wp-login'), CSS prefixes
	//     ('wp-image-') and block names ('wp-block-navigation') as script
	//     handles — eight confident failures, all noise.
	//   - Filtering to files hooking enqueue_block_editor_assets silently
	//     dropped the pre-publish gate, which registers on admin_enqueue_scripts
	//     gated by hook suffix. That is the single most important editor file
	//     here, and the tool reported a clean 0 while never looking at it: a
	//     FALSE GREEN, which is worse than the noise.
	//
	// So: any file that registers scripts, and within it only array literals
	// whose members are ALL 'wp-*' strings — which is what a dependency array
	// is and what a path list is not.
	foreach ( (array) glob( $root . '/inc/*.php' ) as $file ) {
		$src = (string) file_get_contents( $file );
		if ( false === strpos( $src, 'wp_register_script' ) && false === strpos( $src, 'wp_enqueue_script' ) ) {
			continue;
		}
		if ( ! preg_match_all( "/array\(\s*((?:'wp-[a-z0-9-]+'\s*,?\s*)+)\)/", $src, $m ) ) {
			continue;
		}
		foreach ( $m[1] as $list ) {
			if ( ! preg_match_all( "/'(wp-[a-z0-9-]+)'/", $list, $inner ) ) {
				continue;
			}
			foreach ( $inner[1] as $handle ) {
				$handles[ $handle ][] = basename( $file );
			}
		}
	}
	foreach ( $handles as $handle => $files ) {
		$handles[ $handle ] = array_values( array_unique( $files ) );
	}

	// JS side: wp.<package>.<Symbol> usages in the shipped scripts.
	foreach ( (array) glob( $root . '/assets/*.js' ) as $file ) {
		$src = (string) file_get_contents( $file );
		if ( ! preg_match_all( '/\bwp\.([a-zA-Z][a-zA-Z0-9]*)\.([A-Za-z_][A-Za-z0-9_]*)/', $src, $m, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $m as $hit ) {
			$symbols[ $hit[1] ][ $hit[2] ][] = basename( $file );
		}
	}
	foreach ( $symbols as $pkg => $syms ) {
		foreach ( $syms as $sym => $files ) {
			$symbols[ $pkg ][ $sym ] = array_values( array_unique( $files ) );
		}
	}
	return array( 'handles' => $handles, 'symbols' => $symbols );
}

/**
 * Check the requirements against a WordPress tree.
 *
 * @param array  $req Requirements from sn_editor_requirements().
 * @param string $wp  WordPress root (the dir holding wp-includes/).
 * @return array{fails:string[],skipped:string[],checked:int}
 */
function sn_editor_check( array $req, $wp ) {
	$fails    = array();
	$skipped  = array();
	$checked  = 0;
	$packages = (string) file_get_contents( $wp . '/wp-includes/assets/script-loader-packages.php' );

	foreach ( $req['handles'] as $handle => $where ) {
		$file = substr( $handle, 3 ) . '.js'; // 'wp-editor' → 'editor.js'
		// A handle core publishes appears as a key in the packages manifest.
		if ( false === strpos( $packages, "'" . $file . "' =>" ) ) {
			// Not every wp-* handle is a package script (wp-api-fetch is, but
			// e.g. wp-util ships from wp-includes/js). Only fail when core has
			// no such script at all; otherwise say it was not checked here.
			if ( ! is_file( $wp . '/wp-includes/js/dist/' . $file ) && ! is_file( $wp . '/wp-includes/js/' . $file ) ) {
				$fails[] = sprintf(
					'script handle %s: core publishes no %s — the dependency would never load, so the script never runs (declared in %s).',
					$handle, $file, implode( ', ', array_unique( $where ) )
				);
				continue;
			}
			$skipped[] = "$handle (not a packages-manifest script)";
			continue;
		}
		$checked++;
	}

	foreach ( $req['symbols'] as $pkg => $syms ) {
		$dist = $wp . '/wp-includes/js/dist/' . sn_editor_pkg_file( $pkg ) . '.js';
		if ( ! is_file( $dist ) ) {
			// wp.<something> that is not a core package (our own globals, or a
			// nested access like wp.data.select). Reported, never failed.
			$skipped[] = "wp.$pkg (no core package of that name)";
			continue;
		}
		$src = (string) file_get_contents( $dist );
		foreach ( $syms as $sym => $files ) {
			$checked++;
			// The symbol must appear as a binding IN ITS OWN package. Scoping
			// matters: PluginPrePublishPanel appears in block-directory.js as an
			// import, so an unscoped search would pass even if core moved the
			// export out of wp.editor entirely.
			if ( ! preg_match( '/(^|[^A-Za-z0-9_$])' . preg_quote( $sym, '/' ) . '\s*[:=]/m', $src ) ) {
				$fails[] = sprintf(
					'wp.%s.%s: core\'s %s no longer binds %s — the call would throw in the editor (used in %s).',
					$pkg, $sym, sn_editor_pkg_file( $pkg ) . '.js', $sym, implode( ', ', $files )
				);
			}
		}
	}
	return array( 'fails' => $fails, 'skipped' => $skipped, 'checked' => $checked );
}

// ── Refuse to guess ──
if ( '' === $wp || ! is_dir( $wp ) ) {
	fwrite( STDERR, "editor-api-smoke: --wp=DIR is required and must exist — refusing to report green.\n" );
	exit( 2 );
}
if ( ! is_file( $wp . '/wp-includes/assets/script-loader-packages.php' ) || ! is_dir( $wp . '/wp-includes/js/dist' ) ) {
	fwrite( STDERR, "editor-api-smoke: $wp does not look like a WordPress tree (no packages manifest or js/dist) — refusing to report green.\n" );
	exit( 2 );
}
$wp_version = 'unknown';
if ( preg_match( "/\\\$wp_version = '([^']+)'/", (string) @file_get_contents( $wp . '/wp-includes/version.php' ), $vm ) ) {
	$wp_version = $vm[1];
}

// ── Self-test (negative control): the instrument must discriminate ──
if ( isset( $opts['self-test'] ) ) {
	$good = array(
		'handles' => array( 'wp-editor' => array( 'fixture' ), 'wp-plugins' => array( 'fixture' ) ),
		'symbols' => array( 'editor' => array( 'PluginPrePublishPanel' => array( 'fixture' ) ) ),
	);
	$bad_handle = array( 'handles' => array( 'wp-totally-not-a-package' => array( 'fixture' ) ), 'symbols' => array() );
	$bad_symbol = array( 'handles' => array(), 'symbols' => array( 'editor' => array( 'TotallyFakeSymbolXYZ' => array( 'fixture' ) ) ) );
	// Scoping control: a REAL symbol asked of the WRONG package must fail.
	$wrong_pkg  = array( 'handles' => array(), 'symbols' => array( 'plugins' => array( 'PluginPrePublishPanel' => array( 'fixture' ) ) ) );

	$r_good = sn_editor_check( $good, $wp );
	$ok1 = empty( $r_good['fails'] ) && $r_good['checked'] >= 3;
	$ok2 = ! empty( sn_editor_check( $bad_handle, $wp )['fails'] );
	$ok3 = ! empty( sn_editor_check( $bad_symbol, $wp )['fails'] );
	$ok4 = ! empty( sn_editor_check( $wrong_pkg, $wp )['fails'] );

	echo $ok1 ? "PASS: real handles + real symbol verify clean\n" : "FAIL: real requirements did not verify\n";
	echo $ok2 ? "PASS: a handle core does not publish is detected\n" : "FAIL: phantom handle NOT detected\n";
	echo $ok3 ? "PASS: a symbol core does not bind is detected\n" : "FAIL: phantom symbol NOT detected\n";
	echo $ok4 ? "PASS: a real symbol asked of the WRONG package is detected (scoping works)\n" : "FAIL: scoping is broken — an unscoped match would pass\n";
	echo "against WordPress $wp_version\n";
	exit( ( $ok1 && $ok2 && $ok3 && $ok4 ) ? 0 : 1 );
}

$req = sn_editor_requirements( $root );
if ( empty( $req['handles'] ) && empty( $req['symbols'] ) ) {
	fwrite( STDERR, "editor-api-smoke: derived NO requirements from this repo — the derivation broke; refusing to report green.\n" );
	exit( 2 );
}
$res = sn_editor_check( $req, $wp );

if ( isset( $opts['json'] ) ) {
	echo json_encode(
		array( 'wp_version' => $wp_version, 'checked' => $res['checked'], 'fails' => $res['fails'], 'skipped' => $res['skipped'] ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
} else {
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI
	// output. Guarded to PHP_SAPI 'cli' above; the destination is a terminal or
	// an Actions log, nothing loads WordPress so esc_html() does not exist, and
	// escaping for HTML would corrupt the findings. Scoped to reporting only,
	// mirroring tools/version-tag-parity.php and tools/stub-parity.php.
	foreach ( $res['fails'] as $f ) { echo "FAIL: $f\n"; }
	foreach ( array_unique( $res['skipped'] ) as $s ) { echo "skip: $s\n"; }
	printf(
		"\neditor-api-smoke: %d handle/symbol requirements verified against WordPress %s — %d failing.\n",
		$res['checked'], $wp_version, count( $res['fails'] )
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}
exit( $res['fails'] ? 1 : 0 );
