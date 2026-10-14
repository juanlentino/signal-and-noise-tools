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

if ( ! function_exists( 'snt_test_mcp_telemetry_src' ) ) {
	/**
	 * The source of inc/mcp/mcp-telemetry.php AND its three parts files, for
	 * suites that pin the module's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: mcp-telemetry-*-parts.php would also pull in
	 * mcp-telemetry-read-parts.php, a separate module. A missing file returns ''
	 * so every presence pin goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_mcp_telemetry_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/mcp/';
		$src = '';
		foreach ( array( '', '-shape-parts', '-classify-parts', '-live-parts' ) as $suffix ) {
			$file = $dir . 'mcp-telemetry' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_analytics_view_overview_src' ) ) {
	/**
	 * The source of inc/analytics-view-overview.php AND its three parts files,
	 * for suites that pin the view's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: no other module shares the prefix today, and a future
	 * one would not belong in the read. A missing file returns '' so every
	 * presence pin goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_analytics_view_overview_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-model-parts', '-panels-parts', '-render-parts' ) as $suffix ) {
			$file = $dir . 'analytics-view-overview' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_cron_dashboard_src' ) ) {
	/**
	 * The source of inc/cron-dashboard.php AND its parts files, for suites that
	 * pin the module's text. Split 2026-10-14 (house line cap); reading the shell
	 * alone would turn every pin on moved code red, and an absence pin vacuous.
	 * Not cron-dashboard-admin.php, a separate module. Fewer than the four files
	 * returns '' so every presence pin goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_cron_dashboard_src() {
		$files = array_merge( snt_test_inc_files( 'cron-dashboard.php' ), snt_test_inc_files( 'cron-dashboard-*-parts.php' ) );
		return count( $files ) >= 4 ? implode( "\n", array_map( 'file_get_contents', $files ) ) : '';
	}
}

if ( ! function_exists( 'snt_test_insights_src' ) ) {
	/**
	 * The source of inc/insights.php AND its three parts files, for suites that
	 * pin the module's text. Split 2026-10-14 (house line cap). Named, not
	 * globbed: insights-admin.php, insights-narration.php and the other
	 * insights-*.php files are separate modules. A missing file returns '' so
	 * every presence pin goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_insights_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-signals-parts', '-ai-parts', '-state-parts' ) as $suffix ) {
			$file = $dir . 'insights' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}
