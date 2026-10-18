<?php
/**
 * Signal & Noise Tools: file integrity, read-only (2026-10-18).
 *
 * Nothing compared installed files with checksums: core verifies them only
 * while it updates itself, and Site Health has no such test (checked in 7.2
 * trunk). Once a day this compares core with core's own get_core_checksums()
 * and plugins hosted on wordpress.org with the plugin checksums API. Plugins
 * not on wordpress.org (core leaves them out of its update check, which is how
 * this site's private plugins are skipped) count as "not checkable".
 *
 * The scan and run live here, with their sources injected so the tests run on
 * a real temp tree. Live sources, the watch and the schedule are in
 * inc/file-integrity-watch.php; plugin targets and the per-plugin compare in
 * inc/file-integrity-plugins.php. Stores counts and relative paths only.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_FI_HOOK     = 'sn_file_integrity_check';
const SN_FI_OPT      = 'sn_file_integrity_last';
const SN_FI_PATH_CAP = 20;

/** Spend one unit of the budget; false once files or time run out. */
function snt_fi_spend( array &$budget ) {
	if ( $budget['left'] <= 0 || microtime( true ) > $budget['until'] ) {
		return false;
	}
	--$budget['left'];
	return true;
}

/** Whether a file matches any of the expected hashes (md5 or sha256). */
function snt_fi_matches( $path, $expected, $algo ) {
	// The algorithm is whatever wordpress.org publishes: md5 for core.
	return in_array( hash_file( $algo, $path ), (array) $expected, true );
}

/**
 * Compare core files with core's checksums, then look for PHP files in
 * wp-admin/ and wp-includes/ that core does not ship.
 *
 * @param array  $sums   relative path => md5, from get_core_checksums().
 * @param string $root   ABSPATH.
 * @param array  $budget Budget, spent by reference.
 * @return array{checked:int,modified:int,missing:int,added:int,complete:bool,paths:string[]}
 */
function snt_fi_core_scan( array $sums, $root, array &$budget ) {
	$out  = array( 'checked' => 0, 'modified' => 0, 'missing' => 0, 'added' => 0, 'complete' => false, 'paths' => array() );
	$root = rtrim( (string) $root, '/' );
	foreach ( $sums as $rel => $md5 ) {
		if ( 0 === strpos( (string) $rel, 'wp-content/' ) ) {
			continue; // Bundled themes and plugins update on their own.
		}
		if ( ! snt_fi_spend( $budget ) ) {
			return $out;
		}
		++$out['checked'];
		$path = "$root/$rel";
		if ( ! is_file( $path ) ) {
			++$out['missing'];
		} elseif ( ! snt_fi_matches( $path, $md5, 'md5' ) ) {
			++$out['modified'];
			$out['paths'][] = (string) $rel;
		}
	}
	foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
		if ( ! is_dir( "$root/$dir" ) ) {
			continue;
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( ! snt_fi_spend( $budget ) ) {
				return $out;
			}
			$rel = substr( $file->getPathname(), strlen( $root ) + 1 );
			if ( 'php' === strtolower( $file->getExtension() ) && ! isset( $sums[ $rel ] ) ) {
				++$out['added'];
				$out['paths'][] = $rel;
			}
		}
	}
	$out['complete'] = true;
	return $out;
}

/**
 * One run. Sources are injected (see snt_fi_live_deps()).
 *
 * @param array $d Dependencies.
 * @return array The stored result.
 */
function snt_fi_run( array $d ) {
	$budget = array( 'left' => (int) $d['max_files'], 'until' => microtime( true ) + (float) $d['seconds'] );
	$sums   = call_user_func( $d['core_checksums'], $d['version'], $d['locale'] );
	$core   = is_array( $sums ) && $sums ? snt_fi_core_scan( $sums, $d['root'], $budget ) : array( 'checked' => 0, 'modified' => 0, 'missing' => 0, 'added' => 0, 'complete' => false, 'paths' => array(), 'error' => 'core checksums could not be fetched' );
	$paths  = $core['paths'];
	unset( $core['paths'] );
	$plugins = (array) call_user_func( $d['plugins'] );
	$targets = snt_fi_plugin_targets( $plugins, call_user_func( $d['updates'] ) );
	$plug    = array( 'checked' => 0, 'modified' => 0, 'not_checkable' => count( $plugins ) - count( $targets ), 'errors' => 0, 'unfinished' => 0 );
	foreach ( $targets as $file => $slug ) {
		if ( ! $core['complete'] || ! snt_fi_spend( $budget ) ) { // The time cap also gates each fetch.
			++$plug['unfinished'];
			continue;
		}
		$files = call_user_func( $d['plugin_checksums'], $slug, (string) ( $plugins[ $file ]['Version'] ?? '' ) );
		if ( null === $files ) {
			++$plug['not_checkable'];
			continue;
		}
		if ( ! is_array( $files ) ) {
			++$plug['errors'];
			continue;
		}
		$scan = snt_fi_plugin_scan( $files, rtrim( (string) $d['plugin_dir'], '/' ) . '/' . dirname( $file ), 'plugins/' . dirname( $file ) . '/', $budget );

		$plug['checked']    += $scan['checked'];
		$plug['modified']   += $scan['modified'];
		$plug['unfinished'] += $scan['complete'] ? 0 : 1;

		$paths = array_merge( $paths, $scan['paths'] );
	}
	$out = array( 'at' => (int) $d['now'], 'core' => $core, 'plugins' => $plug, 'paths' => array_slice( $paths, 0, SN_FI_PATH_CAP ) );
	update_option( SN_FI_OPT, $out, false );
	return $out;
}
