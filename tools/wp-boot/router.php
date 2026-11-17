<?php
/**
 * tools/wp-boot only: the php -S router for the local boot (127.0.0.1). Real
 * files as themselves, everything else through WordPress. Every path is
 * resolved with realpath() and must sit inside the docroot, so a request can
 * only reach files the local WordPress already serves. Not shipped
 * (tools/ is export-ignored).
 */
$root = realpath( $_SERVER['DOCUMENT_ROOT'] );
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$real = realpath( $root . $path );
$file = ( false !== $real && ( $real === $root || str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) ) ? $real : '';

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
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- dev-only local router: $file is realpath()-resolved and confined to the docroot above, else WordPress's own index.php.
require $file;
