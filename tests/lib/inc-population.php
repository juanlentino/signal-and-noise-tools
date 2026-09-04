<?php
/**
 * Shared population helper for guards that sweep the plugin's PHP sources.
 *
 * NOT a suite - tests/run.sh globs tests/*.php non-recursively, so nothing in
 * tests/lib/ is swept. Suites require this file.
 *
 * Why it exists: twelve guards enumerated `inc/*.php`, which is the TOP of inc/
 * and nothing below it. 86 files - 17% of the tree - live in packages
 * (inc/sn-apply/, inc/mcp/, inc/content-migrations/, inc/admin-forms/,
 * inc/admin-post-actions/, inc/ai-bootstrap/) and were invisible to all of
 * them. None of the guards announced the narrowing: fieldset-actions-inline.php
 * printed "PASS: no banned inline styles in inc/*.php" with a live violation
 * sitting in inc/sn-apply/. See issue #987.
 *
 * The population is WALKED rather than pattern-matched so it cannot need
 * re-widening by hand every time a package is created.
 *
 * @since 13.95.3
 */

if ( ! function_exists( 'snt_test_inc_files' ) ) {
	/**
	 * Every PHP file under inc/, at any depth.
	 *
	 * @param string $basename_glob fnmatch pattern applied to the BASENAME,
	 *                              e.g. 'abilities-*.php'. Default all PHP.
	 * @return string[] Absolute paths, sorted - a stable order matters because
	 *                  several callers print the first offender they find.
	 */
	function snt_test_inc_files( $basename_glob = '*.php' ) {
		$base = dirname( dirname( __DIR__ ) ) . '/inc';
		if ( ! is_dir( $base ) ) {
			return array();
		}

		$found = array();
		$walk  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $walk as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( ! fnmatch( $basename_glob, $name ) ) {
				continue;
			}
			$found[] = (string) $file->getPathname();
		}
		sort( $found );

		return $found;
	}
}

if ( ! function_exists( 'snt_test_inc_packages' ) ) {
	/**
	 * The subdirectories of inc/ that hold PHP, as basenames.
	 *
	 * Callers use this to assert their population actually REACHED the packages
	 * rather than merely being large. A count cannot tell a complete set from a
	 * truncated one; a depth check can.
	 *
	 * @return string[] Sorted package basenames.
	 */
	function snt_test_inc_packages() {
		$base = dirname( dirname( __DIR__ ) ) . '/inc';
		$out  = array();
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( glob( $dir . '/*.php' ) ) {
				$out[] = basename( (string) $dir );
			}
		}
		sort( $out );

		return $out;
	}
}
