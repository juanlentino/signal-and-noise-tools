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
		'phpstan.neon.dist',
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
			$size = @filesize( $child_path );
			if ( @unlink( $child_path ) ) {
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
	 * live SNT_VERSION).
	 *
	 * @param string|null $base    Override for tests; defaults to the plugin root.
	 * @param string|null $version Override for tests; defaults to SNT_VERSION.
	 * @return array{deleted:array<string,int>,freed_bytes:int,errors:string[]}|null The sweep result, or null when the gate skipped the run.
	 */
	function sn_footprint_janitor_maybe_run( $base = null, $version = null ) {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return null;
		}

		$version = null !== $version ? $version : ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '' );
		if ( get_option( 'snt_janitor_last' ) === $version ) {
			return null;
		}

		$base   = null !== $base ? $base : ( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __FILE__, 2 ) . '/' );
		$result = sn_janitor_run( rtrim( (string) $base, '/' ) );

		update_option( 'snt_janitor_last', $version, false );

		if ( $result['freed_bytes'] > 0 ) {
			update_option(
				'snt_janitor_log',
				array(
					'version'     => $version,
					'freed_bytes' => $result['freed_bytes'],
					'deleted'     => $result['deleted'],
					'errors'      => $result['errors'],
					'time'        => current_time( 'timestamp' ),
				),
				false
			);
		}

		return $result;
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

		$log = get_option( 'snt_janitor_log' );
		if ( is_array( $log ) && ! empty( $log['freed_bytes'] ) ) {
			$fields['janitor_log'] = array(
				'label' => __( 'Last janitor sweep', 'signal-and-noise-tools' ),
				'value' => sprintf(
					/* translators: 1: freed size (e.g. "3.2 MB"), 2: plugin version the sweep ran on. */
					__( 'freed %1$s on v%2$s', 'signal-and-noise-tools' ),
					sn_footprint_format_bytes( (int) $log['freed_bytes'] ),
					(string) ( $log['version'] ?? '' )
				),
			);
		}

		$info['snt_footprint'] = array(
			'label'       => __( 'Signal & Noise — plugin footprint', 'signal-and-noise-tools' ),
			'description' => __( 'Top-level plugin-directory entries and their sizes, plus the legacy-deploy janitor status.', 'signal-and-noise-tools' ),
			'fields'      => $fields,
		);

		return $info;
	}
}
