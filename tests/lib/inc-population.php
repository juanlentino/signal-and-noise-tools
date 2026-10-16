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

if ( ! function_exists( 'snt_test_provenance_integrity_src' ) ) {
	/**
	 * The source of inc/provenance-integrity.php AND its three parts files, for
	 * suites that pin the module's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: no other module shares the prefix today, and a future
	 * one would not belong in the read. A missing file returns '' so every
	 * presence pin goes red and every guarded absence pin fails rather than
	 * passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_provenance_integrity_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-fetch-parts', '-sweep-parts', '-report-parts' ) as $suffix ) {
			$file = $dir . 'provenance-integrity' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_provenance_core_src' ) ) {
	/**
	 * The source of inc/provenance-core.php AND its four parts files, for suites
	 * that pin the module's text. Split 2026-10-15 (house line cap). Named, not
	 * globbed: no other module shares the prefix today, and a future one would
	 * not belong in the read. A missing file returns '' so every presence pin
	 * goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_provenance_core_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-normalize-parts', '-chain-parts', '-record-parts', '-subject-parts' ) as $suffix ) {
			$file = $dir . 'provenance-core' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_wp_update_integration_src' ) ) {
	/**
	 * The source of inc/wp-update-integration.php AND its three parts files, for
	 * suites that pin the self-updater's text. Split 2026-10-15 (house line cap).
	 * Named, not globbed: a future wp-update-integration-* module would not
	 * belong in the read. A missing file returns '' so every presence pin goes
	 * red and every guarded absence pin fails rather than passing on a partial
	 * read.
	 *
	 * @return string
	 */
	function snt_test_wp_update_integration_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-fetch-parts', '-offer-parts', '-install-parts' ) as $suffix ) {
			$file = $dir . 'wp-update-integration' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
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

if ( ! function_exists( 'snt_test_ml_kernel_src' ) ) {
	/**
	 * The source of inc/ml-kernel.php AND its four parts files, for suites that
	 * pin the kernel's text (its purity pins are absence pins, so a read of the
	 * shell alone would pass them vacuously). Split 2026-10-14 (house line cap).
	 * Named, not globbed: a future ml-kernel-* module would not belong in the
	 * read. A missing file returns '' so every presence pin goes red and every
	 * guarded absence pin fails rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_ml_kernel_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-text-parts', '-graph-parts', '-cadence-parts', '-drift-parts' ) as $suffix ) {
			$file = $dir . 'ml-kernel' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_cloudflare_purge_src' ) ) {
	/**
	 * The source of inc/cloudflare-purge.php AND its three parts files, for
	 * suites that pin the purge module's text (the Cloudflare tab, the save
	 * hook, the API calls). Split 2026-10-15 (house line cap). Named, not
	 * globbed: cloudflare-purge-send.php, -save.php, -words.php, -probe.php and
	 * the rest share the prefix and are separate modules with their own reads.
	 * A missing file returns '' so every presence pin goes red and every
	 * guarded absence pin fails rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_cloudflare_purge_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-api-parts', '-post-parts', '-tab-parts' ) as $suffix ) {
			$file = $dir . 'cloudflare-purge' . $suffix . '.php';
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

if ( ! function_exists( 'snt_test_edge_rollup_src' ) ) {
	/**
	 * The source of inc/edge-rollup.php AND its four parts files, for suites
	 * that pin or extract the module's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: a future edge-rollup-* module would not belong in the
	 * read. A missing file returns '' so every presence pin goes red rather
	 * than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_edge_rollup_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-schema-parts', '-upsert-parts', '-run-parts', '-errors-parts' ) as $suffix ) {
			$file = $dir . 'edge-rollup' . $suffix . '.php';
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

if ( ! function_exists( 'snt_test_attention_readers_src' ) ) {
	/**
	 * The source of apps/signal-noise/parts/attention-readers.php AND its four
	 * parts files (an app, not inc/), for suites that pin the readers' text.
	 * Split 2026-10-14 (house line cap). Named, not globbed: attention-*.php
	 * also matches the composition half, which the seam pin must keep out of
	 * this read. A missing file returns '' so every presence pin goes red and
	 * every guarded absence pin fails rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_attention_readers_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/apps/signal-noise/parts/';
		$src = '';
		foreach ( array( '', '-integrity-parts', '-edge-parts', '-schedule-parts', '-search-parts' ) as $suffix ) {
			$file = $dir . 'attention-readers' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_maturity_roadmap_shortcode_src' ) ) {
	/**
	 * The source of inc/maturity-roadmap-shortcode.php AND its three parts files,
	 * for suites that pin the module's text. Split 2026-10-15 (house line cap).
	 * Named, not globbed: maturity-roadmap-merge.php and the other maturity-*.php
	 * files are separate modules. A missing file returns '' so every presence pin
	 * goes red and every guarded absence pin fails rather than passing on a
	 * partial read.
	 *
	 * @return string
	 */
	function snt_test_maturity_roadmap_shortcode_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-board-parts', '-gate-parts', '-render-parts' ) as $suffix ) {
			$file = $dir . 'maturity-roadmap-shortcode' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_sn_scan_adapters_src' ) ) {
	/**
	 * The source of inc/sn-scan-adapters.php AND its three parts files, for
	 * suites that pin the adapters' text. Split 2026-10-15 (house line cap).
	 * Named, not globbed: a future sn-scan-adapters-* module would not belong in
	 * the read. A missing file returns '' so every presence pin goes red rather
	 * than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_sn_scan_adapters_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-structure-parts', '-links-media-parts', '-emdash-tags-parts' ) as $suffix ) {
			$file = $dir . 'sn-scan-adapters' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_analytics_rollup_src' ) ) {
	/**
	 * The source of inc/analytics-rollup.php AND its four parts files, for
	 * suites that pin the data layer's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: a future analytics-rollup-* module would not belong
	 * in the read. A missing file returns '' so every presence pin goes red and
	 * every guarded absence pin fails rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_analytics_rollup_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-schema-parts', '-gated-parts', '-upsert-parts', '-run-parts' ) as $suffix ) {
			$file = $dir . 'analytics-rollup' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_attention_src' ) ) {
	/**
	 * The source of apps/signal-noise/parts/attention.php AND its four parts
	 * files (an app, not inc/), for suites that pin the composition's text.
	 * Split 2026-10-14 (house line cap). Named, not globbed: attention-*.php
	 * also matches attention-readers.php and its parts, the other half of the
	 * seam the attention suite measures. A missing file returns '' so every
	 * presence pin goes red and every guarded absence pin fails rather than
	 * passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_attention_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/apps/signal-noise/parts/';
		$src = '';
		foreach ( array( '', '-vocab-parts', '-shape-parts', '-compose-parts', '-items-parts' ) as $suffix ) {
			$file = $dir . 'attention' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
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

if ( ! function_exists( 'snt_test_desktop_status_extra_src' ) ) {
	/**
	 * The source of inc/desktop-mode-status-extra.php AND its three parts files,
	 * for suites that pin the module's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: the other desktop-mode-*.php files are separate
	 * modules. A missing file returns '' so every presence pin goes red and
	 * every guarded absence pin fails rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_desktop_status_extra_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-edge-parts', '-reads-parts', '-rows-parts' ) as $suffix ) {
			$file = $dir . 'desktop-mode-status-extra' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_seo_src' ) ) {
	/**
	 * The source of inc/seo.php AND its four parts files, for suites that pin
	 * the module's text. Split 2026-10-14 (house line cap). Named, not globbed:
	 * seo-schema.php, seo-schema-music.php and seo-validators.php are separate
	 * modules (seo-live-audit.php counts a needle across seo AND schema, so a
	 * glob would double it). A missing file returns '' so every presence pin
	 * goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_seo_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/';
		$src = '';
		foreach ( array( '', '-meta-parts', '-head-parts', '-og-parts', '-delivery-parts' ) as $suffix ) {
			$file = $dir . 'seo' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}

if ( ! function_exists( 'snt_test_sn_apply_block_edit_src' ) ) {
	/**
	 * The source of inc/sn-apply/block-edit.php AND its two parts files, for
	 * suites that pin the module's text. Split 2026-10-14 (house line cap).
	 * Named, not globbed: no other module shares the prefix today, and a future
	 * one would not belong in the read. A missing file returns '' so every
	 * presence pin goes red rather than passing on a partial read.
	 *
	 * @return string
	 */
	function snt_test_sn_apply_block_edit_src() {
		$dir = dirname( dirname( __DIR__ ) ) . '/inc/sn-apply/';
		$src = '';
		foreach ( array( '', '-resolve-parts', '-compute-parts' ) as $suffix ) {
			$file = $dir . 'block-edit' . $suffix . '.php';
			if ( ! is_file( $file ) ) {
				return '';
			}
			$src .= file_get_contents( $file ) . "\n";
		}
		return $src;
	}
}
