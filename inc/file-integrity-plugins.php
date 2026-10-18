<?php
/**
 * Signal & Noise Tools: file integrity, the plugin half (2026-10-18). Which
 * plugins wordpress.org can vouch for, and the per-plugin compare. The run is
 * in inc/file-integrity.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which installed plugins wordpress.org knows: plugin file => slug. Read from
 * core's update_plugins transient; when that is cold, folders without an
 * Update URI are tried by folder name (a 404 then makes them not checkable).
 *
 * @param array      $plugins get_plugins().
 * @param mixed|null $updates The update_plugins transient.
 * @return array<string,string>
 */
function snt_fi_plugin_targets( array $plugins, $updates ) {
	$known = array();
	if ( is_object( $updates ) ) {
		foreach ( array( 'response', 'no_update' ) as $k ) {
			foreach ( (array) ( $updates->$k ?? array() ) as $file => $row ) {
				$slug = is_object( $row ) ? ( $row->slug ?? '' ) : ( $row['slug'] ?? '' );
				if ( '' !== (string) $slug ) {
					$known[ $file ] = (string) $slug;
				}
			}
		}
	}
	$targets = array();
	foreach ( $plugins as $file => $head ) {
		if ( '.' === dirname( $file ) || 'signal-and-noise-tools/signal-and-noise-tools.php' === $file ) {
			continue;
		}
		if ( isset( $known[ $file ] ) ) {
			$targets[ $file ] = $known[ $file ];
		} elseif ( ! is_object( $updates ) && '' === (string) ( $head['UpdateURI'] ?? '' ) ) {
			$targets[ $file ] = dirname( $file );
		}
	}
	return $targets;
}

/**
 * Compare one plugin's files with its wordpress.org checksums. Files the list
 * names but the install lacks are skipped (readmes are often removed).
 *
 * @param array  $files  relative path => {md5, sha256}; either may be a list.
 * @param string $dir    The plugin's folder.
 * @param string $prefix Prefix for the stored relative paths.
 * @param array  $budget Budget, spent by reference.
 * @return array{checked:int,modified:int,complete:bool,paths:string[]}
 */
function snt_fi_plugin_scan( array $files, $dir, $prefix, array &$budget ) {
	$out = array( 'checked' => 0, 'modified' => 0, 'complete' => false, 'paths' => array() );
	foreach ( $files as $rel => $h ) {
		if ( ! is_file( "$dir/$rel" ) ) {
			continue;
		}
		if ( ! snt_fi_spend( $budget ) ) {
			return $out;
		}
		++$out['checked'];
		$algo = empty( $h['sha256'] ) ? 'md5' : 'sha256';
		if ( ! snt_fi_matches( "$dir/$rel", $h[ $algo ] ?? '', $algo ) ) {
			++$out['modified'];
			$out['paths'][] = $prefix . $rel;
		}
	}
	$out['complete'] = true;
	return $out;
}
