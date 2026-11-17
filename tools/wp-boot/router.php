<?php
// php -S router: real files as themselves, everything else through WordPress.
$p = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$f = $_SERVER['DOCUMENT_ROOT'] . $p;
if ( '/' !== $p && is_file( $f ) && ! str_ends_with( $f, '.php' ) ) {
	return false;
}
if ( is_file( $f ) && str_ends_with( $f, '.php' ) ) {
	chdir( dirname( $f ) );
	require $f;
	return;
}
if ( is_dir( $f ) && is_file( rtrim( $f, '/' ) . '/index.php' ) && '/' !== $p ) {
	chdir( rtrim( $f, '/' ) );
	require rtrim( $f, '/' ) . '/index.php';
	return;
}
chdir( $_SERVER['DOCUMENT_ROOT'] );
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
