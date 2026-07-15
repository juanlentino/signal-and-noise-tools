<?php
/**
 * Plugin-footprint diagnostic + one-time legacy-file janitor.
 *
 * WHY: the live install has historically deployed via an SSH git checkout
 * (see .github/workflows/deploy.yml comments), so the plugin directory on
 * disk can still carry `.git/`, `tests/`, `docs/`, `phpstan-*`, and the
 * other dev/CI-only files listed in .gitattributes' export-ignore block —
 * none of which the tag-archive payload (the thing the self-updater
 * actually ships) contains. Web probes can't see any of this because
 * nginx 403s dotfiles, so the drift between "what's on disk" and "what
 * v9.42.1 ships" was invisible until now.
 *
 * This file ships two things, both scoped to the plugin's OWN directory —
 * never anything outside it:
 *
 *   1. OBSERVABILITY — a Site Health "Info" panel (snt_footprint) naming
 *      every top-level entry with its size, so an operator can always see
 *      the drift directly instead of guessing from "du -sh" over SSH.
 *
 *   2. A SAFE one-time JANITOR — deletes ONLY the hardcoded legacy
 *      manifest below, once per plugin version (admin_init + a
 *      snt_janitor_last sentinel option, the same pattern as
 *      inc/migrate-orphan-options.php). Everything in the manifest is
 *      reproducible from the GitHub repo (nothing here is unique,
 *      user-authored data), so nothing can be lost. Anything NOT on the
 *      manifest is only ever reported by the scan, never deleted.
 *
 * THE MANIFEST is the union of every top-level name that has EVER been
 * `export-ignore`d in .gitattributes across this repo's history (dev/CI
 * tooling that was never meant to ship), plus `.git` itself (which the
 * SSH-checkout deploy path leaves behind but which no .gitattributes rule
 * can ever list — a repo can't export-ignore its own control directory)
 * and `.planning` (pre-dates the .gitattributes cleanup, retired before an
 * export-ignore rule was ever added for it). Hardcoded rather than derived
 * at runtime from .gitattributes: the live install may not even HAVE a
 * `.git` checkout by the time this runs (a WP-admin-driven update replaces
 * the directory with the tag archive), so there is nothing to read the
 * rule from in production — the manifest has to be a fixed, audited list.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard ceiling on files walked in one scan; guards against a runaway tree. */
const SN_FOOTPRINT_SCAN_FILE_BUDGET = 50000;

/**
 * Cap on error strings persisted in snt_janitor_log. A full `.git` failure
 * (e.g. every file owned by a different user than the PHP process) appends
 * one error per file — thousands, on a live install. The option itself must
 * stay small; `errors_total` (uncapped) keeps the true count so the panel
 * can still say "…and N more" instead of silently truncating.
 */
const SN_JANITOR_ERRORS_STORED = 20;

/**
 * How long an errored sweep waits before self-re-arming on the SAME plugin
 * version (see sn_footprint_janitor_maybe_run()). A permission fix on the
 * server should heal within a day, not wait for the next release. Literal
 * seconds (== DAY_IN_SECONDS) rather than the WP constant: this file's
 * standalone CLI test harness never loads WP, so DAY_IN_SECONDS wouldn't
 * exist there.
 */
const SN_JANITOR_RETRY_INTERVAL_S = 86400;

/**
 * The hardcoded legacy-deploy manifest. See the file header for the
 * derivation. Root-relative, top-level names only — the janitor never
 * looks inside these for anything else to delete.
 *
 * @return string[]
 */
function sn_footprint_legacy_manifest() {
	return array(
		'.git',
		'.github',
		'.gitattributes',
		'.gitignore',
		'.gitleaks.toml',
		'.planning',
		'.pre-commit-config.yaml',
		'CHANGELOG.md',
		'composer.json',
		'composer.lock',
		'docs',
		'phpcs.xml.dist',
		'phpstan-baseline.neon',
		'phpstan-bootstrap.php',
		'phpstan.neon',
		'tests',
	);
}

