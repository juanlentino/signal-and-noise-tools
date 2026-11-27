<?php
/**
 * tools/wp-boot only: the php -S router for the local boot (127.0.0.1). Real
 * files as themselves, everything else through WordPress. Every path is
 * resolved with realpath() and must sit inside the docroot or the plugin's
 * symlink target, and no path segment may start with a dot (.git, .env,
 * .gitattributes). Not shipped (tools/ is export-ignored).
 */
$root = realpath( $_SERVER['DOCUMENT_ROOT'] );
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$real = realpath( $root . $path );
// The plugin is symlinked in from the checkout, so its realpath leaves the
// docroot: its own target is an allowed root too, or every plugin asset falls
// through to WordPress and 301s (the editor then loads none of its scripts).
$roots = array_filter( array( $root, realpath( $root . '/wp-content/plugins/signal-and-noise-tools' ) ) );
$file  = '';
$dotted = 1 === preg_match( '#/\.#', $path ); // .git, .env and the like: never served or run.
foreach ( $roots as $base ) {
	if ( ! $dotted && false !== $real && ( $real === $base || str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) ) {
		$file = $real;
	}
}

if ( '' !== $file && is_dir( $file ) ) {
	$file = is_file( $file . '/index.php' ) && $file !== $root ? $file . '/index.php' : '';
}
if ( '' !== $file && is_file( $file ) && ! str_ends_with( $file, '.php' ) ) {
	return false; // php -S serves the static file itself.
}
if ( '' === $file || ! is_file( $file ) ) {
	$file = $root . '/index.php';
}
chdir( dirname( $file ) );
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- dev-only local router: $file is realpath()-resolved and confined to the docroot or the plugin's symlink target above, dot paths refused, else WordPress's own index.php.
require $file;