/**
 * Recursively measure bytes + file count under $path against a shared file
 * budget. Symlinks are never followed — a symlink's OWN lstat size counts
 * as a single leaf, matching how the janitor deletes them (unlink the
 * link, never chase it into whatever it points at). Unreadable files/dirs
 * degrade to 0 rather than emitting a PHP warning (permission races,
 * something deleted mid-walk — this is a diagnostic, not a hard failure).
 *
 * @param string $path        Absolute path to measure.
 * @param int    $file_budget Remaining file budget, by reference (decremented).
 * @param bool   $truncated   Flips true once the budget is exhausted, by reference.
 * @return array{0:int,1:int} [ bytes, file_count ]
 */
function sn_footprint_measure( $path, &$file_budget, &$truncated ) {
	if ( $truncated || $file_budget <= 0 ) {
		$truncated = true;
		return array( 0, 0 );
	}

	// Checked BEFORE is_dir()/is_file(): both of those follow a symlink to
	// its target, which would defeat "never follow symlinks" entirely.
	if ( is_link( $path ) ) {
		$stat = @lstat( $path );
		--$file_budget;
		return array( $stat && isset( $stat['size'] ) ? (int) $stat['size'] : 0, 1 );
	}

	if ( is_dir( $path ) ) {
		if ( ! is_readable( $path ) ) {
			return array( 0, 0 );
		}
		$children = @scandir( $path );
		if ( ! is_array( $children ) ) {
			return array( 0, 0 );
		}
		$bytes = 0;
		$files = 0;
		foreach ( $children as $child ) {
			if ( '.' === $child || '..' === $child ) {
				continue;
			}
			if ( $truncated || $file_budget <= 0 ) {
				$truncated = true;
				break;
			}
			list( $child_bytes, $child_files ) = sn_footprint_measure( $path . '/' . $child, $file_budget, $truncated );
			$bytes                            += $child_bytes;
			$files                            += $child_files;
		}
		return array( $bytes, $files );
	}

	if ( is_file( $path ) && is_readable( $path ) ) {
		$size = @filesize( $path );
		--$file_budget;
		return array( false !== $size ? (int) $size : 0, 1 );
	}

	return array( 0, 0 );
}

/**
 * Scan $base's top-level entries: name, recursive byte size, recursive
 * file count, and whether the name is on the legacy manifest.
 *
 * @param string   $base        Absolute directory to scan (the plugin root in production).
 * @param int|null $file_budget Override the file-walk ceiling (tests only); null uses the real budget.
 * @return array{entries:array<int,array{name:string,bytes:int,files:int,is_legacy:bool}>,total_bytes:int,total_files:int,truncated:bool}
 */
function sn_footprint_scan( $base, $file_budget = null ) {
	$base   = rtrim( (string) $base, '/' );
	$legacy = sn_footprint_legacy_manifest();
	$budget = null === $file_budget ? SN_FOOTPRINT_SCAN_FILE_BUDGET : (int) $file_budget;

	$empty = array(
		'entries'     => array(),
		'total_bytes' => 0,
		'total_files' => 0,
		'truncated'   => false,
	);

	if ( '' === $base || ! is_dir( $base ) || ! is_readable( $base ) ) {
		return $empty;
	}

	clearstatcache();

	$top = @scandir( $base );
	if ( ! is_array( $top ) ) {
		return $empty;
	}
	sort( $top ); // deterministic ordering for the Site Health panel + tests.

	$entries     = array();
	$total_bytes = 0;
	$total_files = 0;
	$truncated   = false;

	foreach ( $top as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		if ( $truncated || $budget <= 0 ) {
			$truncated = true;
			break;
		}
		list( $bytes, $files ) = sn_footprint_measure( $base . '/' . $name, $budget, $truncated );
		$entries[]              = array(
			'name'      => $name,
			'bytes'     => $bytes,
			'files'     => $files,
			'is_legacy' => in_array( $name, $legacy, true ),
		);
		$total_bytes += $bytes;
		$total_files += $files;
	}

	return array(
		'entries'     => $entries,
		'total_bytes' => $total_bytes,
		'total_files' => $total_files,
		'truncated'   => $truncated,
	);
}

/**
 * Guard: is $name a safe, deletable, root-level entry directly inside $base?
 * Pure name-shape check + existence — does NOT itself delete anything.
 *
 * @param string $base Absolute base directory.
 * @param string $name Candidate root-relative entry name.
 * @return bool
 */
function sn_footprint_entry_deletable( $base, $name ) {
	if ( ! is_string( $name ) || '' === $name || '.' === $name ) {
		return false;
	}
	if ( false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) ) {
		return false;
	}
	if ( false !== strpos( $name, '..' ) ) {
		return false;
	}

	$base = rtrim( (string) $base, '/' );
	if ( '' === $base ) {
		return false;
	}

	// lstat, not stat/file_exists: a dangling symlink must still be
	// deletable (it exists as a link even though its target does not).
	return false !== @lstat( $base . '/' . $name );
}

/**
 * Delete one manifest entry ($base/$name). Symlinks lose only the link.
 * Real directories are walked with a containment check on every descent —
 * refuses and records an error rather than following a directory that has
 * been swapped for something resolving outside $base mid-walk.
 *
 * @param string   $base   Absolute base directory (already validated by the caller).
 * @param string   $name   Root-relative entry name (already validated by the caller).
 * @param string[] $errors Accumulator, by reference.
 * @return int Bytes actually freed.
 */
function sn_footprint_delete_entry( $base, $name, array &$errors ) {
	$base      = rtrim( (string) $base, '/' );
	$real_base = realpath( $base );
	$path      = $base . '/' . $name;

	if ( false === $real_base ) {
		$errors[] = "Refused to delete \"$name\": base path does not resolve.";
		return 0;
	}

	if ( is_link( $path ) ) {
		$stat  = @lstat( $path );
		$bytes = $stat && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		if ( ! @unlink( $path ) ) {
			$errors[] = "Failed to remove symlink \"$name\".";
			return 0;
		}
		return $bytes;
	}

	if ( is_file( $path ) ) {
		$size = @filesize( $path );
		if ( ! @unlink( $path ) ) {
			$errors[] = "Failed to remove file \"$name\".";
			return 0;
		}
		return false !== $size ? (int) $size : 0;
	}

	if ( is_dir( $path ) ) {
		return sn_footprint_delete_dir_recursive( $path, $real_base, $errors );
	}

	return 0; // Doesn't exist — the caller's presence check already filtered this out.
}

/**
 * Recursive directory delete with a containment check on every descent.
 *
 * @param string   $path      Absolute directory to remove.
 * @param string   $real_base realpath() of the trusted containment root.
 * @param string[] $errors    Accumulator, by reference.
 * @return int Bytes actually freed.
 */
function sn_footprint_delete_dir_recursive( $path, $real_base, array &$errors ) {
	$real_path = realpath( $path );
	if ( false === $real_path || 0 !== strpos( $real_path . '/', rtrim( $real_base, '/' ) . '/' ) ) {
		$errors[] = "Refused to descend into \"$path\": escapes the containment root.";
		return 0;
	}

	// SSH-checkout-era ownership can leave a dir the PHP user can't write
	// into (or, less commonly, can't even read) — heal it here rather than
	// erroring out on every child. A directory's OWN mode gates whether its
	// children can be unlinked, independent of each child's own mode.
	if ( ! is_writable( $path ) || ! is_readable( $path ) ) {
		@chmod( $path, 0755 );
	}

	$children = @scandir( $path );
	if ( ! is_array( $children ) ) {
		$errors[] = "Could not read directory \"$path\".";
		return 0;
	}

	$bytes = 0;
	foreach ( $children as $child ) {
		if ( '.' === $child || '..' === $child ) {
			continue;
		}
		$child_path = $path . '/' . $child;

		if ( is_link( $child_path ) ) {
			$stat = @lstat( $child_path );
			if ( @unlink( $child_path ) ) {
				$bytes += $stat && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			} else {
				$errors[] = "Failed to remove symlink \"$child_path\".";
			}
			continue;
		}

		if ( is_dir( $child_path ) ) {
			$bytes += sn_footprint_delete_dir_recursive( $child_path, $real_base, $errors );
			continue;
		}

		if ( is_file( $child_path ) ) {
			$size    = @filesize( $child_path );
			$removed = @unlink( $child_path );
			if ( ! $removed ) {
				// One heal-and-retry: fix the file's own mode bits (a
				// stubborn dir mode is already handled above) before
				// giving up and recording the error.
				@chmod( $child_path, 0644 );
				$removed = @unlink( $child_path );
			}
			if ( $removed ) {
				$bytes += false !== $size ? (int) $size : 0;
			} else {
				$errors[] = "Failed to remove file \"$child_path\".";
			}
		}
	}

	if ( ! @rmdir( $path ) ) {
		$errors[] = "Failed to remove directory \"$path\" after clearing its contents.";
	}

	return $bytes;
}

/**
 * Run the janitor once against $base: for every manifest entry that is
 * PRESENT, measure + delete it (recursive for real dirs, unlink for
 * files/symlinks). Absent entries are silent no-ops. Never touches
 * anything that isn't on the manifest.
 *
 * @param string $base Absolute directory to sweep (the plugin root in production).
 * @return array{deleted:array<string,int>,freed_bytes:int,errors:string[]}
 */
function sn_janitor_run( $base ) {
	$base    = rtrim( (string) $base, '/' );
	$deleted = array();
	$errors  = array();
	$freed   = 0;

	foreach ( sn_footprint_legacy_manifest() as $name ) {
		if ( ! sn_footprint_entry_deletable( $base, $name ) ) {
			continue; // Not present (or fails the guard) — silent no-op.
		}
		$bytes           = sn_footprint_delete_entry( $base, $name, $errors );
		$deleted[ $name ] = $bytes;
		$freed           += $bytes;
	}

	return array(
		'deleted'     => $deleted,
		'freed_bytes' => $freed,
		'errors'      => $errors,
	);
}

/**
 * Minimal byte formatter (mirrors size_format()'s output shape: binary
 * units, short decimals) that doesn't depend on WP being loaded, so the
 * Site Health panel and this file's own tests render identical strings.
 *
 * @param int $bytes
 * @return string
 */
function sn_footprint_format_bytes( $bytes ) {
	$bytes = max( 0, (int) $bytes );
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$i     = 0;
	$value = (float) $bytes;
	while ( $value >= 1024 && $i < count( $units ) - 1 ) {
		$value /= 1024;
		++$i;
	}
	$decimals = ( 0 === $i || $value >= 100 ) ? 0 : 1;
	return number_format( $value, $decimals ) . ' ' . $units[ $i ];
}

if ( function_exists( 'add_action' ) ) {

	add_action( 'admin_init', 'sn_footprint_janitor_maybe_run' );
	add_filter( 'debug_information', 'sn_footprint_debug_information' );

	/**
	 * admin_init callback: run the janitor once per plugin version. Uses the
	 * same sentinel-option pattern as inc/migrate-orphan-options.php — an
	 * install-time hook can't self-observe, so admin_init + a version
	 * sentinel is what actually catches SSH-checkout deploys AND covers
	 * post-update strays (the sentinel is keyed on version, not on whether
	 * the manifest is currently empty, so it re-sweeps every time the
	 * version changes even if a prior sweep already ran).
	 *
	 * $base and $version are only ever overridden by tests — the injected
	 * default is what runs in production (plugin root via SNT_PATH; the
	 * live SNT_VERSION). $run_override is ALSO test-only: when given, it
	 * stands in for the sn_janitor_run() result outright, so tests can
	 * drive the storage/gate logic (A1/A2) with a canned outcome instead
	 * of needing a real filesystem failure (which A4's chmod healing
	 * would routinely undo for any owner-chmodable fixture).
	 *
	 * The gate: run when the version sentinel doesn't match (unchanged), OR
	 * — even on a version match — when the last stored sweep recorded
	 * errors and is more than SN_JANITOR_RETRY_INTERVAL_S old. That lets a
	 * server-side permission fix self-heal within a day instead of sitting
	 * broken until the next release re-opens the version gate. A fresh
	 * errored log (or a clean log, of any age) still skips — this must NOT
	 * become a run-on-every-admin_init check.
	 *
	 * @param string|null $base         Override for tests; defaults to the plugin root.
	 * @param string|null $version      Override for tests; defaults to SNT_VERSION.
	 * @param array|null  $run_override Override for tests; stands in for the sn_janitor_run() result.
	 * @return array{deleted:array<string,int>,freed_bytes:int,errors:string[]}|null The sweep result, or null when the gate skipped the run.
	 */
	function sn_footprint_janitor_maybe_run( $base = null, $version = null, $run_override = null ) {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return null;
		}

		$version = null !== $version ? $version : ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '' );
		if ( get_option( 'snt_janitor_last' ) === $version && ! sn_footprint_janitor_should_retry( get_option( 'snt_janitor_log' ) ) ) {
			return null;
		}

		$base   = null !== $base ? $base : ( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __FILE__, 2 ) . '/' );
		$result = null !== $run_override ? $run_override : sn_janitor_run( rtrim( (string) $base, '/' ) );

		update_option( 'snt_janitor_last', $version, false );

		update_option(
			'snt_janitor_log',
			array(
				'version'      => $version,
				'freed_bytes'  => $result['freed_bytes'],
				'deleted'      => $result['deleted'],
				'errors'       => array_slice( $result['errors'], 0, SN_JANITOR_ERRORS_STORED ),
				'errors_total' => count( $result['errors'] ),
				'time'         => current_time( 'timestamp' ),
			),
			false
		);

		return $result;
	}

	/**
	 * Should an errored sweep re-arm despite the version sentinel matching?
	 * Pure predicate over an already-fetched snt_janitor_log value — kept
	 * separate from sn_footprint_janitor_maybe_run() so "skip on a fresh
	 * errored log" (no run-on-every-admin_init) is a single, obviously
	 * testable branch.
	 *
	 * @param mixed $log The stored snt_janitor_log option value (or false/anything else if unset).
	 * @return bool
	 */
	function sn_footprint_janitor_should_retry( $log ) {
		if ( ! is_array( $log ) || empty( $log['errors_total'] ) || ! isset( $log['time'] ) ) {
			return false;
		}
		return ( current_time( 'timestamp' ) - (int) $log['time'] ) >= SN_JANITOR_RETRY_INTERVAL_S;
	}

	/**
	 * debug_information filter: the "Signal & Noise — plugin footprint"
	 * Site Health panel. $base is only ever overridden by tests; production
	 * always resolves the real plugin root.
	 *
	 * @param array       $info Core's accumulated debug-info panels.
	 * @param string|null $base Override for tests; defaults to the plugin root.
	 * @return array
	 */
	function sn_footprint_debug_information( $info, $base = null ) {
		$base = null !== $base ? $base : ( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __FILE__, 2 ) . '/' );
		$scan = sn_footprint_scan( rtrim( (string) $base, '/' ) );

		$fields                = array();
		$fields['total_size']  = array(
			'label' => __( 'Total plugin-directory size', 'signal-and-noise-tools' ),
			'value' => sn_footprint_format_bytes( $scan['total_bytes'] )
				. ( $scan['truncated'] ? ' (' . __( 'scan truncated at the file budget', 'signal-and-noise-tools' ) . ')' : '' ),
		);

		foreach ( $scan['entries'] as $entry ) {
			$value = sn_footprint_format_bytes( $entry['bytes'] );
			if ( $entry['is_legacy'] ) {
				$value .= ' — ' . __( 'legacy deploy leftover', 'signal-and-noise-tools' );
			}
			$key             = 'entry_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $entry['name'] ) );
			$fields[ $key ]  = array(
				'label'   => $entry['name'],
				'value'   => $value,
				'private' => true, // filenames are internal-layout detail, not user-facing content.
			);
		}

		// Every past sweep gets a row now (A1: the option is written
		// unconditionally, "nothing to remove" included) — so presence is
		// keyed on the log existing at all, not on freed_bytes being
		// truthy (a real, error-free sweep can legitimately free 0 bytes).
		$log = get_option( 'snt_janitor_log' );
		if ( is_array( $log ) && isset( $log['version'] ) ) {
			$version_str  = (string) $log['version'];
			$freed        = (int) ( $log['freed_bytes'] ?? 0 );
			$errors_total = (int) ( $log['errors_total'] ?? 0 );

			if ( $freed > 0 ) {
				$value = sprintf(
					/* translators: 1: freed size (e.g. "3.2 MB"), 2: plugin version the sweep ran on. */
					__( 'freed %1$s on v%2$s', 'signal-and-noise-tools' ),
					sn_footprint_format_bytes( $freed ),
					$version_str
				);
				if ( $errors_total > 0 ) {
					$value .= sprintf(
						/* translators: %d: number of errors encountered during the sweep. */
						__( ', %d error(s)', 'signal-and-noise-tools' ),
						$errors_total
					);
				}
			} elseif ( $errors_total > 0 ) {
				$value = sprintf(
					/* translators: 1: plugin version the sweep ran on, 2: number of errors encountered. */
					__( 'removed nothing on v%1$s — %2$d error(s)', 'signal-and-noise-tools' ),
					$version_str,
					$errors_total
				);
			} else {
				$value = sprintf(
					/* translators: %s: plugin version the sweep ran on. */
					__( 'nothing to remove (v%s)', 'signal-and-noise-tools' ),
					$version_str
				);
			}

			$fields['janitor_log'] = array(
				'label' => __( 'Last janitor sweep', 'signal-and-noise-tools' ),
				'value' => $value,
			);

			// The stored 'errors' array is already capped at
			// SN_JANITOR_ERRORS_STORED (A2); errors_total is the true
			// pre-cap count, so "…and N more" only appears when it
			// exceeds what's actually listed.
			$stored_errors = is_array( $log['errors'] ?? null ) ? $log['errors'] : array();
			if ( ! empty( $stored_errors ) ) {
				$parts = $stored_errors;
				if ( $errors_total > count( $stored_errors ) ) {
					$parts[] = sprintf(
						/* translators: %d: number of additional errors beyond the stored cap. */
						__( '…and %d more', 'signal-and-noise-tools' ),
						$errors_total - count( $stored_errors )
					);
				}
				$fields['janitor_errors'] = array(
					'label'   => __( 'Janitor sweep errors', 'signal-and-noise-tools' ),
					'value'   => implode( ' | ', $parts ),
					'private' => true, // paths are internal layout, not user-facing content.
				);
			}
		}

		$info['snt_footprint'] = array(
			'label'       => __( 'Signal & Noise — plugin footprint', 'signal-and-noise-tools' ),
			'description' => __( 'Top-level plugin-directory entries and their sizes, plus the legacy-deploy janitor status.', 'signal-and-noise-tools' ),
			'fields'      => $fields,
		);

		return $info;
	}
}
